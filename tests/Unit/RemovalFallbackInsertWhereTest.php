<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The failed-compile fallback in syslog_remove_items(): when the transferal
 * (INSERT JOIN) shape of a filter rule cannot compile, the rule contributes
 * no staging deletes but processing continues with an empty WHERE.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

it('continues with an empty insert shape when the filter cannot compile', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];

	$rule = [
		'id' => 5, 'name' => 'Broken', 'type' => 'filter', 'message' => '{not json',
		'method' => 1, 'num' => 1, 'enabled' => 'on', 'date' => 1
	];

	test_override('syslog_db_fetch_assoc', function ($sql) use ($rule) {
		if (str_contains($sql, '`syslog_remove`')) {
			return [$rule];
		}

		return [];
	});

	test_override('syslog_db_fetch_cell_prepared', static fn() => 0);
	test_override('syslog_status_set', static fn() => true);
	test_override('syslog_status_increment', static fn() => true);

	$executed = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$executed) {
		$executed[] = $sql;

		return true;
	});

	syslog_remove_items('syslog_incoming', 100);

	// No DELETE/INSERT ran with the broken rule: the compiled shapes never
	// produced a WHERE fragment.
	$deleting = array_values(array_filter($executed, fn ($sql) => stripos($sql, 'DELETE FROM `syslog') !== false || stripos($sql, 'INSERT INTO `syslog_incoming`') !== false));

	expect($deleting)->toBe([]);
});
