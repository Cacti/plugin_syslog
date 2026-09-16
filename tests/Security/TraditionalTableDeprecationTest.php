<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the traditional (non-partitioned) table
 * deprecation:
 *
 * - New installs must always create partitioned tables; the 'trad'
 *   architecture is no longer selectable and silently upgrades to 'part'.
 * - The storage engine choice is limited to InnoDB and Aria; MyISAM and
 *   unknown engines fall back to InnoDB.
 * - Existing traditional installs keep working and receive a throttled
 *   deprecation notice instead of a fatal error.
 *
 * The table-creation assertions are static analysis on setup.php (the
 * real function requires a live database) mirroring the style of
 * PartitionTableLockingTest; the helper assertions execute the real
 * functions extracted from functions.php.
 */

it('restricts the storage engine choice to InnoDB and Aria', function () {
	$functions = plugin_test_read_source('functions.php');

	if (!preg_match('/function\s+syslog_validate_storage_engine\s*\([^)]*\)\s*\{.*?\n\}/s', $functions, $m)) {
		throw new RuntimeException('Could not extract syslog_validate_storage_engine from functions.php');
	}

	$source = str_replace('function syslog_validate_storage_engine', 'function test_validate_engine', $m[0]);

	eval($source);

	expect(test_validate_engine('innodb'))->toBe('InnoDB', 'lowercase innodb normalizes to InnoDB');
	expect(test_validate_engine('InnoDB'))->toBe('InnoDB', 'InnoDB passes through');
	expect(test_validate_engine('aria'))->toBe('Aria', 'aria normalizes to Aria');
	expect(test_validate_engine('Aria Storage'))->toBe('Aria', 'engine names containing aria normalize to Aria');
	expect(test_validate_engine('myisam'))->toBe('InnoDB', 'MyISAM falls back to InnoDB');
	expect(test_validate_engine(''))->toBe('InnoDB', 'empty engine falls back to InnoDB');
	expect(test_validate_engine(null))->toBe('InnoDB', 'null falls back to InnoDB');
	expect(test_validate_engine(42))->toBe('InnoDB', 'non-string falls back to InnoDB');
});

it('no longer offers traditional tables or MyISAM in the installer', function () {
	$setup = plugin_test_read_source('setup.php');

	expect(strpos($setup, "'trad' => __('Traditional Table', 'syslog')"))->toBeFalse('Traditional Table choice remains in the installer');
	expect(strpos($setup, "'myisam' => __('MyISAM Storage', 'syslog')"))->toBeFalse('MyISAM choice remains in the installer');
});

it('always creates partitioned tables regardless of legacy options', function () {
	$setup = plugin_test_read_source('setup.php');

	if (!preg_match('/function\s+syslog_setup_table_new\s*\(.*?\n\}/s', $setup, $m)) {
		throw new RuntimeException('Could not extract syslog_setup_table_new from setup.php');
	}

	$function = $m[0];

	// The traditional CREATE TABLE statement must be gone entirely.
	expect(strpos($function, 'CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog` ('))
		->toBeFalse('Traditional syslog table creation remains in syslog_setup_table_new');

	// Partitioned creation must be unconditional (no branch guard left).
	expect(preg_match('/if\s*\(\s*!\s*\$partitioned\s*\)/', $function))->toBe(0, 'Traditional branch guard remains in syslog_setup_table_new');
	expect(strpos($function, 'syslog_create_partitioned_syslog_table('))
		->not->toBeFalse('Partitioned table creation is missing from syslog_setup_table_new');

	// The engine must flow through the validation helper.
	expect(strpos($function, 'syslog_validate_storage_engine('))
		->not->toBeFalse('Storage engine validation is missing from syslog_setup_table_new');
});

it('coerces a legacy trad db_type setting to partitioned', function () {
	$setup = plugin_test_read_source('setup.php');

	if (!preg_match('/function\s+syslog_setup_table_new\s*\(.*?\n\}/s', $setup, $m)) {
		throw new RuntimeException('Could not extract syslog_setup_table_new from setup.php');
	}

	$function = $m[0];

	// The coercion must run for every code path, not only when options
	// were auto-populated from the settings table.
	expect(preg_match("/db_type'\]\s*!=\s*'part'/", $function))->toBeGreaterThan(0, 'db_type partition coercion is missing');

	// The legacy 'trad' value must be logged when encountered.
	expect(preg_match("/==\s*'trad'/", $function))->toBeGreaterThan(0, 'Legacy trad value detection is missing');
});

it('warns about traditional tables but keeps them working', function () {
	$root      = dirname(__DIR__, 2);
	$functions = file_get_contents($root . '/functions.php');
	$setup     = file_get_contents($root . '/setup.php');
	$process   = file_get_contents($root . '/syslog_process.php');

	// The deprecation helper must exist and must not abort processing.
	expect(strpos($functions, 'function syslog_notice_traditional_tables'))
		->not->toBeFalse('Deprecation notice helper is missing from functions.php');

	// The poller must keep processing traditional tables (no fatal exit).
	if (!preg_match('/function\s+syslog_traditional_manage\s*\(.*?\n\}/s', $functions, $m)) {
		throw new RuntimeException('Could not extract syslog_traditional_manage from functions.php');
	}

	expect(strpos($m[0], 'syslog_notice_traditional_tables(false)'))
		->not->toBeFalse('Traditional maintenance path must raise the throttled notice');

	// The UI check raises the notice when no version upgrade is required.
	expect(substr_count($setup, 'syslog_notice_traditional_tables(true)'))
		->toBeGreaterThanOrEqual(2, 'The UI upgrade check must raise the notice on both no-upgrade paths');

	// The notice is throttled through the settings table, not spamming.
	expect(strpos($functions, 'syslog_traditional_notice'))
		->not->toBeFalse('Deprecation notice throttling setting is missing');
});

it('notifies about traditional tables with throttling and safe messages', function () {
	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$calls = [
		'config_options' => [],
		'logs'           => [],
		'messages'       => [],
		'db'             => [],
	];

	test_override('syslog_db_table_exists', function ($table, $log = true) use (&$calls) {
		$calls['db'][] = ['fn' => 'syslog_db_table_exists', 'args' => [$table, $log]];

		return true;
	});

	// functions.php's syslog_notice_traditional_tables() calls
	// syslog_is_partitioned() directly, so override the underlying
	// SHOW CREATE TABLE fetch it performs.
	test_override('syslog_db_fetch_row', function ($sql, $log = true) use (&$calls) {
		$calls['db'][] = ['fn' => 'syslog_db_fetch_row', 'args' => [$sql, $log]];

		return ['Create Table' => 'CREATE TABLE `syslog` (\n  seq bigint unsigned NOT NULL AUTO_INCREMENT\n)'];
	});

	test_override('read_config_option', function ($name, $force = false) use (&$calls) {
		$calls['config_options'][$name] = ($calls['config_options'][$name] ?? 0) + 1;

		// After the first notice the helper persists the throttle
		// timestamp through set_config_option(); subsequent reads must
		// return it so the throttle window is actually exercised.
		if ($name === 'syslog_traditional_notice' && isset($calls['config_options']['__notice_saved_at'])) {
			return $calls['config_options']['__notice_saved_at'];
		}

		return '';
	});

	test_override('set_config_option', function ($name, $value) use (&$calls) {
		if ($name === 'syslog_traditional_notice') {
			$calls['config_options']['__notice_saved_at'] = (int) $value;
		}

		return true;
	});

	test_override('cacti_log', function ($message, $output = false, $environ = '', $level = 0) use (&$calls) {
		$calls['logs'][] = $message;

		return null;
	});

	test_override('raise_message', function ($id, $text = '', $level = 0) use (&$calls) {
		$calls['messages'][] = ['id' => $id, 'level' => $level];

		return null;
	});

	syslog_load_plugin_source('functions.php');

	// Table exists and is not partitioned: warn, and raise when asked.
	expect(syslog_notice_traditional_tables(true))->toBeTrue('A traditional table must be reported');
	expect($calls['logs'])->toHaveCount(1, 'Exactly one log line is expected for a traditional table');
	expect($calls['messages'])->toHaveCount(1, 'Exactly one UI message is expected when raising');
	expect($calls['messages'][0]['id'])->toBe('syslog_traditional_deprecated');
	expect($calls['messages'][0]['level'])->toBe(MESSAGE_LEVEL_WARN, 'The deprecation notice must be a warning, not a fatal error');

	// A second call inside the throttle window stays silent but still
	// reports the traditional state.
	$logs_before   = count($calls['logs']);
	$messages_before = count($calls['messages']);

	expect(syslog_notice_traditional_tables(true))->toBeTrue('The table is still traditional');
	expect(count($calls['logs']))->toBe($logs_before, 'The notice must be throttled to once per day');
	expect(count($calls['messages']))->toBe($messages_before, 'The UI message must be throttled to once per day');
});

it('stays silent for partitioned or missing tables', function () {
	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$calls = [
		'logs'     => [],
		'messages' => [],
	];

	test_override('syslog_db_table_exists', function ($table, $log = true) {
		return true;
	});

	test_override('syslog_db_fetch_row', function ($sql, $log = true) {
		return ['Create Table' => 'CREATE TABLE `syslog` (...) PARTITION BY RANGE (UNIX_TIMESTAMP(logtime))'];
	});

	test_override('read_config_option', function ($name, $force = false) {
		return '';
	});

	test_override('set_config_option', function ($name, $value) {
		return true;
	});

	test_override('cacti_log', function ($message, $output = false, $environ = '', $level = 0) use (&$calls) {
		$calls['logs'][] = $message;

		return null;
	});

	test_override('raise_message', function ($id, $text = '', $level = 0) use (&$calls) {
		$calls['messages'][] = $id;

		return null;
	});

	syslog_load_plugin_source('functions.php');

	expect(syslog_notice_traditional_tables(true))->toBeFalse('A partitioned table must not raise the notice');
	expect($calls['logs'])->toHaveCount(0, 'No log line is expected for a partitioned table');
	expect($calls['messages'])->toHaveCount(0, 'No UI message is expected for a partitioned table');

	// Missing tables (fresh install before table creation) are silent too.
	test_override('syslog_db_table_exists', function ($table, $log = true) {
		return false;
	});

	test_override('syslog_db_fetch_row', function ($sql, $log = true) {
		throw new RuntimeException('No query should run when the table does not exist');
	});

	expect(syslog_notice_traditional_tables(true))->toBeFalse('A missing table must not raise the notice');
	expect($calls['logs'])->toHaveCount(0, 'No log line is expected when the table is missing');
	expect($calls['messages'])->toHaveCount(0, 'No UI message is expected when the table is missing');
});