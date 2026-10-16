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

afterEach(function () {
	syslog_test_reset_globals();
});

it('uses the Syslog database for every rule import lookup and write', function ($file, $handler, $table) {
	$source = plugin_test_read_source($file);
	$body = substr($source, strpos($source, 'function ' . $handler . '('));
	expect($body)->toContain("syslog_sql_save(\$save, '$table')")
		->not->toMatch('/(?<!syslog_)\b(?:sql_save|db_fetch_cell_prepared)\(/');
})->with([
	['syslog_alerts.php', 'alert_import', 'syslog_alert'],
	['syslog_removal.php', 'removal_import', 'syslog_remove'],
	['syslog_reports.php', 'report_import', 'syslog_reports'],
]);


it('accounts for every required table when uninstalling all plugin data', function () {
	$cli = plugin_test_read_source('cli/syslog_schema.php');
	preg_match('/\$tables = (\[.*?\]);/s', $cli, $match);
	$tables = eval('return ' . $match[1] . ';');
	$tables = array_merge($tables, ['syslog_replication_receipts', 'syslog_replication_collectors']);
	$setup = plugin_test_read_source('setup.php');
	$body = substr($setup, strpos($setup, 'function plugin_syslog_uninstall'), strpos($setup, 'function plugin_syslog_check_config') - strpos($setup, 'function plugin_syslog_uninstall'));
	foreach ($tables as $table) {
		expect($body)->toContain('DROP TABLE IF EXISTS `$syslogdb_default`.`' . $table . '`');
	}
});

it('adds missing digest columns idempotently without modifying history rows', function () {
	syslog_load_plugin_source('includes/schema.php');
	$columns = [];
	test_override('syslog_db_table_exists', static fn () => true);
	test_override('syslog_db_column_exists', static fn () => false);
	test_override('syslog_db_add_column', function ($table, $column) use (&$columns) {
		$columns[$table] = $column['name'];
		return true;
	});
	syslog_ensure_message_digest_columns();
	expect($columns)->toBe(['syslog' => 'message_digest', 'syslog_removed' => 'message_digest']);
	$columns = [];
	test_override('syslog_db_column_exists', static fn () => true);
	syslog_ensure_message_digest_columns();
	expect($columns)->toBe([]);
});
