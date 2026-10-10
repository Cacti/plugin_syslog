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

// Suppress automatic Syslog migrations during Cacti bootstrap.
define('SYSLOG_SCHEMA_CLI', true);

// Plugin CLI scripts are one directory deeper than plugin entry points.
include(__DIR__ . '/../../../include/cli_check.php');
require_once(dirname(__DIR__) . '/setup.php');
require_once(dirname(__DIR__) . '/includes/schema.php');
require_once(dirname(__DIR__) . '/includes/settings.php');

$action_requested = false;
$audit = false;
foreach (array_slice($_SERVER['argv'], 1) as $argument) {
	if (in_array($argument, ['--help', '-h', '-H'], true)) {
		print "Usage: php syslog_schema.php --audit|--repair|--upgrade\n  --audit    Report missing tables without changing the schema.\n  --repair   Repair missing tables and rerun schema migrations.\n  --upgrade  Upgrade the schema, including missing-table repair.\nRepair and upgrade preserve existing data and preferences.\n";
		exit(0);
	}

	if ($argument === '--audit') {
		$audit = true;
		continue;
	}

	if (in_array($argument, ['--repair', '--upgrade'], true)) {
		$action_requested = true;
		continue;
	}

	fwrite(STDERR, "ERROR: Unknown argument: $argument\n");
	exit(1);
}

if ($audit && $action_requested) {
	fwrite(STDERR, "ERROR: --audit cannot be combined with --repair or --upgrade.\n");
	exit(1);
}

if (!$action_requested && !$audit) {
	fwrite(STDERR, "ERROR: Specify --audit, --repair or --upgrade. Use --help for usage.\n");
	exit(1);
}

syslog_determine_config();
if (!defined('SYSLOG_CONFIG')) {
	fwrite(STDERR, "ERROR: Syslog database configuration is missing.\n");
	exit(1);
}

$database_last_error = '';
if (!syslog_connect(true)) {
	fwrite(STDERR, "ERROR: Unable to connect to the Syslog database.\n");
	exit(1);
}

if (!$audit) {
	print "Upgrading Syslog schema and repairing missing tables...\n";
	syslog_setup_table_new([
		'upgrade_type' => 'upgrade',
		'engine'       => read_config_option('syslog_install_engine') ?: 'InnoDB',
		'db_type'      => 'part',
		'days'         => read_config_option('syslog_install_days')
	], true);
	syslog_check_upgrade(true);
}

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
	fwrite(STDERR, "ERROR: Schema check encountered a database error; check the Cacti log.\n");
	exit(1);
}
if ($missing) {
	fwrite(STDERR, 'ERROR: Missing tables: ' . implode(', ', $missing) . "\n");
	exit(1);
}

print $audit
	? "Syslog schema audit passed; all required tables exist.\n"
	: "Syslog schema upgrade completed; all required tables exist.\n";
