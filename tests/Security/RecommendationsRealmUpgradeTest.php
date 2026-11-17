<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

afterEach(function () {
	syslog_test_reset_globals();
});

it('adds the recommendations page to the Syslog Administration realm without changing its id', function () {
	syslog_load_plugin_source('setup.php');
	$realms = [
		['id' => 3, 'file' => 'syslog_alerts.php,syslog_removal.php,syslog_reports.php', 'display' => 'Rule Viewer'],
		['id' => 7, 'file' => 'syslog_saved_searches.php,syslog_dashboards.php', 'display' => 'Syslog Administration']
	];
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

	syslog_upgrade_recommendations_realm();
	expect($realms[1]['id'])->toBe(7)
		->and($realms[1]['file'])->toBe('syslog_saved_searches.php,syslog_dashboards.php,syslog_recommendations.php')
		->and($realms[0]['file'])->not->toContain('syslog_recommendations.php')
		->and($writes)->toBe(1);

	syslog_upgrade_recommendations_realm();
	expect($writes)->toBe(1, 'Repeated upgrade is idempotent');

	$realms = [['id' => 3, 'file' => 'syslog_alerts.php', 'display' => 'Rule Viewer']];
	$writes = 0;
	syslog_upgrade_recommendations_realm();
	expect($writes)->toBe(0, 'Missing administration realm is left for the realm creation step');
});
