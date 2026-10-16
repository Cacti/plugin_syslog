<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/**
 * Exercise syslog_backfill_message_digest() against stubbed storage so the
 * patch-coverage gate can measure every branch of the chunked migration:
 * table/column guards, empty tables, completed cursors, the empty-digest
 * shortcut, and a two-iteration walk of the seq cursor.
 */
afterEach(function () {
	syslog_test_reset_globals();
});

it('backfills digests in bounded seq chunks and resumes from the saved cursor', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'archive';

	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', static fn() => true);

	$options = [
		'syslog_digest_backfill_seq_syslog'      => '0',
		'syslog_digest_backfill_seq_syslog_done' => '0',
	];
	test_override('read_config_option', static fn($name) => $options[$name] ?? '');

	$fetch_calls = 0;
	test_override('syslog_db_fetch_cell', function ($sql) use (&$fetch_calls) {
		$fetch_calls++;

		if (str_contains($sql, 'syslog_removed')) {
			return 0; // empty table: no work is scheduled for it
		}

		if (str_contains($sql, 'COALESCE(MAX(seq)')) {
			return 250;
		}

		// syslog table COUNTs: first reports a backlog, the closing re-count
		// runs after both chunks and reports nothing left.
		return $fetch_calls < 3 ? 10 : 0;
	});

	$updates  = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$updates) {
		$updates[] = $params;
		return true;
	});

	$settings = [];
	test_override('set_config_option', function ($name, $value) use (&$settings) {
		$settings[$name] = $value;
		return true;
	});

	// The chunk size bottoms out at 10000 (max($rows_per_run, 10000)); with
	// 250 records that is one primary-key range scan over [0, 250).
	expect(syslog_backfill_message_digest(200))->toBe(0);

	expect($updates)->toHaveCount(1)
		->and($updates[0])->toBe([0, 250]);

	// the cursor is persisted for the next poller cycle and the completion
	// flag is set at the high-water mark so future runs short-circuit
	expect($settings['syslog_digest_backfill_seq_syslog'])->toBe('250')
		->and($settings['syslog_digest_backfill_seq_syslog_done'])->toBe('250');
});

it('returns the remaining backlog count when the budget exhausts before the table does', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'archive';

	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', static fn() => true);

	// done flag unset but cursor already at max_seq means the while loop
	// cannot run; only the closing COUNT path executes.
	test_override('read_config_option', static fn($name) =>
		$name === 'syslog_digest_backfill_seq_syslog' ? '250' : '0');

	$fetch_calls = 0;
	test_override('syslog_db_fetch_cell', function ($sql) use (&$fetch_calls) {
		$fetch_calls++;

		if (str_contains($sql, 'FROM `archive`.`syslog_removed`')) {
			return 0; // nothing to backfill on the second table
		}

		if (str_contains($sql, 'COALESCE(MAX(seq)')) {
			return 250;
		}

		return 100; // always backlog on the syslog table so the run stops at its closing count
	});

	$updates = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$updates) {
		$updates[] = $params;
		return true;
	});

	expect(syslog_backfill_message_digest(50))->toBe(100);
	expect($updates)->toBe([]); // cursor == max_seq: no range scan is issued
});

it('short-circuits when the table is gone, the column is absent, or the migration is done', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'archive';

	// no tables at all
	test_override('syslog_db_table_exists', static fn() => false);
	expect(syslog_backfill_message_digest())->toBe(0);

	// tables exist but the digest column is missing on both
	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', static fn() => false);
	expect(syslog_backfill_message_digest())->toBe(0);

	// digest column exists; completed cursor at the high-water mark
	test_override('syslog_db_column_exists', static fn() => true);

	$options = [
		'syslog_digest_backfill_seq_syslog'              => '40',
		'syslog_digest_backfill_seq_syslog_done'         => '40',
		'syslog_digest_backfill_seq_syslog_removed'      => '9',
		'syslog_digest_backfill_seq_syslog_removed_done' => '9',
	];
	test_override('read_config_option', static fn($name) => $options[$name] ?? '');

	test_override('syslog_db_fetch_cell', static fn($sql) =>
		str_contains($sql, 'syslog_removed') ? 9 : 40);

	expect(syslog_backfill_message_digest())->toBe(0);

	// the digest column exists again on the removed table but its backlog
	// is empty, so the migration stamps the done flag and skips it.
	test_override('syslog_db_fetch_cell', function ($sql) {
		if (str_contains($sql, 'syslog_removed')) {
			if (str_contains($sql, 'COALESCE(MAX(seq)')) {
				return 9;
			}
			return 0; // empty backlog
		}

		return str_contains($sql, 'COALESCE(MAX(seq)') ? 40 : 0;
	});
	test_override('read_config_option', static fn($name) =>
		str_contains($name, 'syslog_removed') && str_contains($name, '_done') ? '0' : '9');

	$settings = [];
	test_override('set_config_option', function ($name, $value) use (&$settings) {
		$settings[$name] = $value;
		return true;
	});

	expect(syslog_backfill_message_digest())->toBe(0)
		->and($settings)->toHaveKey('syslog_digest_backfill_seq_syslog_removed_done', '9');
});
