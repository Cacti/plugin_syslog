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

include(__DIR__ . '/../../../include/cli_check.php');
require_once(dirname(__DIR__) . '/setup.php');
require_once(dirname(__DIR__) . '/includes/schema.php');
require_once(dirname(__DIR__) . '/includes/settings.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$audit   = false;
$repair  = false;
$upgrade = false;

if (!cacti_sizeof($parms)) {
	fwrite(STDERR, 'ERROR: Specify --audit, --repair or --upgrade. Use --help for usage.' . PHP_EOL);
	exit(1);
}

foreach ($parms as $arg) {
	switch ($arg) {
		case '--audit':
			$audit = true;
			break;
		case '--repair':
			$repair = true;
			break;
		case '--upgrade':
			$upgrade = true;
			break;
		case '--help':
		case '-H':
		case '-h':
			syslog_schema_display_help();
			exit(0);
		default:
			fwrite(STDERR, "ERROR: Unknown argument: $arg" . PHP_EOL);
			exit(1);
	}
}

if ($audit && ($repair || $upgrade)) {
	fwrite(STDERR, 'ERROR: --audit cannot be combined with --repair or --upgrade.' . PHP_EOL);
	exit(1);
}

syslog_determine_config();
if (!defined('SYSLOG_CONFIG')) {
	fwrite(STDERR, 'ERROR: Syslog database configuration is missing.' . PHP_EOL);
	exit(1);
}

$database_last_error = '';
if (!syslog_connect()) {
	fwrite(STDERR, 'ERROR: Unable to connect to the Syslog database.' . PHP_EOL);
	exit(1);
}

if ($repair || $upgrade) {
	$exit_code = syslog_schema_repair_database() ? 0 : 1;
} else {
	$exit_code = syslog_schema_report_audit_results() ? 0 : 1;
}

exit($exit_code);

/**
 * Repair missing tables and rerun the existing Syslog schema migrations.
 *
 * @return bool Whether the migrations and final table audit succeeded.
 */
function syslog_schema_repair_database(): bool {
	print 'Upgrading Syslog schema and repairing missing tables...' . PHP_EOL;
	syslog_setup_table_new([
		'upgrade_type' => 'upgrade',
		'engine'       => read_config_option('syslog_install_engine') ?: 'InnoDB',
		'db_type'      => 'part',
		'days'         => read_config_option('syslog_install_days')
	], true);
	if (!syslog_check_upgrade(true)) {
		fwrite(STDERR, 'ERROR: Syslog schema upgrade failed; check the Cacti log.' . PHP_EOL);
		return false;
	}

	$success = syslog_schema_report_audit_results(false);
	if ($success) {
		print 'Syslog schema upgrade completed; all required tables exist.' . PHP_EOL;
	}

	return $success;
}

/**
 * Report missing Syslog tables for the configured Data Collector without repairing them.
 *
 * @param bool $audit Print the audit success message for read-only runs.
 *
 * @return bool Whether all required tables exist and the audit queries succeeded.
 */
function syslog_schema_report_audit_results(bool $audit = true): bool {
	global $config, $database_last_error;

	$tables = [
		'syslog', 'syslog_alert', 'syslog_alert_suppression', 'syslog_incoming',
		'syslog_remove', 'syslog_reports', 'syslog_saved_searches', 'syslog_status',
		'syslog_programs', 'syslog_hosts', 'syslog_facilities', 'syslog_priorities',
		'syslog_host_facilities', 'syslog_removed', 'syslog_logs',
		'syslog_dashboards', 'syslog_dashboard_panels', 'syslog_dashboards_perm',
		'syslog_saved_searches_perm', 'syslog_device_rule', 'syslog_replication_output',
		'syslog_replication_recovery'
	];
	if ((int) $config['poller_id'] <= 1) {
		$tables[] = 'syslog_replication_receipts';
		$tables[] = 'syslog_replication_collectors';
	}

	$missing = [];
	foreach ($tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			$missing[] = $table;
		}
	}

	if (!empty($database_last_error)) {
		fwrite(STDERR, 'ERROR: Schema check encountered a database error; check the Cacti log.' . PHP_EOL);
		return false;
	}
	if ($missing) {
		fwrite(STDERR, 'ERROR: Missing tables: ' . implode(', ', $missing) . PHP_EOL);
		return false;
	}

	if ($audit) {
		print 'Syslog schema audit passed; all required tables exist.' . PHP_EOL;
	}
	return true;
}

/**
 * Display the Syslog schema utility usage and options.
 *
 * @return void
 */
function syslog_schema_display_help(): void {
	print 'Usage: php syslog_schema.php --audit|--repair|--upgrade' . PHP_EOL;
	print '  --audit    Report missing tables without changing the schema.' . PHP_EOL;
	print '  --repair   Repair missing tables and rerun schema migrations.' . PHP_EOL;
	print '  --upgrade  Upgrade the schema, including missing-table repair.' . PHP_EOL;
	print 'Repair and upgrade preserve existing data and preferences.' . PHP_EOL;
}
