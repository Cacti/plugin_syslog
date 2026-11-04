<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * A host-level alert rule expression is passed to the database as written.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function host_level_setup(): void {
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


function host_level_rule(int $id, string $message, int $level = 0): array {
	return [
		'id' => $id, 'name' => 'Rule ' . $id, 'type' => 'sql', 'message' => $message,
		'method' => 1, 'num' => 1, 'level' => $level, 'threshold_window' => 0,
		'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
		'repeat_alert' => 0, 'command' => '', 'severity' => 3, 'body' => '',
		'ticket' => '', 'open_ticket' => '', 'date' => 1,
	];
}


it('keeps asterisks inside a host-level rule expression intact', function () {
	host_level_setup();

	$rule = host_level_rule(31, "message REGEXP 'link.*down'", 1);

	test_override('syslog_db_fetch_assoc', static fn($sql) => str_contains($sql, "enabled = 'on'") ? [$rule] : []);

	$queries = [];

	test_override('syslog_db_fetch_assoc_prepared', function ($sql) use (&$queries) {
		$queries[] = $sql;

		if (str_contains($sql, 'SUM(CASE WHEN')) {
			return [['m0' => 3]];
		}

		return [];
	});
	test_override('syslog_db_fetch_cell_prepared', static fn($sql) => str_contains($sql, 'GET_LOCK') ? 1 : 0);
	test_override('syslog_db_fetch_row_prepared', static fn() => []);
	test_override('syslog_db_execute', static fn() => true);
	test_override('syslog_db_execute_prepared', static fn() => true);

	syslog_process_alerts(100);

	$grouped = array_values(array_filter($queries, static fn($sql) => str_contains($sql, 'GROUP BY host')));

	expect($grouped)->toHaveCount(1)
		->and($grouped[0])->toContain("REGEXP 'link.*down'")
		->and($grouped[0])->toStartWith('SELECT host, COUNT(*) AS count');
});
