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

it('rejects PID values that are not integers, floats or strings', function () {
	expect(syslog_compat_pid_is_valid(null))->toBeFalse()
		->and(syslog_compat_pid_is_valid(true))->toBeFalse()
		->and(syslog_compat_pid_is_valid([12345]))->toBeFalse();
});

it('accepts positive, in-range PIDs', function () {
	expect(syslog_compat_pid_is_valid(1))->toBeTrue()
		->and(syslog_compat_pid_is_valid('12345'))->toBeTrue()
		->and(syslog_compat_pid_is_valid('2147483647'))->toBeTrue();
});

it('resolves the script path from a cmdline and ignores non-php arguments', function () {
	expect(syslog_compat_script_from_cmdline("php\0" . __FILE__))->toBe(realpath(__FILE__))
		->and(syslog_compat_script_from_cmdline("php\0/nonexistent/worker.php"))->toBe('/nonexistent/worker.php')
		->and(syslog_compat_script_from_cmdline("php\0-r\0echo 1;"))->toBeFalse();
});

it('treats the current process as sharing its own identity', function () {
	expect(syslog_compat_pid_identity_matches(getmypid()))->toBeTrue();
});

it('returns null when the target process has no readable cmdline', function () {
	// No reader argument exercises the real /proc reader against a PID that
	// cannot exist, so the identity check cannot be established.
	expect(syslog_compat_pid_identity_matches(2147483646))->toBeNull();
});

it('matches identical injected script identities', function () {
	$script = realpath(__FILE__);
	$reader = static function ($pid) use ($script) {
		return "php\0" . $script;
	};

	expect(syslog_compat_pid_identity_matches(999001, $reader))->toBeTrue();
});

it('returns null when an injected identity cannot be confirmed', function () {
	$script = realpath(__FILE__);
	$reader = static function ($pid) use ($script) {
		return $pid === getmypid() ? "php\0" . $script : "php\0-r\0noop";
	};

	expect(syslog_compat_pid_identity_matches(999002, $reader))->toBeNull();
});

it('reports a mismatch when the two scripts differ', function () {
	$reader = static function ($pid) {
		return $pid === getmypid()
			? "php\0/opt/cacti/poller.php"
			: "php\0/opt/cacti/other.php";
	};

	expect(syslog_compat_pid_identity_matches(999003, $reader))->toBeFalse();
});

it('signals valid PIDs and refuses out-of-range or protected PIDs', function () {
	expect(cacti_process_kill(0, 15))->toBeFalse()
		->and(cacti_process_kill(1, 15))->toBeFalse()
		->and(cacti_process_kill(getmypid(), 0))->toBeTrue();
});
