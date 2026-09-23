<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for per-message rule-editor links, severity color
 * classes, and the logical-search "has a date predicate" detector.
 */

it('builds rule-editor links only for permitted, valid main-table records', function () {
	syslog_load_plugin_source('functions.php');

	$allowed = [];

	test_override('api_plugin_user_realm_auth', function ($page) use (&$allowed) {
		return in_array($page, $allowed, true);
	});

	expect(syslog_message_rule_links(42, 'main', '2026-09-13 00:12:42'))->toBe([], 'Readers have no rule actions');

	$allowed = ['syslog_alerts.php', 'syslog_removal.php', 'syslog_rule_administrator.php'];
	$links = syslog_message_rule_links(43, 'main', '2026-09-13 00:12:42');

	foreach (['alarm' => 'syslog_alerts.php', 'removal' => 'syslog_removal.php'] as $action => $page) {
		expect(strpos($links[$action], $page . '?'))->toBe(0, 'Correct editor');

		parse_str(parse_url($links[$action], PHP_URL_QUERY), $query);

		expect($query['id'] === '43' && $query['action'] === 'newedit' && $query['date'] === '2026-09-13 00:12:42')->toBeTrue('Editor targets the selected record');
	}

	foreach (['removed', 'alerts', ''] as $source) {
		expect(syslog_message_rule_links(42, $source, ''))->toBe([], 'Unsupported source must not resolve an unrelated main record');
	}

	expect(syslog_message_rule_links('42&evil=1', 'main', ''))->toBe([], 'IDs must be numeric');

	foreach ([0 => 'logEmergency', 1 => 'logAlert', 2 => 'logCritical', 3 => 'logError', 4 => 'logWarning', 5 => 'logNotice', 6 => 'logInfo', 7 => 'logDebug'] as $id => $class) {
		expect(syslog_priority_class($id))->toBe($class, 'Severity colors retained');
	}

	expect(syslog_search_has_time(syslog_parse_logical_search('host = "router" OR logtime last "604800"')))->toBeTrue('Authored date condition preserved');
	expect(syslog_search_has_time(syslog_parse_logical_search('message contains "logtime"')))->toBeFalse('Literal date field name is not a date predicate');
});
