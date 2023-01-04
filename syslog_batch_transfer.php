<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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

chdir('../../');
include('./include/cli_check.php');
include_once('./lib/poller.php');
include_once('./plugins/syslog/functions.php');
include_once('./plugins/syslog/database.php');

syslog_determine_config();
include(SYSLOG_CONFIG);
syslog_connect();

/* Let it run for an hour if it has to, to clear up any big
 * bursts of incoming syslog events
 */
ini_set('max_execution_time', 3600);
ini_set('memory_limit', '-1');

global $debug;

$debug = true;

/* process calling arguments */
$parms = $_SERVER['argv'];

array_shift($parms);
if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '--debug':
			case '-d':
				$debug = true;

				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit;
			case '--help':
			case '-H':
			case '-h':
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
$start_time = microtime(true);

/* Connect to the Syslog Database */
global $syslog_cnn, $cnn_id, $database_default;
if (empty($syslog_cnn)) {
	if ((strtolower($database_hostname) == strtolower($syslogdb_hostname)) &&
		($database_default == $syslogdb_default)) {
		/* move on, using Cacti */
		$syslog_cnn = $cnn_id;
	}else{
		if (!isset($syslogdb_port)) {
			$syslogdb_port = '3306';
		}

		if (!isset($syslogdb_retries)) {
			$syslogdb_retries = '5';
		}

		if (!isset($syslogdb_ssl)) {
		    $syslogdb_ssl = false;
		}

		if (!isset($syslogdb_ssl_key)) {
		    $syslogdb_ssl_key = '';
		}

		if (!isset($syslogdb_ssl_cert)) {
		    $syslogdb_ssl_cert = '';
		}

		if (!isset($syslogdb_ssl_ca)) {
		    $syslogdb_ssl_ca = '';
		}

		$syslog_cnn = syslog_db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type, $syslogdb_port, $syslogdb_retries, $syslogdb_ssl, $syslogdb_ssl_key, $syslogdb_ssl_cert, $syslogdb_ssl_ca);
	}
}

/* If Syslog Collection is Disabled, Exit Here */
if (read_config_option('syslog_enabled') == '') {
	print "NOTE: Syslog record transferral and alerting/reporting is disabled.  Exiting\n";
	exit -1;
}

/* remove records that don't need to to be transferred */
syslog_debug('Syslog Batch Transfer / Remove Process started ...... ');
$syslog_items   = syslog_manage_items('syslog', 'syslog_removed');
$syslog_removed = $syslog_items['removed'];
$syslog_xferred = $syslog_items['xferred'];
syslog_debug("Removed     " . $syslog_removed . ",  Message(s) from the 'syslog' table");
syslog_debug("Xferred     " . $syslog_xferred . ",  Message(s) to the 'syslog_removed' table");

syslog_debug('Finished processing...');

function display_version() {
	global $config;

	if (!function_exists('plugin_syslog_version')) {
		include_once($config['base_path'] . '/plugins/syslog/setup.php');
	}

	$info = plugin_syslog_version();
	echo "Syslog Batch Process, Version " . $info['version'] . ", " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	echo "\nusage: syslog_batch_transfer.php [--debug|-d]\n\n";
	echo "The Syslog batch process script for Cacti Syslogging.\n";
	echo "This script removes old messages from main view.\n";
}


