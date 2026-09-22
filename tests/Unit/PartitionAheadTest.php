<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('defaults partition pre-create window to three days and clamps invalid values', function () {
	syslog_load_plugin_source('functions.php');

	test_override('read_config_option', function ($name) {
		return $name === 'syslog_partition_ahead_days' ? '' : '';
	});

	expect(syslog_partition_ahead_days())->toBe(3);

	foreach (['0', '8', '-1', 'abc'] as $invalid) {
		test_override('read_config_option', function ($name) use ($invalid) {
			return $name === 'syslog_partition_ahead_days' ? $invalid : '';
		});

		expect(syslog_partition_ahead_days())->toBe(3);
	}

	test_override('read_config_option', function ($name) {
		return $name === 'syslog_partition_ahead_days' ? '7' : '';
	});

	expect(syslog_partition_ahead_days())->toBe(7);
});

it('ensures partitions sequentially through the configured future horizon', function () {
	syslog_load_plugin_source('functions.php');

	$created = [];
	$last_partition = '20260914';
	$base_time = gmmktime(22, 30, 0, 9, 15, 2026);

	$GLOBALS['syslogdb_default'] = 'syslog';

	// Raise the per-run recovery limit above the four-day horizon so the
	// complete recovery path is exercised.
	test_override('read_config_option', function ($name) {
		return $name === 'syslog_partition_recover_limit' ? '7' : '';
	});

	test_override('syslog_db_fetch_cell_prepared', function ($sql) use (&$last_partition) {
		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'PARTITION_NAME')) {
			return 'd' . $last_partition;
		}

		return '';
	});

	test_override('syslog_db_fetch_row_prepared', function ($sql, $params = []) {
		if (str_contains($sql, 'information_schema')) {
			return [];
		}

		if (str_contains($sql, 'SHOW CREATE TABLE')) {
			return ['Create Table' => 'CREATE TABLE `syslog` (`logtime` timestamp NOT NULL) PARTITION BY RANGE (UNIX_TIMESTAMP(logtime))'];
		}

		return [];
	});

	test_override('syslog_db_execute_prepared', function ($sql) use (&$created, &$last_partition) {
		if (preg_match('/PARTITION (d\d{8}) VALUES LESS THAN/', $sql, $matches)) {
			$created[] = $matches[1];
			$last_partition = substr($matches[1], 1);
		}

		return true;
	});

	$recovery = syslog_partition_recover('syslog', $base_time, 3);

	expect($recovery['created'])->toBe(4);
	expect($recovery['missing'])->toBe(0);
	expect($recovery['stop_reason'])->toBe('');
	expect($recovery['retention_deferred'])->toBeFalse();
	expect($recovery['dmax_risk'])->toBeFalse();
	expect($created)->toBe([
		'd20260915',
		'd20260916',
		'd20260917',
		'd20260918'
	]);
});

it('keeps retention plus future partitions when pruning', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$dropped = [];

	test_override('read_config_option', function ($name) {
		if ($name === 'syslog_retention') {
			return '3';
		}

		if ($name === 'syslog_partition_ahead_days') {
			return '3';
		}

		return '';
	});

	test_override('syslog_db_fetch_cell_prepared', function () {
		return 1;
	});

	test_override('syslog_db_fetch_assoc_prepared', function () {
		return [
			['PARTITION_NAME' => 'd20260910'],
			['PARTITION_NAME' => 'd20260911'],
			['PARTITION_NAME' => 'd20260912'],
			['PARTITION_NAME' => 'd20260913'],
			['PARTITION_NAME' => 'd20260914'],
			['PARTITION_NAME' => 'd20260915'],
			['PARTITION_NAME' => 'd20260916'],
			['PARTITION_NAME' => 'dMaxValue']
		];
	});

	test_override('syslog_db_execute_prepared', function ($sql) use (&$dropped) {
		if (preg_match('/DROP PARTITION `([^`]+)`/', $sql, $matches)) {
			$dropped[] = $matches[1];
		}

		return true;
	});

	expect(syslog_partition_remove('syslog'))->toBe(1);
	expect($dropped)->toBe(['d20260910']);
});
