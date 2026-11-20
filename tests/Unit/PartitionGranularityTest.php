<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Hourly and daily partitioning: server partition limits, the settings-save
 * gate, and bounded hourly recovery that stays cheap once the horizon exists.
 */

it('derives the server partition limit from the database version', function () {
	syslog_load_plugin_source('includes/functions.php');

	foreach ([
		'10.5.29-MariaDB'          => 8192,
		'5.5.5-10.0.3-MariaDB-log' => 1024,
		'10.0.4-MariaDB'           => 8192,
		'8.0.36'                   => 8192,
		'5.7.44-log'               => 8192,
		'5.6.51'                   => 1024,
		'unknown'                  => 1024
	] as $version => $limit) {
		expect(syslog_partition_server_limit($version))->toBe($limit, $version);
	}
});

it('rejects partitioning combinations above the partition limit or unbounded hourly growth', function () {
	syslog_load_plugin_source('includes/functions.php');

	expect(syslog_partition_name('hour', gmmktime(13, 5, 0, 11, 20, 2026)))->toBe('h2026112013')
		->and(syslog_partition_name('day', gmmktime(13, 5, 0, 11, 20, 2026)))->toBe('d20261120')
		->and(syslog_partition_ahead_days_for('hour', 1))->toBe(2)
		->and(syslog_partition_ahead_days_for('day', 1))->toBe(1)
		->and(syslog_partition_projected_count('day', 30, 3))->toBe(35)
		->and(syslog_partition_projected_count('hour', 30, 1))->toBe(24 * 33 + 1)
		->and(syslog_partition_projected_count('hour', 0, 3))->toBeNull()
		->and(syslog_partition_settings_error('day', '30', '3', 1024))->toBe('')
		->and(syslog_partition_settings_error('day', '0', '3', 1024))->toBe('')
		->and(syslog_partition_settings_error('hour', '183', '3', 8192))->toBe('')
		->and(syslog_partition_settings_error('hour', '365', '3', 8192))->toContain('8,857 partitions')->toContain('limit of 8,192')
		->and(syslog_partition_settings_error('hour', '60', '3', 1024))->toContain('1,537 partitions')
		->and(syslog_partition_settings_error('hour', '0', '3', 8192))->toContain('Indefinite')
		->and(syslog_partition_settings_error('day', '', '3', 8192))->toContain('retention is invalid')
		->and(syslog_partition_settings_error('week', '30', '3', 8192))->not->toBe('');
});

it('refuses to save Syslog settings that exceed the partition limit', function () {
	syslog_load_plugin_source('includes/settings.php');

	$method   = $_SERVER['REQUEST_METHOD'] ?? null;
	$messages = [];
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION['sess_error_fields'] = [];
	test_override('raise_message', function ($id, $text, $level) use (&$messages) {
		$messages[] = [$id, $text, $level];
	});

	try {
		$GLOBALS['request'] = ['syslog_partition_granularity' => 'hour', 'syslog_retention' => '365', 'syslog_partition_ahead_days' => '3'];

		expect(syslog_settings_partition_gate('10.11.6-MariaDB'))->toContain('8,857 partitions')
			->and($messages)->toHaveCount(1)
			->and($messages[0][0])->toBe('syslog_partition_limit')
			->and($messages[0][1])->toContain('The Syslog settings were not saved.')
			->and($messages[0][2])->toBe(MESSAGE_LEVEL_ERROR)
			->and($_SESSION['sess_error_fields'])->toHaveKeys(['syslog_retention', 'syslog_partition_granularity']);

		$GLOBALS['request']['syslog_retention'] = '60';
		expect(syslog_settings_partition_gate('10.11.6-MariaDB'))->toBe('')
			->and(syslog_settings_partition_gate('10.0.3-MariaDB'))->toContain('limit of 1,024');

		$_SERVER['REQUEST_METHOD'] = 'GET';
		expect(syslog_settings_partition_gate('10.0.3-MariaDB'))->toBe('');
	} finally {
		if ($method === null) {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $method;
		}

		$GLOBALS['request'] = [];
		$_SESSION['sess_error_fields'] = [];
	}
});

it('recovers hourly partitions in bounded runs and checks only the horizon once it exists', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$state = (object) ['max' => gmmktime(12, 0, 0, 11, 20, 2026), 'created' => [], 'max_reads' => 0, 'limit' => '3'];

	test_override('read_config_option', fn($name) => $name === 'syslog_partition_recover_limit' ? $state->limit : '');
	test_override('syslog_db_fetch_cell_prepared', function ($sql) use ($state) {
		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'MAX(CAST(partition_description')) {
			$state->max_reads++;

			return $state->max;
		}

		return str_contains($sql, 'SELECT partition_expression') ? 'unix_timestamp(`logtime`)' : '';
	});
	test_override('syslog_db_fetch_row_prepared', function ($sql) use ($state) {
		return match (true) {
			str_contains($sql, 'AS max_boundary')    => ['max_boundary' => $state->max, 'partition_expression' => 'unix_timestamp(`logtime`)'],
			str_contains($sql, 'AS overflow_rows')   => ['overflow_rows' => 0, 'overflow_bytes' => 0],
			str_contains($sql, 'SHOW CREATE TABLE')  => ['Create Table' => 'PARTITION BY RANGE (UNIX_TIMESTAMP(logtime))'],
			default                                   => []
		};
	});
	test_override('syslog_db_execute_prepared', function ($sql) use ($state) {
		if (preg_match('/PARTITION (h\d{10}) VALUES LESS THAN \((\d+)\)/', $sql, $match)) {
			$state->created[] = $match[1];
			$state->max = (int) $match[2];
		}

		return true;
	});

	$base = gmmktime(10, 30, 0, 11, 20, 2026);
	$run  = syslog_partition_recover('syslog', $base, 2, 'hour');

	// Hours through 11:00 exist; the two-day horizon ends at 2026-11-22 10:00.
	expect($state->created)->toBe(['h2026112012', 'h2026112013', 'h2026112014'])
		->and($run['created'])->toBe(3)
		->and($run['missing'])->toBe(44)
		->and($run['retention_deferred'])->toBeTrue();

	$state->limit = '31';
	$state->created = [];

	while (($run = syslog_partition_recover('syslog', $base, 2, 'hour'))['missing'] > 0) {
		expect($run['created'])->toBe(31);
	}

	expect(end($state->created))->toBe('h2026112210')
		->and($run['stop_reason'])->toBe('');

	$state->created = [];
	$state->max_reads = 0;
	$run = syslog_partition_recover('syslog', $base, 2, 'hour');

	expect($run['created'])->toBe(0)
		->and($state->created)->toBe([])
		->and($state->max_reads)->toBe(1);
});
