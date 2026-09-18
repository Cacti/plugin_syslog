<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the aggregation of parallel worker statistics
 * in the syslog poller master.  The helper must read the settings rows
 * with a fresh SELECT rather than read_config_option(): that helper
 * caches per process, and the master has already read these same keys
 * while waiting out the references phase, where the moved count is
 * always zero.  The cached numbers made the transfer total and the
 * 'Records processed' figure on the Syslog Status tab stay at zero.
 *
 * The helper lives in functions.php so the bootstrap can load it; the
 * wiring inside syslog_process.php is pinned by the last test.
 */

syslog_load_plugin_source('functions.php');

it('sums moved and resolved counts from a fresh settings table read', function () {
	$GLOBALS['syslogdb_default'] = 'syslog';
	$executed = [];

	test_override('db_fetch_assoc', function ($sql) use (&$executed) {
		$executed[] = ['fn' => 'db_fetch_assoc', 'sql' => $sql];

		return [
			['name' => 'stats_syslog_child_1', 'value' => json_encode(['child' => 1, 'phase' => 'transfer', 'moved' => 1200, 'resolved' => 2])],
			['name' => 'stats_syslog_child_2', 'value' => json_encode(['child' => 2, 'phase' => 'transfer', 'moved' => 300, 'resolved' => 0])]
		];
	});

	test_override('db_execute', function ($sql) use (&$executed) {
		$executed[] = ['fn' => 'db_execute', 'sql' => $sql];

		return true;
	});

	$totals = syslog_aggregate_worker_stats(2);

	expect($totals)->toBe(['moved' => 1500, 'resolved' => 2], 'Transfer totals must sum every reporting worker');
	expect($executed[0])->toBe(['fn' => 'db_fetch_assoc', 'sql' => "SELECT `name`, `value`
		FROM settings
		WHERE `name` LIKE 'stats_syslog_child_%'"], 'Statistics must come from a fresh SELECT, not the read_config_option() cache');
});

it('prunes the per child settings rows after aggregating', function () {
	$GLOBALS['syslogdb_default'] = 'syslog';
	$deletes = [];

	test_override('db_fetch_assoc', function () {
		return [];
	});

	test_override('db_execute', function ($sql) use (&$deletes) {
		$deletes[] = $sql;

		return true;
	});

	syslog_aggregate_worker_stats(2);

	expect($deletes)->toHaveCount(1);
	expect($deletes[0])->toContain('DELETE FROM settings');
	expect($deletes[0])->toContain('stats_syslog_child_%');
});

it('ignores malformed rows and rows outside the configured worker range', function () {
	$GLOBALS['syslogdb_default'] = 'syslog';
	$warnings = 0;

	test_override('db_fetch_assoc', function () {
		return [
			['name' => 'stats_syslog_child_1', 'value' => 'not-json-at-all'],
			['name' => 'stats_syslog_child_2', 'value' => json_encode(['moved' => 7, 'resolved' => 1])],
			['name' => 'stats_syslog_child_9', 'value' => json_encode(['moved' => 500])]
		];
	});

	test_override('db_execute', function () {
		return true;
	});

	test_override('cacti_log', function ($message) use (&$warnings) {
		$warnings++;
	});

	$totals = syslog_aggregate_worker_stats(2);

	expect($totals)->toBe(['moved' => 7, 'resolved' => 1], 'Malformed rows are skipped and unknown child numbers ignored');
	expect($warnings)->toBe(1, 'Exactly one malformed row warning is logged');
});

it('does not read worker stats through the cached config option helper', function () {
	$root    = dirname(__DIR__, 2);
	$functions = file_get_contents($root . '/functions.php');

	// The regression: read_config_option() caches per process, so the
	// master re-read its references phase numbers instead of the fresh
	// transfer rows.  The aggregation helper must not consult it.
	$aggregate = strpos($functions, 'function syslog_aggregate_worker_stats');
	$block     = substr($functions, $aggregate, strpos($functions, 'function syslog_is_partitioned', $aggregate) - $aggregate);

	expect(str_contains($block, 'read_config_option('))->toBeFalse('Aggregation must not use the per process cached config option reads');
	expect(str_contains($block, 'db_fetch_assoc('))->toBeTrue('Aggregation must read fresh rows from the settings table');
});