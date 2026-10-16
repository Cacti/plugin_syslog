<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('defaults partition pre-create window to three days and clamps invalid values', function () {
	syslog_load_plugin_source('includes/functions.php');

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
	syslog_load_plugin_source('includes/functions.php');

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
		if (str_contains($sql, 'AS overflow_rows')) {
			return ['overflow_rows' => 0, 'overflow_bytes' => 0];
		}

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
	syslog_load_plugin_source('includes/functions.php');

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
		$today = intdiv(time(), 86400) * 86400;
		$partitions = [];
		for ($day = -3; $day <= 3; $day++) {
			$partitions[] = [
				'PARTITION_NAME' => 'd' . gmdate('Ymd', $today + $day * 86400),
				'PARTITION_DESCRIPTION' => (string) ($today + ($day + 1) * 86400),
				'PARTITION_EXPRESSION' => 'unix_timestamp(`logtime`)',
			];
		}
		$partitions[] = ['PARTITION_NAME' => 'dMaxValue'];

		return $partitions;
	});

	test_override('syslog_db_execute_prepared', function ($sql) use (&$dropped) {
		if (preg_match('/DROP PARTITION `([^`]+)`/', $sql, $matches)) {
			$dropped[] = $matches[1];
		}

		return true;
	});

	expect(syslog_partition_remove('syslog'))->toBe(1);
	expect($dropped)->toBe(['d' . gmdate('Ymd', time() - 3 * 86400)]);
});
