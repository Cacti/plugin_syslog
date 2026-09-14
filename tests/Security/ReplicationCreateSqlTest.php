<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #258: syslog_replace_data() must execute the
 * CREATE TABLE statement captured from SHOW CREATE TABLE (not merely log it)
 * during replication sync, and must no-op safely when that SQL is missing.
 */

it('executes the captured CREATE TABLE SQL during replication sync', function () {
	$GLOBALS['issue258_show_create']               = ['Create Table' => 'CREATE TABLE `syslog_alert` (`id` INT NOT NULL)'];
	$GLOBALS['syslog_replace_data_execute_calls']  = [];
	$GLOBALS['syslog_replace_data_prepared_calls'] = [];
	$GLOBALS['issue258_logs']                      = [];

	test_override('db_fetch_row', function ($sql) {
		return $GLOBALS['issue258_show_create'];
	});

	test_override('syslog_db_table_exists', function ($table) {
		return false;
	});

	test_override('syslog_db_execute', function ($sql) {
		$GLOBALS['syslog_replace_data_execute_calls'][] = $sql;

		return true;
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) {
		$GLOBALS['syslog_replace_data_prepared_calls'][] = ['sql' => $sql, 'params' => $params];

		return true;
	});

	test_override('cacti_log', function ($message) {
		$GLOBALS['issue258_logs'][] = $message;
	});

	syslog_load_plugin_source('setup.php');

	$data = [
		['id' => 1, 'hash' => 'abc123', 'name' => 'sample'],
	];

	syslog_replace_data('syslog_alert', $data);

	$executed = $GLOBALS['syslog_replace_data_execute_calls'];

	expect(cacti_sizeof($executed))->toBeGreaterThanOrEqual(2, 'Expected CREATE + TRUNCATE calls to run.');
	expect($executed[0])->toBe('CREATE TABLE `syslog_alert` (`id` INT NOT NULL)', 'Expected CREATE TABLE SQL to be executed from create_sql.');
	expect($executed[1])->toBe('TRUNCATE TABLE syslog_alert', 'Expected TRUNCATE TABLE to run after CREATE TABLE.');

	$prepared = $GLOBALS['syslog_replace_data_prepared_calls'];

	expect(cacti_sizeof($prepared))->toBe(1, 'Expected a single prepared INSERT statement execution.');

	$GLOBALS['syslog_replace_data_execute_calls']  = [];
	$GLOBALS['syslog_replace_data_prepared_calls'] = [];
	$GLOBALS['issue258_show_create']               = false;

	syslog_replace_data('syslog_alert', $data);

	expect(cacti_sizeof($GLOBALS['syslog_replace_data_execute_calls']))->toBe(0, 'Expected no execute calls when CREATE TABLE SQL is unavailable.');
	expect(cacti_sizeof($GLOBALS['syslog_replace_data_prepared_calls']))->toBe(0, 'Expected no prepared inserts when CREATE TABLE SQL is unavailable.');
});
