<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Poller and replication callbacks. */

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
	include_once(dirname(__DIR__) . '/setup.php');
	syslog_connect();

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		$remote_poller_id = $data['remote_poller_id'];
		$rcnn_id          = $data['rcnn_id'];
		$class            = $data['class'];

		cacti_log('INFO: Replicating for the Syslog Plugin', false, 'REPLICATE');

		if ($class == 'all') {
			$tdata = syslog_db_fetch_assoc('SELECT * FROM syslog_alert');
			replicate_out_table($rcnn_id, $tdata, 'syslog_alert', $remote_poller_id);
			$tdata = syslog_db_fetch_assoc('SELECT * FROM syslog_device_rule');
			replicate_out_table($rcnn_id, $tdata, 'syslog_device_rule', $remote_poller_id);
			$tdata = syslog_db_fetch_assoc('SELECT * FROM syslog_remove');
			replicate_out_table($rcnn_id, $tdata, 'syslog_remove', $remote_poller_id);
			$tdata = syslog_db_fetch_assoc('SELECT * FROM syslog_reports');
			replicate_out_table($rcnn_id, $tdata, 'syslog_reports', $remote_poller_id);
		}
	}

	return $data;
}

/**
 * Pull the Syslog rules tables from the main Cacti database.
 *
 * @return void
 */
function syslog_replicate_in(): void {
	include_once(dirname(__DIR__) . '/setup.php');
	syslog_connect();

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		$data = db_fetch_assoc('SELECT * FROM syslog_alert');
		syslog_replace_data('syslog_alert', $data);

		$data = db_fetch_assoc('SELECT * FROM syslog_device_rule');
		syslog_replace_data('syslog_device_rule', $data);

		$data = db_fetch_assoc('SELECT * FROM syslog_remove');
		syslog_replace_data('syslog_remove', $data);

		$data = db_fetch_assoc('SELECT * FROM syslog_reports');
		syslog_replace_data('syslog_reports', $data);
	}
}

/**
 * Poller bottom hook, launches the Syslog message processing process.
 *
 * @return void
 */
function syslog_poller_bottom(): void {
	global $config;

	if (syslog_config_safe()) {
		include_once(dirname(__DIR__) . '/setup.php');

		syslog_connect();
		syslog_status_set('last_polling_time', time());

		$command_string = (string) read_config_option('path_php_binary');
		$extra_args     = ' -q ' . $config['base_path'] . '/plugins/syslog/syslog_process.php';
		exec_background($command_string, $extra_args);
	} else {
		cacti_log('WARNING: You have installed the Syslog plugin, but you have not properly set a config.php or config_local.php', false, 'POLLER');
	}
}

/**
 * Replace the data in the local table with the replicated data.
 *
 * @param string                           $table The name of the table to replace.
 * @param array<int, array<string, mixed>> $data  The replicated rows, passed by reference.
 *
 * @return void
 */
function syslog_replace_data($table, &$data) {
	if (cacti_sizeof($data)) {
		$sqlData    = [];
		$sqlQuery   = [];
		$columns    = array_keys($data[0]);
		$create_sql = '';

		$create = db_fetch_row('SHOW CREATE TABLE ' . $table);

		if (is_array($create)) {
			if (isset($create["CREATE TABLE `$table`"])) {
				$create_sql = $create["CREATE TABLE `$table`"];
			} elseif (isset($create['Create Table'])) {
				$create_sql = $create['Create Table'];
			}
		}

		if (!syslog_db_table_exists($table)) {
			if ($create_sql == '') {
				cacti_log('WARNING: Unable to derive CREATE TABLE SQL for `' . $table . '` during Syslog replication.', false, 'REPLICATE');

				return;
			}

			syslog_db_execute($create_sql);
			syslog_db_execute("TRUNCATE TABLE $table");
		}

		// Make the prefix
		$sql_prefix = "INSERT INTO $table (`" . implode('`,`', $columns) . '`) VALUES ';

		// Make the suffix
		$sql_suffix = ' ON DUPLICATE KEY UPDATE ';

		foreach ($columns as $c) {
			$sql_suffix .= " $c = VALUES($c),";
		}
		$sql_suffix = trim($sql_suffix, ',');

		// Construct the prepared statement
		foreach ($data as $row) {
			$sqlQuery[] = '(' . trim(str_repeat('?, ', cacti_sizeof($columns)), ', ') . ')';

			foreach ($row as $col) {
				$sqlData[] = $col;
			}
		}

		$sql = implode(', ', $sqlQuery);

		syslog_db_execute_prepared($sql_prefix . $sql . $sql_suffix, $sqlData);
	}
}
