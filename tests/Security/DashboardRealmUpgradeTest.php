<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

it('adds dashboard access to a legacy admin realm without changing its id', function () {
	syslog_load_plugin_source('setup.php');
	$realms = [['id' => 7, 'file' => 'syslog_alerts.php,syslog_removal.php,syslog_reports.php,syslog_saved_searches.php', 'display' => 'Legacy Admin']];
	$writes = 0;

	test_override('db_fetch_assoc_prepared', function () use (&$realms) {
		return $realms;
	});
	test_override('db_execute_prepared', function ($sql, $params) use (&$realms, &$writes) {
		foreach ($realms as &$realm) {
			if ($realm['id'] == $params[1]) {
				$realm['file'] = $params[0];
			}
		}
		$writes++;
		return true;
	});

	syslog_upgrade_dashboard_realm();
	expect($realms[0]['id'])->toBe(7)
		->and($realms[0]['file'])->toContain('syslog_dashboards.php')
		->and($writes)->toBe(1);
	syslog_upgrade_dashboard_realm();
	expect($writes)->toBe(1, 'Repeated upgrade is idempotent');

	$realms = [];
	$writes = 0;
	syslog_upgrade_dashboard_realm();
	expect($writes)->toBe(0, 'Missing admin realm fails closed');
});
