<?php

it('marks a threshold rule at the exact threshold without clearing prior breaches', function () {
	syslog_load_plugin_source('includes/functions.php');
	$calls = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$calls) {
		$calls[] = [$sql, $params];
		return true;
	});
	$rule = ['id' => 42, 'method' => 1, 'num' => 5, 'threshold_breached' => 0];
	syslog_alert_record_threshold_breach($rule, 4);
	syslog_alert_record_threshold_breach($rule, 0);
	expect($calls)->toBe([]);
	syslog_alert_record_threshold_breach($rule, 5);
	expect($calls)->toBe([['UPDATE syslog_alert SET threshold_breached = 1 WHERE id = ? AND threshold_breached = 0', [42]]]);
	$rule['threshold_breached'] = 1;
	syslog_alert_record_threshold_breach($rule, 6);
	syslog_alert_record_threshold_breach($rule, 0);
	$rule['threshold_breached'] = 0;
	$rule['method'] = 0;
	syslog_alert_record_threshold_breach($rule, 10);
	expect($calls)->toHaveCount(1);
});

it('does not export threshold breach runtime state', function () {
	syslog_load_plugin_source('includes/functions.php');
	$export = json_decode(syslog_rules_array2json('syslog_alert', [
		['id' => 42, 'hash' => 'test', 'name' => 'Threshold', 'threshold_breached' => 1]
	]), true);
	expect($export['templates'][0])->not->toHaveKey('threshold_breached');
});
