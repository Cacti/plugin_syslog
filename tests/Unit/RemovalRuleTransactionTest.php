<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #263: removal rules that archive/move rows
 * must not leave copied rows behind if the paired delete fails.
 */

it('wraps syslog_remove_items archive and delete operations in a transaction', function () {
	$functions = plugin_test_read_source('functions.php');

	if (!preg_match('/function\s+syslog_remove_items\s*\(\s*\$table\s*,\s*\$max_seq\s*\)\s*\{(.+?)\nfunction\s+syslog_log_row_color/s', $functions, $match)) {
		throw new RuntimeException('syslog_remove_items function not found.');
	}

	$body = $match[1];

	expect(substr_count($body, "syslog_db_execute('START TRANSACTION')"))->toBeGreaterThanOrEqual(1);
	expect(substr_count($body, "syslog_db_execute('ROLLBACK')"))->toBeGreaterThanOrEqual(3);
	expect(substr_count($body, "syslog_db_execute('COMMIT')"))->toBeGreaterThanOrEqual(1);
	expect(strpos($body, '$xferred += $messages_xferred'))->toBeGreaterThan(strpos($body, "syslog_db_execute('COMMIT')"));
	expect(strpos($body, '$removed += $messages_removed'))->toBeGreaterThan(strpos($body, "syslog_db_execute('COMMIT')"));
});

it('wraps syslog_manage_items move and delete operations in a transaction', function () {
	$functions = plugin_test_read_source('functions.php');

	if (!preg_match('/function\s+syslog_manage_items\s*\(\s*\$from_table\s*,\s*\$to_table\s*\)\s*\{(.+?)\nfunction\s+get_hash_syslog/s', $functions, $match)) {
		throw new RuntimeException('syslog_manage_items function not found.');
	}

	$body = $match[1];

	expect(substr_count($body, "syslog_db_execute('START TRANSACTION')"))->toBeGreaterThanOrEqual(1);
	expect(substr_count($body, "syslog_db_execute('ROLLBACK')"))->toBeGreaterThanOrEqual(3);
	expect(substr_count($body, "syslog_db_execute('COMMIT')"))->toBeGreaterThanOrEqual(1);
	expect(strpos($body, '$xferred += $messages_moved'))->toBeGreaterThan(strpos($body, "syslog_db_execute('COMMIT')"));
});
