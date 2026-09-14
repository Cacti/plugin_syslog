<?php

require dirname(__DIR__, 2) . '/setup.php';

function db_fetch_assoc_prepared($sql, $params) {
	return $GLOBALS['test_realms'];
}

function db_execute_prepared($sql, $params) {
	foreach ($GLOBALS['test_realms'] as &$realm) {
		if ($realm['id'] == $params[1]) {
			$realm['file'] = $params[0];
		}
	}
	$GLOBALS['test_writes']++;
	return true;
}

function api_plugin_replicate_config() {
	$GLOBALS['test_replications']++;
}

function api_plugin_user_realm_auth($file) {
	return in_array($GLOBALS['user_auth_realm_filenames'][$file] ?? 0, $GLOBALS['test_grants'], true);
}

function realm_assert($value, $message) {
	if (!$value) {
		throw new RuntimeException($message);
	}
}

foreach (['missing', 'shared', 'standalone'] as $layout) {
	foreach ([[], [107], [108]] as $grants) {
		$test_realms = [['id' => 7, 'file' => 'syslog_alerts.php,syslog_removal.php,syslog_reports.php']];
		$user_auth_realm_filenames = ['syslog_alerts.php' => 107];
		if ($layout === 'shared') {
			$test_realms[0]['file'] .= ',syslog_saved_searches.php';
			$user_auth_realm_filenames['syslog_saved_searches.php'] = 107;
		} elseif ($layout === 'standalone') {
			$test_realms[] = ['id' => 8, 'file' => 'syslog_saved_searches.php'];
			$user_auth_realm_filenames['syslog_saved_searches.php'] = 108;
		}
		$before = $test_realms;
		$test_grants = $grants;
		$test_writes = $test_replications = 0;
		syslog_upgrade_saved_search_realm();
		$allowed = in_array(107, $grants, true) || ($layout === 'standalone' && in_array(108, $grants, true));
		realm_assert(api_plugin_user_realm_auth('syslog_saved_searches.php') === $allowed, "$layout: template access");
		realm_assert(api_plugin_user_realm_auth('syslog_alerts.php') === in_array(107, $grants, true), "$layout: admin access unchanged");
		realm_assert($test_grants === $grants, 'Grants remain unchanged');
		realm_assert($test_realms[0]['id'] === 7, 'Admin realm ID preserved');
		if ($layout !== 'missing') {
			realm_assert($test_realms === $before, 'Existing mappings preserved');
		}
		syslog_upgrade_saved_search_realm();
		realm_assert($test_writes === ($layout === 'missing' ? 1 : 0), 'Migration is idempotent');
		realm_assert($test_replications === $test_writes, 'Only changed mappings replicated');
	}
}

$test_realms = [];
$user_auth_realm_filenames = [];
syslog_upgrade_saved_search_realm();
realm_assert($user_auth_realm_filenames === [], 'Missing admin realm fails closed');
echo "saved_search_realm_upgrade_test passed\n";
