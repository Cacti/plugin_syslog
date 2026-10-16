<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The dMaxValue row/byte extraction inside syslog_partition_observability():
 * the safety partition is matched case-insensitively across the two
 * information_schema column casings and contributes the dmax_* metrics.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

it('extracts dmax rows and bytes from the safety partition metadata', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'status_test';

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		if ($params[1] === 'syslog_removed') {
			return [];
		}

		if ($params[0] !== 'status_test' || $params[1] !== 'syslog') {
			throw new RuntimeException('Partition observability must scope to the configured table.');
		}

		return [
			['partition_name' => 'd20260920'],
			['partition_name' => 'd20260921'],
			['partition_name' => 'dMaxValue', 'table_rows' => '42', 'data_length' => '1024', 'index_length' => '512'],
		];
	});

	$result = syslog_partition_observability();

	expect($result['syslog']['coverage_start'])->toBe('20260920')
		->and($result['syslog']['coverage_end'])->toBe('20260921')
		->and($result['syslog']['partitions'])->toBe(2)
		->and($result['syslog']['dmax_rows'])->toBe(42)
		->and($result['syslog']['dmax_bytes'])->toBe(1536);
});

it('reports null dmax metrics when information_schema returns no statistics', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'status_test';

	test_override('syslog_db_fetch_assoc_prepared', function () {
		return [
			['PARTITION_NAME' => 'd20260920'],
			['PARTITION_NAME' => 'dMaxValue', 'TABLE_ROWS' => null, 'DATA_LENGTH' => null, 'INDEX_LENGTH' => null],
		];
	});

	$result = syslog_partition_observability();

	expect($result['syslog']['dmax_rows'])->toBeNull()
		->and($result['syslog']['dmax_bytes'])->toBeNull();
});