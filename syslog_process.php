<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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

if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
} else {
	declare(ticks = 100);
}

include(__DIR__ . '/../../include/cli_check.php');
include_once(__DIR__ . '/setup.php');
include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/database.php');

syslog_connect();

/**
 * Let it run for an hour if it has to, to clear up any big
 * bursts of incoming syslog events
 */
ini_set('output_buffering', 'Off');
ini_set('max_execution_time', 3600);
ini_set('memory_limit', '-1');

set_time_limit(3600);
ob_implicit_flush();

global $debug, $syslog_facilities, $syslog_levels;

global $child, $run_id, $phase, $seq_start, $seq_end;

$debug     = false;
$forcer    = false;
$child     = 0;
$run_id    = '';
$phase     = '';
$seq_start = 0;
$seq_end   = 0;

// process calling arguments
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms)) {
	foreach ($parms as $parameter) {
		if (strpos($parameter, '=')) {
			[$arg, $value] = explode('=', $parameter);
		} else {
			$arg   = $parameter;
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
			case '--child':
				if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
					print "ERROR: Child identifier must be a positive integer.\n\n";
					display_help();
					exit(1);
				}

				$child = (int) $value;

				break;
			case '--run-id':
				if (preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
					print "ERROR: Run identifier must be a 32 character hexadecimal value.\n\n";
					display_help();
					exit(1);
				}

				$run_id = $value;

				break;
			case '--phase':
				if (!in_array($value, ['references', 'transfer'], true)) {
					print "ERROR: Phase must be one of 'references' or 'transfer'.\n\n";
					display_help();
					exit(1);
				}

				$phase = $value;

				break;
			case '--seq-start':
				if (preg_match('/^[0-9]+$/', $value) !== 1) {
					print "ERROR: Sequence start must be a non-negative integer.\n\n";
					display_help();
					exit(1);
				}

				$seq_start = (int) $value;

				break;
			case '--seq-end':
				if (preg_match('/^[0-9]+$/', $value) !== 1) {
					print "ERROR: Sequence end must be a non-negative integer.\n\n";
					display_help();
					exit(1);
				}

				$seq_end = (int) $value;

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

// install signal handlers for UNIX only
if (function_exists('pcntl_signal')) {
	pcntl_signal(SIGTERM, 'sig_handler');
	pcntl_signal(SIGINT, 'sig_handler');
}

// record the start time
$start_time = microtime(true);
$start_timestamp = time();

/**
 * Read the maximum number of worker processes once.  An unset or
 * invalid value means single process operation which is the default.
 */
$syslog_max_workers = max(1, (int) read_config_option('syslog_max_workers'));
$syslogdb_default   = isset($syslogdb_default) ? $syslogdb_default : '';

/**
 * Child worker mode.  When launched by the master with --child=N, the
 * process executes exactly one phase of work over one seq slice and
 * exits.  This mirrors the child handling in Cacti's poller_boost.php.
 */
if ($child > 0) {
	syslog_worker_main($debug);

	// syslog_worker_main() always exits, this is a fail-safe
	exit(1);
}

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

	// replicate in syslog tables sync is enabled
	syslog_replicate_in();
}

/**
 * Register the start of the syslog process, or if it's found to still be
 * running exit until such time as the syslog process times out.
 */
if (!register_process_start('syslog', 'master', $config['poller_id'], 1200)) {
	exit(0);
}

syslog_status_set('last_polling_time', $start_timestamp);
syslog_status_set('last_start_time', $start_timestamp);

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
 * pre-processing includes marking a max_seq to be used
 * in the processesing of alerts and stripping domains
 * from hostnames in the case that the administrator
 * chooses to strip them.
 */
$results  = syslog_preprocess_incoming_records();
$max_seq  = $results['max_seq'];
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
 *
 * When multiple workers are configured and the platform
 * supports them, the per host resolution part is spread
 * across parallel worker processes operating on disjoint
 * seq slices.  The set based reference upserts always
 * remain on the master to avoid concurrent
 * ON DUPLICATE KEY deadlocks.
 */
$parallel = syslog_parallel_supported();

if ($parallel && $max_seq > 0) {
	syslog_debug('Parallel Processing Enabled with ' . $syslog_max_workers . ' Worker(s)');

	$run_id = bin2hex(random_bytes(16));

	// ROUND 1: resolve hostnames for incoming records in parallel
	$host_slices = syslog_compute_slices(1, $max_seq, $syslog_max_workers);

	$launched = syslog_launch_workers('references', $host_slices, $run_id, $debug);

	/*
	 * The reference upserts, the removal rules and the alert rules that
	 * follow all operate on the same incoming rows the workers are
	 * updating, so the master must not race ahead of its children.
	 */
	syslog_wait_workers($launched);

	syslog_normalize_reference_tables($max_seq);
} else {
	syslog_update_reference_tables($max_seq);
}

/**
 * remove records that don't need to to be transferred
 */
$results = syslog_remove_items('syslog_incoming', $max_seq);
$removed = $results['removed'];
$xferred = $results['xferred'];

/**
 * process the syslog rules and generate alerts
 */
$results = syslog_process_alerts($max_seq);
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
 *
 * In parallel mode the transfer is spread across workers
 * over disjoint seq slices.  Slices are recomputed after
 * removal rules have deleted their share of rows so
 * workers never spawn for empty ranges.
 */
if ($parallel && $max_seq > 0) {
	$remaining = (int) syslog_db_fetch_cell_prepared("SELECT COUNT(seq)
		FROM `$syslogdb_default`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?",
		[$max_seq]);

	if ($remaining > 0) {
		$min_seq = (int) syslog_db_fetch_cell_prepared("SELECT MIN(seq)
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?",
			[$max_seq]);

		$transfer_slices = syslog_compute_slices($min_seq, $max_seq, $syslog_max_workers);

		$launched = syslog_launch_workers('transfer', $transfer_slices, $run_id, $debug);

		/*
		 * The stale record cleanup, the stats aggregation and the
		 * master's own exit all assume the transfer is complete, so
		 * block until every child has recorded its statistics and
		 * unregistered itself.
		 */
		syslog_wait_workers($launched);

		$stale = syslog_delete_stale_incoming();

		$worker_stats = syslog_aggregate_worker_stats($syslog_max_workers);

		$moved = $worker_stats['moved'];
	} else {
		$moved = 0;
		$stale = 0;
	}
} else {
	$results = syslog_incoming_to_syslog($max_seq);
	$moved   = $results['moved'];
	$stale   = $results['stale'];
}

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
syslog_status_set('last_end_time', time());
syslog_status_set('last_record_count', $moved);
syslog_status_record_runtime(microtime(true) - $start_time);

/**
 * unregister the syslog process entry so the next poller
 * run can lock the process.
 */
unregister_process('syslog', 'master', $config['poller_id']);

exit(0);

/**
 * sig_handler - handles UNIX signals and logs shutdown events to the Cacti log.
 *
 * The master terminates any running worker children before unregistering
 * itself.  Each child unregisters its own process table row.
 *
 * @param int $signo The signal received by the process.
 *
 * @return (void)
 */
function sig_handler($signo) {
	global $config, $child;

	switch ($signo) {
		case SIGTERM:
		case SIGINT:
			if ($child > 0) {
				cacti_log("WARNING: Syslog 'child' process $child is shutting down by signal!", false, 'SYSLOG');

				unregister_process('syslog', 'child', $child);

				exit(1);
			}

			cacti_log("WARNING: Syslog 'master' is shutting down by signal!", false, 'SYSLOG');

			syslog_kill_workers();

			unregister_process('syslog', 'master', $config['poller_id']);

			exit(1);

			break;
		default:
			// ignore all other signals
	}
}

/**
 * syslog_worker_main - entry point for a parallel worker child process.
 *
 * The child registers itself in Cacti's process table, executes exactly
 * one phase (references or transfer) over one seq slice, records its
 * statistics in the settings table for the master to aggregate, and
 * unregisters itself.  A child never continues past a failure; it exits
 * with a non-zero status so the master can log a warning.
 *
 * @param bool $debug Whether to emit verbose debug output
 *
 * @return (void) This function always exits.
 */
function syslog_worker_main($debug = false) {
	global $child, $run_id, $phase, $seq_start, $seq_end;

	// The command line is the only source of these values, revalidate
	if (!syslog_validate_worker_args($child, $run_id, $phase, $seq_start, $seq_end)) {
		cacti_log("ERROR: Syslog child $child rejected invalid worker arguments", false, 'SYSLOG');
		exit(1);
	}

	if (!register_process_start('syslog', 'child', $child, 1200)) {
		/*
		 * A previous worker with this number is still registered
		 * (crashed without unregistering, or the master did not wait
		 * for it).  Never fail silently: the master checks that every
		 * launched child reported statistics, so leave a breadcrumb
		 * for that warning too.
		 */
		cacti_log("WARNING: Syslog child $child could not register, another worker with that number is still registered", false, 'SYSLOG');

		exit(1);
	}

	syslog_status_set('last_worker_start', time());

	$child_start = microtime(true);
	$success     = false;

	switch ($phase) {
		case 'references':
			$resolved = syslog_resolve_incoming_hosts($seq_end, $seq_start, $seq_end);
			$moved    = 0;
			$success  = true;

			syslog_debug(sprintf('Resolved %5s - Hostname(s) in slice %d-%d', $resolved, $seq_start, $seq_end));

			break;
		case 'transfer':
			$results = syslog_incoming_to_syslog($seq_end, $seq_start, $seq_end);
			$moved   = $results['moved'];
			$success = true;

			syslog_debug(sprintf('Moved   %5s - Message(s) in slice %d-%d', $moved, $seq_start, $seq_end));

			break;
		default:
			// unreachable, validated above
			break;
	}

	$child_end = microtime(true);

	$stats = [
		'child'    => $child,
		'run_id'   => $run_id,
		'phase'    => $phase,
		'moved'    => $moved,
		'resolved' => isset($resolved) ? $resolved : 0,
		'runtime'  => round($child_end - $child_start, 3),
	];

	set_config_option('stats_syslog_child_' . $child, json_encode($stats));

	syslog_log_child_statistics($child_start, $child, $moved, isset($resolved) ? $resolved : 0);

	unregister_process('syslog', 'child', $child);

	cacti_log(sprintf('NOTE: Syslog child %s completed phase %s (%d-%d) in %01.2f seconds', $child, $phase, $seq_start, $seq_end, round($child_end - $child_start, 2)), false, 'SYSLOG', ($debug ? POLLER_VERBOSITY_NONE : POLLER_VERBOSITY_MEDIUM));

	if ($success) {
		exit(0);
	}

	exit(1);
}

/**
 * syslog_parallel_supported - determine whether the current platform and
 * configuration can run parallel worker processes.
 *
 * @return bool true when parallel workers may be launched
 */
function syslog_parallel_supported() {
	global $syslog_max_workers;

	if ($syslog_max_workers <= 1) {
		return false;
	}

	if ($GLOBALS['config']['cacti_server_os'] == 'win32') {
		return false;
	}

	if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) {
		return false;
	}

	return true;
}

/**
 * syslog_launch_workers - spawn the configured worker child processes.
 *
 * Each child is started detached in the background, exactly as Cacti's
 * boost poller launches its children, and coordinates through the
 * process table.
 *
 * @param string $phase  The phase to run, references or transfer
 * @param array  $slices The seq slices to distribute across the children
 * @param string $run_id The shared run identifier for this cycle
 * @param bool   $debug  Whether to propagate debug output to children
 *
 * @return int The number of children launched
 */
function syslog_launch_workers($phase, $slices, $run_id, $debug = false) {
	$children = 0;

	// Pre-clean any stats from a previous crashed run so aggregation
	// only counts children that reported during this cycle.  The
	// settings table lives in the main Cacti database, not the syslog
	// database, so the core helper is required here.
	db_execute("DELETE FROM settings
		WHERE name LIKE 'stats_syslog_child_%'");

	$php_binary = read_config_option('path_php_binary');

	if (empty($php_binary)) {
		$php_binary = PHP_BINARY;
	}

	$script = __FILE__;

	foreach ($slices as $index => $slice) {
		$child_number = $index + 1;

		$extra_args = ' -q ' . cacti_escapeshellarg($script) .
			' --child=' . $child_number .
			' --run-id=' . $run_id .
			' --phase=' . $phase .
			' --seq-start=' . $slice['start'] .
			' --seq-end=' . $slice['end'] .
			($debug ? ' --debug' : '');

		exec_background($php_binary, $extra_args);

		$children++;
	}

	cacti_log("NOTE: Syslog launched $children '$phase' worker process(es)", false, 'SYSLOG');

	// No settle delay here: the caller's syslog_wait_workers() covers the
	// registration window with a fast poll instead of a fixed sleep.

	return $children;
}

/**
 * syslog_kill_workers - signal any registered worker children to
 * terminate.  Used by the master on shutdown signals.
 *
 * @return (void)
 */
function syslog_kill_workers() {
	$processes = db_fetch_assoc("SELECT pid
		FROM processes
		WHERE tasktype = 'syslog'
		AND taskname = 'child'");

	if (!cacti_sizeof($processes)) {
		return;
	}

	foreach ($processes as $process) {
		$pid = (int) $process['pid'];

		if (cacti_process_still_running($pid)) {
			cacti_log("WARNING: Stopping Syslog worker PID $pid due to master shutdown.", false, 'SYSLOG');

			cacti_process_kill($pid, SIGTERM);
		}
	}
}

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
	print '       syslog_process.php --child=N --run-id=<32hex> --phase=references|transfer --seq-start=N --seq-end=N' . PHP_EOL . PHP_EOL;
	print 'options:' . PHP_EOL;
	print '    --force-report   Send email reports now.' . PHP_EOL;
	print '    --debug          Provide more verbose debug output.' . PHP_EOL . PHP_EOL;
	print 'worker options (used internally by the master process):' . PHP_EOL;
	print '    --child          The worker process number, a positive integer.' . PHP_EOL;
	print '    --run-id         The 32 character hexadecimal run identifier.' . PHP_EOL;
	print '    --phase          The phase to process, references or transfer.' . PHP_EOL;
	print '    --seq-start      The first seq of the slice to process.' . PHP_EOL;
	print '    --seq-end        The last seq of the slice to process.' . PHP_EOL . PHP_EOL;
}
