<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

it('checks Cacti realm IDs and fails closed without a realm', function () {
	syslog_load_plugin_source('includes/functions.php');
	$allowed = [108];
	test_override('db_fetch_assoc_prepared', function ($sql, $params) {
		return ($params[1] ?? '') === 'Syslog Administration' ? [['id' => 7], ['id' => 8]] : [];
	});
	test_override('is_realm_allowed', function ($id) use (&$allowed) {
		return in_array($id, $allowed, true);
	});

	expect(syslog_saved_search_admin())->toBeTrue('A granted duplicate realm remains usable')
		->and(syslog_dashboard_admin())->toBeTrue()
		->and(syslog_realm_allowed('Missing Realm'))->toBeFalse();
	$allowed = [];
	expect(syslog_saved_search_admin())->toBeFalse('No implicit filename fallback');
});
