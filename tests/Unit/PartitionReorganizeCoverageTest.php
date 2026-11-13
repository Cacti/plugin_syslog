<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for syslog_partition_create(): when the target
 * partition name is absent but an existing partition boundary already
 * reaches or exceeds the day being recovered, splitting dMaxValue would be
 * rejected by MySQL with error 1493 ("VALUES LESS THAN value must be
 * strictly increasing"). That left partition recovery wedged and parked
 * writes in the dMaxValue safety partition (issue #434). The boundary guard
 * must treat an already-covered day as a successful no-op and never emit the
 * doomed REORGANIZE, while still creating genuinely new partitions.
 */

function partition_reorg_guard_setup($expression, $max_boundary, $to_days_value, &$alters, &$released) {
	test_override('syslog_db_fetch_cell_prepared', function ($sql) use ($to_days_value, &$released) {
		if (str_contains($sql, 'GET_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'RELEASE_LOCK')) {
			$released++;

			return 1;
		}

		if (str_contains($sql, 'TO_DAYS')) {
			return $to_days_value;
		}

		return 1;
	});

	test_override('syslog_db_fetch_row_prepared', function ($sql) use ($expression, $max_boundary) {
		if (str_contains($sql, 'AS max_boundary')) {
			return ['max_boundary' => $max_boundary, 'partition_expression' => $expression];
		}

		if (str_contains($sql, 'AS overflow_rows')) {
			return ['overflow_rows' => 0, 'overflow_bytes' => 0];
		}

		if (str_contains($sql, 'SHOW CREATE TABLE')) {
			return ['Create Table' => str_contains($expression, 'to_days')
				? 'PARTITION BY RANGE (TO_DAYS(logtime))'
				: 'PARTITION BY RANGE (UNIX_TIMESTAMP(logtime))'];
		}

		// The information_schema existence lookup resolves to "not found".
		return [];
	});

	test_override('syslog_db_execute_prepared', function ($sql) use (&$alters) {
		$alters[] = $sql;

		return true;
	});
}

it('skips the REORGANIZE when a TO_DAYS boundary already covers the day', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';

	$alters = [];
	$released = 0;

	// Target TO_DAYS boundary equals the highest existing boundary: covered.
	partition_reorg_guard_setup('to_days(logtime)', 739568, 739568, $alters, $released);

	$reason = 'prior deferral';

	expect(syslog_partition_create('syslog', 1767398400, $reason))->toBeTrue();
	expect($alters)->toBe([], 'No REORGANIZE may be issued for an already-covered day');
	expect($reason)->toBe('');
	expect($released)->toBe(1, 'The creation lock must be released');
});

it('skips the REORGANIZE when a UNIX_TIMESTAMP boundary already covers the day', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';

	$alters = [];
	$released = 0;

	// Highest existing epoch boundary is far beyond the target: covered.
	partition_reorg_guard_setup('unix_timestamp(logtime)', 9999999999, 0, $alters, $released);

	$reason = '';

	expect(syslog_partition_create('syslog_removed', 1767398400, $reason))->toBeTrue();
	expect($alters)->toBe([], 'No REORGANIZE may be issued for an already-covered day');
	expect($released)->toBe(1, 'The creation lock must be released');
});

it('still creates a genuinely new partition when the boundary extends the range', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';

	$alters = [];
	$released = 0;

	// Highest existing boundary is below the target: a real new partition.
	partition_reorg_guard_setup('to_days(logtime)', 739000, 739568, $alters, $released);

	$reason = '';

	expect(syslog_partition_create('syslog', 1767398400, $reason))->toBeTrue();
	expect($alters)->toHaveCount(1);
	expect($alters[0])->toContain('REORGANIZE PARTITION dMaxValue')->toContain('TO_DAYS');
	expect($released)->toBe(1, 'The creation lock must be released');
});
