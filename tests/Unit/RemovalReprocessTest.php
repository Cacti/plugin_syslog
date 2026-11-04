<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Reprocessing runs only the selected removal rule.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function reprocess_setup(): void {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['config']['poller_id']    = 1;
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];

	test_override('syslog_db_fetch_cell_prepared', static fn() => 0);
	test_override('syslog_status_set', static fn() => true);
	test_override('syslog_status_increment', static fn() => true);
}


it('reprocesses only the selected removal rule', function () {
	reprocess_setup();

	$rule  = ['id' => 5, 'name' => 'Only', 'type' => 'messagec', 'message' => 'noise', 'method' => 'del', 'enabled' => 'on'];
	$asked = [];

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$asked, $rule) {
		$asked[] = [$sql, $params];

		return [$rule];
	});

	$unfiltered = [];

	test_override('syslog_db_fetch_assoc', function ($sql) use (&$unfiltered) {
		$unfiltered[] = $sql;

		return [];
	});

	$deleted = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$deleted) {
		if (str_starts_with($sql, 'DELETE FROM')) {
			$deleted[] = $params;
		}

		return true;
	});
	test_override('db_affected_rows', static fn() => 3);

	expect(syslog_remove_items('syslog', 0, 5))->toBe(['removed' => 3, 'xferred' => 0]);
	expect($asked)->toHaveCount(1)
		->and($asked[0][0])->toContain('AND id = ?')
		->and($asked[0][1])->toBe([5])
		->and($deleted)->toBe([['%noise%']])
		->and(array_filter($unfiltered, static fn($sql) => str_contains($sql, '`syslog_remove`')))->toBe([]);
});
