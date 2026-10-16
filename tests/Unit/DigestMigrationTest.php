<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

afterEach(function () {
	syslog_test_reset_globals();
});

function syslog_test_digest_setup(): void {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['digest_state'] = [];
	$GLOBALS['digest_updates'] = [];
	$GLOBALS['digest_failure'] = '';
	$GLOBALS['digest_reads'] = 0;
	test_override('syslog_db_table_exists', static fn ($table) => $table !== 'syslog_removed');
	test_override('syslog_db_column_exists', static fn () => true);
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params) {
		return $GLOBALS['digest_failure'] === 'read' ? false : ($GLOBALS['digest_state'][$params[0]] ?? '');
	});
	test_override('syslog_db_fetch_cell', function ($sql) {
		expect($sql)->not->toContain('COUNT(');
		$GLOBALS['digest_reads']++;
		return $GLOBALS['digest_failure'] === 'max' ? false : 250;
	});
	test_override('syslog_db_execute_prepared', function ($sql, $params) {
		if (str_starts_with($sql, 'UPDATE')) {
			$GLOBALS['digest_updates'][] = $params;
			return $GLOBALS['digest_failure'] !== 'update';
		}
		if (str_contains($sql, 'syslog_status')) {
			if ($GLOBALS['digest_failure'] === 'save') {
				return false;
			}
			$GLOBALS['digest_state'][$params[0]] = $params[1];
		}
		return true;
	});
}

it('fixes the migration boundary once and never recounts growing history', function () {
	syslog_test_digest_setup();
	expect(syslog_backfill_message_digest(100))->toBe(150)
		->and(syslog_backfill_message_digest(100))->toBe(50)
		->and(syslog_backfill_message_digest(100))->toBe(0)
		->and(syslog_backfill_message_digest(100))->toBe(0)
		->and($GLOBALS['digest_reads'])->toBe(1)
		->and($GLOBALS['digest_updates'])->toBe([[0, 100], [100, 200], [200, 250]]);
});

it('retains the last durable cursor on update or progress write failure', function ($failure) {
	syslog_test_digest_setup();
	$GLOBALS['digest_state']['digest_backfill_syslog'] = json_encode(['cursor' => 100, 'target' => 250]);
	$GLOBALS['digest_failure'] = $failure;
	expect(syslog_backfill_message_digest(100))->toBe(-1)
		->and(json_decode($GLOBALS['digest_state']['digest_backfill_syslog'], true)['cursor'])->toBe(100);
	$GLOBALS['digest_failure'] = '';
	expect(syslog_backfill_message_digest(100))->toBe(50)
		->and($GLOBALS['digest_updates'])->toBe([[100, 200], [100, 200]]);
})->with(['update', 'save']);

it('fails safely on missing metadata or initial progress storage failures', function ($failure) {
	syslog_test_digest_setup();
	$GLOBALS['digest_failure'] = $failure;
	expect(syslog_backfill_message_digest())->toBe(-1)
		->and($GLOBALS['digest_updates'])->toBe([]);
})->with(['read', 'max', 'save']);

it('rejects malformed progress and invalid budgets without updating history', function () {
	syslog_test_digest_setup();
	$GLOBALS['digest_state']['digest_backfill_syslog'] = '{bad';
	expect(syslog_backfill_message_digest())->toBe(-1)
		->and(syslog_backfill_message_digest(0))->toBe(-1)
		->and($GLOBALS['digest_updates'])->toBe([]);
});

it('skips absent digest columns and runs only after master registration', function () {
	syslog_test_digest_setup();
	test_override('syslog_db_column_exists', static fn () => false);
	expect(syslog_backfill_message_digest())->toBe(0);
	$process = plugin_test_read_source('syslog_process.php');
	expect(strpos($process, 'syslog_backfill_message_digest()'))->toBeGreaterThan(strpos($process, "register_process_start('syslog', 'master'"))
		->and(strpos($process, 'syslog_backfill_message_digest()'))->toBeGreaterThan(strpos($process, 'if ($child > 0)'));
});
