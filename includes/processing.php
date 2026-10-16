<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

// Poller and replication callbacks.

require_once(dirname(__DIR__) . '/setup.php');

/**
 * Replicate the Syslog rules tables to a remote data collector.
 *
 * @param array<string, mixed> $data The replication data provided by Cacti,
 *                                   containing the remote poller id, the remote
 *                                   connection id and the replication class.
 *
 * @return array<string, mixed> The replication data, unmodified.
 */
function syslog_replicate_out($data) {
	global $syslog_cnn;

	if (syslog_connect() && read_config_option('syslog_remote_enabled') == 'on'
		&& read_config_option('syslog_remote_sync_rules') == 'on' && $data['class'] == 'all') {
		foreach (['syslog_alert', 'syslog_device_rule', 'syslog_remove', 'syslog_reports'] as $table) {
			$rows = syslog_db_fetch_assoc("SELECT * FROM `$table`");

			if (!syslog_replace_data($table, $rows, $data['rcnn_id'], $syslog_cnn)) {
				cacti_log("ERROR: Unable to replicate Syslog rule table $table", false, 'REPLICATE');

				break;
			}
		}
	}

	return $data;
}

/**
 * Pull the authoritative rule snapshot from Cacti's local replicated tables.
 *
 * @return bool Whether every rule table was synchronized successfully.
 */
function syslog_replicate_in(): bool {
	global $local_db_cnn_id, $syslog_cnn;

	if (!syslog_connect()) {
		return false;
	}

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on'
		&& $syslog_cnn !== $local_db_cnn_id) {
		foreach (['syslog_alert', 'syslog_device_rule', 'syslog_remove', 'syslog_reports'] as $table) {
			$rows = db_fetch_assoc("SELECT * FROM `$table`", true, $local_db_cnn_id);

			if (!syslog_replace_data($table, $rows)) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Poller bottom hook, launches the Syslog message processing process.
 *
 * @return void
 */
function syslog_poller_bottom(): void {
	global $config;

	if (!api_plugin_is_enabled('syslog')) {
		return;
	}

	if (syslog_config_safe()) {
		if (!syslog_connect()) {
			cacti_log('ERROR: Syslog database or schema is unavailable; skipping processing.', false, 'SYSLOG');

			return;
		}
		syslog_pipeline_health_check();
		syslog_status_set('last_polling_time', time());

		$command_string = (string) read_config_option('path_php_binary');
		$extra_args     = ' -q ' . cacti_escapeshellarg($config['base_path'] . '/plugins/syslog/syslog_process.php');
		exec_background($command_string, $extra_args);
	} else {
		cacti_log('WARNING: You have installed the Syslog plugin, but you have not properly set a config.php or config_local.php', false, 'POLLER');
	}
}

/**
 * Publish a complete rule snapshot, including an empty table, atomically.
 *
 * Cacti's replicate_out_table() reads schema from its default connection.
 * Explicit connections here also support a dedicated main Syslog database.
 *
 * @param string      $table  Allowlisted plugin-owned rule table.
 * @param array|false $data   Source rows; false must never clear a table.
 * @param object|null $target Destination, defaulting to the local Syslog DB.
 * @param object|null $source Schema source, defaulting to Cacti's local DB.
 *
 * @return bool Whether the full snapshot was published.
 */
function syslog_replace_data($table, &$data, $target = null, $source = null): bool {
	global $syslog_cnn, $local_db_cnn_id;

	if (!in_array($table, ['syslog_alert', 'syslog_device_rule', 'syslog_remove', 'syslog_reports'], true)
		|| !is_array($data)) {
		return false;
	}

	$target = $target ?? $syslog_cnn;
	$source = $source ?? $local_db_cnn_id;

	if (!is_object($target) || !is_object($source)) {
		return false;
	}

	if ($target === $source) {
		return true;
	}

	$create     = db_fetch_row("SHOW CREATE TABLE `$table`", true, $source);
	$create_sql = is_array($create) ? ($create['Create Table'] ?? $create["CREATE TABLE `$table`"] ?? '') : '';
	$stage      = $table . '_sync_' . bin2hex(random_bytes(6));
	$backup     = $stage . '_old';
	$create_sql = preg_replace('/^CREATE TABLE `' . preg_quote($table, '/') . '`/', 'CREATE TABLE `' . $stage . '`', $create_sql, 1, $replaced);

	if ($replaced !== 1) {
		return false;
	}

	if ($data && (!isset($data[0]) || !is_array($data[0]) || !$data[0])) {
		return false;
	}

	$columns = $data ? array_keys($data[0]) : [];

	foreach ($columns as $column) {
		if (!is_string($column) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
			return false;
		}
	}

	foreach ($data as $row) {
		if (!is_array($row) || array_keys($row) !== $columns) {
			return false;
		}
	}

	$lock = 'syslog_rule_snapshot_' . $table;

	if (!db_fetch_cell_prepared('SELECT GET_LOCK(?, 5)', [$lock], '', true, $target)) {
		return false;
	}

	try {
		if (!db_execute($create_sql, true, $target)) {
			return false;
		}

		foreach (array_chunk($data, 250) as $rows) {
			$values = [];
			$params = [];

			foreach ($rows as $row) {
				$values[] = '(' . implode(', ', array_fill(0, cacti_sizeof($columns), '?')) . ')';
				$params   = array_merge($params, array_values($row));
			}

			if (!db_execute_prepared("INSERT INTO `$stage` (`" . implode('`, `', $columns) . '`) VALUES ' . implode(', ', $values), $params, true, $target)) {
				return false;
			}
		}

		$exists = db_fetch_cell_prepared('SELECT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?)', [$table], '', true, $target);

		if ($exists === false) {
			return false;
		}

		$rename = $exists ? "`$table` TO `$backup`, " : '';

		return db_execute("RENAME TABLE $rename`$stage` TO `$table`", true, $target);
	} finally {
		db_execute("DROP TABLE IF EXISTS `$stage`, `$backup`", true, $target);
		db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock], '', true, $target);
	}
}
