<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2011 The Cacti Group                                 |
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
list($micro,$seconds) = explode(" ", microtime());
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
syslog_connect();

if (sizeof(syslog_db_fetch_row("SHOW TABLES IN " . $syslogdb_default . " LIKE 'syslog'"))) {
	syslog_db_execute("RENAME TABLE `" . $syslogdb_default . "`.`syslog` TO `" . $syslogdb_default . "`.`syslog_pre_upgrade`");
}

/* perform the upgrade */
syslog_upgrade_pre_oneoh_tables($options, true);

cacti_log("SYSLOG NOTE: Background Syslog Database Upgrade Process Completed", false, "SYSTEM");

function display_help() {
	echo "Syslog Database Upgrade, Copyright 2004-2011 - The Cacti Group\n\n";
	echo "Syslog Database Upgrade script for Cacti Syslogging.\n\n";
	echo "usage: syslog_upgrade.php --type=trad|part --engine=MyISAM|InnoDB --days=N [--debug|-d]\n\n";
}
