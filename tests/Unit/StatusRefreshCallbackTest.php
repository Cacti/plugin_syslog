<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The Status tab saves its auto-refresh interval through a callback instead of
 * reloading the page, and stops registering Cacti's page refresh.
 */

it('saves a valid Status refresh interval through a CSRF-protected callback', function () {
	$save   = syslog_test_extract_function('syslog.php', 'syslog_status_refresh_save');
	$method = $_SERVER['REQUEST_METHOD'] ?? null;
	$saved  = [];

	$GLOBALS['page_refresh_interval'] = [0 => 'Never', 60 => '1 Minute', 300 => '5 Minutes'];
	test_override('set_user_setting', function ($name, $value) use (&$saved) {
		$saved[] = [$name, $value];
	});

	try {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['request'] = ['refresh' => '60'];
		expect(json_decode($save(), true))->toBe(['ok' => true])
			->and($saved)->toBe([['syslog_status_refresh', 60]]);

		foreach (['61', '-5', 'abc', ''] as $invalid) {
			$GLOBALS['request'] = ['refresh' => $invalid];
			expect(json_decode($save(), true))->toHaveKey('error');
		}

		$GLOBALS['request'] = ['refresh' => '300'];
		test_override('csrf_check', fn() => false);
		expect(json_decode($save(), true))->toHaveKey('error');

		test_override('csrf_check', fn() => true);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		expect(json_decode($save(), true))->toHaveKey('error')
			->and($saved)->toHaveCount(1);
	} finally {
		if ($method === null) {
			unset($_SERVER['REQUEST_METHOD']);
		} else {
			$_SERVER['REQUEST_METHOD'] = $method;
		}

		$GLOBALS['request'] = [];
	}
});

it('defaults the Status refresh interval to the log viewer refresh', function () {
	$interval = syslog_test_extract_function('syslog.php', 'syslog_status_refresh_interval');
	$settings = ['syslog_refresh' => '120'];

	test_override('read_config_option', fn($name) => $name === 'syslog_refresh' ? '300' : '');
	test_override('read_user_setting', function ($name, $default = '') use (&$settings) {
		return $settings[$name] ?? $default;
	});

	expect($interval())->toBe(120);

	$settings['syslog_status_refresh'] = '60';
	expect($interval())->toBe(60);

	$settings = [];
	expect($interval())->toBe(300);
});
