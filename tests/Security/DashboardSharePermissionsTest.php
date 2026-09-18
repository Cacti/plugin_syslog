<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the dashboard share toggle: making a dashboard
 * global requires the Share Dashboards permission, and only the owner or
 * an administrator may change the flag of an existing dashboard.
 */

it('only allows the owner or an admin to toggle a dashboard share', function () {
	// Real syslog_dashboard_admin() and syslog_dashboard_share() delegate to
	// api_plugin_user_realm_auth(), which is overridden below; loading them
	// here keeps this test correct regardless of what other test files
	// already loaded.
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	test_override('get_username', function ($id) {
		return 'tester';
	});

	test_override('get_filter_request_var', function ($key, $filter) {
		return 7;
	});

	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) {
		return $GLOBALS['row'];
	});

	test_override('api_plugin_user_realm_auth', function ($file) {
		return $GLOBALS['admin'] || ($GLOBALS['share'] && $file === 'syslog_dashboards_share.php');
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) {
		$GLOBALS['writes']++;

		return true;
	});

	test_override('__', function ($text, ...$args) {
		return $text;
	});

	$GLOBALS['syslogdb_default'] = 'syslog';

	foreach ([false, true] as $admin) {
		foreach ([false, true] as $share) {
			foreach (['', 'on'] as $global) {
				foreach (['tester', 'someone-else'] as $owner) {
					$_SESSION          = ['sess_user_id' => 2];
					$GLOBALS['row']    = ['id' => 7, 'user' => $owner, 'is_global' => $global];
					$GLOBALS['admin']  = $admin;
					$GLOBALS['share']  = $share;
					$GLOBALS['writes'] = 0;

					$result = json_decode(syslog_dashboard_global(), true);
					// Sharing a foreign dashboard additionally requires the
					// admin realm; owners only need the share permission.
					$allowed = ($owner === 'tester' && ($admin || $share))
						|| ($owner !== 'tester' && $admin);

					if ($GLOBALS['writes'] !== (int) $allowed || isset($result['error']) === $allowed) {
						throw new RuntimeException('Share permissions do not match ownership and permission');
					}
				}
			}
		}
	}

	expect(true)->toBeTrue();
});