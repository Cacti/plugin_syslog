<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the include-path hardening: every plugin
 * entrypoint must include functions.php/database.php via an __DIR__-based
 * path (or the chdir('../../') CWD-relative style paired with the chdir
 * call), never a bare CWD-relative include that silently depends on the
 * working directory the script happened to be launched from.
 */

it('includes plugin sources via a CWD-independent path from every entrypoint', function () {
	$root = dirname(__DIR__, 2);

	$entrypoints = [
		'syslog.php',
		'syslog_alerts.php',
		'syslog_removal.php',
		'syslog_batch_transfer.php',
		'syslog_reports.php',
		'syslog_process.php',
	];

	$plugin_includes = [
		'setup.php',
		'functions.php',
		'database.php',
	];

	foreach ($plugin_includes as $inc) {
		if (!file_exists($root . '/' . $inc)) {
			throw new RuntimeException("Required plugin file missing: $inc");
		}
	}

	// setup.php is not part of the standard per-entrypoint include chain; it is
	// pulled in on demand via $config['base_path'] where a runtime setup step is
	// actually needed, so only functions.php and database.php are required here.
	$required_includes = ['functions.php', 'database.php'];

	foreach ($entrypoints as $file) {
		$path = $root . '/' . $file;

		if (!file_exists($path)) {
			throw new RuntimeException("Entrypoint file missing: $file");
		}

		$content = file_get_contents($path);

		if ($content === false) {
			throw new RuntimeException("Failed to read $file");
		}

		foreach ($required_includes as $inc) {
			// Entrypoints use one of two equivalent styles: __DIR__-based (no
			// chdir) or chdir('../../') plus a CWD-relative plugin path. Either
			// resolves to the same file; only require that one of them is used.
			$dir_pattern = '/include_once\s*\(\s*__DIR__\s*\.\s*[\'"]\/\s*' . preg_quote($inc, '/') . '[\'"]\s*\)/';
			$cwd_pattern = '/include_once\s*\(\s*[\'"]\.\/plugins\/syslog\/' . preg_quote($inc, '/') . '[\'"]\s*\)/';

			if (!preg_match($dir_pattern, $content) && !preg_match($cwd_pattern, $content)) {
				throw new RuntimeException("$file must include $inc via __DIR__ or the chdir('../../') CWD-relative style");
			}

			// The CWD-relative style is only safe when paired with a chdir to
			// the Cacti base path; without it the include is CWD-dependent.
			if (preg_match($cwd_pattern, $content) && !preg_match('/chdir\s*\(\s*[\'"]\.\.\/\.\.\/[\'"]\s*\)/', $content)) {
				throw new RuntimeException("$file uses CWD-relative include for $inc without chdir('../../')");
			}
		}
	}

	$setup = file_get_contents($root . '/setup.php');

	if ($setup === false) {
		throw new RuntimeException('Failed to read setup.php');
	}

	$expected_functions = [
		'plugin_syslog_install',
		'plugin_syslog_check_config',
		'syslog_connect',
		'syslog_determine_config',
	];

	foreach ($expected_functions as $func) {
		if (!preg_match('/function\s+' . preg_quote($func, '/') . '\s*\(/', $setup)) {
			throw new RuntimeException("setup.php missing expected function: $func");
		}
	}

	$functions = file_get_contents($root . '/functions.php');
	$database  = file_get_contents($root . '/database.php');

	if ($functions === false || $database === false) {
		throw new RuntimeException('Failed to read functions.php or database.php');
	}

	if (!preg_match('/function\s+syslog_db_connect_real\s*\(/', $database)) {
		throw new RuntimeException('database.php missing syslog_db_connect_real');
	}

	if (!preg_match('/function\s+syslog_apply_selected_items_action\s*\(/', $functions)) {
		throw new RuntimeException('functions.php missing syslog_apply_selected_items_action');
	}

	if (!preg_match('/include_once\s*\(\s*__DIR__\s*\.\s*[\'"]\/functions\.php[\'"]\s*\)/', $setup)) {
		throw new RuntimeException('setup.php must use __DIR__ for functions.php include');
	}

	if (!preg_match('/include_once\s*\(\s*__DIR__\s*\.\s*[\'"]\/database\.php[\'"]\s*\)/', $setup)) {
		throw new RuntimeException('setup.php must use __DIR__ for database.php include');
	}
});
