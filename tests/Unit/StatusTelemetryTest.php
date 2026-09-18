<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('stores syslog status values in the telemetry table', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$executed = [];

	test_override('syslog_db_table_exists', function ($table) {
		return $table === 'syslog_status';
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$executed) {
		$executed[] = [$sql, $params];

		return true;
	});

	expect(syslog_status_set('last_record_count', 42))->toBeTrue();
	expect($executed)->toHaveCount(1);
	expect($executed[0][1][0])->toBe('last_record_count');
	expect($executed[0][1][1])->toBe('42');
});

it('rejects invalid syslog status field names', function () {
	syslog_load_plugin_source('functions.php');

	$executed = false;

	test_override('syslog_db_execute_prepared', function () use (&$executed) {
		$executed = true;

		return true;
	});

	expect(syslog_status_set('last record count', 42))->toBeFalse();
	expect($executed)->toBeFalse();
});

it('records polling runtime min average and max values', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$values = [];

	test_override('syslog_db_table_exists', function ($table) {
		return $table === 'syslog_status';
	});

	test_override('syslog_db_fetch_assoc', function () use (&$values) {
		return array_map(function ($name, $value) {
			return ['name' => $name, 'value' => $value, 'updated' => time()];
		}, array_keys($values), $values);
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$values) {
		$values[$params[0]] = $params[1];

		return true;
	});

	expect(syslog_status_record_runtime(2.0))->toBeTrue();
	expect(syslog_status_record_runtime(4.0))->toBeTrue();

	expect($values['polling_runtime_last'])->toBe('4');
	expect($values['polling_runtime_min'])->toBe('2');
	expect($values['polling_runtime_avg'])->toBe('3');
	expect($values['polling_runtime_max'])->toBe('4');
	expect($values['polling_runtime_count'])->toBe('2');
});

it('increments rule processing totals from existing status values', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$values = ['total_alert_rules_processed' => '3'];

	test_override('syslog_db_table_exists', function ($table) {
		return $table === 'syslog_status';
	});

	test_override('syslog_db_fetch_assoc', function () use (&$values) {
		return array_map(function ($name, $value) {
			return ['name' => $name, 'value' => $value, 'updated' => time()];
		}, array_keys($values), $values);
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$values) {
		$values[$params[0]] = $params[1];

		return true;
	});

	expect(syslog_status_set('last_alert_rules_processed', 0))->toBeTrue();
	expect(syslog_status_increment('total_alert_rules_processed', 2))->toBeTrue();

	expect($values['last_alert_rules_processed'])->toBe('0');
	expect($values['total_alert_rules_processed'])->toBe('5');
});

it('serializes last run rule activity with names and counts', function () {
	syslog_load_plugin_source('functions.php');

	$json = syslog_status_rule_activity_json([
		['name' => 'Disk Full', 'count' => 2],
		['name' => 'Drop Noise', 'count' => 15],
	]);

	expect(json_decode($json, true))->toBe([
		['name' => 'Disk Full', 'count' => 2],
		['name' => 'Drop Noise', 'count' => 15],
	]);
});
