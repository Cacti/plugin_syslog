<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for a handful of small, pure setup.php functions that had
 * no coverage at all: plugin_syslog_version(), syslog_check_dependencies(),
 * syslog_top_graph_refresh(), and syslog_draw_navigation_text().
 *
 * These are deliberately narrow: this plugin's setup.php also contains
 * much larger functions (plugin_syslog_uninstall(), syslog_connect(),
 * syslog_check_upgrade(), syslog_install_advisor(), etc.) that do real
 * database connection setup, read/write config files, and emit
 * header()/exit flows - those are intentionally left untouched here
 * rather than risk destabilizing this plugin's large, actively-developed
 * existing test suite with speculative new stubs.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	if (function_exists('syslog_test_reset_globals')) {
		syslog_test_reset_globals();
	}
});

it('parses the plugin INFO file into an info array', function () {
	$info = plugin_syslog_version();

	expect($info)->toBeArray();
	expect($info)->toHaveKey('name');
	expect($info)->toHaveKey('version');
	expect($info['name'])->toBe('syslog');
});

it('reports its dependencies as always satisfied', function () {
	expect(syslog_check_dependencies())->toBeTrue();
});

it('passes the refresh value through unchanged', function () {
	expect(syslog_top_graph_refresh(30))->toBe(30);
	expect(syslog_top_graph_refresh(0))->toBe(0);
});

it('adds the syslog breadcrumb entries without disturbing existing ones', function () {
	$nav = syslog_draw_navigation_text(['other.php:' => ['title' => 'Other']]);

	expect($nav)->toHaveKey('other.php:');
	expect($nav)->toHaveKey('syslog.php:');
	expect($nav)->toHaveKey('syslog_removal.php:');
	expect($nav)->toHaveKey('syslog_alerts.php:');
	expect($nav)->toHaveKey('syslog_reports.php:');
});
