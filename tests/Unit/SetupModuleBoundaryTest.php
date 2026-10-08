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
		'syslog_determine_config',
		'syslog_config_safe',
		'syslog_connect',
		'syslog_upgrade_saved_search_realm',
		'syslog_upgrade_dashboard_realm',
		'syslog_upgrade_device_rule_realm',
		'syslog_upgrade_rule_permissions',
		'syslog_upgrade_consolidate_rule_realms',
		'syslog_upgrade_explicit_realm_grants',
		'syslog_upgrade_create_permission_realms',
		'syslog_check_upgrade',
		'plugin_syslog_version',
		'syslog_refresh_plugin_version',
		'syslog_upgrade_hook_module_paths',
		'syslog_check_dependencies',
	];

	expect($matches[1])->toEqualCanonicalizing($allowed);
});

it('leaves Cacti realm maps to the plugin API', function () {
	$settings = plugin_test_read_source('includes/settings.php');

	expect($settings)->not->toContain('user_auth_realm_filenames');
});

it('runs realm and version reconciliation only during install or upgrade', function () {
	$setup = plugin_test_read_source('setup.php');
	$check_config = substr($setup, strpos($setup, 'function plugin_syslog_check_config'));
	$check_upgrade = substr($setup, strpos($setup, 'function syslog_check_upgrade'));
	$plugin_upgrade = substr($setup, strpos($setup, 'function plugin_syslog_upgrade'));

	expect($check_config)->toContain('syslog_upgrade_hook_module_paths();')
		->toContain('syslog_check_upgrade();')
		->not->toContain("api_plugin_installed('flowview')");
	expect($plugin_upgrade)->toContain('syslog_upgrade_hook_module_paths();');
	expect($check_upgrade)->toContain('syslog_refresh_plugin_version();')
		->toContain('syslog_upgrade_create_permission_realms()');
	expect(strpos($check_upgrade, 'syslog_refresh_plugin_version();'))
		->toBeGreaterThan(strpos($check_upgrade, 'syslog_ensure_replication_history_columns();'));
	expect($setup)->not->toContain("api_plugin_register_hook('syslog', 'config_insert'")
		->not->toContain('if (!defined(\'IN_PLUGIN_INSTALL\')');
	expect(plugin_test_read_source('includes/settings.php'))
		->not->toContain('$permission_realms_repaired = syslog_upgrade_create_permission_realms();');
	expect($setup)->toContain("'config_arrays'         => ['syslog_config_arrays', 'includes/settings.php']");
});

it('confirms a version upgrade on re-enable without using Cacti installation', function () {
	$setup = plugin_test_read_source('setup.php');
	$check_config = substr($setup, strpos($setup, 'function plugin_syslog_check_config'),
		strpos($setup, 'function plugin_syslog_upgrade') - strpos($setup, 'function plugin_syslog_check_config'));
	$advisor = plugin_test_read_source('includes/installer.php');
	$advisor = substr($advisor, strpos($advisor, 'function syslog_upgrade_advisor'),
		strpos($advisor, 'function syslog_install_advisor') - strpos($advisor, 'function syslog_upgrade_advisor'));

	expect($check_config)->toContain("get_nfilter_request_var('mode') === 'enable'")
		->toContain('version_compare((string) $installed, $version[\'version\'], \'<\')')
		->toContain("!isset_request_var('syslog_upgrade_confirm')")
		->toContain('syslog_upgrade_advisor(');
	expect($advisor)->toContain("form_hidden_box('mode', 'enable'")
		->toContain("form_hidden_box('syslog_upgrade_confirm', '1'")
		->not->toContain("form_hidden_box('mode', 'install'")
		->not->toContain('syslog_setup_table_new(');
});

it('boots the compatibility facade before Cacti can invoke registered callbacks', function () {
	$setup = plugin_test_read_source('setup.php');

	preg_match_all("/api_plugin_register_hook\\([^\\n]*['\"]includes\\/([^'\"]+\\.php)['\"]/", $setup, $matches);

	foreach (array_unique($matches[1]) as $module) {
		$source    = plugin_test_read_source('includes/' . $module);
		$bootstrap = strpos($source, "require_once(dirname(__DIR__) . '/setup.php');");
		$callback  = strpos($source, 'function ');

		expect($bootstrap)->not->toBeFalse()
			->toBeLessThan($callback);
	}
});

it('loads extracted modules only at the lifecycle boundary that needs them', function () {
	$setup = plugin_test_read_source('setup.php');

	expect($setup)->toContain("require_once(__DIR__ . '/includes/schema.php');")
		->toContain("require_once(__DIR__ . '/includes/settings.php');")
		->toContain("require_once(__DIR__ . '/includes/installer.php');")
		->not->toContain("require_once(__DIR__ . '/includes/processing.php');")
		->not->toContain("require_once(__DIR__ . '/includes/navigation.php');")
		->not->toContain("require_once(__DIR__ . '/includes/utilities.php');");
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
