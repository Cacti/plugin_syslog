<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU Public License as published by     |
 | the Free Software Foundation; either version 2 of the License, or      |
 | (at your option) any later version.                                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the master/worker race: the master poller must
 * not continue past a parallel phase until the worker children have
 * registered, completed, and recorded their statistics.  Before the fix,
 * syslog_wait_workers() was defined but never called, and the master raced
 * ahead of its children after a bare sleep(2), duplicating or stranding
 * incoming records.
 *
 * The wait helpers live in functions.php so the test bootstrap can load
 * them; the wiring inside the syslog_process.php master flow is covered
 * by the source scan in the last test.  The fakes feed the wait a
 * snapshot of the process table per poll, so each scenario pins down the
 * exact poll sequence.  Note that the Stage 2 debug line calls
 * syslog_workers_running() a second time per iteration, which each
 * scenario's snapshot list accounts for.
 */

syslog_load_plugin_source('functions.php');

/**
 * Install fakes that replay a fixed snapshot of the process table on
 * every poll and record every log line and DELETE issued.
 *
 * @param array $snapshots List of pid arrays, one per db_fetch_assoc call
 * @param array $logs      Filled with every cacti_log() message
 * @param array $deletes   Filled with every db_execute() statement
 * @param array $stats     Child numbers that recorded statistics
 *
 * @return void
 */
function syslog_wait_test_environment(array $snapshots, array &$logs, array &$deletes, array $stats = [1, 2]) {
	$logs    = [];
	$deletes = [];
	$poll    = 0;

	test_override('db_fetch_assoc', function ($sql) use ($snapshots, &$poll) {
		// The last snapshot repeats so a runaway loop fails the poll
		// count assertion below rather than looping the suite forever.
		return array_map(function ($pid) {
			return ['pid' => $pid];
		}, $snapshots[min($poll++, max(0, cacti_sizeof($snapshots) - 1))]);
	});

	test_override('cacti_process_still_running', function ($pid) {
		return true;
	});

	test_override('db_execute', function ($sql) use (&$deletes) {
		$deletes[] = $sql;

		return true;
	});

	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});

	test_override('read_config_option', function ($name) use ($stats) {
		if (preg_match('/^stats_syslog_child_(\d+)$/', $name, $m) && in_array((int) $m[1], $stats, true)) {
			return json_encode(['child' => (int) $m[1], 'moved' => 10, 'resolved' => 0, 'runtime' => 0.1]);
		}

		return '';
	});

	return function () use (&$poll) {
		return $poll;
	};
}

it('waits while registered workers are still running', function () {
	$logs    = [];
	$deletes = [];

	$polls = syslog_wait_test_environment(
		[[101, 102], [101], [101], []],
		$logs, $deletes
	);

	// Stage 1 sees both children registered.  Stage 2 must keep polling
	// while they run: first poll still sees a child, the debug line
	// counts a second poll, and only then do both finish.
	syslog_wait_workers(2);

	expect($logs)->toBeEmpty('A clean drain must not log any warnings');
	expect($deletes)->toBeEmpty('Live workers must not have their process rows removed');
	expect($polls())->toBe(4, 'The master must poll the process table until the children drain');
});

it('removes the stale row of a worker that died without unregistering', function () {
	$logs    = [];
	$deletes = [];

	$polls = syslog_wait_test_environment(
		[[201], [201], []],
		$logs, $deletes
	);

	// The worker registered, then died without unregistering: a bare
	// running-count loop would block the master forever on its stale
	// process table row.
	$liveness = 0;

	test_override('cacti_process_still_running', function ($pid) use (&$liveness) {
		return $liveness++ === 0;
	});

	syslog_wait_workers(1);

	expect($deletes)->toHaveCount(1, 'The dead worker process row must be removed');
	expect($deletes[0])->toContain('DELETE FROM processes');
	expect($deletes[0])->toContain("pid = 201");
	expect($logs)->toContain('WARNING: Syslog worker PID 201 is no longer running, removing its stale process entry');
	expect($logs)->not->toContain('WARNING: Syslog worker(s) exited without recording completion: 1');
});

it('warns when a worker exits without recording its statistics', function () {
	$logs    = [];
	$deletes = [];

	$polls = syslog_wait_test_environment(
		[[301, 302], []],
		$logs, $deletes,
		[1]
	);

	// Both children registered and drained, but only worker 1 recorded
	// its statistics; worker 2 never came up.
	syslog_wait_workers(2);

	expect($logs)->toContain('WARNING: Syslog worker(s) exited without recording completion: 2');
});

it('waits out the registration window instead of racing ahead at zero', function () {
	$logs    = [];
	$deletes = [];

	$polls = syslog_wait_test_environment(
		[[], [401, 402], []],
		$logs, $deletes
	);

	/*
	 * No child has reached the process table on the first poll.  The
	 * registration grace must hold the master here instead of letting
	 * the zero count look like a completed phase, which is precisely
	 * the race the bare sleep(2) used to hide.
	 */
	syslog_wait_workers(2);

	expect($polls())->toBeGreaterThanOrEqual(3, 'The wait must re-poll while children register');
	expect($logs)->toBeEmpty('Registered children that drain cleanly must not log warnings');
	expect($deletes)->toBeEmpty();
});

it('wires the wait into both parallel phases of the master poller', function () {
	$root    = dirname(__DIR__, 2);
	$process = file_get_contents($root . '/syslog_process.php');

	// The master must block after launching each phase.
	expect(substr_count($process, 'syslog_wait_workers($launched);'))->toBe(2, 'Both launch sites must be followed by a wait');

	foreach (['references', 'transfer'] as $phase) {
		$launch = strpos($process, "syslog_launch_workers('$phase'");

		if ($launch === false) {
			throw new RuntimeException("The '$phase' phase launch is missing from the master flow");
		}

		if (strpos($process, 'syslog_wait_workers($launched);', $launch) === false) {
			throw new RuntimeException("The '$phase' phase launch is not followed by a wait");
		}
	}

	// A child that cannot register must leave a breadcrumb and exit
	// non-zero, never exit silently.
	$register = strpos($process, "register_process_start('syslog', 'child'");

	if ($register === false) {
		throw new RuntimeException('The child registration call is missing');
	}

	$block = substr($process, $register, strpos($process, 'exit(1);', $register) - $register);

	expect(strpos($block, 'cacti_log('))->not->toBeFalse('Registration failure must be logged');

	if (preg_match("/register_process_start\('syslog', 'child'[^;]*;\s*\n\s*exit\(0\);/", $process)) {
		throw new RuntimeException('A child registration failure must not exit silently');
	}
});