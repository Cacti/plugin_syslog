<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Entrypoints must load setup.php through a CWD-independent path.
 * syslog_connect() loads functions.php and database.php from setup.php's directory.
 */

it('includes plugin sources via a CWD-independent path from every entrypoint', function () {
	$root = dirname(__DIR__, 2);

	$entrypoints = [
		'syslog.php',
		'syslog_alerts.php',
		'syslog_removal.php',
		'syslog_batch_transfer.php',
		'syslog_device_rules.php',
		'syslog_reports.php',
		'syslog_process.php',
	];

	$plugin_includes = [
		'setup.php',
		'includes/functions.php',
		'includes/database.php',
	];

	foreach ($plugin_includes as $inc) {
		if (!file_exists($root . '/' . $inc)) {
			throw new RuntimeException("Required plugin file missing: $inc");
		}
	}

	foreach ($entrypoints as $file) {
		$path = $root . '/' . $file;

		if (!file_exists($path)) {
			throw new RuntimeException("Entrypoint file missing: $file");
		}

		$content = file_get_contents($path);

		if ($content === false) {
			throw new RuntimeException("Failed to read $file");
		}

		if (!str_contains($content, "include_once(__DIR__ . '/setup.php');")) {
			throw new RuntimeException("$file must load setup.php through __DIR__");
		}
	}

	$setup = file_get_contents($root . '/setup.php');

	if ($setup === false) {
		throw new RuntimeException('Failed to read setup.php');
	}

	$expected_functions = [
		'plugin_syslog_install'       => 'setup.php',
		'plugin_syslog_check_config'  => 'setup.php',
		'syslog_connect'              => 'setup.php',
		'syslog_determine_config'     => 'includes/settings.php',
	];

	foreach ($expected_functions as $func => $owner) {
		$source = plugin_test_read_source($owner);

		if (!preg_match('/function\s+' . preg_quote($func, '/') . '\s*\(/', $source)) {
			throw new RuntimeException("$owner missing expected function: $func");
		}
	}

	$functions = file_get_contents($root . '/includes/functions.php');
	$database  = file_get_contents($root . '/includes/database.php');

	if ($functions === false || $database === false) {
		throw new RuntimeException('Failed to read functions.php or database.php');
	}

	if (!preg_match('/function\s+syslog_db_connect_real\s*\(/', $database)) {
		throw new RuntimeException('database.php missing syslog_db_connect_real');
	}

	if (!preg_match('/function\s+syslog_apply_selected_items_action\s*\(/', $functions)) {
		throw new RuntimeException('functions.php missing syslog_apply_selected_items_action');
	}

	if (!preg_match('/include_once\s*\(\s*__DIR__\s*\.\s*[\'"]\/includes\/functions\.php[\'"]\s*\)/', $setup)) {
		throw new RuntimeException('setup.php must use __DIR__ for the includes/functions.php include');
	}

	if (!preg_match('/include_once\s*\(\s*__DIR__\s*\.\s*[\'"]\/includes\/database\.php[\'"]\s*\)/', $setup)) {
		throw new RuntimeException('setup.php must use __DIR__ for the includes/database.php include');
	}

	expect(true)->toBeTrue();
});
