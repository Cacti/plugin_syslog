<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * One alert rule that fails to run does not stop the other rules from being evaluated.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function isolation_setup(): void {
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


function isolation_rule(int $id, string $message, int $level = 0): array {
	return [
		'id' => $id, 'name' => 'Rule ' . $id, 'type' => 'sql', 'message' => $message,
		'method' => 1, 'num' => 1, 'level' => $level, 'threshold_window' => 0,
		'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
		'repeat_alert' => 0, 'command' => '', 'severity' => 3, 'body' => '',
		'ticket' => '', 'open_ticket' => '', 'date' => 1,
	];
}


it('still evaluates the other rules when the combined count query fails', function () {
	isolation_setup();

	$good = isolation_rule(33, "message LIKE 'good'");
	$bad  = isolation_rule(34, "message LIKE 'bad' AND (");

	test_override('syslog_db_fetch_assoc', static fn($sql) => str_contains($sql, "enabled = 'on'") ? [$good, $bad] : []);
	test_override('syslog_db_fetch_assoc_prepared', function ($sql) {
		if (str_contains($sql, 'SUM(CASE WHEN')) {
			return false;
		}

		if (str_contains($sql, 'threshold_recovery_queue')) {
			return [];
		}

		return [['seq' => 1, 'host' => 'router1', 'message' => 'good', 'logtime' => '2026-10-16 12:00:00', 'priority_id' => 3, 'facility_id' => 1]];
	});
	test_override('syslog_db_fetch_cell_prepared', function ($sql) {
		if (str_contains($sql, 'GET_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'SELECT COUNT(*)')) {
			return str_contains($sql, "'bad'") ? false : 4;
		}

		return 0;
	});
	test_override('syslog_db_fetch_row_prepared', static fn() => []);
	test_override('syslog_db_execute', static fn() => true);
	test_override('syslog_db_execute_prepared', static fn() => true);
	test_override('sql_save', static fn() => 1);

	$result = syslog_process_alerts(100);

	expect($result['syslog_alerts'])->toBe(2)
		->and($result['syslog_alarms'])->toBe(1);
});
