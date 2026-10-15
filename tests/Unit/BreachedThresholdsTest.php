<?php

beforeEach(function () {
	syslog_load_plugin_source('lib/syslog_thresholds.php');
	$GLOBALS['config']['url_path'] = '/cacti/';
});

it('renders device and shared scopes with escaped values and recovery deadlines', function () {
	test_override('syslog_db_fetch_cell', static fn() => 2);
	test_override('syslog_db_fetch_assoc', static fn() => [
		['name' => '<rule>', 'alert_id' => 7, 'level' => 1, 'scope_key' => 'host<a>', 'host_id' => 3, 'source_poller_id' => 2, 'breached_at' => 1000, 'last_match' => 1200, 'expires_at' => 1500],
		['name' => 'Shared rule', 'alert_id' => 8, 'level' => 0, 'scope_key' => '', 'host_id' => null, 'source_poller_id' => 1, 'breached_at' => null, 'last_match' => 1200, 'expires_at' => 1500]
	]);
	ob_start();
	syslog_breached_thresholds();
	$html = ob_get_clean();
	expect($html)->toContain('&lt;rule&gt;', 'host&lt;a&gt;', 'All matching devices', 'Unknown', 'syslog_alerts.php?action=edit&amp;id=7', 'reset=1', 'rfilter=host')
		->not->toContain('<rule>', '<a>');
});

it('renders an explicit healthy state without edit links for read only viewers', function () {
	test_override('syslog_db_fetch_cell', static fn() => 0);
	test_override('syslog_db_fetch_assoc', static fn() => []);
	ob_start(); syslog_breached_thresholds(); $html = ob_get_clean();
	expect($html)->toContain('No thresholds are currently breached.')->not->toContain('syslog_alerts.php');
});

it('bounds pagination and restricts configuration links to authorized users', function () {
	test_override('syslog_db_fetch_cell', static fn() => 101);
	test_override('get_filter_request_var', static fn() => 99999);
	test_override('api_plugin_user_realm_auth', static fn() => false);
	$sql = '';
	test_override('syslog_db_fetch_assoc', function ($query) use (&$sql) {
		$sql = $query;
		return [['name' => 'Rule', 'alert_id' => 1, 'level' => 0, 'scope_key' => '', 'host_id' => null, 'source_poller_id' => 1, 'breached_at' => 1, 'last_match' => 2, 'expires_at' => 3]];
	});
	ob_start(); syslog_breached_thresholds(); $html = ob_get_clean();
	expect($sql)->toContain('LIMIT 50 OFFSET 100', 's.rule_date = a.date', 's.expires_at > UNIX_TIMESTAMP()');
	expect($html)->toContain('Previous')->not->toContain('syslog_alerts.php', '>Next<');
});
