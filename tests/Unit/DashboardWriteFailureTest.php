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

function syslog_test_dashboard_writes(): void {
	syslog_test_reset_globals();
	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$_SESSION['sess_user_id'] = 1;
	test_override('get_username', static fn () => 'alice');
	test_override('syslog_db_fetch_row_prepared', static fn ($sql) => str_contains($sql, 'SELECT p.*') ?
		['id' => 7, 'dashboard_id' => 5, 'dashboard_user' => 'alice', 'dashboard_global' => '', 'source' => 'syslog', 'kind' => 'timeseries', 'chart' => 'line', 'field' => 'host', 'interval' => 'auto', 'timespan' => 'dashboard', 'removal' => 1, 'top_n' => 10, 'width' => 1, 'height' => 0] :
		['id' => 5, 'user' => 'alice', 'is_global' => 'on', 'name' => 'Operations']);
	test_override('db_fetch_assoc_prepared', static fn () => [['id' => 1]]);
}

it('reports failed dashboard and panel writes without returning a success id', function ($handler, $request) {
	syslog_test_dashboard_writes();
	$GLOBALS['request'] = $request;
	test_override('syslog_db_execute_prepared', static fn () => false);
	$response = json_decode($handler(), true);
	expect($response)->toHaveKey('error')->not->toHaveKey('id')
		->and($response['error'])->toBe('Unable to save dashboard changes.');
})->with([
	['syslog_dashboard_save', ['name' => 'Renamed', 'id' => 5]],
	['syslog_dashboard_save', ['name' => 'New', 'id' => 0]],
	['syslog_dashboard_save', ['name' => 'Operations', 'id' => 5, 'dashboard_delete' => '1']],
	['syslog_dashboard_global', ['id' => 5]],
	['syslog_dashboard_panel_save', ['dashboard_id' => 5, 'panel_id' => 7, 'panel_delete' => '1']],
	['syslog_dashboard_panel_save', ['dashboard_id' => 5, 'panel_id' => 7, 'panel_resize' => '1', 'width' => 2, 'height' => 300]],
	['syslog_dashboard_panel_save', ['dashboard_id' => 5, 'panel_id' => 7, 'title' => 'Panel', 'expression' => '', 'source' => 'syslog', 'kind' => 'timeseries', 'chart' => 'line', 'field' => 'host', 'removal' => '1', 'top_n' => 10, 'interval' => 'auto', 'timespan' => 'dashboard']],
	['syslog_dashboard_panel_save', ['dashboard_id' => 5, 'panel_id' => 0, 'title' => 'Panel', 'expression' => '', 'source' => 'syslog', 'kind' => 'timeseries', 'chart' => 'line', 'field' => 'host', 'removal' => '1', 'top_n' => 10, 'interval' => 'auto', 'timespan' => 'dashboard']],
]);

it('rolls back every partially completed dashboard delete', function ($failed_step) {
	syslog_test_dashboard_writes();
	$statements = [];
	$writes = 0;
	test_override('syslog_db_execute', function ($sql) use (&$statements, $failed_step) {
		$statements[] = $sql;
		return $sql !== $failed_step;
	});
	test_override('syslog_db_execute_prepared', function () use (&$writes, $failed_step) {
		return ++$writes !== $failed_step;
	});
	expect(syslog_dashboard_delete(5))->toBe($failed_step === 'none');
	if ($failed_step === 'none') {
		expect($statements)->toBe(['START TRANSACTION', 'COMMIT']);
	} elseif ($failed_step === 'START TRANSACTION') {
		expect($writes)->toBe(0);
	} else {
		expect(end($statements))->toBe('ROLLBACK');
	}
})->with([1, 2, 3, 'START TRANSACTION', 'COMMIT', 'none']);

it('rolls back failed panel ordering rather than saving part of the order', function ($handler, $failed_step) {
	syslog_test_dashboard_writes();
	$statements = [];
	test_override('syslog_db_execute', function ($sql) use (&$statements, $failed_step) {
		$statements[] = $sql;
		return $sql !== $failed_step;
	});
	test_override('syslog_db_fetch_assoc_prepared', static fn () => [['id' => 7], ['id' => 8]]);
	test_override('syslog_db_execute_prepared', static fn () => $failed_step !== 'UPDATE');
	expect($handler(['id' => 7], 5, 1))->toBe($failed_step === 'none');
	if ($failed_step !== 'none' && $failed_step !== 'START TRANSACTION') {
		expect(end($statements))->toBe('ROLLBACK');
	}
})->with(['syslog_dashboard_panel_move', 'syslog_dashboard_panel_reposition'])->with(['START TRANSACTION', 'UPDATE', 'COMMIT', 'none']);

it('rolls back a dashboard copy when any step fails', function ($failure) {
	syslog_test_dashboard_writes();
	$GLOBALS['request'] = ['id' => 5, 'name' => 'Copy'];
	$statements = [];
	test_override('syslog_db_execute', function ($sql) use (&$statements, $failure) {
		$statements[] = $sql;
		return $sql !== $failure;
	});
	test_override('syslog_db_fetch_insert_id', static fn () => 20);
	test_override('syslog_db_fetch_assoc_prepared', static fn () => [['title' => 'Panel', 'expression' => '', 'source' => 'syslog', 'removal' => 1, 'kind' => 'timeseries', 'chart' => 'line', 'field' => 'host', 'interval' => 'auto', 'timespan' => 'dashboard', 'top_n' => 10, 'width' => 1, 'height' => 0, 'position' => 1]]);
	test_override('syslog_db_execute_prepared', static fn ($sql) => !str_contains($sql, $failure));
	$result = json_decode(syslog_dashboard_copy(), true);
	expect($result)->toHaveKey('error')->not->toHaveKey('id');
	if ($failure !== 'START TRANSACTION') {
		expect(end($statements))->toBe('ROLLBACK');
	}
})->with(['START TRANSACTION', 'syslog_dashboards`', 'syslog_dashboard_panels`', 'COMMIT']);


it('returns errors from failed panel ordering endpoints', function ($request) {
	syslog_test_dashboard_writes();
	$GLOBALS['request'] = $request + ['dashboard_id' => 5, 'panel_id' => 7];
	test_override('syslog_db_execute', static fn () => false);
	$result = json_decode(syslog_dashboard_panel_save(), true);
	expect($result)->not->toHaveKey('id')->and($result['error'])->toBe('Unable to save dashboard changes.');
})->with([
	[['panel_move' => 'up']],
	[['panel_position' => '1', 'position' => 2]],
]);

it('preserves ordering when its locked read fails', function ($handler) {
	syslog_test_dashboard_writes();
	$statements = [];
	test_override('syslog_db_execute', function ($sql) use (&$statements) { $statements[] = $sql; return true; });
	test_override('syslog_db_fetch_assoc_prepared', static fn () => false);
	expect($handler(['id' => 7], 5, 1))->toBeFalse()
		->and($statements)->toBe(['START TRANSACTION', 'ROLLBACK']);
})->with(['syslog_dashboard_panel_move', 'syslog_dashboard_panel_reposition']);

it('returns persisted ids and rejects missing ids for successful create statements', function ($handler, $lookup) {
	syslog_test_dashboard_writes();
	$GLOBALS['request'] = ['id' => 0, 'name' => 'New', 'dashboard_id' => 5, 'panel_id' => 0,
		'title' => 'Panel', 'expression' => '', 'source' => 'syslog', 'kind' => 'timeseries', 'chart' => 'line', 'field' => 'host', 'removal' => '1', 'top_n' => 10, 'interval' => 'auto', 'timespan' => 'dashboard'];
	test_override('syslog_db_fetch_cell_prepared', static fn () => $lookup);
	$result = json_decode($handler(), true);
	if ($lookup) {
		expect($result)->toBe(['id' => $lookup]);
	} else {
		expect($result['error'])->toBe('Unable to save dashboard changes.');
	}
})->with(['syslog_dashboard_save', 'syslog_dashboard_panel_save'])->with([0, 20]);

it('commits a successful dashboard copy and handles missing ids or a failed source read', function ($failure) {
	syslog_test_dashboard_writes();
	$GLOBALS['request'] = ['id' => 5, 'name' => 'Copy'];
	$statements = [];
	test_override('syslog_db_execute', function ($sql) use (&$statements) { $statements[] = $sql; return true; });
	test_override('syslog_db_fetch_cell_prepared', static fn () => $failure === 'id' ? 0 : 20);
	test_override('syslog_db_fetch_assoc_prepared', static fn () => $failure === 'read' ? false : []);
	$result = json_decode(syslog_dashboard_copy(), true);
	if ($failure === 'none') {
		expect($result)->toBe(['id' => 20])->and($statements)->toBe(['START TRANSACTION', 'COMMIT']);
	} else {
		expect($result)->toHaveKey('error')->not->toHaveKey('id')
			->and(end($statements))->toBe('ROLLBACK');
	}
})->with(['none', 'id', 'read']);
