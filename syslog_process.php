<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2010 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}
$no_http_headers = true;

/* Let it run for an hour if it has to, to clear up any big
 * bursts of incoming syslog events
 */
ini_set('max_execution_time', 3600);
ini_set('memory_limit', '256M');

global $syslog_debug;

$syslog_debug = false;

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

if (sizeof($parms)) {
	foreach($parms as $parameter) {
		@list($arg, $value) = @explode("=", $parameter);

		switch ($arg) {
		case "--debug":
		case "-d":
			$syslog_debug = true;

			break;
		case "--version":
		case "-V":
		case "-H":
		case "--help":
			display_help();
			exit(0);
		default:
			echo "ERROR: Invalid Argument: ($arg)\n\n";
			display_help();
			exit(1);
		}
	}
}

/* record the start time */
list($micro,$seconds) = explode(" ", microtime());
$start_time = $seconds + $micro;

$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'plugins') !== false) {
	chdir('../../');
}
include("./include/global.php");
include_once("./lib/poller.php");
include("./plugins/syslog/config.php");
include_once(dirname(__FILE__) . "/functions.php");

/* Connect to the Syslog Database */
global $syslog_cnn, $cnn_id, $database_default;
if (empty($syslog_cnn)) {
	if ((strtolower($database_hostname) == strtolower($syslogdb_hostname)) &&
		($database_default == $syslogdb_default)) {
		/* move on, using Cacti */
		$syslog_cnn = $cnn_id;
	}else{
		if (!isset($syslogdb_port)) {
			$syslogdb_port = "3306";
		}
		$syslog_cnn = db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type, $syslogdb_port);
	}
}

/* If Syslog Collection is Disabled, Exit Here */
if (read_config_option("syslog_enabled") == '') {
	print "NOTE: Syslog record transferral and alerting/reporting is disabled.  Exiting\n";
	exit -1;
}

/* Initialization Section */
$r = read_config_option("syslog_retention");
if ($r == '' or $r < 0 or $r > 365) {
	if ($r == '') {
		$sql = "REPLACE INTO `" . $database_default . "`.`settings` (name, value) VALUES ('syslog_retention','30')";
	}else{
		$sql = "UPDATE `" . $database_default . "`.`settings` SET value='30' WHERE name='syslog_retention'";
	}

	$result = db_execute($sql);

	kill_session_var("sess_config_array");
}

$retention = read_config_option("syslog_retention");
$retention = date("Y-m-d", time() - (86400 * $retention));
$email     = read_config_option("syslog_email");
$emailname = read_config_option("syslog_emailname");
$from      = '';

if ($email != '') {
	if ($emailname != '') {
		$from = "\"$emailname\" ($email)";
	} else {
		$from = $email;
	}
}

$syntax = syslog_db_fetch_row("SHOW CREATE TABLE `" . $syslogdb_default . "`.`syslog`");
if (substr_count($syntax["Create Table"], "PARTITION")) {
	$partitioned = true;
}else{
	$partitioned = false;
}

/* delete old syslog and syslog soft messages */
if ($retention > 0 || $partitioned) {
	if (!$partitioned) {
		syslog_debug("Syslog Table is NOT Partitioned");

		/* delete from the main syslog table first */
		syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog` WHERE logtime < '$retention'");

		$syslog_deleted = $syslog_cnn->Affected_Rows();

		/* now delete from the syslog removed table */
		syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog_removed` WHERE logtime < '$retention'");

		$syslog_deleted += $syslog_cnn->Affected_Rows();

		syslog_debug("Deleted " . $syslog_deleted .
			",  Syslog Message(s)" .
			" (older than $retention days)");
	}else{
		syslog_debug("Syslog Table IS Partitioned");

		$syslog_deleted = 0;
		$number_of_partitions = syslog_db_fetch_assoc("SELECT *
			FROM `information_schema`.`partitions`
			WHERE table_schema='" . $syslogdb_default . "' AND table_name='syslog'
			ORDER BY partition_ordinal_position");

		$time     = time();
		$now      = date('Y-m-d', $time);
		$format   = date('Ymd', $time);
		$cur_day  = syslog_db_fetch_row("SELECT TO_DAYS('$now') AS today");
		$cur_day  = $cur_day["today"];

		$lday_ts  = read_config_option("syslog_lastday_timestamp");
		$lnow     = date('Y-m-d', $lday_ts);
		$lformat  = date('Ymd', $lday_ts);
		$last_day = syslog_db_fetch_row("SELECT TO_DAYS('$lnow') AS today");
		$last_day = $last_day["today"];
		$days     = read_config_option("syslog_retention");

		syslog_debug("There are currently '" . sizeof($number_of_partitions) . "' Syslog Partitions, We will keep '$days' of them.");
		//cacti_log("SYSLOG: There are currently '" . sizeof($number_of_partitions) . "' Partitions, We will keep '$days' of them.", false, "SYSTEM");

		syslog_debug("The current day is '$cur_day', the last day is '$last_day'");

		if ($cur_day != $last_day) {
			syslog_db_execute("REPLACE INTO `" . $database_default . "`.`settings` SET name='syslog_lastday_timestamp', value='$time'");

			if ($lday_ts != '') {
				cacti_log("SYSLOG: Creating new partition 'd" . $lformat . "'", false, "SYSTEM");
				syslog_debug("Creating new partition 'd" . $lformat . "'");
				syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog` REORGANIZE PARTITION dMaxValue INTO (
					PARTITION d" . $lformat . " VALUES LESS THAN (TO_DAYS('$lnow')),
					PARTITION dMaxValue VALUES LESS THAN MAXVALUE)");

				syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_removed` REORGANIZE PARTITION dMaxValue INTO (
					PARTITION d" . $lformat . " VALUES LESS THAN (TO_DAYS('$lnow')),
					PARTITION dMaxValue VALUES LESS THAN MAXVALUE)");

				if ($days > 0) {
					$user_partitions = sizeof($number_of_partitions) - 1;
					if ($user_partitions >= $days) {
						$i = 0;
						while ($user_partitions > $days) {
							$oldest = $number_of_partitions[$i];
							cacti_log("SYSLOG: Removing old partition 'd" . $oldest["PARTITION_NAME"] . "'", false, "SYSTEM");
							syslog_debug("Removing partition '" . $oldest["PARTITION_NAME"] . "'");
							syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog` DROP PARTITION " . $oldest["PARTITION_NAME"]);
							syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_removed` DROP PARTITION " . $oldest["PARTITION_NAME"]);
							$i++;
							$user_partitions--;
							$syslog_deleted++;
						}
					}
				}
			}
		}
	}
}

/* get a uniqueID to allow moving of records to done table */
while (1) {
	$uniqueID = rand(1, 127);
	$count    = syslog_db_fetch_cell("SELECT count(*) FROM `" . $syslogdb_default . "`.`syslog_incoming` WHERE status=" . $uniqueID);

	if ($count == 0) {
		break;
	}
}

syslog_debug("Unique ID = " . $uniqueID);

/* flag all records with the uniqueID prior to moving */
syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_incoming` SET status=" . $uniqueID . " WHERE status=0");

$syslog_incoming = $syslog_cnn->Affected_Rows();

syslog_debug("Found   " . $syslog_incoming .
	",  New Message(s)" .
	" to process");

/* update the hosts, facilities, and priorities tables */
syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_facilities` (facility) SELECT DISTINCT facility FROM `" . $syslogdb_default . "`.`syslog_incoming` ON DUPLICATE KEY UPDATE facility=VALUES(facility)");
syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_priorities` (priority) SELECT DISTINCT priority FROM `" . $syslogdb_default . "`.`syslog_incoming` ON DUPLICATE KEY UPDATE priority=VALUES(priority)");
syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_hosts` (host) SELECT DISTINCT host FROM `" . $syslogdb_default . "`.`syslog_incoming` ON DUPLICATE KEY UPDATE host=VALUES(host)");
syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_host_facilities`
	(host_id, facility_id)
	SELECT host_id, facility_id
	FROM ((SELECT DISTINCT host, facility
		FROM `" . $syslogdb_default . "`.`syslog_incoming`) AS s
		INNER JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
		ON s.host=sh.host
		INNER JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
		ON sf.facility=s.facility)
	ON DUPLICATE KEY UPDATE host_id=VALUES(host_id)");

/* remote records that don't need to to be transferred */
$syslog_items   = syslog_remove_items("syslog_incoming", $uniqueID);
$syslog_removed = $syslog_items["removed"];
$syslog_xferred = $syslog_items["xferred"];

/* send out the alerts */
$query = syslog_db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_alert` WHERE enabled='on'");
$syslog_alerts  = sizeof($query);

if (read_config_option("syslog_html") == "on") {
	$html = true;
}else{
	$html = false;
}

syslog_debug("Found   " . $syslog_alerts .
	",  Alert Rule" . ($syslog_alerts == 1 ? "" : "s" ) .
	" to process");

$syslog_alarms = 0;
if (sizeof($query)) {
	foreach($query as $alert) {
		$sql      = '';
		$alertm   = '';
		$htmlm    = '';
		$smsalert = '';
		$th_sql   = '';

		if ($alert['type'] == 'facility') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog_incoming`
				WHERE " . $syslog_incoming_config["facilityField"] . "='" . $alert['message'] . "'
				AND status=" . $uniqueID;
		} else if ($alert['type'] == 'messageb') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog_incoming`
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '" . $alert['message'] . "%'
				AND status=" . $uniqueID;
		} else if ($alert['type'] == 'messagec') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog_incoming`
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $alert['message'] . "%'
				AND status=" . $uniqueID;
		} else if ($alert['type'] == 'messagee') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog_incoming`
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $alert['message'] . "'
				AND status=" . $uniqueID;
		} else if ($alert['type'] == 'host') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog_incoming`
				WHERE " . $syslog_incoming_config["hostField"] . "='" . $alert['message'] . "'
				AND status=" . $uniqueID;
		} else if ($alert['type'] == 'sql') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog_incoming`
				WHERE (" . $alert['message'] . ")
				AND status=" . $uniqueID;
		}

		if ($sql != '') {
			if ($alert['method'] == "1") {
				$th_sql = str_replace("*", "count(*)", $sql);
				$count = syslog_db_fetch_cell($th_sql);
			}

			if (($alert['method'] == "1" && $count >= $alert["num"]) || ($alert["method"] == "0")) {
				$at = syslog_db_fetch_assoc($sql);

				if (sizeof($at)) {
					$htmlm .= "<html><head><style type='text/css'>";
					$htmlm .= file_get_contents($config['base_path'] . "/plugins/syslog/syslog.css");
					$htmlm .= "</style></head>";

					if ($alert['method'] == "1") {
						$alertm .= "-----------------------------------------------\n";
						$alertm .= "WARNING: A Syslog Plugin Instance Count Alert has Been Triggered". "\n";
						$alertm .= "Name: " . $alert['name']     . "\n";
						$alertm .= "Severity: " . $severities[$alert['severity']] . "\n";
						$alertm .= "Threshold: " . $alert['num'] . "\n";
						$alertm .= "Count: " . sizeof($at)       . "\n";
						$alertm .= "Message String: " . $alert['message'] . "\n";

						$htmlm  .= "<body class='body'><h1 class='h1'>Cacti Syslog Plugin Threshold Alert '" . $alert['name'] . "'</h1>";
						$htmlm  .= "<table class='table' cellspacing='0' cellpadding='3' border='1'>";
						$htmlm  .= "<tr><th class='th'>Alert Name</th><th class='th'>Severity</th><th class='th'>Threshold</th><th class='th'>Count</th><th class='th'>Match String</th></tr>";
						$htmlm  .= "<tr><td class='td'>" . $alert['name']    . "</td>\n";
						$htmlm  .= "<td class='td'>" . $severities[$alert['severity']]  . "</td>\n";
						$htmlm  .= "<td class='td'>" . $alert['num']     . "</td>\n";
						$htmlm  .= "<td class='td'>"     . sizeof($at)       . "</td>\n";
						$htmlm  .= "<td class='td'>"     . htmlspecialchars($alert['message']) . "</td></tr></table><br>\n";
					}else{
						$htmlm .= "<body class='body'><h1 class='h1'>Cacti Syslog Plugin Alert '" . $alert['name'] . "'</h1>";
					}

					$htmlm .= "<table  class='table' cellspacing='0' cellpadding='3' border='1'>";
					$htmlm .= "<tr><th class='th'>Hostname</th><th class='th'>Date</th><th class='th'>Severity</th><th class='th'>Priotity</th><th class='th'>Message</th></tr>";

					$max_alerts  = read_config_option("syslog_maxrecords");
					$alert_count = 0;
					$htmlh       = $htmlm;
					$alerth      = $alertm;
					foreach($at as $a) {
						$a['message'] = str_replace('  ', "\n", $a['message']);
						while (substr($a['message'], -1) == "\n") {
							$a['message'] = substr($a['message'], 0, -1);
						}

						if (($alert["method"] == 1 && $alert_count < $max_alerts) || $alert["method"] == 0) {
							if ($alert["method"] == 0) $alertm  = $alerth;
							$alertm .= "-----------------------------------------------\n";
							$alertm .= 'Hostname : ' . $a['host'] . "\n";
							$alertm .= 'Date     : ' . $a['date'] . ' ' . $a['time'] . "\n";
							$alertm .= 'Severity : ' . $severities[$alert['severity']] . "\n\n";
							$alertm .= 'Priority : ' . $a['priority'] . "\n\n";
							$alertm .= 'Message  :'  . "\n" . $a['message'] . "\n";

							if ($alert["method"] == 0) $htmlm   = $htmlh;
							$htmlm  .= "<tr><td class='td'>" . $a['host']                      . "</td>"      . "\n";
							$htmlm  .= "<td class='td'>"     . $a['date'] . ' ' . $a['time']   . "</td>"      . "\n";
							$htmlm  .= "<td class='td'>"     . $severities[$alert['severity']] . "</td>"      . "\n";
							$htmlm  .= "<td class='td'>"     . $a['priority']                  . "</td>"      . "\n";
							$htmlm  .= "<td class='td'>"     . $a['message']                   . "</td></tr>" . "\n";
						}

						$syslog_alarms++;
						$alert_count++;

						if ($alert['method'] != "1") {
							$htmlm  .= "</table></body></html>";
							$sequence = syslog_log_alert($alert["id"], $alert["name"], $alert["severity"], $a, 1, $htmlm);
							$smsalert = "Sev:" . $severities[$alert["severity"]] . ", Host:" . $a["host"] . ", URL:" . read_config_option("alert_base_url") . "plugins/syslog/syslog.php?tab=current&id=" . $sequence;
						}

						if (trim($alert['command']) != "") {
							$command = alert_replace_variables($alert, $a);
							cacti_log("SYSLOG NOTICE: Executing '$command'", true, "SYSTEM");
							exec_background($command);
						}
					}

					$htmlm  .= "</table></body></html>";
					$alertm .= "-----------------------------------------------\n\n";

					if ($alert["method"] == 1) {
						$sequence = syslog_log_alert($alert["id"], $alert["name"] . " [" . $alert["message"] . "]", $alert["severity"], $at[0], sizeof($at), $htmlm);
						$smsalert = "Sev:" . $severities[$alert["severity"]] . ", Count:" . sizeof($at) . ", URL:" . read_config_option("alert_base_url") . "plugins/syslog/syslog.php?tab=current&id=" . $sequence;
					}
					syslog_debug("Alert Rule '" . $alert['name'] . "' has been activated");
				}
			}
		}

		if ($alertm != '') {
			syslog_sendemail(trim($alert['email']), '', 'Event Alert - ' . $alert['name'], ($html ? $htmlm:$alertm), $smsalert);
		}
	}
}

/* move syslog records to the syslog table */
syslog_db_execute('INSERT INTO `' . $syslogdb_default . '`.`syslog` (logtime, priority_id, facility_id, host_id, message)
	SELECT TIMESTAMP(`' . $syslog_incoming_config['dateField'] . '`, `' . $syslog_incoming_config["timeField"]     . '`),
	priority_id, facility_id, host_id, message
	FROM (SELECT date, time, priority_id, facility_id, host_id, message
		FROM syslog_incoming AS si
		INNER JOIN syslog_facilities AS sf
		ON sf.facility=si.facility
		INNER JOIN syslog_priorities AS sp
		ON sp.priority=si.priority
		INNER JOIN syslog_hosts AS sh
		ON sh.host=si.host
		WHERE status=' . $uniqueID . ") AS merge");

$moved = $syslog_cnn->Affected_Rows();

syslog_debug("Moved   " . $moved . ",  Message(s) to the 'syslog' table");

/* remove flagged messages */
syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog_incoming` WHERE status=" . $uniqueID);

syslog_debug("Deleted " . $syslog_cnn->Affected_Rows() . ",  Already Processed Message(s) from incoming");

/* Add the unique hosts to the syslog_hosts table */
$sql = "INSERT INTO `" . $syslogdb_default . "`.`syslog_hosts` (host)
	(SELECT DISTINCT host FROM `" . $syslogdb_default . "`.`syslog_incoming`)
	ON DUPLICATE KEY UPDATE host=VALUES(host)";

syslog_db_execute($sql);

syslog_debug("Updated " . $syslog_cnn->Affected_Rows() .
	",  Hosts in the syslog hosts table");

/* OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP */
if (date("G") == 0 && date("i") < 5) {
	if (!$partitioned) {
		syslog_db_execute("OPTIMIZE TABLE
			`" . $syslogdb_default . "`.`syslog_incoming`,
			`" . $syslogdb_default . "`.`syslog`,
			`" . $syslogdb_default . "`.`syslog_remove`,
			`" . $syslogdb_default . "`.`syslog_removed`,
			`" . $syslogdb_default . "`.`syslog_alert`");
	}else{
		syslog_db_execute("OPTIMIZE TABLE
			`" . $syslogdb_default . "`.`syslog_incoming`,
			`" . $syslogdb_default . "`.`syslog_remove`,
			`" . $syslogdb_default . "`.`syslog_alert`");
	}
}

syslog_debug("Processing Reports...");

/* Lets run the reports */
$reports = syslog_db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_reports` WHERE enabled='on'");
$syslog_reports = sizeof($reports);

syslog_debug("We have " . $syslog_reports . " Reports in the database");

if (sizeof($reports)) {
foreach($reports as $syslog_report) {
	print '   Report: ' . $syslog_report['name'] . "\n";
	if ($syslog_report['min'] < 10)
		$syslog_report['min'] = '0' . $syslog_report['min'];

	$base_start_time = $syslog_report['hour'] . ' : ' . $syslog_report['min'];

	$current_time = strtotime("now");
	if (empty($last_run_time)) {
		if ($current_time > strtotime($base_start_time)) {
			/* if timer expired within a polling interval, then poll */
			if (($current_time - 300) < strtotime($base_start_time)) {
				$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
			}else{
				$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time) + 3600*24;
			}
		}else{
			$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
		}
	}else{
		$next_run_time = $last_run_time + $seconds_offset;
	}
	$time_till_next_run = $next_run_time - $current_time;

	if ($next_run_time < 0) {
		print '       Next Send: Now' . "\n";
		print "       Creating Report...\n";

		$sql     = '';
		$reptext = '';
		if ($syslog_report['type'] == 'messageb') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog`
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagec') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog`
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagee') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog`
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "'";
		}

		if ($syslog_report['type'] == 'host') {
			$sql = "SELECT * FROM `" . $syslogdb_default . "`.`syslog`
				WHERE " . $syslog_incoming_config["hostField"] . "='" . $syslog_report['message'] . "'";
		}

		if ($sql != '') {
			$date2 = date("Y-m-d H:i:s", time());
			$date1 = date("Y-m-d H:i:s", time() - 86400);
			$sql  .= " AND logtime BETWEEN '". $date1 . "' AND '" . $date2 . "'";
			$sql  .= " ORDER BY logtime DESC";
			$items = syslog_db_fetch_assoc($sql);

			syslog_debug("We have " . $syslog_cnn->Affected_Rows() . " items for the Report");

			if (sizeof($items)) {
			foreach($items as $item) {
				$reptext .= "<tr>" . $item['date'] . "</td><td>" . $item['time'] . "</td><td>" . $item['message'] . "</td></tr>\n";
			}
			}

			if ($reptext != '') {
				$headtext .= "<html><head><style type='text/css'>";
				$headtext .= file_get_contents($config['base_path'] . "/plugins/syslog/syslog.css");
				$headtext .= "</style></head>";

				$headtext .= "<body class='body'><h1 class='h1'>" . $syslog_report['name'] . "</h1><table>\n" .
					    "<tr><th class='th'>Date</th><th class='th'>Time</th><th class='th'>Message</th></tr>\n" . $reptext;

				$headtext .= "</table>\n";
				$smsalert  = $headtext;
				// Send mail
				syslog_sendemail($syslog_report['email'], '', 'Event Report - ' . $syslog_report['name'], $headtext, $smsalert);
			}
		}
	} else {
		print '       Next Send: ' . date("Y-m-d H:i:s", $next_run_time) . "\n";
	}
}
}

syslog_debug("Finished processing Reports...");

syslog_process_log($start_time, $syslog_deleted, $syslog_incoming, $syslog_removed, $syslog_xferred, $syslog_alerts, $syslog_alarms, $syslog_reports);

function syslog_process_log($start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports) {
	global $database_default;

	/* record the end time */
	list($micro,$seconds) = explode(" ", microtime());
	$end_time = $seconds + $micro;

	cacti_log("SYSLOG STATS:Time:" . round($end_time-$start_time,2) . " Deletes:" . $deleted . " Incoming:" . $incoming . " Removes:" . $removed . " XFers:" . $xferred . " Alerts:" . $alerts . " Alarms:" . $alarms . " Reports:" . $reports, true, "SYSTEM");

	db_execute("REPLACE INTO `" . $database_default . "`.`settings` SET name='syslog_stats', value='time:" . round($end_time-$start_time,2) . " deletes:" . $deleted . " incoming:" . $incoming . " removes:" . $removed . " xfers:" . $xferred . " alerts:" . $alerts . " alarms:" . $alarms . " reports:" . $reports . "'");
}

function display_help() {
	echo "Syslog Poller Process 1.0, Copyright 2004-2010 - The Cacti Group\n\n";
	echo "The main Syslog poller process script for Cacti Syslogging.\n\n";
	echo "usage: syslog_process.php [--debug|-d]\n\n";
}

function alert_replace_variables($alert, $a) {
	global $severities;

	$command = $alert["command"];

	$command = str_replace("<ALERTID>",  $a["id"], $command);
	$command = str_replace("<HOSTNAME>", $a["hostname"], $command);
	$command = str_replace("<PRIORITY>", $a["priority"], $command);
	$command = str_replace("<FACILITY>", $a["facility"], $command);
	$command = str_replace("<MESSAGE>",  $a["message"], $command);
	$command = str_replace("<SEVERITY>", $severities[$a["severity"]], $command);

	return $command;
}
