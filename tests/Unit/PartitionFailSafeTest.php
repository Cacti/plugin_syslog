<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Fail-safe partition maintenance.  syslog_partition_manage() must stop
 * all partition actions when metadata is invalid or creation fails, never
 * remove the dMaxValue safety partition, and record the blocked condition
 * in the syslog_status telemetry table for the Status page.
 *
 * Partition metadata checks and DDL are faked through the syslog_db_*
 * overrides exactly as PartitionAheadTest does; the status writes are
 * captured from the prepared INSERT/UPDATE statements.
 */

function partition_failsafe_install_status_capture(): array {
	$values = [];

	test_override('syslog_db_table_exists', function ($table) {
		return $table === 'syslog_status';
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$values) {
		if (str_contains($sql, 'syslog_status')) {
			$values[$params[0]] = $params[1];
		}

		return true;
	});

	test_override('syslog_db_fetch_assoc', function () use (&$values) {
		return array_map(function ($name, $value) {
			return ['name' => $name, 'value' => $value, 'updated' => time()];
		}, array_keys($values), $values);
	});

	return $values;
}

function partition_failsafe_healthy_state(): void {
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		return [
			['partition_name' => 'd20260913', 'partition_description' => '1799193600', 'partition_ordinal_position' => 1],
			['partition_name' => 'd20260914', 'partition_description' => '1799280000', 'partition_ordinal_position' => 2],
			['partition_name' => 'dMaxValue', 'partition_description' => 'MAXVALUE', 'partition_ordinal_position' => 3]
		];
	});
}

it('stops all partition maintenance when partition metadata is invalid', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$values = partition_failsafe_install_status_capture();

	// dMaxValue appears before the last partition: invalid layout.
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		return [
			['partition_name' => 'dMaxValue', 'partition_description' => 'MAXVALUE', 'partition_ordinal_position' => 1],
			['partition_name' => 'd20260914', 'partition_description' => '1799280000', 'partition_ordinal_position' => 2]
		];
	});

	$altered = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$altered, &$values) {
		if (str_contains($sql, 'syslog_status')) {
			$values[$params[0]] = $params[1];
		} else {
			$altered[] = $sql;
		}

		return true;
	});

	expect(syslog_partition_manage())->toBe(0);
	expect($altered)->toBe([], 'No DDL may run while partition metadata is invalid.');
	expect($values['partition_maintenance_blocked'])->toBe('1');
	expect($values['partition_maintenance_reason'])->toContain('syslog');
	expect($values['partition_maintenance_reason'])->toContain('dMaxValue');
});

it('defers retention pruning and reports the gap when partition creation fails', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$values = partition_failsafe_install_status_capture();

	partition_failsafe_healthy_state();

	// The last concrete partition is behind the window, so every day needs
	// a new partition, and the ALTER TABLE REORGANIZE fails.
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params = []) {
		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'PARTITION_NAME')) {
			return 'd20260913';
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

	$altered = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$altered, &$values) {
		if (str_contains($sql, 'syslog_status')) {
			$values[$params[0]] = $params[1];

			return true;
		}

		if (str_contains($sql, 'REORGANIZE PARTITION')) {
			$altered[] = $sql;

			return false;
		}

		return true;
	});

	$base_time = gmmktime(22, 30, 0, 9, 14, 2026);

	$recovery = syslog_partition_recover('syslog', $base_time, 3);

	expect($recovery['created'])->toBe(0);
	expect($recovery['missing'])->toBe(4);
	expect($recovery['stop_reason'])->toBe('partition creation failed');
	expect($recovery['retention_deferred'])->toBeTrue();
	expect($recovery['dmax_risk'])->toBeTrue();
});

it('creates only the bounded number of missing partitions per run', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$values = partition_failsafe_install_status_capture();

	partition_failsafe_healthy_state();

	test_override('read_config_option', function ($name) {
		if ($name === 'syslog_partition_recover_limit') {
			return '2';
		}

		return '';
	});

	// The last concrete partition is behind the window: every day needs creation.
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params = []) {
		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'PARTITION_NAME')) {
			return 'd20260913';
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

	$created = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$created) {
		if (preg_match('/PARTITION (d\d{8}) VALUES LESS THAN/', $sql, $matches)) {
			$created[] = $matches[1];
		}

		return true;
	});

	$base_time = gmmktime(22, 30, 0, 9, 14, 2026);

	$recovery = syslog_partition_recover('syslog', $base_time, 3);

	expect($recovery['created'])->toBe(2);
	expect($recovery['missing'])->toBe(2);
	expect($recovery['stop_reason'])->toBe('per run recovery limit reached');
	expect($recovery['retention_deferred'])->toBeTrue();
	expect(cacti_sizeof($created))->toBe(2, 'Only the configured per-run limit may be created.');
});

it('runs retention pruning and clears the block when recovery completes', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$values = partition_failsafe_install_status_capture();

	// Nothing is missing: the full horizon already exists.
	test_override('read_config_option', function ($name) {
		if ($name === 'syslog_partition_recover_limit') {
			return '7';
		}

		return '';
	});

	// All windows through the horizon are covered: the second-to-last
	// partition is d20260918, at or after every checked day.
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		return [
			['partition_name' => 'd20260914', 'partition_description' => '1799280000', 'partition_ordinal_position' => 1],
			['partition_name' => 'd20260918', 'partition_description' => '1799625600', 'partition_ordinal_position' => 2],
			['partition_name' => 'dMaxValue', 'partition_description' => 'MAXVALUE', 'partition_ordinal_position' => 3]
		];
	});

	// No future partition is missing.
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params = []) {
		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'PARTITION_NAME')) {
			return 'd20260918';
		}

		return '';
	});

	$dropped = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$dropped, &$values) {
		if (str_contains($sql, 'syslog_status')) {
			$values[$params[0]] = $params[1];

			return true;
		}

		if (preg_match('/DROP PARTITION `([^`]+)`/', $sql, $matches)) {
			$dropped[] = $matches[1];
		}

		return true;
	});

	expect(syslog_partition_manage())->toBeGreaterThanOrEqual(0);
	expect($values['partition_maintenance_blocked'])->toBe('0');
	expect($dropped)->toBe([], 'No retention drop is due when the partition count is within the keep window.');
});

it('never removes the dMaxValue safety partition', function () {
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

	// Only the safety partition plus one old partition: the prune would
	// target dMaxValue if the guard were missing.
	test_override('syslog_db_fetch_assoc_prepared', function () {
		return [
			['PARTITION_NAME' => 'd20260910'],
			['PARTITION_NAME' => 'dMaxValue']
		];
	});

	test_override('syslog_db_execute_prepared', function ($sql) use (&$dropped) {
		if (preg_match('/DROP PARTITION `([^`]+)`/', $sql, $matches)) {
			$dropped[] = $matches[1];
		}

		return true;
	});

	expect(syslog_partition_remove('syslog'))->toBe(0);
	expect($dropped)->toBe([], 'The dMaxValue safety partition must never be dropped.');
});