<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Imported alert rules are checked like edited ones and cannot choose the row id.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

function alert_import_setup(): void {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['config']['poller_id']    = 1;
	$GLOBALS['config']['base_path']    = dirname(__DIR__) . '/Fixtures/threshold_notifications';
	$GLOBALS['severities']             = [3 => 'Error'];
	$GLOBALS['syslog_levels']          = [3 => 'Error'];
	$GLOBALS['syslog_facilities']      = [1 => 'User'];
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];
}

function alert_import_run(array $templates, array $dependencies = []): array {
	alert_import_setup();

	$import = syslog_test_extract_function('syslog_alerts.php', 'alert_import');

	$GLOBALS['request']['import_text'] = json_encode(['version' => 1, 'table' => 'syslog_alert', 'templates' => $templates]);

	test_override('db_fetch_assoc_prepared', static fn($sql) => str_contains($sql, 'plugin_realms') ? [['id' => 2]] : []);
	test_override('syslog_db_column_exists', static fn() => true);
	test_override('syslog_db_fetch_cell_prepared', static fn($sql, $params) => ($params[0] ?? '') === 'known-hash' ? 9 : false);
	test_override('syslog_db_fetch_assoc_prepared', $dependencies['query'] ?? static fn() => []);

	$saved = [];

	test_override('sql_save', function ($row) use (&$saved) {
		$saved[] = $row;

		return 1;
	});

	$import();

	return $saved;
}

it('does not let an imported file choose the row id', function () {
	$saved = alert_import_run([[
		'hash' => 'known-hash', 'id' => 77, 'name' => 'Imported', 'severity' => 3, 'method' => 0,
		'type' => 'messagec', 'message' => 'error',
	]]);

	expect($saved)->toHaveCount(1)
		->and((int) $saved[0]['id'])->toBe(9);
});

it('rejects an imported alert whose query does not run', function () {
	$saved = alert_import_run([[
		'name' => 'Broken', 'severity' => 3, 'method' => 0, 'type' => 'sql', 'message' => 'message LIKE (',
	]], ['query' => static fn() => false]);

	expect($saved)->toBe([]);
});

it('rejects an imported alert without a usable match type', function () {
	$saved = alert_import_run([[
		'name' => 'Untyped', 'severity' => 3, 'method' => 0, 'message' => 'error',
	]]);

	expect($saved)->toBe([]);
});

it('imports an alert whose query runs', function () {
	$saved = alert_import_run([[
		'name' => 'Fine', 'severity' => 3, 'method' => 0, 'type' => 'messagec', 'message' => 'error',
	]]);

	expect($saved)->toHaveCount(1)
		->and($saved[0]['name'])->toBe('Fine');
});
