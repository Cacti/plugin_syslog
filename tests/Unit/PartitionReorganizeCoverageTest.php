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

/*
 * End-to-end coverage for the reviewer scenario on issue #434: a legacy
 * TO_DAYS table whose last concrete partition is named d20261111 but whose
 * upper boundary already reaches 2026-11-15. Recovery on 2026-11-12 with the
 * default three-day horizon must not waste its creation budget on the three
 * already-covered days (Nov 12-14); it must create only the genuine gap
 * (Nov 15), and a second recovery run must be a stable no-op.
 */
it('spends the recovery budget only on the genuine gap and stays stable across runs', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';

	// Monotonic stand-in for MySQL TO_DAYS(), sufficient for ordering.
	$fake_to_days = static function ($date) {
		return (int) floor(strtotime($date . ' 00:00:00 UTC') / 86400) + 719528;
	};

	// Existing partitions already cover every day whose data is below the
	// 2026-11-15 boundary, i.e. through Nov 14. Nov 15 is the real gap.
	$covering_boundary = $fake_to_days('2026-11-15');
	$alters = [];

	// Default recovery limit (3) and horizon (3): without the fix the three
	// covered days would exhaust the budget before reaching Nov 15.
	test_override('read_config_option', static fn() => '');

	// syslog_partition_check() reads boundaries through _cell_prepared.
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params = []) use (&$covering_boundary, $fake_to_days) {
		if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
			return 1;
		}

		if (str_contains($sql, 'partition_expression')) {
			return 'to_days(`logtime`)';
		}

		if (str_contains($sql, 'MAX(CAST(partition_description')) {
			return $covering_boundary;
		}

		if (str_contains($sql, 'TO_DAYS(?)')) {
			return $fake_to_days($params[0]);
		}

		return '';
	});

	// syslog_partition_create() reads existence/overflow/boundary and the
	// table DDL through _row_prepared.
	test_override('syslog_db_fetch_row_prepared', function ($sql) use (&$covering_boundary) {
		if (str_contains($sql, 'AS max_boundary')) {
			return ['max_boundary' => $covering_boundary, 'partition_expression' => 'to_days(`logtime`)'];
		}

		if (str_contains($sql, 'AS overflow_rows')) {
			return ['overflow_rows' => 0, 'overflow_bytes' => 0];
		}

		if (str_contains($sql, 'SHOW CREATE TABLE')) {
			return ['Create Table' => 'CREATE TABLE `syslog` (`logtime` timestamp NOT NULL) PARTITION BY RANGE (TO_DAYS(`logtime`))'];
		}

		// The information_schema existence lookup resolves to "not found".
		return [];
	});

	// A successful REORGANIZE extends coverage to the new partition boundary.
	test_override('syslog_db_execute_prepared', function ($sql) use (&$alters, &$covering_boundary, $fake_to_days) {
		if (preg_match("/PARTITION (d\\d{8}) VALUES LESS THAN \\(TO_DAYS\\('(\\d{4}-\\d{2}-\\d{2})'\\)\\)/", $sql, $m)) {
			$alters[] = $m[1];
			$covering_boundary = $fake_to_days($m[2]);
		}

		return true;
	});

	$base_time = gmmktime(12, 0, 0, 11, 12, 2026);

	$run1 = syslog_partition_recover('syslog', $base_time, 3);

	expect($run1['created'])->toBe(1, 'Only the genuine Nov 15 gap is created');
	expect($run1['missing'])->toBe(0);
	expect($run1['stop_reason'])->toBe('');
	expect($run1['dmax_risk'])->toBeFalse();
	expect($alters)->toBe(['d20261115'], 'Covered days must not emit a REORGANIZE');

	$run2 = syslog_partition_recover('syslog', $base_time, 3);

	expect($run2['created'])->toBe(0, 'Second run finds nothing missing');
	expect($run2['missing'])->toBe(0);
	expect($alters)->toBe(['d20261115'], 'Repeated recovery issues no further DDL');
});

it('resolves UNIX_TIMESTAMP coverage by boundary, not partition name', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';

	// Highest existing boundary is the next UTC midnight after 1767398400.
	$max_boundary = (intdiv(1767398400, 86400) + 1) * 86400;

	test_override('syslog_db_fetch_cell_prepared', function ($sql) use ($max_boundary) {
		if (str_contains($sql, 'partition_expression')) {
			return 'unix_timestamp(`logtime`)';
		}

		if (str_contains($sql, 'MAX(CAST(partition_description')) {
			return $max_boundary;
		}

		return '';
	});

	// That day's boundary equals the highest existing boundary: covered.
	expect(syslog_partition_check('syslog', 1767398400))->toBeFalse();

	// A week later the boundary extends past the range: a real gap.
	expect(syslog_partition_check('syslog', 1767398400 + 7 * 86400))->toBeTrue();
});

it('requests creation when the existing boundary metadata is not numeric', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';

	test_override('syslog_db_fetch_cell_prepared', function ($sql) {
		if (str_contains($sql, 'partition_expression')) {
			return 'unix_timestamp(`logtime`)';
		}

		// Unreadable max boundary forces the conservative "create" path,
		// which syslog_partition_create()'s own guard keeps safe.
		return 'MAXVALUE';
	});

	expect(syslog_partition_check('syslog', 1767398400))->toBeTrue();
});
