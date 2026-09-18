<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Coverage for the parallel worker statistics surfaced on the Syslog
 * Status tab: running worker count from Cacti's process table, the
 * configured maximum, and per child records handled during the last
 * parallel run.  Malformed statistics rows must be ignored, not fatal.
 */

syslog_load_plugin_source('functions.php');

it('reports running workers, configured workers, and per child records', function () {
	test_override('read_config_option', function ($name, $force = false) {
		return $name === 'syslog_max_workers' ? '4' : '';
	});

	test_override('db_table_exists', function ($table, $connection = false) {
		return $table === 'processes';
	});

	test_override('db_fetch_cell', function ($sql) {
		return '2';
	});

	test_override('db_fetch_assoc', function ($sql) {
		$GLOBALS['worker_stats_settings_sql'] = $sql;

		return [
			[
				'name'  => 'stats_syslog_child_1',
				'value' => json_encode(['child' => 1, 'run_id' => str_repeat('a', 32), 'phase' => 'transfer', 'moved' => 1200, 'resolved' => 0, 'runtime' => 1.25])
			],
			[
				'name'  => 'stats_syslog_child_2',
				'value' => json_encode(['child' => 2, 'run_id' => str_repeat('a', 32), 'phase' => 'references', 'moved' => 0, 'resolved' => 15, 'runtime' => 0.5])
			]
		];
	});

	$stats = syslog_worker_stats_get();

	expect($stats['running'])->toBe(2, 'Running count read from the process table');
	expect($stats['workers'])->toBe(4, 'Configured maximum read from settings');
	expect($stats['children'][1])->toBe([
		'child'    => 1,
		'run_id'   => str_repeat('a', 32),
		'phase'    => 'transfer',
		'moved'    => 1200,
		'resolved' => 0,
		'runtime'  => 1.25
	]);
	expect($stats['children'][2]['resolved'])->toBe(15);
	expect(str_contains($GLOBALS['worker_stats_settings_sql'], 'stats_syslog_child_%'))->toBeTrue('Stats read from the settings table');
});

it('sorts per child statistics by child number', function () {
	test_override('read_config_option', function ($name, $force = false) {
		return $name === 'syslog_max_workers' ? '4' : '';
	});

	test_override('db_table_exists', function ($table, $connection = false) {
		return $table === 'processes';
	});

	test_override('db_fetch_cell', function ($sql) {
		return '0';
	});

	test_override('db_fetch_assoc', function ($sql) {
		return [
			['name' => 'stats_syslog_child_3', 'value' => json_encode(['child' => 3, 'moved' => 3])],
			['name' => 'stats_syslog_child_1', 'value' => json_encode(['child' => 1, 'moved' => 1])],
			['name' => 'stats_syslog_child_2', 'value' => json_encode(['child' => 2, 'moved' => 2])]
		];
	});

	$stats = syslog_worker_stats_get();

	expect(array_keys($stats['children']))->toBe([1, 2, 3], 'Children ordered by process number for the status table');
});

it('ignores malformed statistics rows and defaults partial ones', function () {
	test_override('read_config_option', function ($name, $force = false) {
		return $name === 'syslog_max_workers' ? '1' : '';
	});

	test_override('db_table_exists', function ($table, $connection = false) {
		return $table === 'processes';
	});

	test_override('db_fetch_cell', function ($sql) {
		return '0';
	});

	test_override('db_fetch_assoc', function ($sql) {
		return [
			['name' => 'stats_syslog_child_1', 'value' => 'not-json-at-all'],
			['name' => 'stats_syslog_child_2', 'value' => json_encode(['moved' => 7])]
		];
	});

	$stats = syslog_worker_stats_get();

	expect($stats['children'])->toBe([
		2 => [
			'child'    => 2,
			'run_id'   => '',
			'phase'    => '',
			'moved'    => 7,
			'resolved' => 0,
			'runtime'  => 0.0
		]
	], 'Non-JSON row ignored, partial JSON row filled with defaults');
	expect($stats['running'])->toBe(0);
	expect($stats['workers'])->toBe(1);
});

it('returns zeroed stats when the process table is absent', function () {
	test_override('read_config_option', function ($name, $force = false) {
		return $name === 'syslog_max_workers' ? '8' : '';
	});

	test_override('db_table_exists', function ($table, $connection = false) {
		return false;
	});

	$GLOBALS['__test_db_calls'] = [];

	$stats = syslog_worker_stats_get();

	expect($stats)->toBe([
		'running'  => 0,
		'workers'  => 8,
		'children' => []
	], 'No process table means no workers can be running');
	expect($GLOBALS['__test_db_calls'])->toBe([], 'No SQL executed without the process table');
});

it('clamps a missing configured worker count to a single worker', function () {
	test_override('read_config_option', function ($name, $force = false) {
		return '';
	});

	test_override('db_table_exists', function ($table, $connection = false) {
		return false;
	});

	$stats = syslog_worker_stats_get();

	expect($stats['workers'])->toBe(1, 'Unset setting falls back to single process');
});