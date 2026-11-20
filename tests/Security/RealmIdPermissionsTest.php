<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

it('checks Cacti realm IDs and fails closed without a realm', function () {
	syslog_load_plugin_source('includes/functions.php');
	$allowed = [107, 109];
	test_override('db_fetch_assoc_prepared', function ($sql, $params) {
		return match ($params[1] ?? '') {
			'Syslog Administration' => [['id' => 7]],
			'Duplicate Realm' => [['id' => 8], ['id' => 9]],
			default => []
		};
	});
	test_override('is_realm_allowed', function ($id) use (&$allowed) {
		return in_array($id, $allowed, true);
	});

	expect(syslog_realm_allowed('Duplicate Realm'))->toBeTrue('A granted duplicate realm remains usable')
		->and(syslog_saved_search_admin())->toBeTrue()
		->and(syslog_dashboard_admin())->toBeTrue()
		->and(syslog_realm_allowed('Missing Realm'))->toBeFalse();
	$allowed = [];
	expect(syslog_saved_search_admin())->toBeFalse('No implicit filename fallback');
});

it('allows sharing saved searches through either the admin or share realm', function () {
	syslog_load_plugin_source('includes/functions.php');
	$allowed = [109];
	test_override('db_fetch_assoc_prepared', function ($sql, $params) {
		$id = ['Syslog Administration' => 7, 'Share Saved Templates' => 9][$params[1] ?? ''] ?? 0;
		return $id ? [['id' => $id]] : [];
	});
	test_override('is_realm_allowed', function ($id) use (&$allowed) {
		return in_array($id, $allowed, true);
	});

	expect(syslog_saved_search_share())->toBeTrue();
	$allowed = [107];
	expect(syslog_saved_search_share())->toBeTrue();
	$allowed = [];
	expect(syslog_saved_search_share())->toBeFalse();
});

it('caches populated realm lookups by display name without caching permissions', function () {
	syslog_load_plugin_source('includes/functions.php');
	$lookups = [];
	$allowed = [141];
	test_override('db_fetch_assoc_prepared', function ($sql, $params) use (&$lookups) {
		$display = $params[1];
		$lookups[$display] = ($lookups[$display] ?? 0) + 1;
		$id = ['Cached Allowed Realm' => 41, 'Cached Denied Realm' => 42][$display] ?? 0;

		return $id ? [['id' => $id]] : [];
	});
	test_override('is_realm_allowed', function ($id) use (&$allowed) {
		return in_array($id, $allowed, true);
	});

	foreach ([1, 2] as $attempt) {
		expect(syslog_realm_allowed('Cached Allowed Realm'))->toBeTrue()
			->and(syslog_realm_allowed('Cached Denied Realm'))->toBeFalse()
			->and(syslog_realm_allowed('Cached Missing Realm'))->toBeFalse();
	}

	$allowed = [142];
	expect(syslog_realm_allowed('Cached Allowed Realm'))->toBeFalse()
		->and(syslog_realm_allowed('Cached Denied Realm'))->toBeTrue()
		->and($lookups)->toBe([
			'Cached Allowed Realm' => 1,
			'Cached Denied Realm' => 1,
			'Cached Missing Realm' => 2
		]);
});
