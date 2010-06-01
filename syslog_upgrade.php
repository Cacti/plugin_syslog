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

ini_set('max_execution_time', "0");
ini_set('memory_limit', '256M');

$fetch_size = '10000';

global $syslog_debug;

$syslog_debug = false;

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

$engine = "MyISAM";
$type   = "trad";
$days   = 30;

if (sizeof($parms)) {
	foreach($parms as $parameter) {
		@list($arg, $value) = @explode("=", $parameter);

		switch ($arg) {
		case "--engine":
			$engine = trim($value);

			break;
		case "--type":
			$type = trim($value);

			break;
		case "--days":
			$days = trim($value);

			break;
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

if (!is_numeric($days)) {
	echo "FATAL: Days value '$days' must be numeric";
	exit(1);
}

if ($type != "trad" && $type != "part") {
	echo "FATAL: Type must be either 'trad' or 'part'";
	exit(1);
}

if ($engine != "MyISAM" && $engine != "InnoDB") {
	echo "FATAL: Engine must be either 'MyISAM' or 'InnoDB'";
	exit(1);
}

$options["engine"]       = $engine;
$options["db_type"]      = $type;
$options["days"]         = $days;
$options["upgrade_type"] = "background";

/* record the start time */
list($micro,$seconds) = split(" ", microtime());
$start_time = $seconds + $micro;

$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'plugins') !== false) {
	chdir('../../');
}
include("./include/global.php");
include_once(dirname(__FILE__) . "/setup.php");
include(dirname(__FILE__) . "/config.php");
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


if (sizeof(db_fetch_row("SHOW TABLES IN " . $syslogdb_default . " LIKE 'syslog'", true, $syslog_cnn))) {
	db_execute("RENAME TABLE `" . $syslogdb_default . "`.`syslog`TO `" . $syslogdb_default . "`.`syslog_pre_upgrade`", true, $syslog_cnn);
}

syslog_setup_table_new($options);

/* populate the tables */
db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_hosts` (host)
	SELECT DISTINCT host
	FROM `" . $syslogdb_default . "`.`syslog_pre_upgrade`
	ON DUPLICATE KEY UPDATE host=VALUES(host)", true, $syslog_cnn);

db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_facilities` (facility)
	SELECT DISTINCT facility
	FROM `" . $syslogdb_default . "`.`syslog_pre_upgrade`
	ON DUPLICATE KEY UPDATE facility=VALUES(facility)", true, $syslog_cnn);

foreach($syslog_levels as $id => $priority) {
	db_execute("REPLACE INTO `" . $syslogdb_default . "`.`syslog_priorities` (priority_id, priority) VALUES ($id, '$priority')", true, $syslog_cnn);
}

/* a bit more horsepower please */
db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_host_facilities`
	(host_id, facility_id)
	SELECT host_id, facility_id
	FROM ((SELECT DISTINCT host, facility
		FROM `" . $syslogdb_default . "`.`syslog_pre_upgrade`) AS s
		INNER JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
		ON s.host=sh.host
		INNER JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
		ON sf.facility=s.facility)
	ON DUPLICATE KEY UPDATE host_id=VALUES(host_id)", true, $syslog_cnn);

/* change the structure of the syslog table for performance sake */
$mysqlVersion = syslog_get_mysql_version("syslog");
if ($mysqlVersion >= 5) {
	db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_pre_upgrade`
		MODIFY COLUMN message varchar(1024) DEFAULT NULL,
		ADD COLUMN facility_id int(10) UNSIGNED NULL AFTER facility,
		ADD COLUMN priority_id int(10) UNSIGNED NULL AFTER facility_id,
		ADD COLUMN host_id int(10) UNSIGNED NULL AFTER priority_id,
		ADD COLUMN logtime DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER priority,
		ADD INDEX facility_id (facility_id),
		ADD INDEX priority_id (priority_id),
		ADD INDEX host_id (host_id),
		ADD INDEX logtime(logtime);", true, $syslog_cnn);
}else{
	db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_pre_upgrade`
		ADD COLUMN facility_id int(10) UNSIGNED NULL AFTER host,
		ADD COLUMN priority_id int(10) UNSIGNED NULL AFTER facility_id,
		ADD COLUMN host_id int(10) UNSIGNED NULL AFTER priority_id,
		ADD COLUMN logtime DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER priority,
		ADD INDEX facility_id (facility_id),
		ADD INDEX priority_id (priority_id),
		ADD INDEX host_id (host_id),
		ADD INDEX logtime(logtime);", true, $syslog_cnn);
}

/* convert dates and times to timestamp */
db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_pre_upgrade` SET logtime=TIMESTAMP(`date`, `time`)", true, $syslog_cnn);

/* update the host_ids */
$hosts = db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_hosts`", true, $syslog_cnn);
if (sizeof($hosts)) {
	foreach($hosts as $host) {
		db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_pre_upgrade`
			SET host_id=" . $host["host_id"] . "
			WHERE host='" . $host["host"] . "'", true, $syslog_cnn);
	}
}

/* update the priority_ids */
$priorities = $syslog_levels;
if (sizeof($priorities)) {
	foreach($priorities as $id => $priority) {
		db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_pre_upgrade`
			SET priority_id=" . $id . "
			WHERE priority='" . $priority . "'", true, $syslog_cnn);
	}
}

/* update the facility_ids */
$fac = db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_facilities`", true, $syslog_cnn);
if (sizeof($fac)) {
	foreach($fac as $f) {
		db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_pre_upgrade`
			SET facility_id=" . $f["facility_id"] . "
			WHERE facility='" . $f["facility"] . "'", true, $syslog_cnn);
	}
}

db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_pre_upgrade`
	DROP COLUMN `date`,
	DROP COLUMN `time`,
	DROP COLUMN `host`,
	DROP COLUMN `facility`,
	DROP COLUMN `priority`", true, $syslog_cnn);

while ( true ) {
	$sequence = db_fetch_cell("SELECT max(seq) FROM (SELECT seq FROM `" . $syslogdb_default . "`.`syslog_pre_upgrade` ORDER BY seq LIMIT $fetch_size) AS preupgrade", '', false, $syslog_cnn);

	if ($sequence > 0 && $sequence != '') {
		db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog` (facility_id, priority_id, host_id, logtime, message)
			SELECT facility_id, priority_id, host_id, logtime, message
			FROM `" . $syslogdb_default . "`.`syslog_pre_upgrade`
			WHERE seq<$sequence", true, $syslog_cnn);
		db_execute("DELETE FROM syslog_pre_upgrade WHERE seq<=$sequence", true, $syslog_cnn);
	}else{
		db_execute("DROP TABLE `" . $syslogdb_default . "`.`syslog_pre_upgrade`", true, $syslog_cnn);
		break;
	}
}

function display_help() {
	echo "Syslog Database Upgrade, Copyright 2004-2010 - The Cacti Group\n\n";
	echo "Syslog Database Upgrade script for Cacti Syslogging.\n\n";
	echo "usage: syslog_upgrade.php --type=trad|part --engine=MyISAM|InnoDB --days=N [--debug|-d]\n\n";
}
