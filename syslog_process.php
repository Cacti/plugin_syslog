<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007 The Cacti Group                                      |
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
ini_set('memory_limit', '64M');

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
if (file_exists("./include/global.php")) {
	include("./include/global.php");
} else {
	include("./include/config.php");
}

include_once($config["library_path"] . "/functions.php");
include_once($config["base_path"] . '/plugins/syslog/config.php');
include_once($config["base_path"] . '/plugins/syslog/functions.php');

/* Connect to the Syslog Database */
$link = mysql_connect($syslogdb_hostname, $syslogdb_username, $syslogdb_password) or die('Could not connect to the database!');
mysql_select_db($syslogdb_default) or die('');

/* Initialization Section */
$r = read_config_option("syslog_retention");
if ($r == '' or $r < 0 or $r > 365) {
	if ($r == '') {
		$sql = "REPLACE INTO settings VALUES ('syslog_retention','30')";
	}else{
		$sql = "UPDATE settings
			SET value='30'
			WHERE name='syslog_retention'";
	}

	$result = db_execute($sql);

	kill_session_var("sess_config_array");
}

$retention = read_config_option("syslog_retention");
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
	mysql_query("DELETE FROM " . $syslog_config["syslogTable"] . "
		WHERE DATE_SUB(CURDATE(),INTERVAL $retention DAY) > date()");

	$syslog_deleted = mysql_affected_rows();

	/* now delete from the syslog removed table */
	mysql_query("DELETE FROM " . $syslog_config["syslogRemovedTable"] . "
		WHERE DATE_SUB(CURDATE(),INTERVAL $retention DAY) > date()");

	$syslog_deleted += mysql_affected_rows();

	syslog_debug("Deleted " . $syslog_deleted .
		" Syslog Message" . ($syslog_deleted == 1 ? "" : "s" ) .
		" (older than $retention days)");
}

/* get a uniqueID to allow moving of records to done table */
while (1) {
	$uniqueID = rand(1, 127);
	$query    = mysql_query("SELECT *
		FROM " . $syslog_config["incomingTable"] . "
		WHERE status=" . $uniqueID);

	if (mysql_affected_rows() == 0) {
		break;
	}
}

syslog_debug("Unique ID = " . $uniqueID);

/* flag all records with the uniqueID prior to moving */
mysql_query("UPDATE " . $syslog_config["incomingTable"] . "
	SET status=" . $uniqueID . "
	WHERE status=0");

syslog_debug("Found " . mysql_affected_rows() .
	" new Message" . (mysql_affected_rows() == 1 ? "" : "s" ) .
	" to process");

/* remote records that don't need to to be transferred */
syslog_remove_items($syslog_config["incomingTable"]);

/* send out the alerts */
$query = mysql_query("SELECT * FROM " . $syslog_config["alertTable"]);

syslog_debug("Found " . mysql_affected_rows() .
	" Alert Rule" . (mysql_affected_rows() == 1 ? "" : "s" ) .
	" to process");

while ($alert = mysql_fetch_array($query, MYSQL_ASSOC)) {
	$sql    = '';
	$alertm = '';

	if ($alert['type'] == 'facility') {
		$sql = 'SELECT * FROM ' . $syslog_config["incomingTable"] . "
			WHERE " . $syslog_config["facilityField"] . "='" . $alert['message'] . "'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messageb') {
		$sql = 'SELECT * FROM ' . $syslog_config["incomingTable"] . "
			WHERE " . $syslog_config["textField"] . "
			LIKE '" . $alert['message'] . "%'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messagec') {
		$sql = 'SELECT * FROM ' . $syslog_config["incomingTable"] . "
			WHERE " . $syslog_config["textField"] . "
			LIKE '%" . $alert['message'] . "%'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messagee') {
		$sql = 'SELECT * FROM ' . $syslog_config["incomingTable"] . "
			WHERE " . $syslog_config["textField"] . "
			LIKE '%" . $alert['message'] . "'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'host') {
		$sql = 'SELECT * FROM ' . $syslog_config["incomingTable"] . "
			WHERE " . $syslog_config["hostField"] . "='" . $alert['message'] . "'
			AND status=" . $uniqueID;
	}

	if ($sql != '') {
		$at = mysql_query($sql);

		while ($a = mysql_fetch_array($at, MYSQL_ASSOC)) {
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

	if ($alertm != '') {
		syslog_sendemail($alert['email'], '', 'Event Alert - ' . $alert['name'], $alertm);
	}
}

/* MOVE ALL FLAGGED MESSAGES TO THE SYSLOG TABLE */
mysql_query('INSERT INTO ' .
	$syslog_config["syslogTable"]   . ' (`' .
	$syslog_config["dateField"]     . '`, `' .
	$syslog_config["timeField"]     . '`, ' .
	$syslog_config["priorityField"] . ', ' .
	$syslog_config["facilityField"] . ', ' .
	$syslog_config["hostField"]     . ', ' .
	$syslog_config["textField"]     . ') ' .
	'SELECT `' .
	$syslog_config["dateField"]     . '`, `' .
	$syslog_config["timeField"]     . '`, ' .
	$syslog_config["priorityField"] . ', ' .
	$syslog_config["facilityField"] . ', ' .
	$syslog_config["hostField"] . ' , ' .
	$syslog_config["textField"] .
	' FROM ' . $syslog_config["incomingTable"] . ' WHERE status=' . $uniqueID);


$moved = mysql_affected_rows();

syslog_debug("Moved " . $moved .
	" Message" . ($moved == 1 ? "" : "s" ) .
	" to the '" . $syslog_config["syslogTable"] . "' table");

/* DELETE ALL FLAGGED ITEMS FROM THE INCOMING TABLE */
mysql_query("DELETE FROM " . $syslog_config["incomingTable"] . "
	WHERE status=" . $uniqueID);

syslog_debug("Deleted " . mysql_affected_rows() .
	" already processed Messages from incoming");

/* Add the unique hosts to the syslog_hosts table */
$sql = "INSERT INTO syslog_hosts (host)
        (SELECT DISTINCT host
        FROM " . $syslog_config["syslogTable"] . ")
        ON DUPLICATE KEY UPDATE host=VALUES(host)";

mysql_query($sql);

syslog_debug("Updated " . mysql_affected_rows() .
	" hosts in the syslog hosts table");

/* OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP */
if (date("G") == 0 && date("i") < 5) {
	db_execute("OPTIMIZE TABLE " .
		$syslog_config["incomingTable"] . ", " .
		$syslog_config["syslogTable"]   . ", " .
		$syslog_config["removeTable"]   . ", " .
		$syslog_config["alertTable"]);
}

syslog_debug("Processing Reports...");

/* Lets run the reports */
$syslog_reports = mysql_query("SELECT * FROM " . $syslog_config["reportTable"]);

syslog_debug("We have " . mysql_affected_rows() . " Reports in the database");

while ($syslog_report = mysql_fetch_array($syslog_reports, MYSQL_ASSOC)) {
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
			$sql = 'SELECT * FROM ' . $syslog_config["syslogTable"] . "
				WHERE " . $syslog_config["textField"] . "
				LIKE '" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagec') {
			$sql = 'SELECT * FROM ' . $syslog_config["syslogTable"] . "
				WHERE " . $syslog_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagee') {
			$sql = 'SELECT * FROM ' . $syslog_config["syslogTable"] . "
				WHERE " . $syslog_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "'";
		}

		if ($syslog_report['type'] == 'host') {
			$sql = 'SELECT * FROM ' . $syslog_config["syslogTable"] . "
				WHERE " . $syslog_config["hostField"] . "='" . $syslog_report['message'] . "'";
		}

		if ($sql != '') {
			$date2 = date("Y-m-d H:i:s", time());
			$date1 = date("Y-m-d H:i:s", time() - 86400);
			$sql  .= " AND concat(DATE_FORMAT(" . $syslog_config["dateField"] . ",'%Y-%m-%d'),' ',TIME_FORMAT(" . $syslog_config["timeField"] . ",'%H:%i:%s')) BETWEEN '". $date1 . "' AND '" . $date2 . "'";
			$sql  .= " ORDER BY " . $syslog_config["dateField"] . " DESC," . $syslog_config["timeField"] . " DESC";
			$items = mysql_query($sql);

			syslog_debug("We have " . mysql_affected_rows() . " items for the Report");

			while ($item = mysql_fetch_array($items, MYSQL_ASSOC)) {
				$reptext .= "<tr>" . $item['date'] . "</td><td>" . $item['time'] . "</td><td>" . $item['message'] . "</td></tr>\n";
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

syslog_debug("Finished processing Reports...");
