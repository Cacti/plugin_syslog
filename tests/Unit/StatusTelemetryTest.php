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
