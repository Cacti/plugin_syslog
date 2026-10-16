<?php

it('checks overflow size under the creation lock and defers unsafe reorganizations', function ($metadata, $blocked) {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';

	foreach (['syslog', 'syslog_removed'] as $table) {
		$locked = false;
		$released = 0;
		$alters = [];
		test_override('syslog_db_fetch_cell_prepared', function ($sql) use (&$locked, &$released) {
			if (str_contains($sql, 'GET_LOCK')) {
				$locked = true;

				return 1;
			}
			if (str_contains($sql, 'RELEASE_LOCK')) {
				$locked = false;
				$released++;

				return 1;
			}

			return 'd20260101';
		});
		test_override('syslog_db_fetch_row_prepared', function ($sql, $params) use ($metadata, &$locked, $table, $blocked) {
			if (str_contains($sql, 'AS overflow_rows')) {
				expect($locked)->toBeTrue();
				expect($params)->toBe(['syslog_test', $table, 'dMaxValue']);

				return $metadata;
			}
			if (str_contains($sql, 'SHOW CREATE TABLE')) {
				expect($blocked)->toBeFalse('Blocked recovery must stop before inspecting DDL.');

				return ['Create Table' => 'PARTITION BY RANGE (UNIX_TIMESTAMP(logtime))'];
			}

			return [];
		});
		test_override('syslog_db_execute_prepared', function ($sql) use (&$alters) {
			$alters[] = $sql;

			return true;
		});

		$result = syslog_partition_recover($table, 1767398400, 0);
		expect($released)->toBe(1)->and($locked)->toBeFalse();
		expect($result['retention_deferred'])->toBe($blocked);
		if ($blocked) {
			expect($result['created'])->toBe(0)->and($result['missing'])->toBe(1);
			expect($result['stop_reason'])->toContain('dMaxValue')->toContain('intervention required');
			expect($alters)->toBe([]);
		} else {
			expect($result['created'])->toBe(1)->and($result['stop_reason'])->toBe('');
			expect($alters)->toHaveCount(1);
			expect($alters[0])->toContain('REORGANIZE PARTITION dMaxValue');
		}
	}
})->with([
	'empty' => [['overflow_rows' => 0, 'overflow_bytes' => 0], false],
	'at limits' => [['overflow_rows' => '100000', 'overflow_bytes' => '134217728'], false],
	'row limit exceeded' => [['overflow_rows' => 100001, 'overflow_bytes' => 0], true],
	'byte limit exceeded' => [['overflow_rows' => 0, 'overflow_bytes' => 134217729], true],
	'missing partition metadata' => [[], true],
	'failed metadata query' => [false, true],
	'unknown rows' => [['overflow_rows' => null, 'overflow_bytes' => 0], true],
	'unknown bytes' => [['overflow_rows' => 0, 'overflow_bytes' => null], true],
	'invalid rows' => [['overflow_rows' => 'unknown', 'overflow_bytes' => 0], true],
	'invalid bytes' => [['overflow_rows' => 0, 'overflow_bytes' => 'unknown'], true],
	'negative rows' => [['overflow_rows' => -1, 'overflow_bytes' => 0], true],
	'negative bytes' => [['overflow_rows' => 0, 'overflow_bytes' => -1], true],
]);

it('does not apply overflow limits when the requested partition already exists', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';
	test_override('syslog_db_fetch_cell_prepared', static fn() => 1);
	test_override('syslog_db_fetch_row_prepared', function ($sql) {
		expect($sql)->not->toContain('AS overflow_rows');

		return ['partition_name' => 'd20260103'];
	});
	$reason = 'previous deferral';
	expect(syslog_partition_create('syslog', 1767398400, $reason))->toBeTrue();
	expect($reason)->toBe('');
});

it('persists the overflow deferral reason without pruning either affected table', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';
	$values = [];
	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => [
		['partition_name' => 'd20260101', 'partition_description' => '1767312000', 'partition_ordinal_position' => 1],
		['partition_name' => 'dMaxValue', 'partition_description' => 'MAXVALUE', 'partition_ordinal_position' => 2],
	]);
	test_override('syslog_db_fetch_cell_prepared', static fn($sql) => str_contains($sql, 'PARTITION_NAME') ? 'd20260101' : 1);
	test_override('syslog_db_fetch_row_prepared', static fn($sql) => str_contains($sql, 'AS overflow_rows')
		? ['overflow_rows' => 100001, 'overflow_bytes' => 0] : []);
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$values) {
		expect($sql)->not->toContain('REORGANIZE PARTITION')->not->toContain('DROP PARTITION');
		if (str_contains($sql, 'syslog_status')) {
			$values[$params[0]] = $params[1];
		}

		return true;
	});

	expect(syslog_partition_manage())->toBe(0);
	expect($values['partition_maintenance_blocked'])->toBe('1');
	expect($values['partition_maintenance_reason'])->toContain('100001 rows')->toContain('intervention required');
});
