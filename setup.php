<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Install the Syslog plugin, registering its hooks, realms and database tables.
 *
 * @return bool|void True when the install action was queued or completed, false
 *                   when the Syslog config file has not been created yet, and
 *                   nothing when the install is already running in the background.
 */
function plugin_syslog_install() {
	global $config, $syslog_upgrade;
	static $bg_inprocess = false;

	require_once(__DIR__ . '/includes/installer.php');
	require_once(__DIR__ . '/includes/schema.php');
	require_once(__DIR__ . '/includes/settings.php');

	syslog_determine_config();

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	} else {
		raise_message('syslog_info', __('Please rename either your config.php.dist or config_local.php.dist files in the syslog directory, and change setup your database before installing.', 'syslog'), MESSAGE_LEVEL_ERROR);

		return false;
	}

	syslog_connect();

	/** @var string $syslogdb_default The name of the Syslog database, defined in the Syslog config file. */
	$syslog_exists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `$syslogdb_default` LIKE 'syslog'"));

	// ================= input validation =================
	get_filter_request_var('days');
	// ====================================================

	api_plugin_register_hook('syslog', 'config_arrays',         'syslog_config_arrays',        'includes/settings.php');
	api_plugin_register_hook('syslog', 'draw_navigation_text',  'syslog_draw_navigation_text', 'includes/navigation.php');
	api_plugin_register_hook('syslog', 'config_settings',       'syslog_config_settings',      'includes/settings.php');
	api_plugin_register_hook('syslog', 'settings_bottom',       'syslog_settings_bottom',      'includes/settings.php', 1);
	api_plugin_register_hook('syslog', 'top_header_tabs',       'syslog_show_tab',             'includes/navigation.php');
	api_plugin_register_hook('syslog', 'top_graph_header_tabs', 'syslog_show_tab',             'includes/navigation.php');
	api_plugin_register_hook('syslog', 'poller_bottom',         'syslog_poller_bottom',        'includes/processing.php');
	api_plugin_register_hook('syslog', 'graph_buttons',         'syslog_graph_buttons',        'includes/navigation.php');
	api_plugin_register_hook('syslog', 'config_insert',         'syslog_config_insert',        'includes/settings.php');
	api_plugin_register_hook('syslog', 'utilities_list',        'syslog_utilities_list',       'includes/utilities.php');
	api_plugin_register_hook('syslog', 'utilities_action',      'syslog_utilities_action',     'includes/utilities.php');

	// hook for table replication
	api_plugin_register_hook('syslog', 'replicate_out',         'syslog_replicate_out',        'includes/processing.php');

	api_plugin_register_realm('syslog', 'syslog.php', 'Syslog User', 1);
	api_plugin_register_realm('syslog', 'syslog_alerts.php,syslog_removal.php,syslog_reports.php', 'Rule Viewer', 1);
	api_plugin_register_realm('syslog', 'syslog_rule_administrator.php,syslog_device_rules.php', 'Rule Administrator', 1);
	api_plugin_register_realm('syslog', 'syslog_saved_searches.php,syslog_dashboards.php', 'Syslog Administration', 1);
	api_plugin_register_realm('syslog', 'syslog_saved_searches_share.php', 'Share Saved Templates', 1);
	api_plugin_register_realm('syslog', 'syslog_dashboards_share.php', 'Share Dashboards', 1);
	api_plugin_register_realm('syslog', 'syslog_administrator.php', 'Syslog Administrator', 1);

	if (isset_request_var('install')) {
		if (!$bg_inprocess) {
			syslog_execute_update($syslog_exists, $_REQUEST);
			$bg_inprocess = true;

			return true;
		}
	} elseif (isset($syslog_install_options) && cacti_sizeof($syslog_install_options)) {
		// hack for syslog so IBM Spectrum LSF RTM can install syslog without user interaction with preset defaults
		if (!$bg_inprocess) {
			/** @var array<string, mixed> $syslog_install_options Optional pre-configured install options, defined in the Syslog config file. */
			syslog_execute_update($syslog_exists, $syslog_install_options);
			$bg_inprocess = true;
		}
	} elseif (isset_request_var('cancel')) {
		header('Location:' . $config['url_path'] . 'plugins.php?mode=uninstall&id=syslog&uninstall&uninstall_method=all');
		exit;
	} else {
		syslog_install_advisor($syslog_exists);
		exit;
	}
}

/**
 * Perform the requested Syslog install or upgrade action.
 *
 * @param int                  $syslog_exists Whether the Syslog table already exists.
 * @param array<string, mixed> $options       The install options, either from the
 *                                            request or the Syslog config file.
 *
 * @return void
 */
function syslog_execute_update(int $syslog_exists, array $options): void {
	global $config;
	$remote_options = ['syslog_remote_enabled', 'syslog_remote_sync_rules', 'syslog_remote_store_records'];

	require_once(__DIR__ . '/includes/installer.php');
	require_once(__DIR__ . '/includes/schema.php');

	if (isset($options['cancel'])) {
		header('Location:' . $config['url_path'] . 'plugins.php?mode=uninstall&id=syslog&uninstall&uninstall_method=all');
		exit;
	}

	$requested_engine = isset($options['engine']) ? $options['engine'] : 'InnoDB';
	$compatibility_options = $options;
	if (isset($options['install'])) {
		foreach ($remote_options as $option) {
			$compatibility_options[$option] = isset($options[$option]) && $options[$option] === 'on' ? 'on' : '';
		}
	}

	if (!syslog_install_storage_engine_is_compatible($requested_engine, $compatibility_options)) {
		raise_message('syslog_error', __('Aria storage cannot be used when any Remote Data Collector option is enabled. Select InnoDB or disable all Remote Data Collector options.', 'syslog'), MESSAGE_LEVEL_ERROR);
		cacti_log('SYSLOG ERROR: Install rejected because Aria storage is incompatible with Remote Data Collector options', false, 'SYSLOG');

		if (isset($options['install'])) {
			syslog_install_advisor($syslog_exists, $options);
		}

		return;
	}

	if (!isset($options['return']) && (isset($options['install']) || array_intersect($remote_options, array_keys($options)))) {
		foreach ($remote_options as $option) {
			set_config_option($option, isset($options[$option]) && $options[$option] === 'on' ? 'on' : '');
		}
	}

	if (isset($options['return'])) {
		db_execute('DELETE FROM plugin_config WHERE directory="syslog"');
		db_execute('DELETE FROM plugin_realms WHERE plugin="syslog"');
		db_execute('DELETE FROM plugin_db_changes WHERE plugin="syslog"');
		db_execute('DELETE FROM plugin_hooks WHERE name="syslog"');
	} elseif (isset($options['upgrade_type'])) {
		if ($options['upgrade_type'] == 'truncate') {
			syslog_setup_table_new($options);
		}
	} else {
		syslog_setup_table_new($options);
	}

	set_config_option('syslog_retention', $options['days']);
}

/**
 * Uninstall the Syslog plugin, optionally removing its database tables.
 *
 * @return void
 */
function plugin_syslog_uninstall(): void {
	global $config, $syslogdb_default;

	require_once(__DIR__ . '/includes/installer.php');

	syslog_determine_config();
	syslog_connect();

	if (isset_request_var('cancel') || isset_request_var('return')) {
		header('Location:' . $config['url_path'] . 'plugins.php?header=false');
		exit;
	}

	if (isset_request_var('uninstall_method')) {
		if (get_nfilter_request_var('uninstall_method') == 'all') {
			// do the big tables first
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_removed`");

			// do the settings tables last
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_incoming`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_alert`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_remove`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_reports`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_facilities`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_statistics`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_host_facilities`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_priorities`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_logs`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_hosts`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_saved_searches`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_dashboard_panels`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_dashboards`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_dashboards_perm`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_saved_searches_perm`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_replication_output`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_replication_receipts`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_replication_collectors`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_replication_recovery`");
		} else {
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog`");
			syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_removed`");
		}
	} elseif (function_exists('syslog_uninstall_advisor')) {
		syslog_uninstall_advisor();
		exit;
	}
}

/**
 * Check that the Syslog config file has been created before installing.
 *
 * @return bool True when either config file is present, false otherwise.
 */
function plugin_syslog_check_config(): bool {
	// Here we will check to ensure everything is configured
	if (!file_exists(__DIR__ . '/config_local.php') && !file_exists(__DIR__ . '/config.php')) {
		raise_message('syslog_info', __('Please rename either your config.php.dist or config_local.php.dist files in the syslog directory, and change setup your database before installing.', 'syslog'), MESSAGE_LEVEL_ERROR);

		return false;
	}

	if (api_plugin_installed('flowview')) {
		syslog_check_upgrade();
	}

	return true;
}

/**
 * Upgrade the Syslog plugin to the newest version.
 *
 * @return bool Always false, the upgrade is handled by syslog_check_upgrade().
 */
function plugin_syslog_upgrade(): bool {
	require_once(__DIR__ . '/includes/settings.php');

	// Here we will upgrade to the newest version
	api_plugin_register_hook('syslog', 'settings_bottom', 'syslog_settings_bottom', 'includes/settings.php', 1);
	syslog_check_upgrade();

	return false;
}

/**
 * Determine which Syslog config file is present, and if it is for a remote database.
 *
 * @return void
 */
function syslog_determine_config(): void {
	global $config;

	if (!defined('SYSLOG_CONFIG')) {
		if (file_exists(__DIR__ . '/config_local.php')) {
			define('SYSLOG_CONFIG', __DIR__ . '/config_local.php');
			$config['syslog_remote_db'] = true;
		} elseif (file_exists(__DIR__ . '/config.php')) {
			define('SYSLOG_CONFIG', __DIR__ . '/config.php');
			$config['syslog_remote_db'] = false;
		}
	}
}

/**
 * Check that either Syslog config file exists and is readable.
 *
 * @return bool True when a Syslog config file is available, false otherwise.
 */
function syslog_config_safe(): bool {
	foreach ([__DIR__ . '/config_local.php', __DIR__ . '/config.php'] as $file) {
		if (file_exists($file) && is_readable($file)) {
			return true;
		}
	}

	return false;
}

/**
 * Connect to the Syslog database, either the local Cacti database or a remote one.
 *
 * @param bool $repair Skip automatic install so the CLI can repair non-destructively.
 *
 * @return bool True when a Syslog database connection is available, false otherwise.
 */
function syslog_connect(bool $repair = false): bool {
	global $config, $syslog_cnn, $syslogdb_default, $local_db_cnn_id, $remote_db_cnn_id, $syslog_incoming_config;

	syslog_determine_config();

	// Handle remote syslog processing
	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	require_once(__DIR__ . '/includes/functions.php');
	require_once(__DIR__ . '/includes/database.php');

	$connect_remote = false;
	$connected      = true;

	// Connect to the Syslog Database
	if (empty($syslog_cnn)) {
		/** @var bool $use_cacti_db Whether the Syslog tables live in the local Cacti database, defined in the Syslog config file. */
		if ($config['poller_id'] == 1) {
			if ($use_cacti_db == true) {
				$syslog_cnn = $local_db_cnn_id;
				$connected  = true;
			} else {
				$connect_remote = true;
			}
		} elseif (isset($config['syslog_remote_db'])) {
			if ($use_cacti_db == true) {
				$syslog_cnn = $local_db_cnn_id;
				$connected  = true;
			} else {
				$connect_remote = true;
			}
		} else {
			if ($use_cacti_db == true) {
				$syslog_cnn = $remote_db_cnn_id;
				$connected  = true;
			} else {
				$connect_remote = true;
			}
		}

		if ($connect_remote) {
			if (!isset($syslogdb_port)) {
				$syslogdb_port = '3306';
			}

			if (!isset($syslogdb_retries)) {
				$syslogdb_retries = '5';
			}

			if (!isset($syslogdb_ssl)) {
				$syslogdb_ssl = false;
			}

			if (!isset($syslogdb_ssl_key)) {
				$syslogdb_ssl_key = '';
			}

			if (!isset($syslogdb_ssl_cert)) {
				$syslogdb_ssl_cert = '';
			}

			if (!isset($syslogdb_ssl_ca)) {
				$syslogdb_ssl_ca = '';
			}

			/**
			 * @var string $syslogdb_hostname The hostname of the Syslog database server.
			 * @var string $syslogdb_username The username for the Syslog database server.
			 * @var string $syslogdb_password The password for the Syslog database server.
			 * @var string $syslogdb_type     The database type of the Syslog database server.
			 */
			$syslog_cnn = syslog_db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type, $syslogdb_port, $syslogdb_retries, $syslogdb_ssl, $syslogdb_ssl_key, $syslogdb_ssl_cert, $syslogdb_ssl_ca);

			if ($syslog_cnn == false) {
				print "FATAL Can not connect\n";
				$connected = false;
			}
		}

		if (!$repair && $connected && !syslog_db_table_exists('syslog') && api_plugin_is_enabled('syslog')) {
			cacti_log('Setting Up Database Tables Since they do not exist', false, 'SYSLOG');

			if (!isset($syslog_install_options)) {
				$syslog_install_options = [];
			}

			require_once(__DIR__ . '/includes/schema.php');
			syslog_setup_table_new($syslog_install_options);
		}
	}

	return $connected;
}

/**
 * Repair old installs before Cacti's auth.php checks the requested page.
 *
 * @return void
 */
function syslog_upgrade_saved_search_realm(): void {
	global $user_auth_realm_filenames;

	$admin = null;
	$template = null;
	$realms = db_fetch_assoc_prepared('SELECT id, file, display FROM plugin_realms WHERE plugin = ?', ['syslog']);

	if (is_array($realms)) {
		foreach ($realms as $realm) {
			$files = explode(',', $realm['file']);
			if (in_array('syslog_alerts.php', $files, true) && ($realm['display'] ?? '') !== 'Rule Viewer') {
				$admin = $realm;
			}
			if (in_array('syslog_saved_searches.php', $files, true)) {
				$template = $realm;
			}
		}
	}

	if ($admin === null) {
		return;
	}

	if ($template === null) {
		// Keep the realm ID: existing user and group grants must not change.
		if (!db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE id = ? AND plugin = ?',
			[$admin['file'] . ',syslog_saved_searches.php', $admin['id'], 'syslog'])) {
			return;
		}
		$template = $admin;
		api_plugin_replicate_config();
	}

	// A legacy standalone realm retains its grants. Administrators can also
	// access Templates, without granting legacy template users other admin pages.
	// Update the already-loaded map so the repair works on this request too.
	if ($template['id'] == $admin['id'] || api_plugin_user_realm_auth('syslog_alerts.php')) {
		$user_auth_realm_filenames['syslog_saved_searches.php'] = (int) $admin['id'] + 100;
	}
}

/**
 * Give pre-existing installs the Dashboards console page inside the admin realm.
 *
 * @return void
 */
function syslog_upgrade_dashboard_realm(): void {
	global $user_auth_realm_filenames;

	$realms = db_fetch_assoc_prepared('SELECT id, file, display FROM plugin_realms WHERE plugin = ?', ['syslog']);

	if (is_array($realms)) {
		foreach ($realms as $realm) {
			$files = explode(',', $realm['file']);
			if (!in_array('syslog_alerts.php', $files, true) || ($realm['display'] ?? '') === 'Rule Viewer') {
				continue;
			}

			if (in_array('syslog_dashboards.php', $files, true)) {
				return;
			}

			// Keep the realm ID: existing user and group grants must not change.
			if (!db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE id = ? AND plugin = ?',
				[$realm['file'] . ',syslog_dashboards.php', $realm['id'], 'syslog'])) {
				return;
			}
			api_plugin_replicate_config();

			// Update the already-loaded map so the repair works on this request too.
			$user_auth_realm_filenames['syslog_dashboards.php'] = (int) $realm['id'] + 100;

			return;
		}
	}
}

/** Give Rule Administrators access to device alert rules. */
function syslog_upgrade_device_rule_realm(): void {
	global $user_auth_realm_filenames;
	$realms = db_fetch_assoc_prepared('SELECT id, file, display FROM plugin_realms WHERE plugin = ?', ['syslog']);
	if (!is_array($realms)) {
		return;
	}
	foreach ($realms as $realm) {
		if (($realm['display'] ?? '') !== 'Rule Administrator') {
			continue;
		}
		$files = explode(',', $realm['file']);
		if (!in_array('syslog_device_rules.php', $files, true)) {
			$files[] = 'syslog_device_rules.php';
			if (!db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE id = ? AND plugin = ?', [implode(',', $files), $realm['id'], 'syslog'])) {
				return;
			}
			api_plugin_replicate_config();
		}
		$user_auth_realm_filenames['syslog_device_rules.php'] = (int) $realm['id'] + 100;
		return;
	}
}

/**
 * Preserve existing Syslog Administration grants as Rule Administrator grants.
 *
 * Rule pages must be registered to the Rule Viewer realm so Cacti can admit
 * read-only users before page code runs.  The administrator realm is a
 * permission-only entry that the rule pages check before every mutation.
 * Legacy combined realms must not retain Saved Search Templates or Dashboards:
 * those pages belong exclusively to the separate Syslog Administration realm.
 *
 * @return void
 */
function syslog_upgrade_rule_permissions(): void {
	global $user_auth_realm_filenames;

	$realms = db_fetch_assoc_prepared('SELECT id, file, display FROM plugin_realms WHERE plugin = ?', ['syslog']);

	if (!is_array($realms)) {
		return;
	}

	foreach ($realms as $realm) {
		$files = explode(',', $realm['file']);
		if (($realm['display'] ?? '') !== 'Syslog Administration' || !in_array('syslog_alerts.php', $files, true) || in_array('syslog_rule_administrator.php', $files, true)) {
			continue;
		}

		$files = array_values(array_diff($files, [
			'syslog_alerts.php',
			'syslog_removal.php',
			'syslog_reports.php',
			'syslog_saved_searches.php',
			'syslog_dashboards.php'
		]));
		$files[] = 'syslog_rule_administrator.php';

		if (!db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE id = ? AND plugin = ?',
			[implode(',', $files), $realm['id'], 'syslog'])) {
			return;
		}

		api_plugin_replicate_config();
		$user_auth_realm_filenames['syslog_rule_administrator.php'] = (int) $realm['id'] + 100;

		return;
	}
}

/**
 * Merge duplicate granular-rule realms created by earlier registrations.
 *
 * Cacti renders one permission checkbox per realm record.  If an install has
 * several records with the same Rule Viewer or Rule Administrator label, the
 * permissions screen repeats that label. Preserve their grants in the first
 * record and restore canonical filenames so the roles remain separate.
 *
 * @return bool Whether duplicate repair succeeded.
 */
function syslog_upgrade_consolidate_rule_realms(): bool {
	$changed = false;

	foreach (['Rule Viewer', 'Rule Administrator'] as $display) {
		$realms = db_fetch_assoc_prepared('SELECT id, file FROM plugin_realms WHERE plugin = ? AND display = ? ORDER BY id', ['syslog', $display]);
		$files = $display === 'Rule Viewer' ? 'syslog_alerts.php,syslog_removal.php,syslog_reports.php' : 'syslog_rule_administrator.php';

		if (!is_array($realms) || !$realms || (count($realms) === 1 && $realms[0]['file'] === $files)) {
			continue;
		}

		$keeper = array_shift($realms);
		if (!db_begin_transaction()) {
			return false;
		}

		foreach ($realms as $realm) {
			foreach (['user_auth_realm' => 'user_id', 'user_auth_group_realm' => 'group_id'] as $table => $owner) {
				if (!db_execute_prepared("INSERT IGNORE INTO $table ($owner, realm_id) SELECT $owner, ? FROM $table WHERE realm_id = ?", [(int) $keeper['id'] + 100, (int) $realm['id'] + 100]) ||
					!db_execute_prepared("DELETE FROM $table WHERE realm_id = ?", [(int) $realm['id'] + 100])) {
					db_rollback_transaction();
					return false;
				}
			}
			if (!db_execute_prepared('DELETE FROM plugin_realms WHERE id = ? AND plugin = ?', [(int) $realm['id'], 'syslog'])) {
				db_rollback_transaction();
				return false;
			}
		}

		// Broken migrations put the administrator filename in Viewer records.
		// Never union those files: doing so merges the two permission levels.
		if (!db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE id = ?', [$files, (int) $keeper['id']]) || !db_commit_transaction()) {
			db_rollback_transaction();
			return false;
		}
		$changed = true;
	}

	if ($changed) {
		api_plugin_replicate_config();
	}
	return true;
}

/**
 * Create granular realms on existing installations and preserve legacy grants.
 *
 * @return bool Whether realm creation and grant migration succeeded.
 */
function syslog_upgrade_create_permission_realms(): bool {
	$targets = [
		'Rule Viewer' => 'syslog_alerts.php,syslog_removal.php,syslog_reports.php',
		'Rule Administrator' => 'syslog_rule_administrator.php',
		'Syslog Administration' => 'syslog_saved_searches.php,syslog_dashboards.php',
		'Syslog Administrator' => 'syslog_administrator.php'
	];
	$realms = db_fetch_assoc_prepared('SELECT id, file, display FROM plugin_realms WHERE plugin = ?', ['syslog']);
	if (!is_array($realms)) {
		return false;
	}

	$legacy_viewers = [];
	$legacy_admins  = [];
	$missing        = [];
	foreach ($realms as $realm) {
		$files = explode(',', $realm['file']);
		if (in_array('syslog.php', $files, true) && ($realm['display'] ?? '') === 'Syslog User') {
			$legacy_viewers[] = (int) $realm['id'] + 100;
		}
		if (array_intersect($files, ['syslog_alerts.php', 'syslog_removal.php', 'syslog_reports.php'])
			&& !in_array($realm['display'] ?? '', ['Rule Viewer', 'Rule Administrator'], true)) {
			$legacy_admins[] = (int) $realm['id'] + 100;
		}
	}

	foreach ($targets as $display => $files) {
		$found = false;
		foreach ($realms as $realm) {
			if (($realm['display'] ?? '') === $display && $realm['file'] === $files) {
				$found = true;
				break;
			}
		}
		if (!$found) {
			$missing[$display] = $files;
		}
	}
	if (!$missing) {
		return true;
	}

	if (!db_begin_transaction()) {
		return false;
	}
	foreach ($missing as $display => $files) {
		if (!db_execute_prepared('INSERT INTO plugin_realms (plugin, file, display) VALUES (?, ?, ?)', ['syslog', $files, $display])) {
			db_rollback_transaction();
			return false;
		}
	}

	$updated_realms = db_fetch_assoc_prepared('SELECT id, file, display FROM plugin_realms WHERE plugin = ?', ['syslog']);
	if (!is_array($updated_realms)) {
		db_rollback_transaction();
		return false;
	}
	$target_ids = [];
	foreach ($updated_realms as $realm) {
		foreach ($targets as $display => $files) {
			if (($realm['display'] ?? '') === $display && $realm['file'] === $files) {
				$target_ids[$display] = (int) $realm['id'] + 100;
			}
		}
	}

	$sources = [
		'Rule Viewer' => $legacy_viewers,
		'Rule Administrator' => $legacy_admins,
		'Syslog Administration' => $legacy_admins
	];
	foreach ($sources as $display => $source_ids) {
		if (!isset($missing[$display], $target_ids[$display])) {
			continue;
		}
		foreach ($source_ids as $source_id) {
			foreach (['user_auth_realm' => 'user_id', 'user_auth_group_realm' => 'group_id'] as $table => $owner) {
				if (!db_execute_prepared("INSERT IGNORE INTO $table ($owner, realm_id) SELECT $owner, ? FROM $table WHERE realm_id = ?", [$target_ids[$display], $source_id])) {
					db_rollback_transaction();
					return false;
				}
			}
		}
	}

	if (!db_commit_transaction()) {
		db_rollback_transaction();
		return false;
	}
	api_plugin_replicate_config();

	return true;
}

/**
 * Upgrade the Syslog database schema for legacy installs.
 *
 * @param bool $force Run migrations even when the version is current, for CLI repair.
 *
 * @return void
 */
function syslog_check_upgrade(bool $force = false): void {
	global $config, $syslogdb_default, $syslog_levels, $syslog_upgrade;

	require_once(__DIR__ . '/includes/schema.php');
	require_once(__DIR__ . '/includes/settings.php');

	syslog_connect();
	if (!syslog_upgrade_create_permission_realms()) {
		return;
	}
	syslog_upgrade_saved_search_realm();
	syslog_upgrade_dashboard_realm();
	syslog_upgrade_rule_permissions();
	if (!syslog_upgrade_consolidate_rule_realms()) {
		return;
	}
	// Realm registration is install-only in Cacti. Legacy migrations below preserve
	// and adjust existing realm IDs without calling the guarded registration API.
	syslog_upgrade_device_rule_realm();
	syslog_refresh_permission_roles();

	// Let's only run this check if we are on a page that actually needs the data
	$files = ['plugins.php', 'syslog.php', 'syslog_removal.php', 'syslog_alerts.php', 'syslog_device_rules.php', 'syslog_reports.php', 'syslog_saved_searches.php', 'syslog_dashboards.php'];

	if (!$force && substr($_SERVER['SCRIPT_FILENAME'], -18) != 'syslog_process.php' && !in_array(get_current_page(), $files, true)) {
		return;
	}

	syslog_ensure_share_tables();

	// don't let this script timeout
	ini_set('max_execution_time', 0);

	if (function_exists('api_plugin_upgrade_register')) {
		if (!api_plugin_upgrade_register('syslog') && !$force) {
			// This table was introduced after the original remote schema. Ensure
			// an already-current remote collector can repair the omission without
			// requiring a plugin version change.
			syslog_create_device_rule_table();
			// No upgrade required, but still warn about deprecated table layouts
			syslog_notice_traditional_tables(true);

			return;
		}
	} else {
		$version = plugin_syslog_version();
		$current = $version['version'];
		$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='syslog'");

		if ($current != $old || $force) {
				api_plugin_register_hook('syslog', 'replicate_out', 'syslog_replicate_out', 'includes/processing.php', 1);

			db_execute_prepared('UPDATE plugin_config SET
				version = ?, name = ?, author = ?, webpage = ?
				WHERE directory = ?',
				[
					$version['version'],
					$version['longname'],
					$version['author'],
					$version['homepage'],
					$version['name']
				]
			);
		} else {
			syslog_create_device_rule_table();
			// No upgrade required, but still warn about deprecated table layouts
			syslog_notice_traditional_tables(true);

			return;
		}
	}

	if (!syslog_db_column_exists('syslog_alert', 'hash')) {
		syslog_db_add_column('syslog_alert', [
			'name'     => 'hash',
			'type'     => 'varchar(32)',
			'NULL'     => false,
			'default'  => '',
			'after'    => 'id']
		);

		syslog_db_add_column('syslog_remove', [
			'name'     => 'hash',
			'type'     => 'varchar(32)',
			'NULL'     => false,
			'default'  => '',
			'after'    => 'id']
		);

		syslog_db_add_column('syslog_reports', [
			'name'     => 'hash',
			'type'     => 'varchar(32)',
			'NULL'     => false,
			'default'  => '',
			'after'    => 'id']
		);
	}

	if (syslog_db_column_exists('syslog_incoming', 'date')) {
		syslog_db_execute("ALTER TABLE syslog_incoming
			DROP COLUMN date,
			CHANGE COLUMN `time` logtime timestamp default '0000-00-00';");
	}

	if (syslog_db_column_exists('syslog_alert', 'hash')) {
		$alerts = syslog_db_fetch_assoc('SELECT *
			FROM syslog_alert
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($alerts)) {
			foreach ($alerts as $a) {
				$hash = get_hash_syslog($a['id'], 'syslog_alert');
				syslog_db_execute_prepared('UPDATE syslog_alert
					SET hash = ?
					WHERE id = ?',
					[$hash, $a['id']]);
			}
		}
	}

	if (syslog_db_column_exists('syslog_remove', 'hash')) {
		$removes = syslog_db_fetch_assoc('SELECT *
			FROM syslog_remove
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($removes)) {
			foreach ($removes as $r) {
				$hash = get_hash_syslog($r['id'], 'syslog_remove');
				syslog_db_execute_prepared('UPDATE syslog_remove
					SET hash = ?
					WHERE id = ?',
					[$hash, $r['id']]);
			}
		}
	}

	if (syslog_db_column_exists('syslog_reports', 'hash')) {
		$reports = syslog_db_fetch_assoc('SELECT *
			FROM syslog_reports
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($reports)) {
			foreach ($reports as $r) {
				$hash = get_hash_syslog($r['id'], 'syslog_reports');
				syslog_db_execute_prepared('UPDATE syslog_reports
					SET hash = ?
					WHERE id = ?',
					[$hash, $r['id']]);
			}
		}
	}

	if (syslog_db_table_exists('syslog_saved_searches', false) && syslog_db_column_exists('syslog_saved_searches', 'hash')) {
		$searches = syslog_db_fetch_assoc('SELECT *
			FROM syslog_saved_searches
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($searches)) {
			foreach ($searches as $s) {
				$hash = get_hash_syslog($s['id'], 'syslog_saved_searches');
				syslog_db_execute_prepared('UPDATE syslog_saved_searches
					SET hash = ?
					WHERE id = ?',
					[$hash, $s['id']]);
			}
		}
	}

	// These tables were introduced after the original plugin schema.  On a
	// partially upgraded remote collector create them below before attempting
	// their data migration; otherwise the column probe itself emits an SQL
	// error on every poller invocation.
	if (syslog_db_table_exists('syslog_dashboards', false) && syslog_db_column_exists('syslog_dashboards', 'hash')) {
		$dashboards = syslog_db_fetch_assoc('SELECT *
			FROM syslog_dashboards
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($dashboards)) {
			foreach ($dashboards as $d) {
				$hash = get_hash_syslog($d['id'], 'syslog_dashboards');
				syslog_db_execute_prepared('UPDATE syslog_dashboards
					SET hash = ?
					WHERE id = ?',
					[$hash, $d['id']]);
			}
		}
	}

	if (!syslog_db_column_exists('syslog_alert', 'level')) {
		syslog_db_add_column('syslog_alert', [
			'name'     => 'level',
			'type'     => 'int(10)',
			'unsigned' => true,
			'NULL'     => false,
			'default'  => '0',
			'after'    => 'method']
		);
	}

	if (!syslog_db_column_exists('syslog_alert', 'notify')) {
		syslog_db_add_column('syslog_alert', [
			'name'     => 'notify',
			'type'     => 'int(10)',
			'unsigned' => true,
			'NULL'     => false,
			'default'  => '0',
			'after'    => 'email']
		);
	}

	if (!syslog_db_column_exists('syslog_alert', 'body')) {
		syslog_db_add_column('syslog_alert', [
			'name'     => 'body',
			'type'     => 'varchar(8192)',
			'NULL'     => false,
			'default'  => '',
			'after'    => 'message']
		);
	}

	foreach ([
		['cooldown_minutes', 'int(10)', '-1', 'repeat_alert'],
		['deduplication_minutes', 'int(10)', '-1', 'cooldown_minutes'],
		['suppression_schedule', 'text', '', 'deduplication_minutes'],
		['maintenance_mode', 'varchar(16)', 'inherit', 'suppression_schedule'],
		['maintenance_days', 'varchar(32)', '1,2,3,4,5', 'maintenance_mode'],
		['maintenance_start', 'char(5)', '00:00', 'maintenance_days'],
		['maintenance_end', 'char(5)', '00:00', 'maintenance_start'],
		['maintenance_datetime_start', 'varchar(16)', '', 'maintenance_end'],
		['maintenance_datetime_end', 'varchar(16)', '', 'maintenance_datetime_start']
	] as [$name, $type, $default, $after]) {
		if (!syslog_db_column_exists('syslog_alert', $name)) {
			syslog_db_add_column('syslog_alert', [
				'name'     => $name,
				'type'     => $type,
				'NULL'     => false,
				'default'  => $default,
				'after'    => $after]
			);
		}
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_alert_suppression` (
		`alert_id` int(10) unsigned NOT NULL,
		`scope_key` varchar(255) NOT NULL,
		`dedup_hash` char(40) NOT NULL,
		`last_sent` int(10) unsigned NOT NULL default '0',
		PRIMARY KEY (`alert_id`, `scope_key`, `dedup_hash`),
		INDEX `last_sent` (`last_sent`))
		ENGINE=InnoDB");

	// Structured filter JSON can exceed the old VARCHAR limit. TEXT also keeps
	// the alert table below the InnoDB/MariaDB inline row-size ceiling.
	syslog_db_execute('ALTER TABLE syslog_alert MODIFY column message TEXT NOT NULL');

	// Removal rules now store the same structured filter JSON as alert rules.
	syslog_db_execute('ALTER TABLE syslog_remove MODIFY column message TEXT NOT NULL');

	if (!syslog_db_column_exists('syslog_reports', 'notify')) {
		syslog_db_add_column('syslog_reports', [
			'name'     => 'notify',
			'type'     => 'int(10)',
			'unsigned' => true,
			'NULL'     => false,
			'default'  => '0',
			'after'    => 'email']
		);
	}

	syslog_db_execute('ALTER TABLE syslog_reports MODIFY column body VARCHAR(8192) NOT NULL default ""');

	if (!syslog_db_table_exists('syslog_saved_searches', false)) {
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_saved_searches` (
			`id` int(10) NOT NULL auto_increment,
			`hash` varchar(32) NOT NULL default '',
			`name` varchar(128) NOT NULL default '',
			`search` text NOT NULL,
			`removal` int(10) NOT NULL default '1',
			`grouping` int(10) NOT NULL default '0',
			`user` varchar(32) NOT NULL default '',
			`is_global` char(2) NOT NULL default '',
			`date` int(16) NOT NULL default '0',
			PRIMARY KEY (`id`),
			KEY owner (`user`))
			ENGINE=InnoDB
			ROW_FORMAT=Dynamic");
	}

	if (!syslog_db_column_exists('syslog_saved_searches', 'hash')) {
		syslog_db_add_column('syslog_saved_searches', [
			'name'    => 'hash',
			'type'    => 'varchar(32)',
			'NULL'    => false,
			'default' => '',
			'after'   => 'id']
		);
	}

	if (!syslog_db_table_exists('syslog_dashboards', false)) {
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_dashboards` (
			`id` int(10) NOT NULL auto_increment,
			`hash` varchar(32) NOT NULL default '',
			`name` varchar(128) NOT NULL default '',
			`user` varchar(32) NOT NULL default '',
			`is_global` char(2) NOT NULL default '',
			`date` int(16) NOT NULL default '0',
			`updated` int(16) NOT NULL default '0',
			PRIMARY KEY (`id`),
			KEY owner (`user`))
			ENGINE=InnoDB
			ROW_FORMAT=Dynamic");
	}

	if (!syslog_db_column_exists('syslog_dashboards', 'hash')) {
		syslog_db_add_column('syslog_dashboards', [
			'name'    => 'hash',
			'type'    => 'varchar(32)',
			'NULL'    => false,
			'default' => '',
			'after'   => 'id']
		);
	}

	if (!syslog_db_table_exists('syslog_dashboard_panels', false)) {
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_dashboard_panels` (
			`id` int(10) NOT NULL auto_increment,
			`dashboard_id` int(10) NOT NULL default '0',
			`title` varchar(128) NOT NULL default '',
			`expression` text NOT NULL,
			`source` varchar(16) NOT NULL default 'syslog',
			`removal` int(10) NOT NULL default '1',
			`kind` varchar(16) NOT NULL default 'timeseries',
			`chart` varchar(16) NOT NULL default 'line',
			`field` varchar(16) NOT NULL default 'host',
			`interval` varchar(16) NOT NULL default 'dashboard',
			`timespan` varchar(16) NOT NULL default 'dashboard',
			`top_n` int(10) NOT NULL default '10',
			`width` smallint(5) unsigned NOT NULL default '1',
			`height` smallint(5) unsigned NOT NULL default '0',
			`position` int(10) NOT NULL default '0',
			`date` int(16) NOT NULL default '0',
			PRIMARY KEY (`id`),
			KEY dashboard (`dashboard_id`))
			ENGINE=InnoDB
			ROW_FORMAT=Dynamic");
	}

	// Panel resizing persists a grid span and chart height per panel.
	if (!syslog_db_column_exists('syslog_dashboard_panels', 'width')) {
		syslog_db_add_column('syslog_dashboard_panels', [
			'name'    => 'width',
			'type'    => 'smallint(5) unsigned',
			'NULL'    => false,
			'default' => '1',
			'after'   => 'top_n']
		);
	}

	if (!syslog_db_column_exists('syslog_dashboard_panels', 'height')) {
		syslog_db_add_column('syslog_dashboard_panels', [
			'name'    => 'height',
			'type'    => 'smallint(5) unsigned',
			'NULL'    => false,
			'default' => '0',
			'after'   => 'width']
		);
	}

	// Sharing persists a global flag on the dashboard itself.
	if (!syslog_db_column_exists('syslog_dashboards', 'is_global')) {
		syslog_db_add_column('syslog_dashboards', [
			'name'    => 'is_global',
			'type'    => 'char(2)',
			'NULL'    => false,
			'default' => '',
			'after'   => 'user']
		);
	}

	if (!syslog_db_table_exists('syslog_status', false)) {
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_status` (
			`name` varchar(64) NOT NULL default '',
			`value` text NOT NULL,
			`updated` int(16) NOT NULL default '0',
			PRIMARY KEY (`name`))
			ENGINE=InnoDB
			ROW_FORMAT=Dynamic");
	} else {
		syslog_db_execute("ALTER TABLE `$syslogdb_default`.`syslog_status` MODIFY column `value` TEXT NOT NULL");
	}

	syslog_create_device_rule_table();

	syslog_create_replication_output_table();
	syslog_create_replication_receipts_table();
	syslog_create_replication_collectors_table();
	syslog_create_replication_recovery_table();
	syslog_ensure_message_text();
	syslog_ensure_replication_history_columns();
}


/**
 * Return the version information for the Syslog plugin.
 *
 * @return array<string, mixed> The 'info' section of the plugin INFO file, or an
 *                              empty array when the file cannot be parsed.
 */
function plugin_syslog_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (is_array($info) && isset($info['info']) && is_array($info['info'])) {
		return $info['info'];
	}

	return [];
}

/**
 * Check that the Syslog plugin dependencies are met.
 *
 * @return bool Always true, the Syslog plugin has no dependencies.
 */
function syslog_check_dependencies(): bool {
	return true;
}
