<?php

it('builds integer re-alert choices without lossy float key warnings', function ($interval) {
	if (!function_exists('get_repeat_array')) {
		$source = plugin_test_read_source('syslog_alerts.php');
		$start = strpos($source, 'function get_repeat_array()');
		$end = strpos($source, "\n/**", $start);
		eval(substr($source, $start, $end - $start));
	}

	test_override('read_config_option', fn ($name) => $name === 'poller_interval' ? $interval : '');
	$options = get_repeat_array();
	$multiplier = 300 / ($interval > 0 ? $interval : 300);

	expect($options[0])->toBe('Not Set')
		->and($options[(int) ($multiplier * 2)])->toBe('10 Minutes')
		->and(array_filter(array_keys($options), fn ($key) => !is_int($key)))->toBeEmpty();
})->with([0, 60, 120, 300]);
