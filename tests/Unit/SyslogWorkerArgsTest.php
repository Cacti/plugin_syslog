<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Coverage for the worker argument validator used by parallel syslog
 * workers.  All worker arguments originate from the command line, so a
 * strict validation matrix must be enforced before any value reaches the
 * database layer: child number, run identifier, phase name, and seq
 * bounds.
 */

syslog_load_plugin_source('functions.php');

it('accepts a fully valid worker argument set', function () {
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'references', 1, 100))->toBeTrue();
	expect(syslog_validate_worker_args(16, str_repeat('0', 32), 'transfer', 999, 1000))->toBeTrue();
});

it('rejects an invalid child number', function () {
	expect(syslog_validate_worker_args(0, str_repeat('a', 32), 'references', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args(-1, str_repeat('a', 32), 'references', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args('x', str_repeat('a', 32), 'references', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args('1.5', str_repeat('a', 32), 'references', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args('01', str_repeat('a', 32), 'references', 1, 10))->toBeFalse();
});

it('rejects an invalid run identifier', function () {
	expect(syslog_validate_worker_args(1, '', 'references', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args(1, 'zzzz', 'references', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args(1, strtoupper(str_repeat('a', 32)), 'references', 1, 10))->toBeFalse('Uppercase hex rejected');
	expect(syslog_validate_worker_args(1, str_repeat('a', 31), 'references', 1, 10))->toBeFalse('Too short rejected');
	expect(syslog_validate_worker_args(1, str_repeat('a', 33), 'references', 1, 10))->toBeFalse('Too long rejected');
});

it('rejects an unknown phase', function () {
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'alerts', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), '', 1, 10))->toBeFalse();
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'REFERENCES', 1, 10))->toBeFalse('Case sensitive');
});

it('rejects invalid or inverted seq bounds', function () {
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'transfer', 10, 1))->toBeFalse('Inverted range rejected');
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'transfer', -5, 10))->toBeFalse('Negative start rejected');
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'transfer', 'x', 10))->toBeFalse('Non numeric start rejected');
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'transfer', 1, 'y'))->toBeFalse('Non numeric end rejected');
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'transfer', 0, 0))->toBeFalse('Zero range rejected for workers');
});

it('accepts an equal start and end as a single record slice', function () {
	expect(syslog_validate_worker_args(1, str_repeat('a', 32), 'transfer', 42, 42))->toBeTrue();
});