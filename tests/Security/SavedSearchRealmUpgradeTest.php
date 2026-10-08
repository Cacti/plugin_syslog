<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

it('adds saved-search access to a legacy admin realm without changing its id', function () {
	syslog_load_plugin_source('setup.php');
	$realms = [];
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

	foreach (['missing', 'shared', 'standalone'] as $layout) {
		$realms = [['id' => 7, 'file' => 'syslog_alerts.php,syslog_removal.php,syslog_reports.php', 'display' => 'Legacy Admin']];
		if ($layout === 'shared') {
			$realms[0]['file'] .= ',syslog_saved_searches.php';
		} elseif ($layout === 'standalone') {
			$realms[] = ['id' => 8, 'file' => 'syslog_saved_searches.php', 'display' => 'Templates'];
		}
		$writes = 0;
		syslog_upgrade_saved_search_realm();
		expect($realms[0]['id'])->toBe(7)
			->and($writes)->toBe($layout === 'missing' ? 1 : 0);
		if ($layout === 'missing') {
			expect($realms[0]['file'])->toContain('syslog_saved_searches.php');
		}
		syslog_upgrade_saved_search_realm();
		expect($writes)->toBe($layout === 'missing' ? 1 : 0, 'Repeated upgrade is idempotent');
	}

	$realms = [];
	$writes = 0;
	syslog_upgrade_saved_search_realm();
	expect($writes)->toBe(0, 'Missing admin realm fails closed');
});
