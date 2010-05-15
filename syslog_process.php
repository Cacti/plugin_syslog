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

/* Let it run for an hour if it has to, to clear up any big
 * bursts of incoming syslog events
 */
ini_set('max_execution_time', 3600);
ini_set('memory_limit', '256M');

global $syslog_debug;

$syslog_debug = false;

if (isset($_SERVER["argv"][1])) {
	$commands    = $_SERVER["argv"];
	$commands[0] = "";
	if (in_array('/debug', $commands)) {
		$syslog_debug = 1;
	}

	if (in_array('-d', $commands)) {
		$syslog_debug = 1;
	}
}

$no_http_headers = true;

$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'plugins') !== false) {
	chdir('../../');
}
include("./include/global.php");

include_once($config["library_path"] . "/functions.php");
include_once($config["base_path"] . '/plugins/syslog/config.php');
include_once($config["base_path"] . '/plugins/syslog/functions.php');

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

/* delete old syslog and syslog soft messages */
if ($retention > 0) {
	/* delete from the main syslog table first */
	db_execute("DELETE FROM syslog WHERE date < '$retention'", true, $syslog_cnn);

	$syslog_deleted = $syslog_cnn->Affected_Rows();

	/* now delete from the syslog removed table */
	db_execute("DELETE FROM syslog_removed WHERE date < '$retention'", true, $syslog_cnn);

	/* remove old hosts */
	db_execute("DELETE FROM syslog_hosts WHERE UNIX_TIMESTAMP(last_updated)<UNIX_TIMESTAMP()-3600", true, $syslog_cnn);

	$syslog_deleted += $syslog_cnn->Affected_Rows();

	syslog_debug("Deleted " . $syslog_deleted .
		" Syslog Message" . ($syslog_deleted == 1 ? "" : "s" ) .
		" (older than $retention days)");
}

/* get a uniqueID to allow moving of records to done table */
while (1) {
	$uniqueID = rand(1, 127);
	$count    = db_fetch_cell("SELECT count(*) FROM syslog_incoming WHERE status=" . $uniqueID, true, $syslog_cnn);

	if ($count == 0) {
		break;
	}
}

syslog_debug("Unique ID = " . $uniqueID);

/* flag all records with the uniqueID prior to moving */
db_execute("UPDATE syslog_incoming SET status=" . $uniqueID . " WHERE status=0", true, $syslog_cnn);

syslog_debug("Found " . $syslog_cnn->Affected_Rows() .
	" new Message" . ($syslog_cnn->Affected_Rows() == 1 ? "" : "s" ) .
	" to process");

/* remote records that don't need to to be transferred */
syslog_remove_items("syslog_incoming");

/* send out the alerts */
$query = db_fetch_assoc("SELECT * FROM syslog_alert", true, $syslog_cnn);

syslog_debug("Found " . $syslog_cnn->Affected_Rows() .
	" Alert Rule" . ($syslog_cnn->Affected_Rows() == 1 ? "" : "s" ) .
	" to process");

if (sizeof($query)) {
foreach($query as $alert) {
	$sql    = '';
	$alertm = '';

	if ($alert['type'] == 'facility') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["facilityField"] . "='" . $alert['message'] . "'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messageb') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["textField"] . "
			LIKE '" . $alert['message'] . "%'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messagec') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["textField"] . "
			LIKE '%" . $alert['message'] . "%'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messagee') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["textField"] . "
			LIKE '%" . $alert['message'] . "'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'host') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["hostField"] . "='" . $alert['message'] . "'
			AND status=" . $uniqueID;
	}

	if ($sql != '') {
		$at = db_fetch_assoc($sql, true, $syslog_cnn);

		if (sizeof($at)) {
		foreach($at as $a) {
			$a['message'] = str_replace('  ', "\n", $a['message']);
			while (substr($a['message'], -1) == "\n") {
				$a['message'] = substr($a['message'], 0, -1);
			}

			$alertm .= "-----------------------------------------------\n";
			$alertm .= 'Hostname : ' . $a['host'] . "\n";
			$alertm .= 'Date     : ' . $a['date'] . ' ' . $a['time'] . "\n";
			$alertm .= 'Severity : ' . $a['priority'] . "\n\n";
			$alertm .= 'Message  :' . "\n" . $a['message'] . "\n";
			$alertm .= "-----------------------------------------------\n\n";

			syslog_debug("Alert Rule '" . $alert['name'] . "
				' has been activated");
		}
		}
	}

	if ($alertm != '') {
		syslog_sendemail($alert['email'], '', 'Event Alert - ' . $alert['name'], $alertm);
	}
}
}

/* MOVE ALL FLAGGED MESSAGES TO THE SYSLOG TABLE */
db_execute('INSERT INTO syslog (logtime, ' .
	$syslog_incoming_config["priorityField"] . ', ' .
	$syslog_incoming_config["facilityField"] . ', ' .
	$syslog_incoming_config["hostField"]     . ', ' .
	$syslog_incoming_config["textField"]     . ') ' .
	'SELECT TIMESTAMP(`' . $syslog_incoming_config['dateField'] . '`, `' . $syslog_incoming_config["timeField"]     . '`), ' .
	$syslog_incoming_config["priorityField"] . ', ' .
	$syslog_incoming_config["facilityField"] . ', ' .
	$syslog_incoming_config["hostField"] . ' , ' .
	$syslog_incoming_config["textField"] .
	' FROM syslog_incoming WHERE status=' . $uniqueID, true, $syslog_cnn);

$moved = $syslog_cnn->Affected_Rows();

syslog_debug("Moved " . $moved .
	" Message" . ($moved == 1 ? "" : "s" ) .
	" to the 'syslog' table");

/* DELETE ALL FLAGGED ITEMS FROM THE INCOMING TABLE */
db_execute("DELETE FROM syslog_incoming WHERE status=" . $uniqueID, true, $syslog_cnn);

syslog_debug("Deleted " . $syslog_cnn->Affected_Rows() .
	" already processed Messages from incoming");

/* Add the unique hosts to the syslog_hosts table */
$sql = "INSERT INTO syslog_hosts (host) (SELECT DISTINCT host FROM syslog) ON DUPLICATE KEY UPDATE host=VALUES(host)";

db_execute($sql, true, $syslog_cnn);

syslog_debug("Updated " . $syslog_cnn->Affected_Rows() .
	" hosts in the syslog hosts table");

/* OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP */
if (date("G") == 0 && date("i") < 5) {
	db_execute("OPTIMIZE TABLE syslog_incoming, syslog, syslog_remove, syslog_alert");
}

syslog_debug("Processing Reports...");

/* Lets run the reports */
$syslog_reports = db_fetch_assoc("SELECT * FROM syslog_reports", true, $syslog_cnn);

syslog_debug("We have " . $syslog_cnn->Affected_Rows() . " Reports in the database");

if (sizeof($syslog_reports)) {
foreach($syslog_reports as $syslog_report) {
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
			$sql = "SELECT * FROM syslog
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagec') {
			$sql = "SELECT * FROM syslog
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagee') {
			$sql = "SELECT * FROM syslog
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "'";
		}

		if ($syslog_report['type'] == 'host') {
			$sql = "SELECT * FROM syslog
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
