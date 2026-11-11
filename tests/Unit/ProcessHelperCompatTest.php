<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The Cacti process-helper fallbacks (used on Cacti older than 1.2.32) must
 * reject out-of-range PIDs before posix_kill() narrows them to pid_t, and must
 * confirm command identity so a reused PID is not mistaken for a live worker.
 */

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
});

it('rejects non-positive, malformed and out-of-range PIDs', function () {
	expect(syslog_compat_pid_is_valid(0))->toBeFalse()
		->and(syslog_compat_pid_is_valid(-1))->toBeFalse()
		->and(syslog_compat_pid_is_valid('-5'))->toBeFalse()
		->and(syslog_compat_pid_is_valid('abc'))->toBeFalse()
		->and(syslog_compat_pid_is_valid('1.5'))->toBeFalse()
		->and(syslog_compat_pid_is_valid('99999999999'))->toBeFalse()
		->and(syslog_compat_pid_is_valid('4294967295'))->toBeFalse();
});

it('accepts positive, in-range PIDs', function () {
	expect(syslog_compat_pid_is_valid(1))->toBeTrue()
		->and(syslog_compat_pid_is_valid('12345'))->toBeTrue()
		->and(syslog_compat_pid_is_valid('2147483647'))->toBeTrue();
});

it('treats the current process as sharing its own identity', function () {
	expect(syslog_compat_pid_identity_matches(getmypid()))->toBeTrue();
});
