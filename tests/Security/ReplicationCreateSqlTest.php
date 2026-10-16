<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

afterEach(function () {
	syslog_test_reset_globals();
});

function syslog_test_snapshot_setup(): void {
	syslog_load_plugin_source('includes/processing.php');
	$GLOBALS['syslog_cnn'] = new stdClass();
	$GLOBALS['local_db_cnn_id'] = new stdClass();
	$GLOBALS['snapshot_calls'] = [];
	$GLOBALS['snapshot_failure'] = '';
	test_override('db_fetch_row', function ($sql, $log, $connection) {
		expect($connection)->toBe($GLOBALS['local_db_cnn_id']);
		return $GLOBALS['snapshot_failure'] === 'schema' ? false : ['Create Table' => 'CREATE TABLE `syslog_alert` (`id` INT PRIMARY KEY, `name` TEXT) ENGINE=InnoDB'];
	});
	test_override('db_fetch_cell_prepared', function ($sql, $params, $column, $log, $connection) {
		expect($connection)->toBe($GLOBALS['syslog_cnn']);
		return $GLOBALS['snapshot_failure'] === 'lock' ? 0 : 1;
	});
	test_override('db_table_exists', static fn () => true);
	test_override('db_execute', function ($sql, $log, $connection) {
		expect($connection)->toBe($GLOBALS['syslog_cnn']);
		$GLOBALS['snapshot_calls'][] = $sql;
		return !str_starts_with($sql, $GLOBALS['snapshot_failure'] ?: 'NO FAILURE');
	});
	test_override('db_execute_prepared', function ($sql, $params, $log, $connection) {
		expect($connection)->toBe($GLOBALS['syslog_cnn']);
		$GLOBALS['snapshot_calls'][] = $sql;
		return $GLOBALS['snapshot_failure'] !== 'insert';
	});
}

it('publishes the complete snapshot including an empty source with source-owned schema', function ($rows) {
	syslog_test_snapshot_setup();
	expect(syslog_replace_data('syslog_alert', $rows))->toBeTrue();
	$calls = implode("\n", $GLOBALS['snapshot_calls']);
	expect($calls)->toContain('CREATE TABLE `syslog_alert_sync_')->toContain('RENAME TABLE `syslog_alert` TO `syslog_alert_sync_')->toContain('DROP TABLE IF EXISTS');
	if ($rows) {
		expect($calls)->toContain('INSERT INTO `syslog_alert_sync_');
	}
})->with([[[]], [[['id' => 1, 'name' => 'current']]]]);

it('keeps the previous snapshot when copying or locking fails', function ($failure) {
	syslog_test_snapshot_setup();
	$GLOBALS['snapshot_failure'] = $failure;
	$rows = [['id' => 1, 'name' => 'current']];
	expect(syslog_replace_data('syslog_alert', $rows))->toBeFalse();
	$calls = implode("\n", $GLOBALS['snapshot_calls']);
	if ($failure !== 'RENAME') {
		expect($calls)->not->toContain('RENAME TABLE');
	}
	if (in_array($failure, ['insert', 'CREATE', 'RENAME'], true)) {
		expect($calls)->toContain('DROP TABLE IF EXISTS');
	}
})->with(['schema', 'lock', 'CREATE', 'insert', 'RENAME']);

it('rejects failed source reads and malformed rows before publishing', function ($rows) {
	syslog_test_snapshot_setup();
	expect(syslog_replace_data('syslog_alert', $rows))->toBeFalse()
		->and($GLOBALS['snapshot_calls'])->toBe([]);
})->with([[false], [[false]], [[['bad`column' => 1]]], [[['id' => 1], ['name' => 'wrong shape']]]]);

it('does not rewrite the live table when the two connections are identical', function () {
	syslog_test_snapshot_setup();
	$GLOBALS['syslog_cnn'] = $GLOBALS['local_db_cnn_id'];
	$rows = [];
	expect(syslog_replace_data('syslog_alert', $rows))->toBeTrue();
	expect($GLOBALS['snapshot_calls'])->toBe([]);
});
