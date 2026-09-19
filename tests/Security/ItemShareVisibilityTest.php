<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for per-user/per-group sharing of dashboards and
 * saved searches: grants must resolve from both direct user rows and group
 * membership, writes must replace the grant rows in full, and visibility
 * must stay closed for anything that was never granted.
 */

it('resolves shared ids from user and group grants', function () {
	syslog_load_plugin_source('functions.php');

	test_override('db_fetch_assoc_prepared', function ($sql, $params) {
		return [['group_id' => '3'], ['group_id' => '5']];
	});

	$_SESSION = ['sess_user_id' => 2];

	expect(syslog_user_group_ids())->toBe([3, 5], 'Group membership resolves to group ids');
	expect(syslog_user_group_ids(7))->toBe([3, 5], 'An explicit user id bypasses the session');

	$captured = [];

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$captured) {
		$captured[] = [$sql, $params];

		return [['id' => '9'], ['id' => '4']];
	});

	$_SESSION = ['sess_user_id' => 1];

	expect(syslog_shared_item_ids('dashboard'))->toBe([9, 4]);

	$sql = $captured[0][0];

	expect(str_contains($sql, 'syslog_dashboards_perm'))->toBeTrue('The dashboard share table is queried');
	expect(str_contains($sql, "type = 'user'"))->toBeTrue('Direct user grants are matched');
	expect(str_contains($sql, "type = 'group'"))->toBeTrue('Group grants are matched');
	expect($captured[0][1])->toBe([1, 3, 5], 'The session user and their groups bind as parameters');

	// The request-scoped cache must answer repeat calls without re-querying.
	$calls = cacti_sizeof($captured);

	expect(syslog_shared_item_ids('dashboard'))->toBe([9, 4]);
	expect(cacti_sizeof($captured))->toBe($calls);
});

it('keeps unknown kinds, anonymous sessions, and ungranted ids out', function () {
	syslog_load_plugin_source('functions.php');

	$queried = false;

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = [], $log = true) use (&$queried) {
		$queried = true;

		return [['id' => '9']];
	});

	$_SESSION = ['sess_user_id' => 1];

	expect(syslog_shared_item_ids('unknown'))->toBe([], 'Unknown item kinds never grant anything');
	expect($queried)->toBeFalse('Unknown item kinds never reach the database');

	$_SESSION = [];

	expect(syslog_shared_item_ids('dashboard'))->toBe([], 'Anonymous sessions see no shares');
	expect($queried)->toBeFalse('Anonymous sessions never reach the database');

	syslog_load_plugin_source('lib/syslog_dashboard.php');

	test_override('get_username', function ($id) {
		return 'tester';
	});

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = [], $log = true) {
		return [['id' => '9']];
	});

	$_SESSION = ['sess_user_id' => 1];

	expect(syslog_dashboard_can_view(['id' => 9, 'user' => 'other', 'is_global' => '']))->toBeTrue('A granted dashboard is visible');
	expect(syslog_dashboard_can_view(['id' => 10, 'user' => 'other', 'is_global' => '']))->toBeFalse('An ungranted dashboard stays hidden');
	expect(syslog_dashboard_can_view(null))->toBeFalse('A missing dashboard is never visible');
});

it('replaces share rows and validates posted selections', function () {
	syslog_load_plugin_source('functions.php');

	$statements = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$statements) {
		$statements[] = [$sql, $params];

		return true;
	});

	syslog_save_item_shares('dashboard', 12, [4, -1, 4], [7]);

	expect(cacti_sizeof($statements))->toBe(3, 'One delete replaces the rows, valid grants insert');
	expect(str_contains($statements[0][0], 'DELETE FROM'))->toBeTrue('Existing grants are cleared first');
	expect($statements[0][1])->toBe([12]);
	expect(str_contains($statements[1][0], 'INSERT INTO'))->toBeTrue('User grants insert one row each');
	expect($statements[1][1])->toBe([12, 'user', 4]);
	expect($statements[2][1])->toBe([12, 'group', 7]);

	syslog_save_item_shares('unknown', 12, [4], []);

	expect(syslog_share_table('unknown'))->toBeNull('Unknown item kinds reject writes');

	test_override('isset_request_var', function ($name) {
		return isset($_REQUEST[$name]);
	});

	test_override('get_nfilter_request_var', function ($name) {
		return $_REQUEST[$name];
	});

	expect(syslog_parse_share_ids('missing'))->toBe([], 'An absent field means no selection');

	$_REQUEST['shared_users'] = ['3', '1', '3', 'x', 0];

	expect(syslog_parse_share_ids('shared_users'))->toBe([3, 1], 'Only unique positive integers survive');

	$shares = ['users' => [], 'groups' => []];

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = [], $log = true) {
		return [['type' => 'user', 'item_id' => '4'], ['type' => 'group', 'item_id' => '7']];
	});

	$shares = syslog_fetch_item_shares('dashboard', 12);

	expect($shares)->toBe(['users' => [['id' => 4]], 'groups' => [['id' => 7]]], 'Grants read back shaped for the multiselects');
});
it('grants everyone through the all sentinel', function () {
	syslog_load_plugin_source('functions.php');

	$captured = [];

	test_override('db_fetch_assoc_prepared', function ($sql, $params = []) {
		return [];
	});

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = [], $log = true) use (&$captured) {
		$captured[] = [$sql, $params];

		return [['id' => '9']];
	});

	$_SESSION = ['sess_user_id' => 2];

	expect(syslog_shared_item_ids('saved_search'))->toBe([9], 'The granted id resolves');

	// An 'all' row must be part of the same query, with no extra binds.
	expect(str_contains($captured[0][0], "type = 'all'"))->toBeTrue('The everyone grant is matched');
	expect($captured[0][1])->toBe([2], 'Only the session user binds for the user clause');

	$statements = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params = []) use (&$statements) {
		$statements[] = [$sql, $params];

		return true;
	});

	syslog_save_item_shares('dashboard', 12, ['all'], [7]);

	expect(cacti_sizeof($statements))->toBe(3, 'The all grant writes one row alongside the others');
	expect($statements[1][1])->toBe([12, 'all', 0], 'The all grant stores a single item_id 0 row');
	expect($statements[2][1])->toBe([12, 'group', 7], 'Group grants still store normally');

	syslog_save_item_shares('dashboard', 13, ['all', 'all'], []);

	expect(cacti_sizeof($statements))->toBe(5, 'Duplicate all sentinels collapse to one row');
	expect($statements[4][1])->toBe([13, 'all', 0], 'No user rows follow an all-only selection');

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = [], $log = true) {
		return [['type' => 'all', 'item_id' => '0']];
	});

	$shares = syslog_fetch_item_shares('saved_search', 5);

	expect($shares)->toBe(['users' => [['id' => 'all']], 'groups' => [['id' => 'all']]], 'The all grant reads back into both selects');

	test_override('isset_request_var', function ($name) {
		return isset($_REQUEST[$name]);
	});

	test_override('get_nfilter_request_var', function ($name) {
		return $_REQUEST[$name];
	});

	$_REQUEST['shared_users'] = ['all', '3', 'all', 'x', 0];

	expect(syslog_parse_share_ids('shared_users'))->toBe(['all', 3], 'The all sentinel survives parsing and deduplication');
});
