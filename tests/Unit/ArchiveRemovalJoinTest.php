<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Archiving removal rules on host and program filter the staging rows before the reference tables are joined.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function archive_join_setup(): void {
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


it('filters staging rows before joining the reference tables for archiving rules', function ($type) {
	archive_join_setup();

	$rule = ['id' => 5, 'name' => 'Archive', 'type' => $type, 'message' => 'router1', 'method' => 'trans', 'enabled' => 'on'];

	test_override('syslog_db_fetch_assoc', static fn($sql) => str_contains($sql, '`syslog_remove`') ? [$rule] : []);

	$executed = [];

	test_override('syslog_db_execute', static fn() => true);
	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$executed) {
		$executed[] = [$sql, $params];

		return true;
	});
	test_override('db_affected_rows', static fn() => 1);

	syslog_remove_items('syslog_incoming', 100);

	$insert = array_values(array_filter($executed, static fn($call) => str_contains($call[0], 'INSERT INTO `syslogdb`.`syslog_removed`')));
	$column = $type === 'host' ? 'host' : 'program';

	expect($insert)->toHaveCount(1)
		->and($insert[0][0])->toContain("FROM (SELECT * FROM `syslogdb`.`syslog_incoming` WHERE `$column` = ?")
		->and($insert[0][0])->not->toMatch('/AS sp\s+ON sp\.program = si\.program\s+WHERE/')
		->and($insert[0][1])->toBe(['router1', 100]);
})->with(['host', 'program']);
