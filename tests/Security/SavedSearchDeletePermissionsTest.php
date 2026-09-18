<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for saved-search deletion authorization: only the
 * owner, or an administrator deleting a shared (is_global) search, may
 * delete a saved search template.
 */

it('only allows the owner or an admin to delete a shared saved search', function () {
	// Real syslog_saved_search_admin() (from functions.php) delegates to
	// api_plugin_user_realm_auth(), which is overridden below; loading it
	// here (rather than faking syslog_saved_search_admin directly) keeps
	// this test correct regardless of what other test files already loaded.
	syslog_load_plugin_source('functions.php');

	$source = plugin_test_read_source('syslog.php');
	$start  = strpos($source, 'function saved_search_delete()');
	$end    = strpos($source, 'function saved_search_global()', $start);

	if ($start === false || $end === false) {
		throw new RuntimeException('Unable to isolate saved_search_delete()');
	}

	eval(substr($source, $start, $end - $start));

	test_override('get_username', function ($id) {
		return 'tester';
	});

	test_override('get_filter_request_var', function ($key, $filter) {
		return 7;
	});

	test_override('get_request_var', function ($key) {
		return 'syslog';
	});

	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) {
		return $GLOBALS['row'];
	});

	test_override('api_plugin_user_realm_auth', function ($file) {
		return $GLOBALS['admin'];
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) {
		$GLOBALS['writes']++;

		return true;
	});

	test_override('kill_session_var', function ($key) {
		unset($_SESSION[$key]);
	});

	test_override('__', function ($text, ...$args) {
		return $text;
	});

	$GLOBALS['syslogdb_default'] = 'syslog';

	foreach ([false, true] as $admin) {
		foreach (['', 'on'] as $global) {
			foreach (['tester', 'someone-else'] as $owner) {
				$_SESSION = ['sess_user_id' => 2];
				$GLOBALS['row']    = ['user' => $owner, 'is_global' => $global];
				$GLOBALS['admin']  = $admin;
				$GLOBALS['writes'] = 0;

				$result  = json_decode(saved_search_delete(), true);
				$allowed = $owner === 'tester' || ($global === 'on' && $admin);

				// An allowed delete also clears the share rows, so count the
				// saved-searches statement rather than an exact total.
				if (($GLOBALS['writes'] > 0) !== $allowed || isset($result['error']) === $allowed) {
					throw new RuntimeException('Deletion permissions do not match ownership and administration');
				}
			}
		}
	}

	expect(true)->toBeTrue();
});
