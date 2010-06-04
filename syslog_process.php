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
list($micro,$seconds) = split(" ", microtime());
$start_time = $seconds + $micro;

$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'plugins') !== false) {
	chdir('../../');
}
include("./include/global.php");
include("./plugins/syslog/config.php");
include_once(dirname(__FILE__) . "/functions.php");

/* Connect to the Syslog Database */
global $syslog_cnn;
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
		$sql = "REPLACE INTO settings VALUES ('syslog_retention','30')";
	}else{
		$sql = "UPDATE settings SET value='30' WHERE name='syslog_retention'";
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

$syntax = db_fetch_row("SHOW CREATE TABLE `" . $syslogdb_default . "`.`syslog`", true, $syslog_cnn);
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
		db_execute("DELETE FROM syslog WHERE logtime < '$retention'", true, $syslog_cnn);

		$syslog_deleted = $syslog_cnn->Affected_Rows();

		/* now delete from the syslog removed table */
		db_execute("DELETE FROM syslog_removed WHERE logtime < '$retention'", true, $syslog_cnn);

		$syslog_deleted += $syslog_cnn->Affected_Rows();

		syslog_debug("Deleted " . $syslog_deleted .
			" Syslog Message" . ($syslog_deleted == 1 ? "" : "s" ) .
			" (older than $retention days)");
	}else{
		syslog_debug("Syslog Table IS Partitioned");

		$syslog_deleted = 0;
		$number_of_partitions = db_fetch_assoc("SELECT * FROM `information_schema`.`partitions` WHERE table_schema='" . $syslogdb_default . "' AND table_name='syslog' ORDER BY partition_ordinal_position", true, $syslog_cnn);

		syslog_debug("There are currently " . sizeof($number_of_partitions) . " Syslog Partitions");

		$time     = time();
		$now      = date('Y-m-d', $time);
		$format   = date('Ymd', $time);
		$cur_day  = db_fetch_row("SELECT TO_DAYS('$now') AS today", true, $syslog_cnn);
		$cur_day  = $cur_day["today"];

		$lday_ts  = read_config_option("syslog_lastday_timestamp");
		$lnow     = date('Y-m-d', $lday_ts);
		$lformat  = date('Ymd', $lday_ts);
		$last_day = db_fetch_row("SELECT TO_DAYS('$lnow') AS today", true, $syslog_cnn);
		$last_day = $last_day["today"];

		syslog_debug("The current day is '$cur_day', the last day is '$last_day'");

		if ($cur_day != $last_day) {
			db_execute("REPLACE INTO settings SET name='syslog_lastday_timestamp', value='$time'", true, $syslog_cnn);

			if ($lday_ts != '') {
				syslog_debug("Creating new partition 'd" . $lformat . "'");
				db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog` REORGANIZE PARTITION dMaxValue INTO (
					PARTITION d" . $lformat . " VALUES LESS THAN (TO_DAYS('$lnow')),
					PARTITION dMaxValue VALUES LESS THAN MAXVALUE)", true, $syslog_cnn);

				db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_removed` REORGANIZE PARTITION dMaxValue INTO (
					PARTITION d" . $lformat . " VALUES LESS THAN (TO_DAYS('$lnow')),
					PARTITION dMaxValue VALUES LESS THAN MAXVALUE)", true, $syslog_cnn);

				if ($retention > 0) {
					$user_partitions = sizeof($number_of_partitions) - 1;
					if ($user_partitions >= $days) {
						$i = 0;
						while ($user_partitions > $days) {
							$oldest = $number_of_partitions[$i];
							syslog_debug("Removing partition '" . $oldest["PARTITION_NAME"] . "'");
							db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog` DROP PARTITION " . $oldest["PARTITION_NAME"], true, $syslog_cnn);
							db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_removed` DROP PARTITION " . $oldest["PARTITION_NAME"], true, $syslog_cnn);
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
	$count    = db_fetch_cell("SELECT count(*) FROM `" . $syslogdb_default . "`.`syslog_incoming` WHERE status=" . $uniqueID, '', true, $syslog_cnn);

	if ($count == 0) {
		break;
	}
}

syslog_debug("Unique ID = " . $uniqueID);

/* flag all records with the uniqueID prior to moving */
db_execute("UPDATE syslog_incoming SET status=" . $uniqueID . " WHERE status=0", true, $syslog_cnn);

$syslog_incoming = $syslog_cnn->Affected_Rows();

syslog_debug("Found   " . $syslog_incoming .
	" new Message" . ($syslog_incoming == 1 ? "" : "s" ) .
	" to process");

/* update the hosts, facilities, and priorities tables */
db_execute("INSERT INTO syslog_facilities (facility) SELECT DISTINCT facility FROM `" . $syslogdb_default . "`.`syslog_incoming` ON DUPLICATE KEY UPDATE facility=VALUES(facility)", true, $syslog_cnn);
db_execute("INSERT INTO syslog_priorities (priority) SELECT DISTINCT priority FROM `" . $syslogdb_default . "`.`syslog_incoming` ON DUPLICATE KEY UPDATE priority=VALUES(priority)", true, $syslog_cnn);
db_execute("INSERT INTO syslog_hosts (host) SELECT DISTINCT host FROM `" . $syslogdb_default . "`.`syslog_incoming` ON DUPLICATE KEY UPDATE host=VALUES(host)", true, $syslog_cnn);
db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_host_facilities`
	(host_id, facility_id)
	SELECT host_id, facility_id
	FROM ((SELECT DISTINCT host, facility
		FROM `" . $syslogdb_default . "`.`syslog_incoming`) AS s
		INNER JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
		ON s.host=sh.host
		INNER JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
		ON sf.facility=s.facility)
	ON DUPLICATE KEY UPDATE host_id=VALUES(host_id)", true, $syslog_cnn);

/* remote records that don't need to to be transferred */
$syslog_items   = syslog_remove_items("syslog_incoming", $uniqueID);
$syslog_removed = $syslog_items["removed"];
$syslog_xferred = $syslog_items["xferred"];

/* send out the alerts */
$query = db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_alert`", true, $syslog_cnn);
$syslog_alerts  = sizeof($query);

if (read_config_option("syslog_html") == "on") {
	$html = true;
}else{
	$html = false;
}

syslog_debug("Found   " . $syslog_alerts .
	" Alert Rule" . ($syslog_alerts == 1 ? "" : "s" ) .
	" to process");

$syslog_alarms = 0;
if (sizeof($query)) {
	foreach($query as $alert) {
		$sql    = '';
		$alertm = '';
		$th_sql = '';

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
		}

		if ($sql != '') {
			if ($alert['method'] == "1") {
				$th_sql = str_replace("*", "count(*)", $sql);
				$count = db_fetch_cell($th_sql, '', true, $syslog_cnn);
			}

			if (($alert['method'] == "1" && $count >= $alert["num"]) || ($alert["method"] == "0")) {
				$at = db_fetch_assoc($sql, true, $syslog_cnn);

				if (sizeof($at)) {
					if ($html) {
						$alertm .= "<html><head><style type='text/css'>";
						$alertm .= file_get_contents($config['base_path'] . "/plugins/syslog/syslog.css");
						$alertm .= "</style></head>";
					}

					if ($alert['method'] == "1") {
						if (!$html) {
							$alertm .= "-----------------------------------------------\n";
							$alertm .= "WARNING: A Number of Instances Alert has Been Triggered". "\n";
							$alertm .= "Name: " . $alert['name']     . "\n";
							$alertm .= "Severity: " . $severities[$alert['severity']] . "\n";
							$alertm .= "Threshold: " . $alert['num'] . "\n";
							$alertm .= "Count: " . sizeof($at)       . "\n";
						}else{
							$alertm .= "<body><h1>Cacti Syslog Plugin Instance Count Alert '" . $alert['name'] . "'</h1>";
							$alertm .= "<table cellspacing='0' cellpadding='3' border='1'>";
							$alertm .= "<tr><th>Alert Name</th><th>Severity</th><th>Threshold</th><th>Count</th></tr>";
							$alertm .= "<tr><td>" . $alert['name'] . "</td>\n";
							$alertm .= "<tr><td>" . $severities[$alert['severity']]  . "</td>\n";
							$alertm .= "<tr><td>" . $alert['num']  . "</td>\n";
							$alertm .= "<td>"     . sizeof($at)    . "</td></tr></table><br>\n";
						}

						syslog_log_alert($alert["id"], $alert["name"], $alert["severity"], $at[0], sizeof($at));
					}else{
						if ($html) {
							$alertm .= "<body><h1>Cacti Syslog Plugin Alert '" . $alert['name'] . "'</h1>";
						}
					}

					if ($html) $alertm .= "<table cellspacing='0' cellpadding='3' border='1'>";
					if ($html) $alertm .= "<tr><th>Hostname</th><th>Date</th><th>Severity</th><th>Priotity</th><th>Message</th></tr>";

					foreach($at as $a) {
						$a['message'] = str_replace('  ', "\n", $a['message']);
						while (substr($a['message'], -1) == "\n") {
							$a['message'] = substr($a['message'], 0, -1);
						}

						if (!$html) {
							$alertm .= "-----------------------------------------------\n";
							$alertm .= 'Hostname : ' . $a['host'] . "\n";
							$alertm .= 'Date     : ' . $a['date'] . ' ' . $a['time'] . "\n";
							$alertm .= 'Severity : ' . $severities[$alert['severity']] . "\n\n";
							$alertm .= 'Priority : ' . $a['priority'] . "\n\n";
							$alertm .= 'Message  :' . "\n" . $a['message'] . "\n";
						}else{
							$alertm .= "<tr><td>" . $a['host']                      . "</td>"      . "\n";
							$alertm .= "<td>"     . $a['date'] . ' ' . $a['time']   . "</td>"      . "\n";
							$alertm .= "<td>"     . $severities[$alert['severity']] . "</td>"      . "\n";
							$alertm .= "<td>"     . $a['priority']                  . "</td>"      . "\n";
							$alertm .= "<td>"     . $a['message']                   . "</td></tr>" . "\n";
						}

						$syslog_alarms++;

						if ($alert['method'] != "1") {
							syslog_log_alert($alert["id"], $alert["name"], $alert["severity"] $a);
						}
					}

					syslog_debug("Alert Rule '" . $alert['name'] . "' has been activated");

					if ($html) {
						$alertm .= "</table></body></html>";
					}else{
						$alertm .= "-----------------------------------------------\n\n";
					}
				}
			}
		}

		if ($alertm != '') {
			syslog_sendemail($alert['email'], '', 'Event Alert - ' . $alert['name'], $alertm);
		}
	}
}

/* MOVE ALL FLAGGED MESSAGES TO THE SYSLOG TABLE */
db_execute('INSERT INTO syslog (logtime, priority_id, facility_id, host_id, message)
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
		WHERE status=' . $uniqueID . ") AS merge", true, $syslog_cnn);

$moved = $syslog_cnn->Affected_Rows();

syslog_debug("Moved   " . $moved . " Message" . ($moved == 1 ? "" : "s" ) . " to the 'syslog' table");

/* DELETE ALL FLAGGED ITEMS FROM THE INCOMING TABLE */
db_execute("DELETE FROM syslog_incoming WHERE status=" . $uniqueID, true, $syslog_cnn);

syslog_debug("Deleted " . $syslog_cnn->Affected_Rows() . " already processed Messages from incoming");

/* Add the unique hosts to the syslog_hosts table */
$sql = "INSERT INTO syslog_hosts (host) (SELECT DISTINCT host FROM syslog_incoming) ON DUPLICATE KEY UPDATE host=VALUES(host)";

db_execute($sql, true, $syslog_cnn);

syslog_debug("Updated " . $syslog_cnn->Affected_Rows() .
	" hosts in the syslog hosts table");

/* OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP */
if (date("G") == 0 && date("i") < 5) {
	if (!$partitioned) {
		db_execute("OPTIMIZE TABLE
			`" . $syslogdb_default . "`.`syslog_incoming`,
			`" . $syslogdb_default . "`.`syslog`,
			`" . $syslogdb_default . "`.`syslog_remove`,
			`" . $syslogdb_default . "`.`syslog_removed`,
			`" . $syslogdb_default . "`.`syslog_alert`", true, $syslog_cnn);
	}else{
		db_execute("OPTIMIZE TABLE
			`" . $syslogdb_default . "`.`syslog_incoming`,
			`" . $syslogdb_default . "`.`syslog_remove`,
			`" . $syslogdb_default . "`.`syslog_alert`", true, $syslog_cnn);
	}
}

syslog_debug("Processing Reports...");

/* Lets run the reports */
$reports = db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_reports`", true, $syslog_cnn);
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
			$items = db_fetch_assoc($sql, true, $syslog_cnn);

			syslog_debug("We have " . $syslog_cnn->Affected_Rows() . " items for the Report");

			if (sizeof($items)) {
			foreach($items as $item) {
				$reptext .= "<tr>" . $item['date'] . "</td><td>" . $item['time'] . "</td><td>" . $item['message'] . "</td></tr>\n";
			}
			}

			if ($reptext != '') {
				$reptext = '<html><body><center><h2>' . $syslog_report['name'] . "</h2></center><table>\n" .
					    "<tr><td>Date</td><td>Time</td><td>Message</td></tr>\n" . $reptext;

				$reptext .= "</table>\n";
				// Send mail
				syslog_sendemail($syslog_report['email'], '', 'Event Report - ' . $syslog_report['name'], $reptext);
			}
		}
	} else {
		print '       Next Send: ' . date("F j, Y, g:i a", $next_run_time) . "\n";
	}
}
}

syslog_debug("Finished processing Reports...");

syslog_process_log($start_time, $syslog_deleted, $syslog_incoming, $syslog_removed, $syslog_xferred, $syslog_alerts, $syslog_alarms, $syslog_reports);

function syslog_process_log($start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports) {
	/* record the end time */
	list($micro,$seconds) = split(" ", microtime());
	$end_time = $seconds + $micro;

	cacti_log("SYSLOG STATS:Time:" . round($end_time-$start_time,2) . " Deletes:" . $deleted . " Incoming:" . $incoming . " Removes:" . $removed . " XFers:" . $xferred . " Alerts:" . $alerts . " Alarms:" . $alarms . " Reports:" . $reports, true, "SYSTEM");

	set_config_option("syslog_stats", "time:" . round($end_time-$start_time,2) . "deletes:" . $deleted . " incoming:" . $incoming . " removes:" . $removed . " xfers:" . $xferred . " alerts:" . $alerts . " alarms:" . $alarms . " reports:" . $reports);
}

function display_help() {
	echo "Syslog Poller Process 1.0, Copyright 2004-2010 - The Cacti Group\n\n";
	echo "The main Syslog poller process script for Cacti Syslogging.\n\n";
	echo "usage: syslog_process.php [--debug|-d]\n\n";
}
