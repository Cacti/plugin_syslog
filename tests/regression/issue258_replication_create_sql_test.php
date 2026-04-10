<?php

$existing_functions = [
	'db_fetch_row',
	'syslog_db_table_exists',
	'syslog_db_execute',
	'syslog_db_execute_prepared'
];

foreach ($existing_functions as $existing_function) {
	if (function_exists($existing_function)) {
		fwrite(STDERR, "This test must run in isolated mode; function already exists: $existing_function\n");
		exit(1);
	}
}

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($value) {
		if (is_array($value) || $value instanceof Countable) {
			return count($value);
		}

		return 0;
	}
}

$GLOBALS['syslog_replace_data_execute_calls']  = [];
$GLOBALS['syslog_replace_data_prepared_calls'] = [];
$GLOBALS['issue258_logs']                      = [];
$GLOBALS['issue258_show_create']               = ['Create Table' => 'CREATE TABLE `syslog_alert` (`id` INT NOT NULL)'];

if (!function_exists('db_fetch_row')) {
	function db_fetch_row($sql) {
		return $GLOBALS['issue258_show_create'];
	}
}

if (!function_exists('syslog_db_table_exists')) {
	function syslog_db_table_exists($table) {
		return false;
	}
}

if (!function_exists('syslog_db_execute')) {
	function syslog_db_execute($sql) {
		$GLOBALS['syslog_replace_data_execute_calls'][] = $sql;

		return true;
	}
}

if (!function_exists('syslog_db_execute_prepared')) {
	function syslog_db_execute_prepared($sql, $params) {
		$GLOBALS['syslog_replace_data_prepared_calls'][] = [
			'sql'    => $sql,
			'params' => $params
		];

		return true;
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $facility = 'SYSTEM', $level = '') {
		$GLOBALS['issue258_logs'][] = $message;
	}
}

require_once dirname(__DIR__, 2) . '/setup.php';

$data = [
	[
		'id'   => 1,
		'hash' => 'abc123',
		'name' => 'sample'
	]
];

syslog_replace_data('syslog_alert', $data);

$executed = $GLOBALS['syslog_replace_data_execute_calls'];

if (cacti_sizeof($executed) < 2) {
	fwrite(STDERR, "Expected CREATE + TRUNCATE calls to run.\n");
	exit(1);
}

if ($executed[0] !== 'CREATE TABLE `syslog_alert` (`id` INT NOT NULL)') {
	fwrite(STDERR, "Expected CREATE TABLE SQL to be executed from create_sql.\n");
	exit(1);
}

if ($executed[1] !== 'TRUNCATE TABLE syslog_alert') {
	fwrite(STDERR, "Expected TRUNCATE TABLE to run after CREATE TABLE.\n");
	exit(1);
}

$prepared = $GLOBALS['syslog_replace_data_prepared_calls'];

if (cacti_sizeof($prepared) !== 1) {
	fwrite(STDERR, "Expected a single prepared INSERT statement execution.\n");
	exit(1);
}

$GLOBALS['syslog_replace_data_execute_calls']  = [];
$GLOBALS['syslog_replace_data_prepared_calls'] = [];
$GLOBALS['issue258_show_create']               = false;

syslog_replace_data('syslog_alert', $data);

if (cacti_sizeof($GLOBALS['syslog_replace_data_execute_calls']) !== 0) {
	fwrite(STDERR, "Expected no execute calls when CREATE TABLE SQL is unavailable.\n");
	exit(1);
}

if (cacti_sizeof($GLOBALS['syslog_replace_data_prepared_calls']) !== 0) {
	fwrite(STDERR, "Expected no prepared inserts when CREATE TABLE SQL is unavailable.\n");
	exit(1);
}

print "issue258_replication_create_sql_test passed\n";
