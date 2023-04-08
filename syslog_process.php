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

include(dirname(__FILE__) . '/../../include/cli_check.php');
include_once(dirname(__FILE__) . '/functions.php');
include_once(dirname(__FILE__) . '/database.php');

syslog_determine_config();
include(SYSLOG_CONFIG);
syslog_connect();

/**
 * Let it run for an hour if it has to, to clear up any big
 * bursts of incoming syslog events
 */
ini_set('max_execution_time', 3600);
ini_set('memory_limit', '-1');

global $debug, $syslog_facilities, $syslog_levels;

$debug  = false;
$forcer = false;

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
			case '--force-report':
			case '-F':
				$forcer = true;

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
				exit;
			default:
				print "ERROR: Invalid Argument: ($arg)\n\n";
				display_help();
				exit(1);
		}
	}
}

/* record the start time */
$start_time = microtime(true);

/**
 * sanity checks before starting.  The first sanity check is
 * to see if Syslog has been disabled entirely.  If so, then
 * exit right away.
 */
if (read_config_option('syslog_enabled') == '') {
	$message = 'WARNING: Syslog record transferral and alerting/reporting is disabled.';

	cacti_log($message, false, 'SYSLOG');
	print $message . PHP_EOL;

	exit(1);
}

/**
 * sanity checks before starting.  The second sanity check is to
 * exit if you are a remote data collector and you have not enabled
 * syslog to operate remotely.  If you have been, then
 * if the the rules replication is enabled, get the latest rules
 * from the main Cacti data collector.
 */
if ($config['poller_id'] > 1) {
	if (read_config_option('syslog_remote_enabled') !== 'on') {
		$message = 'WARNING: Syslog is offline and Remote Data Collector Message Processing is disabled!';

		cacti_log($message, false, 'SYSLOG', POLLER_VERBOSITY_MEDIUM);
		print $message . PHP_EOL;

		exit(1);
	}

	/* replicate in syslog tables sync is enabled */
	syslog_replicate_in();
}

/**
 * Register the start of the syslog process, or if it's found to still be
 * running exit until such time as the syslog process times out.
 */
if (!register_process_start('syslog', 'master', $config['poller_id'], 1200)) {
    exit(0);
}

/**
 * initialize some key variables if they are not already initialized
 * in the Cacti settings table.
 */
syslog_init_variables();

/**
 * delete old syslog messages from the syslog table.  This
 * process may take some time.  It's preferred that users
 * always use partitioning as it will guarantee the best
 * performing syslog database.
 */
syslog_debug('-------------------------------------------------------------------------------------');
if (!syslog_is_partitioned()) {
	syslog_debug('Syslog Table is NOT Partitioned');
	$deleted = syslog_traditional_manage();
} else {
	syslog_debug('Syslog Table IS Partitioned');
	$deleted = syslog_partition_manage();
}
syslog_debug('-------------------------------------------------------------------------------------');

/**
 * pre-processing includes marking a uniqueID to be used
 * in the processesing of alerts and stripping domains
 * from hostnames in the case that the administrator
 * chooses to strip them.
 */
$results  = syslog_preprocess_incoming_records();
$uniqueID = $results['uniqueID'];
$incoming = $results['incoming'];

/**
 * place new normalized values in various reference tables
 * syslog attempts to normalize things like:
 *
 * - hostnames
 * - facilities
 * - priorities
 * - programs
 *
 * To reduce the overall size of the syslog table over
 * time and to speed up searching for these various
 * columns in the database.
 */
syslog_update_reference_tables($uniqueID);

/**
 * The statistics process allows the Cacti
 * administrator to get some comprehension of flow
 * into the syslog table and what message types are flowing
 * into it.
 */
syslog_update_statistics($uniqueID);

/**
 * remove records that don't need to to be transferred
 */
$results = syslog_remove_items('syslog_incoming', $uniqueID);
$removed = $results['removed'];
$xferred = $results['xferred'];

/**
 * process the syslog rules and generate alerts
 */
$results = syslog_process_alerts($uniqueID);
$alerts  = $results['syslog_alerts'];
$alarms  = $results['syslog_alarms'];

/**
 * Perform any plugin specific actions.  Syslog itself does not use
 * this information, but other 3rd party plugins may.  This could
 * be for performing certain maintenance functions that are not
 * performed by syslog directly.
 */
api_plugin_hook('plugin_syslog_after_processing');

/**
 * move records from incoming to syslog table and remove
 * any stale records to to a poller crash
 */
$results = syslog_incoming_to_syslog($uniqueID);
$moved   = $results['moved'];
$stale   = $results['stale'];

/**
 * process any syslog reports that are due to be
 * sent.
 */
$results  = syslog_process_reports();
$reports  = $results['total_reports'];
$sentrpts = $results['sent_reports'];

/**
 * prune and optimize any tables that are required to
 * be optimized.  This should be done once a day
 */
syslog_postprocess_tables();

/**
 * log messages to the Cacti log and save statistics
 * to the settings table
 */
syslog_process_log($start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports);

/**
 * unregister the syslog process entry so the next poller
 * run can lock the process.
 */
unregister_process('syslog', 'master', $config['poller_id']);

exit(0);

/**
 * display_version - displays version information
 *
 * @return (void)
 */
function display_version() {
	global $config;

	if (!function_exists('plugin_syslog_version')) {
		include_once($config['base_path'] . '/plugins/syslog/setup.php');
	}

	$version = plugin_syslog_version();
	print 'Syslog Poller, Version ' . trim($version['version']) . ', ' . COPYRIGHT_YEARS . PHP_EOL;
}

/**
 * display_help - displays help information
 *
 * @return (void)
 */
function display_help() {
	display_version();

	print 'The main Syslog poller process script for Cacti Syslogging.' . PHP_EOL . PHP_EOL;
	print 'usage: syslog_process.php [--debug] [--force-report]' . PHP_EOL . PHP_EOL;
	print 'options:' . PHP_EOL;
	print '    --force-report   Send email reports now.' . PHP_EOL;
	print '    --debug          Provide more verbose debug output.' . PHP_EOL . PHP_EOL;
}

