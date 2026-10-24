<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Threshold alerts list at most the configured number of messages.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function row_limit_setup(): void {
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


function row_limit_rule(int $id, string $message, int $level = 0): array {
	return [
		'id' => $id, 'name' => 'Rule ' . $id, 'type' => 'sql', 'message' => $message,
		'method' => 1, 'num' => 1, 'level' => $level, 'threshold_window' => 0,
		'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
		'repeat_alert' => 0, 'command' => '', 'severity' => 3, 'body' => '',
		'ticket' => '', 'open_ticket' => '', 'date' => 1,
	];
}


it('limits threshold alert detail rows to the configured maximum', function () {
	row_limit_setup();

	test_override('read_config_option', static fn($key) => ['syslog_html' => 'on', 'syslog_maxrecords' => 2][$key] ?? '');

	$rows = [];

	foreach (['hosta', 'hostb', 'hostc', 'hostd'] as $host) {
		$rows[] = ['seq' => 1, 'host' => $host, 'message' => 'error', 'logtime' => '2026-01-01 00:00:00', 'priority_id' => 3, 'facility_id' => 1];
	}

	test_override('syslog_db_fetch_assoc_prepared', static fn() => $rows);

	$saved = [];

	test_override('sql_save', function ($row) use (&$saved) {
		$saved[] = $row;

		return 1;
	});

	$rule = row_limit_rule(35, 'error');

	$rule['num'] = 2;

	expect(syslog_process_alert($rule, 'SELECT * FROM syslog_incoming', [], 4))->toBe(1);

	expect($saved[0]['html'])->toContain('hosta', 'hostb')
		->not->toContain('hostc')
		->not->toContain('hostd');
});
