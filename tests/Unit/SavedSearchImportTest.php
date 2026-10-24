<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Imported saved search templates are checked like edited ones.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function saved_search_import_run(array $templates): array {
	syslog_load_plugin_source('includes/functions.php');

	$source = plugin_test_read_source('syslog_saved_searches.php');

	if (!preg_match('/^function syslog_saved_search_import\(\) \{.*?^\}/ms', $source, $match)) {
		throw new RuntimeException('Could not extract syslog_saved_search_import');
	}

	if (!function_exists('saved_search_import_under_test')) {
		eval(str_replace('function syslog_saved_search_import()', 'function saved_search_import_under_test()', $match[0]));
	}

	$GLOBALS['db']                         = 'syslogdb';
	$GLOBALS['request']['import_text']     = json_encode(['version' => 1, 'table' => 'syslog_saved_searches', 'templates' => $templates]);
	$_SESSION['sess_user_id']              = 1;

	test_override('syslog_db_fetch_cell_prepared', static fn() => false);
	test_override('syslog_db_column_exists', static fn() => true);
	test_override('syslog_save_item_shares', static fn() => null);

	$saved = [];

	test_override('sql_save', function ($row) use (&$saved) {
		$saved[] = $row;

		return 3;
	});

	saved_search_import_under_test();

	return $saved;
}


it('imports a saved search template that passes the editor checks', function () {
	$saved = saved_search_import_run([['name' => 'Errors', 'search' => 'message contains "error"', 'removal' => 1, 'grouping' => 0]]);

	expect($saved)->toHaveCount(1)
		->and($saved[0]['name'])->toBe('Errors');
});


it('rejects a saved search template whose search does not parse', function () {
	expect(saved_search_import_run([['name' => 'Broken', 'search' => 'host = "unterminated']]))->toBe([]);
});


it('rejects a saved search template with an unknown record type or grouping', function ($removal, $grouping) {
	expect(saved_search_import_run([['name' => 'Odd', 'search' => 'message contains "x"', 'removal' => $removal, 'grouping' => $grouping]]))->toBe([]);
})->with([['abc', 0], [1, 7]]);
