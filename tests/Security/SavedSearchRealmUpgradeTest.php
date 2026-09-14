<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the saved-search realm upgrade: legacy installs
 * must gain access to the saved-search template realm without granting
 * template-only users any other admin page, and the migration must be
 * idempotent and fail closed when the admin realm itself is missing.
 */

it('folds the saved-search realm into the admin realm without widening existing grants', function () {
	// syslog_upgrade_saved_search_realm() (setup.php) reads/writes this by
	// `global`; binding it here up front keeps every reference below on the
	// same storage instead of a closure-local shadow.
	global $user_auth_realm_filenames, $test_realms, $test_grants, $test_writes, $test_replications;

	$this->loadPluginSource('setup.php');

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
		global $user_auth_realm_filenames, $test_grants;

		return in_array($user_auth_realm_filenames[$file] ?? 0, $test_grants, true);
	});

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

			$before             = $test_realms;
			$test_grants        = $grants;
			$test_writes        = 0;
			$test_replications  = 0;

			syslog_upgrade_saved_search_realm();

			$allowed = in_array(107, $grants, true) || ($layout === 'standalone' && in_array(108, $grants, true));

			if (api_plugin_user_realm_auth('syslog_saved_searches.php') !== $allowed) {
				throw new RuntimeException("$layout: template access");
			}

			if (api_plugin_user_realm_auth('syslog_alerts.php') !== in_array(107, $grants, true)) {
				throw new RuntimeException("$layout: admin access unchanged");
			}

			if ($test_grants !== $grants) {
				throw new RuntimeException('Grants remain unchanged');
			}

			if ($test_realms[0]['id'] !== 7) {
				throw new RuntimeException('Admin realm ID preserved');
			}

			if ($layout !== 'missing' && $test_realms !== $before) {
				throw new RuntimeException('Existing mappings preserved');
			}

			syslog_upgrade_saved_search_realm();

			// $test_writes is a running total across both calls: idempotent
			// re-runs must not add a second write once the mapping exists.
			if ($test_writes !== ($layout === 'missing' ? 1 : 0)) {
				throw new RuntimeException('Migration is idempotent');
			}

			if ($test_replications !== $test_writes) {
				throw new RuntimeException('Only changed mappings replicated');
			}
		}
	}

	$test_realms = [];
	$user_auth_realm_filenames = [];

	syslog_upgrade_saved_search_realm();

	expect($user_auth_realm_filenames)->toBe([], 'Missing admin realm fails closed');
});
