<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the historical partition pre-create loop bound
 * in syslog_create_partitioned_syslog_table() (setup.php).
 *
 * syslog_connect() and syslog_db_execute() are not stubbable through
 * test_override() here because the real syslog_create_partitioned_syslog_table()
 * calls them directly, and syslog_connect() requires a live database. Instead,
 * the function body is extracted from setup.php (mirroring
 * TraditionalTableDeprecationTest), the syslog_connect() call is stripped, and
 * the final syslog_db_execute() call is replaced with a return of the
 * generated DDL so the partition list can be asserted without a database.
 */
function partition_precreate_extract_function() {
	syslog_load_plugin_source('functions.php');

	$setup = plugin_test_read_source('setup.php');

	if (!preg_match('/function\s+syslog_create_partitioned_syslog_table\s*\(.*?\n\}/s', $setup, $m)) {
		throw new RuntimeException('Could not extract syslog_create_partitioned_syslog_table from setup.php');
	}

	$source = $m[0];
	$source = str_replace('function syslog_create_partitioned_syslog_table', 'function test_create_partitioned_syslog_table', $source);
	$source = str_replace("\tsyslog_connect();\n", '', $source);
	// Freeze $now to an injected, fixed timestamp so the test and the function always agree on "today", regardless of when the test runs.
	$source = str_replace('$now = time();', "\$now = \$GLOBALS['__test_partition_precreate_base_time'];", $source);
	$source = str_replace('syslog_db_execute($sql . $parts);', 'return $sql . $parts;', $source);

	if (!function_exists('test_create_partitioned_syslog_table')) {
		eval($source);
	}
}

function partition_precreate_dates($ddl) {
	preg_match_all('/PARTITION (d\d{8}) VALUES LESS THAN/', $ddl, $matches);

	return $matches[1];
}

it('always pre-creates today\'s partition, even with indefinite (0-day) retention', function () {
	partition_precreate_extract_function();

	$GLOBALS['syslogdb_default'] = 'syslog';

	$base_time = time();
	$GLOBALS['__test_partition_precreate_base_time'] = $base_time;
	$today = gmdate('Ymd', $base_time);

	$ddl = test_create_partitioned_syslog_table('InnoDB', 0, 3);

	$dates = partition_precreate_dates($ddl);

	// Today's partition must always be pre-created, even for indefinite retention.
	expect($dates)->toContain('d' . $today);

	// Indefinite retention should only pre-create today plus the future ahead-days partitions.
	expect($dates)->toHaveCount(4);
});

it('keeps the normal retention window unchanged (30 days plus 3 ahead days)', function () {
	partition_precreate_extract_function();

	$GLOBALS['syslogdb_default'] = 'syslog';

	$base_time = time();
	$GLOBALS['__test_partition_precreate_base_time'] = $base_time;

	$ddl = test_create_partitioned_syslog_table('InnoDB', 30, 3);

	$dates = partition_precreate_dates($ddl);

	// 30 retained days (today plus 29 prior days) plus 3 future ahead-days.
	expect($dates)->toHaveCount(33);

	$oldest = gmdate('Ymd', $base_time - (29 * 86400));

	expect($dates)->toContain('d' . $oldest);
});
