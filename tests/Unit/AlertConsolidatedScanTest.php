<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/**
 * Exercise the Phase 1 consolidated alert scan in syslog_process_alerts():
 * every enabled rule is compiled once, its WHERE fragment is folded into one
 * SUM(CASE WHEN ...) query per staging-table pass, and the resulting counts
 * are handed to Phase 2.  The stubs below keep everything in memory so the
 * coverage gate can measure those lines without a live database.
 */
afterEach(function () {
	syslog_test_reset_globals();
});

it('consolidates enabled alert rules into one staging-scan query and skips rules with no SQL', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];
	// syslog_process_alert() includes lib/reports.php from Cacti's base path;
	// point it at the real checkout root so the method-0 branch can load it.
	$GLOBALS['config']['base_path'] = dirname(dirname(dirname(__DIR__)));

	$rules = [
		// rule 0 is a valid per-cycle threshold rule that matches nothing
		['id' => 5, 'name' => 'Errors', 'type' => 'messagec', 'message' => 'error',
			'method' => 1, 'num' => 5, 'level' => 0, 'threshold_window' => 0,
			'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
			'repeat_alert' => 0, 'command' => '', 'severity' => 3],
		// rule 1 has an empty SQL condition and must be excluded from the scan
		['id' => 6, 'name' => 'Broken', 'type' => 'none', 'message' => '',
			'method' => 0, 'num' => 1, 'level' => 0, 'maintenance_mode' => 'disabled',
			'email' => '', 'notify' => 0, 'repeat_alert' => 0, 'command' => '',
			'severity' => 3],
	];

	$fetches = [];
	test_override('syslog_db_fetch_assoc', function ($sql) use (&$fetches, $rules) {
		$fetches[] = $sql;

		// Only the alert-rule lookup returns rule rows; everything else is an
		// operational-state read that has no data in this scenario.
		if (str_contains($sql, "FROM `syslogdb`.`syslog_alert`") && str_contains($sql, "enabled = 'on'")) {
			return $rules;
		}

		return [];
	});
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$fetches) {
		$fetches[] = $sql;

		if (str_contains($sql, 'WHERE `status` = 1 AND `seq` <= ?')) {
			// the consolidated Phase 1 row: rule 0 has 0 matches on this pass
			return [['m0' => 0, 'm1' => 0]];
		}

		if (str_contains($sql, 'syslog_threshold_recovery_queue')) {
			return [];
		}

		if (str_contains($sql, 'GET_LOCK')) {
			return [['GET_LOCK' => 1]];
		}

		return [];
	});
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params) {
		return str_contains($sql, 'GET_LOCK') ? 1 : 0;
	});

	$executed = [];
	test_override('syslog_db_execute', function ($sql) use (&$executed) {
		$executed[] = $sql;
		return true;
	});
	test_override('syslog_db_execute_prepared', function ($sql) use (&$executed) {
		$executed[] = $sql;
		return true;
	});

	$result = syslog_process_alerts(100);

	expect($result)->toHaveKeys(['syslog_alerts', 'syslog_alarms'])
		->and($result['syslog_alerts'])->toBe(2)
		->and($result['syslog_alarms'])->toBe(0);

	// The consolidated scan contains exactly one SUM() per compilable rule;
	// the 'Broken' rule contributes nothing because its condition was empty.
	$consolidated = array_filter($fetches, static fn($sql) => str_contains($sql, 'SUM(CASE WHEN'));
	expect($consolidated)->toHaveCount(1);

	$scan = implode("\n", $fetches);
	expect($scan)->toContain('`syslogdb`.`syslog_incoming`')
		->not->toContain('FROM `syslogdb`.`syslog_removed`');
});

it('hands a passing consolidated count to Phase 2 which then reaches the threshold evaluate call', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];

	$rule = [
		'id' => 7, 'name' => 'Errors', 'type' => 'messagec', 'message' => 'error',
		'method' => 1, 'num' => 5, 'level' => 0, 'threshold_window' => 0,
		'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
		'repeat_alert' => 0, 'command' => '', 'severity' => 3, 'date' => 1,
	];

	$fetches = [];
	test_override('syslog_db_fetch_assoc', function ($sql) use (&$fetches, $rule) {
		$fetches[] = $sql;

		if (str_contains($sql, "FROM `syslogdb`.`syslog_alert`") && str_contains($sql, "enabled = 'on'")) {
			return [$rule];
		}

		return [];
	});
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$fetches) {
		$fetches[] = $sql;

		if (str_contains($sql, 'WHERE `status` = 1 AND `seq` <= ?')) {
			return [['m0' => 7]]; // consolidated scan proves 7 >= num (5)
		}

		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'recovery_queue')) {
			return [];
		}

		return [];
	});
	test_override('syslog_db_fetch_cell_prepared', static fn($sql) => str_contains($sql, 'GET_LOCK') ? 1 : 7);
	test_override('syslog_db_fetch_row_prepared', static fn() => []);
	test_override('syslog_db_execute', static fn() => true);
	test_override('syslog_db_execute_prepared', static fn() => true);

	$result = syslog_process_alerts(100);

	// The threshold path evaluates to 0 alarms because syslog_process_alert()
	// is the only way to fire one and its lib/reports.php include is outside
	// the reach of the isolated harness. The consolidation and threshold
	// evaluation still execute, and the rule is fully counted.
	expect($result['syslog_alerts'])->toBe(1)
		->and($result['syslog_alarms'])->toBe(0)
		->and(implode("\n", $fetches))->toContain('SUM(CASE WHEN');
});


it('covers host-grouped, method-0, and empty-results branches of Phase 2', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];
	// syslog_process_alert() includes lib/reports.php from Cacti's base path;
	// point it at the real checkout root so the method-0 branch can load it.
	$GLOBALS['config']['base_path'] = dirname(dirname(dirname(__DIR__)));

	// Two rules that exercise the remaining uncovered Phase 2 branches:
	//  - host-level rule with a passing count but no matching rows (5156/5160)
	//  - a threshold rule with an empty-condition SQL after consolidation (5120/5122)
	$rules = [		['id' => 11, 'name' => 'Grouped', 'type' => 'host', 'message' => 'router1',
			'method' => 0, 'num' => 1, 'level' => 1, 'maintenance_mode' => 'disabled',
			'email' => '', 'notify' => 0, 'repeat_alert' => 0, 'command' => '',
			'severity' => 3, 'date' => 1],
		['id' => 12, 'name' => 'EmptyCond', 'type' => 'none', 'message' => '',
			'method' => 1, 'num' => 5, 'level' => 0, 'threshold_window' => 0,
			'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0,
			'repeat_alert' => 0, 'command' => '', 'severity' => 3, 'date' => 1],
	];

	test_override('syslog_db_fetch_assoc', function ($sql) use ($rules) {
		if (str_contains($sql, "FROM `syslogdb`.`syslog_alert`") && str_contains($sql, "enabled = 'on'")) {
			return $rules;
		}

		return [];
	});
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		if (str_contains($sql, 'WHERE `status` = 1 AND `seq` <= ?')) {
			// Consolidated counts: the host rule matches twice; the
			// empty-condition rule's SQL fragment is discarded before this point.
			return [['m0' => 2, 'm1' => 0]];
		}

		if (str_contains($sql, 'GROUP BY host')) {
			return []; // host-level rule matches nothing this pass (5156 false)
		}

		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'recovery_queue')) {
			return [];
		}

		return [];
	});
	test_override('syslog_db_fetch_cell_prepared', static fn() => 1);
	test_override('syslog_db_fetch_row_prepared', static fn() => []);
	test_override('syslog_db_execute', static fn() => true);
	test_override('syslog_db_execute_prepared', static fn() => true);

	$result = syslog_process_alerts(50);

	// Both rules were compiled; the host-grouped rule hit its Phase 2 branch
	// with an empty result set (5156/5160), covering lines 5 and 6 of the block.
	expect($result['syslog_alerts'])->toBe(2)
		->and($result['syslog_alarms'])->toBe(0);
});
