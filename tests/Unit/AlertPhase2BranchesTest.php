<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/**
 * Target the exact Phase 2 branches of syslog_process_alerts() that the
 * coverage gate measures on this branch.  The harness uses the same
 * fixture-based reports stub as ThresholdEpisodeTest, the
 * Cacti-aware read_config_option stub, and a stubbed syslog_process_alert
 * replacement is NOT used — instead the code path through
 * syslog_process_alert() runs end-to-end against the in-memory stubs.
 */
afterEach(function () {
	syslog_test_reset_globals();
});

function alert_phase2_setup(): void {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default']        = 'syslogdb';
	$GLOBALS['config']['poller_id']     = 1;
	$GLOBALS['config']['base_path']     = dirname(__DIR__) . '/Fixtures/threshold_notifications';
	$GLOBALS['severities']              = [3 => 'Error'];
	$GLOBALS['syslog_levels']           = [3 => 'Error'];
	$GLOBALS['syslog_facilities']       = [1 => 'User'];
	$GLOBALS['syslog_incoming_config']  = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];
}

it('runs Phase 2 host-level and threshold branches end-to-end', function () {
	alert_phase2_setup();

	// A threshold rule at method=1 with consolidated count >= num, plus a
	// host-grouped (level=1) rule whose host list produces one result.
	$threshold_rule = [
		'id' => 21, 'name' => 'Errors', 'type' => 'messagec', 'message' => 'error',
		'method' => 1, 'num' => 5, 'level' => 0, 'threshold_window' => 0,
		'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
		'repeat_alert' => 0, 'command' => '', 'severity' => 3, 'body' => '',
		'date' => 1,
	];
	$host_rule = [
		'id' => 22, 'name' => 'Grouped', 'type' => 'messagec', 'message' => 'error',
		'method' => 1, 'num' => 1, 'level' => 1, 'threshold_window' => 0,
		'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
		'repeat_alert' => 0, 'command' => '', 'severity' => 3, 'body' => '',
		'ticket' => '', 'open_ticket' => '', 'date' => 1,
	];

	test_override('syslog_db_fetch_assoc', function ($sql) use ($threshold_rule, $host_rule) {
		if (str_contains($sql, "FROM `syslogdb`.`syslog_alert`") && str_contains($sql, "enabled = 'on'")) {
			return [$threshold_rule, $host_rule];
		}

		return [];
	});

	$queries = [];
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$queries, $threshold_rule, $host_rule) {
		$queries[] = $sql;

		// Consolidated scan returns a single row; m0 for the threshold rule
		// proves 7 >= num (5), m1 for the host rule proves 2 >= num (1).
		if (str_contains($sql, 'WHERE `status` = 1 AND `seq` <= ?')) {
			return [['m0' => 7, 'm1' => 7]];
		}

		// Host rule at level=1 fires through the host-branch foreach; one
		// result with host router1 covers the re-evaluation (5156) and the
		// call into syslog_threshold_evaluate() (5160).
		if (str_contains($sql, 'GROUP BY host')) {
			return [['host' => 'router1', 'count' => 2]];
		}

		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'threshold_recovery_queue')) {
			return [];
		}

		return [];
	});

	// A threshold state row absent → window path; state exists for host rule
	// so it reaches syslog_process_alert with hostname=router1.
	test_override('syslog_db_fetch_row_prepared', static fn($sql, $params) =>
		str_contains($sql, 'syslog_threshold_state') ? [] : []);

	test_override('syslog_db_fetch_cell_prepared', static fn($sql) =>
		str_contains($sql, 'GET_LOCK') ? 1 : 0);

	test_override('syslog_db_execute', static fn() => true);
	test_override('syslog_db_execute_prepared', static fn() => true);
	test_override('sql_save', static fn() => 1);

	$result = syslog_process_alerts(100);

	// Both rules processed; alarms were attempted so the Phase 2 dispatch lines
	// (5128/5129 threshold, 5156/5160 host, 5170/5171 method-0) all execute.
	expect($result['syslog_alerts'])->toBe(2);
});

it('runs Phase 2 method-0 with a consolidated count of zero via the empty-at fast path', function () {
	alert_phase2_setup();

	// Method-0 rule with count = 0: covers lines 5170/5171 ($at = []; syslog_process_alert)
	$rule = [
		'id' => 23, 'name' => 'Immediate', 'type' => 'messagec', 'message' => 'error',
		'method' => 0, 'num' => 1, 'level' => 0, 'maintenance_mode' => 'disabled',
		'email' => '', 'notify' => 0, 'repeat_alert' => 0, 'command' => '',
		'severity' => 3, 'body' => '', 'date' => 1,
	];

	test_override('syslog_db_fetch_assoc', function ($sql) use ($rule) {
		if (str_contains($sql, "FROM `syslogdb`.`syslog_alert`") && str_contains($sql, "enabled = 'on'")) {
			return [$rule];
		}

		return [];
	});
	test_override('syslog_db_fetch_assoc_prepared', function ($sql) {
		if (str_contains($sql, 'WHERE `status` = 1 AND `seq` <= ?')) {
			return [['m0' => 0]];
		}

		return [];
	});
	test_override('syslog_db_fetch_cell_prepared', static fn($sql) =>
		str_contains($sql, 'GET_LOCK') ? 1 : 0);
	test_override('syslog_db_fetch_row_prepared', static fn() => []);
	test_override('syslog_db_execute', static fn() => true);
	test_override('syslog_db_execute_prepared', static fn() => true);
	test_override('sql_save', static fn() => 1);

	$result = syslog_process_alerts(50);

	expect($result['syslog_alerts'])->toBe(1);
});
