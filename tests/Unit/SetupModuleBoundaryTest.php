<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('keeps setup.php limited to its compatibility facade', function () {
	$setup = plugin_test_read_source('setup.php');

	preg_match_all('/^function\s+([a-zA-Z0-9_]+)\s*\(/m', $setup, $matches);

	$allowed = [
		'plugin_syslog_install',
		'syslog_execute_update',
		'plugin_syslog_uninstall',
		'plugin_syslog_check_config',
		'plugin_syslog_upgrade',
		'syslog_connect',
		'syslog_upgrade_saved_search_realm',
		'syslog_upgrade_dashboard_realm',
		'syslog_upgrade_device_rule_realm',
		'syslog_upgrade_rule_permissions',
		'syslog_upgrade_consolidate_rule_realms',
		'syslog_upgrade_create_permission_realms',
		'syslog_check_upgrade',
		'plugin_syslog_version',
		'syslog_check_dependencies',
	];

	expect($matches[1])->toEqualCanonicalizing($allowed);
});

it('keeps config-array dependencies in the directly loaded settings module', function () {
	$settings = plugin_test_read_source('includes/settings.php');

	expect($settings)->toContain('function syslog_refresh_permission_roles(): void');
});

it('boots the compatibility facade in callback modules that use its functions', function () {
	foreach (['includes/settings.php', 'includes/navigation.php', 'includes/processing.php', 'includes/utilities.php'] as $module) {
		expect(plugin_test_read_source($module))->toContain("include_once(dirname(__DIR__) . '/setup.php');");
	}
});

it('loads every extracted module from the setup compatibility facade', function () {
	$setup = plugin_test_read_source('setup.php');

	foreach (['schema.php', 'processing.php', 'settings.php', 'navigation.php', 'installer.php', 'utilities.php'] as $module) {
		expect($setup)->toContain("include_once(__DIR__ . '/includes/$module');");
	}
});

it('boots CLI entrypoints through the setup compatibility facade', function () {
	foreach (['syslog_counter.php', 'syslog_recovery.php'] as $entrypoint) {
		$source = plugin_test_read_source($entrypoint);

		expect($source)->toContain("include_once(__DIR__ . '/setup.php');")
			->not->toContain("include_once(__DIR__ . '/functions.php');")
			->not->toContain("include_once(__DIR__ . '/database.php');");
	}
});

it('registers extracted callbacks against their owning module', function () {
	$setup = plugin_test_read_source('setup.php');

	$ownership = [
		'syslog_config_arrays'         => 'settings.php',
		'syslog_config_settings'       => 'settings.php',
		'syslog_settings_bottom'       => 'settings.php',
		'syslog_config_insert'         => 'settings.php',
		'syslog_show_tab'              => 'navigation.php',
		'syslog_draw_navigation_text'  => 'navigation.php',
		'syslog_graph_buttons'         => 'navigation.php',
		'syslog_poller_bottom'         => 'processing.php',
		'syslog_replicate_out'         => 'processing.php',
		'syslog_utilities_list'        => 'utilities.php',
		'syslog_utilities_action'      => 'utilities.php',
	];

	foreach ($ownership as $callback => $module) {
		expect($setup)->toMatch('/api_plugin_register_hook\([^\n]*[\'\"]' . preg_quote($callback, '/') . '[\'\"][^\n]*[\'\"]includes\/' . preg_quote($module, '/') . '[\'\"]/');
	}
});
