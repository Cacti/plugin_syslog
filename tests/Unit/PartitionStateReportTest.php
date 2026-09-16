<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('reports suspicious partition metadata without changing partition state', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$GLOBALS['partition_logs']   = [];

	test_override('cacti_log', function ($message, $output = false, $environ = '') {
		$GLOBALS['partition_logs'][] = [$message, $environ];
	});

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		expect(str_contains($sql, 'information_schema'))->toBeTrue('Partition report must inspect information_schema.');
		expect(str_contains($sql, 'table_name = ?'))->toBeTrue('Partition report must bind table_name.');
		expect($params)->toBe(['syslog', 'syslog'], 'Partition report must scope by database and table.');

		return [
			[
				'partition_name'             => 'd20260915',
				'partition_description'      => '1799971200',
				'partition_ordinal_position' => 1
			],
			[
				'partition_name'             => 'dMaxValue',
				'partition_description'      => 'MAXVALUE',
				'partition_ordinal_position' => 2
			]
		];
	});

	expect(syslog_partition_report_state('syslog'))->toBeTrue('Healthy metadata should not warn.');
	expect($GLOBALS['partition_logs'])->toBe([], 'Healthy metadata should not log warnings.');

	test_override('syslog_db_fetch_assoc_prepared', function () {
		return [
			[
				'partition_name'             => 'dMaxValue',
				'partition_description'      => 'MAXVALUE',
				'partition_ordinal_position' => 1
			],
			[
				'partition_name'             => 'd20260915',
				'partition_description'      => '1799971200',
				'partition_ordinal_position' => 2
			]
		];
	});

	expect(syslog_partition_report_state('syslog'))->toBeFalse('dMaxValue before the last partition must be reported.');

	expect(array_filter($GLOBALS['partition_logs'], function ($entry) {
		return str_contains($entry[0], 'dMaxValue before the last partition');
	}))->not->toBeEmpty('Expected a loud warning for suspicious post-DDL partition order.');
});
