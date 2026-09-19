<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the dashboard realm upgrade: legacy installs must
 * gain access to the dashboards console page inside the admin realm without
 * the realm id changing (which would orphan existing grants), and the
 * migration must be idempotent and fail closed when the admin realm itself
 * is missing.
 */

it('folds the dashboards realm into the admin realm without changing the realm id', function () {
	// syslog_upgrade_dashboard_realm() (setup.php) reads/writes this by
	// `global`; binding it here up front keeps every reference below on the
	// same storage instead of a closure-local shadow.
	global $user_auth_realm_filenames, $test_realms, $test_writes, $test_replications;

	syslog_load_plugin_source('setup.php');

	test_override('db_fetch_assoc_prepared', function ($sql, $params) {
		global $test_realms;

		return $test_realms;
	});

	test_override('db_execute_prepared', function ($sql, $params) {
		global $test_realms, $test_writes;

		foreach ($test_realms as &$realm) {
			if ($realm['id'] == $params[1]) {
				$realm['file'] = $params[0];
			}
		}

		$test_writes++;

		return true;
	});

	test_override('api_plugin_replicate_config', function () {
		global $test_replications;

		$test_replications++;
	});

	test_override('api_plugin_user_realm_auth', function ($file) {
		global $user_auth_realm_filenames;

		return true;
	});

	// An install whose admin realm predates the dashboards page.
	$test_realms = [['id' => 7, 'file' => 'syslog_alerts.php,syslog_removal.php,syslog_reports.php,syslog_saved_searches.php']];
	$user_auth_realm_filenames = ['syslog_alerts.php' => 107];

	$test_writes       = 0;
	$test_replications = 0;

	syslog_upgrade_dashboard_realm();

	if ($test_realms[0]['id'] !== 7) {
		throw new RuntimeException('Admin realm ID preserved');
	}

	if (!str_contains($test_realms[0]['file'], 'syslog_dashboards.php')) {
		throw new RuntimeException('Dashboards page added to the admin realm');
	}

	if (($user_auth_realm_filenames['syslog_dashboards.php'] ?? 0) !== 107) {
		throw new RuntimeException('Current request can reach the dashboards page');
	}

	if ($test_replications !== $test_writes) {
		throw new RuntimeException('Only changed mappings replicated');
	}

	syslog_upgrade_dashboard_realm();

	if ($test_writes !== 1) {
		throw new RuntimeException('Migration is idempotent');
	}

	// An install already carrying the mapping must not be touched.
	$before       = $test_realms;
	$test_writes  = 0;

	syslog_upgrade_dashboard_realm();

	if ($test_realms !== $before || $test_writes !== 0) {
		throw new RuntimeException('Existing mapping preserved');
	}

	// Missing admin realm: nothing is written.
	$test_realms = [];
	$user_auth_realm_filenames = [];
	$test_writes = 0;

	syslog_upgrade_dashboard_realm();

	if ($test_writes !== 0 || $user_auth_realm_filenames !== []) {
		throw new RuntimeException('Missing admin realm fails closed');
	}

	expect(true)->toBeTrue();
});