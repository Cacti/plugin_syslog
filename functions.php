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

function syslog_apply_selected_items_action($selected_items, $drp_action, $action_map, $export_action = '', $export_items = '') {
	if ($selected_items != false) {
		if (isset($action_map[$drp_action])) {
			$action_function = $action_map[$drp_action];

			if (function_exists($action_function)) {
				foreach ($selected_items as $selected_item) {
					$action_function($selected_item);
				}
			} else {
				cacti_log("SYSLOG ERROR: Bulk action function '$action_function' not found.", false, 'SYSTEM');
			}
		} elseif ($export_action != '' && $drp_action == $export_action) {
			$_SESSION['exporter'] = rawurlencode(serialize($selected_items));
		}
	}
}

function syslog_include_js() {
	global $config;
	?>
	<script type='text/javascript' src='<?php print $config['url_path']; ?>plugins/syslog/js/functions.js'></script>
	<?php
}

function syslog_allow_edits() {
	global $config;

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] > 1) {
			return false;
		}
	}

	return true;
}

function syslog_sync_save($data, $table, $primary = '') {
	global $config, $syslogdb_default;

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] == 1) {
			$stable = '`' . $syslogdb_default . '`.`' . $table . '`';

			$id = syslog_sql_save($data, $stable, $primary);

			if ($id > 0) {
				raise_message(1);
			} else {
				raise_message(2);
			}

			$pollers = array_rekey(
				db_fetch_assoc('SELECT poller_id
					FROM pollers
					WHERE disabled = ""
					AND id > 1'),
				'id', 'id'
			);

			if (cacti_sizeof($pollers)) {
				foreach ($pollers as $poller_id) {
					$rcnn_id = poller_connect_to_remote($poller_id);

					if ($rcnn_id !== false) {
						$id = sql_save($data, $table, $primary, true, $rcnn_id);
					}
				}
			}
		} else {
			raise_message('syslog_denied', __('Save Failed.  Remote Data Collectors in Sync Mode are not allowed to Save Rules.  Save from the Main Cacti Server instead.', 'syslog'), MESSAGE_LEVEL_ERROR);
		}
	} else {
		$stable = '`' . $syslogdb_default . '`.`' . $table . '`';

		$id = syslog_sql_save($data, $stable, $primary);

		if ($id > 0) {
			raise_message(1);
		} else {
			raise_message(2);
		}
	}
}

function syslog_sendemail($to, $from, $subject, $message, $smsmessage = '') {
	syslog_debug("Sending Alert email to '" . $to . "'");

	$sms    = '';
	$nonsms = '';

	// if there are SMS emails, process separately
	if (substr_count($to, 'sms@')) {
		$emails = explode(',', $to);

		if (cacti_sizeof($emails)) {
			foreach ($emails as $email) {
				if (substr_count($email, 'sms@')) {
					$sms .= ($sms != '' ? ', ' : '') . str_replace('sms@', '', trim($email));
				} else {
					$nonsms .= ($nonsms != '' ? ', ' : '') . trim($email);
				}
			}
		}
	} else {
		$nonsms = $to;
	}

	if (strlen($sms) && $smsmessage != '') {
		mailer($from, $sms, '', '', '', $subject, '', $smsmessage);
	}

	if (strlen($nonsms)) {
		if (read_config_option('syslog_html') == 'on') {
			mailer($from, $nonsms, '', '', '', $subject, $message, __('Please use an HTML Email Client', 'syslog'));
		} else {
			$message = strip_tags(str_replace('<br>', "\n", $message));
			mailer($from, $nonsms, '', '', '', $subject, '', $message, '', '', false);
		}
	}
}

function syslog_get_import_xml_payload($redirect_url) {
	if (trim(get_nfilter_request_var('import_text')) != '') {
		// textbox input
		return get_nfilter_request_var('import_text');
	}

	if (isset($_FILES['import_file']['tmp_name']) &&
		$_FILES['import_file']['tmp_name'] != 'none' &&
		$_FILES['import_file']['tmp_name'] != '') {
		// file upload
		$tmp_name = $_FILES['import_file']['tmp_name'];

		if (!isset($_FILES['import_file']['error']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
			header('Location: ' . $redirect_url);
			exit;
		}

		if (!is_uploaded_file($tmp_name)) {
			header('Location: ' . $redirect_url);
			exit;
		}

		$fp = fopen($tmp_name, 'rb');

		if ($fp === false) {
			cacti_log('SYSLOG ERROR: Failed to open uploaded import file', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		$xml_data = fread($fp, filesize($tmp_name));
		fclose($fp);

		if ($xml_data === false) {
			cacti_log('SYSLOG ERROR: Failed to read uploaded import file', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		return $xml_data;
	}

	header('Location: ' . $redirect_url);
	exit;
}

function syslog_is_partitioned() {
	global $syslogdb_default;

	// see if the table is partitioned
	$syntax = syslog_db_fetch_row('SHOW CREATE TABLE `' . $syslogdb_default . '`.`syslog`');

	if (substr_count($syntax['Create Table'], 'PARTITION')) {
		return true;
	} else {
		return false;
	}
}

/**
 * This function will manage old data for non-partitioned tables
 */
function syslog_traditional_manage() {
	global $syslogdb_default, $syslog_cnn;

	// determine the oldest date to retain
	if (read_config_option('syslog_retention') > 0) {
		$retention = date('Y-m-d', time() - (86400 * read_config_option('syslog_retention')));
	} else {
		$retention = date('Y-m-d', time() - (30 * 86400));
		set_config_option('syslog_retention', '30');
	}

	// delete from the main syslog table first
	syslog_db_execute('DELETE FROM `' . $syslogdb_default . "`.`syslog` WHERE logtime < '$retention'");

	$syslog_deleted = db_affected_rows($syslog_cnn);

	// now delete from the syslog removed table
	syslog_db_execute('DELETE FROM `' . $syslogdb_default . "`.`syslog_removed` WHERE logtime < '$retention'");

	$syslog_deleted += db_affected_rows($syslog_cnn);

	syslog_debug(sprintf('Deleted %5s, Syslog Message(s) (older than %s)', $syslog_deleted, $retention));

	return $syslog_deleted;
}

/**
 * This function will manage a partitioned table by checking for time to create
 */
function syslog_partition_manage() {
	$syslog_deleted = 0;

	if (syslog_partition_check('syslog')) {
		syslog_partition_create('syslog');
		$syslog_deleted = syslog_partition_remove('syslog');
	}

	if (syslog_partition_check('syslog_removed')) {
		syslog_partition_create('syslog_removed');
		$syslog_deleted += syslog_partition_remove('syslog_removed');
	}

	return $syslog_deleted;
}

/**
 * Validate tables that support partition maintenance.
 *
 * Any value added to the allowlist MUST match ^[a-z_]+$ so it is safe
 * for identifier interpolation in DDL statements (MySQL does not support
 * parameter binding for identifiers).
 *
 * @param mixed $table
 */
function syslog_partition_table_allowed($table) {
	if (!in_array($table, ['syslog', 'syslog_removed'], true)) {
		return false;
	}

	// Defense-in-depth: reject values unsafe for identifier interpolation.
	if (!preg_match('/^[a-z_]+$/', $table)) {
		return false;
	}

	return true;
}

/**
 * Create a new partition for the specified table.
 *
 * @param mixed $table
 *
 * @return bool true on success, false on lock failure or disallowed table.
 */
function syslog_partition_create($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	// Hash to guarantee the lock name stays within MySQL's 64-byte limit.
	$lock_name = substr(hash('sha256', $syslogdb_default . '.syslog_partition_create.' . $table), 0, 60);

	/*
	 * 10-second timeout is sufficient: partition maintenance runs once per
	 * poller cycle (typically 5 minutes), so sustained contention is not
	 * expected. A failure is logged so monitoring can detect repeated misses.
	 */
	$locked    = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', [$lock_name]);

	if ($locked === null) {
		// NULL means the GET_LOCK call itself failed, not just contention.
		cacti_log("SYSLOG: GET_LOCK call failed for partition create on '$table'", false, 'SYSTEM');

		return false;
	}

	if ((int)$locked !== 1) {
		cacti_log("SYSLOG: Unable to acquire partition create lock for '$table'", false, 'SYSTEM');

		return false;
	}

	try {
		// determine the format of the table name
		$time    = time();
		$cformat = 'd' . date('Ymd', $time);
		$lnow    = date('Y-m-d', $time + 86400);

		$exists = syslog_db_fetch_row_prepared('SELECT *
			FROM `information_schema`.`partitions`
			WHERE table_schema = ?
			AND partition_name = ?
			AND table_name = ?
			ORDER BY partition_ordinal_position',
			[$syslogdb_default, $cformat, $table]);

		if (!cacti_sizeof($exists)) {
			cacti_log("SYSLOG: Creating new partition '$cformat'", false, 'SYSTEM');

			syslog_debug("Creating new partition '$cformat'");

			/*
			 * MySQL does not support parameter binding for DDL identifiers
			 * or partition definitions. $table is safe because it passed
			 * syslog_partition_table_allowed() (two-value allowlist plus
			 * regex guard). $cformat and $lnow derive from date() and
			 * contain only digits, hyphens, and the letter 'd'.
			 */
			syslog_db_execute('ALTER TABLE `' . $syslogdb_default . "`.`$table` REORGANIZE PARTITION dMaxValue INTO (
				PARTITION $cformat VALUES LESS THAN (TO_DAYS('$lnow')),
				PARTITION dMaxValue VALUES LESS THAN MAXVALUE)");
		}
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock_name]);
	}

	return true;
}

/**
 * Remove old partitions for the specified table.
 *
 * @param mixed $table
 */
function syslog_partition_remove($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		cacti_log("SYSLOG: partition_remove called with disallowed table '$table'", false, 'SYSTEM');

		return 0;
	}

	$lock_name = substr(hash('sha256', $syslogdb_default . '.syslog_partition_remove.' . $table), 0, 60);

	$locked = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', [$lock_name]);

	if ($locked === null) {
		cacti_log("SYSLOG: GET_LOCK call failed for partition remove on '$table'", false, 'SYSTEM');

		return 0;
	}

	if ((int)$locked !== 1) {
		cacti_log("SYSLOG: Unable to acquire partition remove lock for '$table'", false, 'SYSTEM');

		return 0;
	}

	$syslog_deleted = 0;

	try {
		$number_of_partitions = syslog_db_fetch_assoc_prepared('SELECT *
			FROM `information_schema`.`partitions`
			WHERE table_schema = ? AND table_name = ?
			ORDER BY partition_ordinal_position',
			[$syslogdb_default, $table]);

		$days = read_config_option('syslog_retention');

		syslog_debug("There are currently '" . sizeof($number_of_partitions) . "' Syslog Partitions, We will keep '$days' of them.");

		if ($days > 0) {
			$user_partitions = sizeof($number_of_partitions) - 1;

			if ($user_partitions >= $days) {
				$i = 0;

				while ($user_partitions > $days) {
					$oldest = $number_of_partitions[$i];

					cacti_log("SYSLOG: Removing old partition '" . $oldest['PARTITION_NAME'] . "'", false, 'SYSTEM');

					syslog_debug("Removing partition '" . $oldest['PARTITION_NAME'] . "'");

					syslog_db_execute('ALTER TABLE `' . $syslogdb_default . "`.`$table` DROP PARTITION " . $oldest['PARTITION_NAME']);

					$i++;
					$user_partitions--;
					$syslog_deleted++;
				}
			}
		}
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock_name]);
	}

	return $syslog_deleted;
}

/*
 * syslog_partition_check is a read-only SELECT against information_schema.
 * It does not execute DDL, so it does not need the named lock that
 * syslog_partition_create and syslog_partition_remove acquire. External
 * serialization is provided by the poller cycle calling
 * syslog_partition_manage().
 */
function syslog_partition_check($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	// find date of last partition
	$last_part = syslog_db_fetch_cell_prepared('SELECT PARTITION_NAME
		FROM `information_schema`.`partitions`
		WHERE table_schema = ? AND table_name = ?
		ORDER BY partition_ordinal_position DESC
		LIMIT 1,1',
		[$syslogdb_default, $table]);

	$lformat   = str_replace('d', '', $last_part);
	$cformat   = date('Ymd');

	if ($cformat > $lformat) {
		return true;
	} else {
		return false;
	}
}

function syslog_check_changed($request, $session) {
	if ((isset_request_var($request)) && (isset($_SESSION[$session]))) {
		if (get_request_var($request) != $_SESSION[$session]) {
			return 1;
		}
	}
}

function syslog_remove_items($table, $max_seq) {
	global $config, $syslog_cnn, $syslog_incoming_config;
	global $syslogdb_default;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Removal Rules...');

	if ($table == 'syslog') {
		$rows = syslog_db_fetch_assoc('SELECT *
			FROM `' . $syslogdb_default . "`.`syslog_remove`
			WHERE enabled = 'on'");
	} else {
		$rows = syslog_db_fetch_assoc('SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_remove`
			WHERE enabled="on"');
	}

	syslog_debug(sprintf('Found   %5s - Removal Rule(s) to process', cacti_sizeof($rows)));

	$removed = 0;
	$xferred = 0;

	if ($table == 'syslog_incoming') {
		$total = syslog_db_fetch_cell_prepared('SELECT count(*)
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?',
			[$max_seq]);
	} else {
		$total = 0;
	}

	if (cacti_sizeof($rows)) {
		foreach ($rows as $remove) {
			$sql_where = '';
			$params    = [];

			if ($remove['type'] == 'facility') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['facilityField'] . '` = ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'];
					$params[] = $max_seq;
				} else {
					$facility_id = syslog_db_fetch_cell_prepared('SELECT facility_id
						FROM `' . $syslogdb_default . '`.`syslog_facilities`
						WHERE facility = ?', [$remove['message']]);

					if (!empty($facility_id)) {
						$sql_where = 'WHERE facility_id = ?';
						$params[]  = $facility_id;
					}
				}
			} elseif ($remove['type'] == 'program') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `program` = ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'];
					$params[] = $max_seq;
				} else {
					$program_id = syslog_db_fetch_cell_prepared('SELECT program_id
						FROM `' . $syslogdb_default . '`.`syslog_programs`
						WHERE program = ?', [$remove['message']]);

					if (!empty($program_id)) {
						$sql_where = 'WHERE program_id = ?';
						$params[]  = $program_id;
					}
				}
			} elseif ($remove['type'] == 'host') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `host` = ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'];
					$params[] = $max_seq;
				} else {
					$host_id = syslog_db_fetch_cell_prepared('SELECT host_id
						FROM `' . $syslogdb_default . '`.`syslog_hosts`
						WHERE host = ?', [$remove['message']]);

					if (!empty($host_id)) {
						$sql_where = 'WHERE host_id = ?';
						$params[]  = $host_id;
					}
				}
			} elseif ($remove['type'] == 'messageb') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'] . '%';
					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE message LIKE ?';
					$params[]  = $remove['message'] . '%';
				}
			} elseif ($remove['type'] == 'messagec') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = '%' . $remove['message'] . '%';
					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE message LIKE ?';
					$params[]  = '%' . $remove['message'] . '%';
				}
			} elseif ($remove['type'] == 'messagee') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = '%' . $remove['message'];
					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE message LIKE ?';
					$params[]  = '%' . $remove['message'];
				}
			} elseif ($remove['type'] == 'sql') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE (' . $remove['message'] . ')
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE (' . $remove['message'] . ')';
				}
			}

			if ($sql_where != '') {
				if ($remove['method'] != 'del') {
					if ($table == 'syslog_incoming') {
						syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT si.logtime, si.priority_id, si.facility_id, sp.program_id, sh.host_id, si.message
							FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
							INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
							ON sh.host = si.host
							INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS sp
							ON sp.program = si.program ' . $sql_where, $params);
					} else {
						syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM `' . $syslogdb_default . '`.`syslog` ' . $sql_where, $params);
					}

					$xferred += db_affected_rows($syslog_cnn);
				}

				if ($table == 'syslog_incoming') {
					syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming` ' . $sql_where, $params);
				} else {
					syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog` ' . $sql_where, $params);
				}

				$removed += db_affected_rows($syslog_cnn);
			}
		}
	}

	syslog_debug(sprintf('Removed %5s - Record(s) from ' . $table, $removed));
	syslog_debug(sprintf('Xferred %5s - Record(s) to the syslog_removed table', $xferred));

	return ['removed' => $removed, 'xferred' => $xferred];
}

/**
 * function syslog_log_row_color()
 * This function set's the CSS for each row of the syslog table as it is displayed
 * it supports both the legacy as well as the new approach to controlling these
 * colors.
 *
 * @param mixed $severity
 * @param mixed $tip_title
 */
function syslog_log_row_color($severity, $tip_title) {
	switch($severity) {
		case '':
		case '0':
			$class = 'logInfo';

			break;
		case '1':
			$class = 'logWarning';

			break;
		case '2':
			$class = 'logAlert';

			break;
	}

	print "<tr class='tableRow selectable $class'>\n";
}

/**
 * function syslog_row_color()
 * This function set's the CSS for each row of the syslog table as it is displayed
 * it supports both the legacy as well as the new approach to controlling these
 * colors.
 *
 * @param mixed $priority
 * @param mixed $message
 */
function syslog_row_color($priority, $message) {
	switch($priority) {
		case '0':
			$class = 'logEmergency';

			break;
		case '1':
			$class = 'logAlert';

			break;
		case '2':
			$class = 'logCritical';

			break;
		case '3':
			$class = 'logError';

			break;
		case '4':
			$class = 'logWarning';

			break;
		case '5':
			$class = 'logNotice';

			break;
		case '6':
			$class = 'logInfo';

			break;
		case '7':
			$class = 'logDebug';

			break;
	}

	print "<tr title='" . html_escape($message) . "' class='tableRow selectable $class syslogRow syslog-detail-row'>";

	return $class;
}

function sql_hosts_where($tab) {
	global $hostfilter, $hostfilter_log, $syslog_incoming_config;
	global $syslogdb_default;

	$hostfilter     = '';
	$hostfilter_log = '';
	$hosts_array    = [];

	if (!isempty_request_var('host') && get_nfilter_request_var('host') != 'null') {
		$hostarray = explode(',', trim(get_nfilter_request_var('host')));

		if ($hostarray[0] != '0') {
			foreach ($hostarray as $host_id) {
				input_validate_input_number($host_id);

				if ($host_id > 0) {
					$log_host = syslog_db_fetch_cell_prepared('SELECT host
						FROM `' . $syslogdb_default . '`.`syslog_hosts`
						WHERE host_id = ?',
						[$host_id]);

					if (!empty($log_host)) {
						$hosts_array[] = db_qstr($log_host);
					}
				}
			}

			if (cacti_sizeof($hosts_array)) {
				$hostfilter_log = ' host IN(' . implode(',', $hosts_array) . ')';
			}

			$hostfilter .= ($hostfilter != '' ? ' AND ' : '') . ' host_id IN(' . implode(',', $hostarray) . ')';
		}
	}
}

function syslog_export($tab) {
	global $syslog_incoming_config, $severities;
	global $syslogdb_default;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if ($tab == 'syslog') {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=syslog_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		$hosts = array_rekey(
			syslog_db_fetch_assoc('SELECT host_id, host
				FROM `' . $syslogdb_default . '`.`syslog_hosts`'),
			'host_id', 'host'
		);

		$facilities = array_rekey(
			syslog_db_fetch_assoc('SELECT facility_id, facility
				FROM `' . $syslogdb_default . '`.`syslog_facilities`'),
			'facility_id', 'facility'
		);

		$priorities = array_rekey(
			syslog_db_fetch_assoc('SELECT priority_id, priority
				FROM `' . $syslogdb_default . '`.`syslog_priorities`'),
			'priority_id', 'priority'
		);

		$programs = array_rekey(
			syslog_db_fetch_assoc('SELECT program_id, program
				FROM `' . $syslogdb_default . '`.`syslog_programs`'),
			'program_id', 'program'
		);

		print 'host, facility, priority, program, date, message' . "\r\n";

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($facilities[$message['facility_id']])) {
					$facility = $facilities[$message['facility_id']];
				} else {
					$facility = 'Unknown';
				}

				if (isset($programs[$message['program_id']])) {
					$program = $programs[$message['program_id']];
				} else {
					$program = 'Unknown';
				}

				if (isset($priorities[$message['priority_id']])) {
					$priority = $priorities[$message['priority_id']];
				} else {
					$priority = 'Unknown';
				}

				if (isset($hosts[$message['host_id']])) {
					$host = $hosts[$message['host_id']];
				} else {
					$host = 'Unknown';
				}

				print '"' .
					$host . '","' .
					ucfirst($facility) . '","' .
					ucfirst($priority) . '","' .
					ucfirst($program) . '","' .
					$message['logtime'] . '","' .
					$message[$syslog_incoming_config['textField']] . '"' . "\r\n";
			}
		}
	} else {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=alert_log_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		print 'name, severity, date, message, host, facility, priority, count' . "\r\n";

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($severities[$message['severity']])) {
					$severity = $severities[$message['severity']];
				} else {
					$severity = 'Unknown';
				}

				print '"' .
					$message['name'] . '","' .
					$severity . '","' .
					$message['logtime'] . '","' .
					$message['logmsg'] . '","' .
					$message['host'] . '","' .
					ucfirst($message['facility']) . '","' .
					ucfirst($message['priority']) . '","' .
					$message['count'] . '"' . "\r\n";
			}
		}
	}
}

function syslog_debug($message) {
	global $debug;

	if ($debug) {
		print date('H:m:s') . ' SYSLOG DEBUG: ' . trim($message) . PHP_EOL;
	}
}

function syslog_log_alert($alert_id, $alert_name, $severity, $msg, $count = 1, $html = '', $hosts = []) {
	global $config, $severities;
	global $syslogdb_default;

	if ($count <= 1) {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = $msg['seq'];
		$save['logtime']     = $msg['logtime'];
		$save['logmsg']      = $msg['message'];
		$save['host']        = $msg['host'];
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = 1;
		$save['html']        = $html;

		$id = 0;
		$id = syslog_sql_save($save, '`' . $syslogdb_default . '`.`syslog_logs`', 'seq');

		$save['seq']        = $id;
		$save['alert_name'] = $alert_name;
		api_plugin_hook_function('syslog_update_hostsalarm', $save);

		cacti_log("WARNING: The Syslog Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered on Host '" . $msg['host'] . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	} else {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = 0;
		$save['logtime']     = date('Y-m-d H:i:s');
		$save['logmsg']      = $alert_name;
		$save['host']        = 'N/A';
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = $count;
		$save['html']        = $html;

		$id = 0;
		$id = syslog_sql_save($save, '`' . $syslogdb_default . '`.`syslog_logs`', 'seq');

		$save['seq']         = $id;
		$save['alert_name']  = $alert_name;

		if (cacti_sizeof($hosts)) {
			foreach ($hosts as $host) {
				$save['host'] = $host;
				api_plugin_hook_function('syslog_update_hostsalarm', $save);
			}
		}

		cacti_log("WARNING: The Syslog Instance Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered, Count was '" . $count . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	}
}

function syslog_manage_items($from_table, $to_table) {
	global $config, $syslog_cnn, $syslog_incoming_config;
	global $syslogdb_default;

	// Select filters to work on
	$rows = syslog_db_fetch_assoc('SELECT * FROM `' . $syslogdb_default . "`.`syslog_remove` WHERE enabled='on'");

	syslog_debug(sprintf('Found   %5s - Removal Rule(s) to process', cacti_sizeof($rows)));

	$removed = 0;
	$xferred = 0;
	$total   = 0;

	if (cacti_sizeof($rows)) {
		foreach ($rows as $remove) {
			syslog_debug('Processing Rule  - ' . $remove['message']);

			$sql_sel = '';
			$sql_dlt = '';

			if ($remove['type'] == 'facility') {
				if ($remove['method'] != 'del') {
					$sql_sel = 'SELECT seq FROM `' . $syslogdb_default . "`. $from_table
						WHERE facility_id IN
							(SELECT distinct facility_id FROM `" . $syslogdb_default . "`syslog_facilities
							WHERE facility ='" . $remove['message'] . "')";
				} else {
					$sql_dlt = 'DELETE FROM `' . $syslogdb_default . "`. $from_table
						WHERE facility_id IN
							(SELECT distinct facility_id FROM `" . $syslogdb_default . "`syslog_facilities
							WHERE facility ='" . $remove['message'] . "')";
				}
			} elseif ($remove['type'] == 'host') {
				if ($remove['method'] != 'del') {
					$sql_sel = 'SELECT seq
						FROM `' . $syslogdb_default . "`. $from_table
						WHERE host_id in
							(SELECT distinct host_id FROM `" . $syslogdb_default . "`syslog_hosts
							WHERE host ='" . $remove['message'] . "')";
				} else {
					$sql_dlt = 'DELETE FROM `' . $syslogdb_default . "`. $from_table
						WHERE host_id in
							(SELECT distinct host_id FROM `" . $syslogdb_default . "`syslog_hosts
							WHERE host ='" . $remove['message'] . "')";
				}
			} elseif ($remove['type'] == 'messageb') {
				if ($remove['method'] != 'del') {
					$sql_sel = 'SELECT seq FROM `' . $syslogdb_default . "`. $from_table
						WHERE message LIKE '" . $remove['message'] . "%' ";
				} else {
					$sql_dlt = 'DELETE FROM `' . $syslogdb_default . "`. $from_table
						WHERE message LIKE '" . $remove['message'] . "%' ";
				}
			} elseif ($remove['type'] == 'messagec') {
				if ($remove['method'] != 'del') {
					$sql_sel = 'SELECT seq FROM `' . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "%' ";
				} else {
					$sql_dlt = 'DELETE FROM `' . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "%' ";
				}
			} elseif ($remove['type'] == 'messagee') {
				if ($remove['method'] != 'del') {
					$sql_sel = 'SELECT seq FROM `' . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "' ";
				} else {
					$sql_dlt = 'DELETE FROM `' . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "' ";
				}
			} elseif ($remove['type'] == 'sql') {
				if ($remove['method'] != 'del') {
					$sql_sel = 'SELECT seq FROM `' . $syslogdb_default . "`. $from_table
						WHERE message (" . $remove['message'] . ') ';
				} else {
					$sql_dlt = 'DELETE FROM `' . $syslogdb_default . "`. $from_table
						WHERE message (" . $remove['message'] . ') ';
				}
			}

			if ($sql_sel != '' || $sql_dlt != '') {
				$debugm = '';

				// process the removal rule first
				if ($sql_sel != '') {
					$move_count = 0;
					// first insert, then delete
					$move_records = syslog_db_fetch_assoc($sql_sel);
					syslog_debug(sprintf('Found   %5s - Message(s)', cacti_sizeof($move_records)));

					if (cacti_sizeof($move_records)) {
						$all_seq        = '';
						$messages_moved = 0;

						foreach ($move_records as $move_record) {
							$all_seq = $all_seq . ', ' . $move_record['seq'];
						}

						$all_seq = preg_replace('/^,/i', '', $all_seq);
						syslog_db_execute('INSERT INTO `' . $syslogdb_default . '`.`' . $to_table . '`
							(facility_id, priority_id, host_id, logtime, message)
							(SELECT facility_id, priority_id, host_id, logtime, message
							FROM `' . $syslogdb_default . '`.' . $from_table . '
							WHERE seq IN (' . $all_seq . '))');

						$messages_moved = db_affected_rows($syslog_cnn);

						if ($messages_moved > 0) {
							syslog_db_execute('DELETE FROM `' . $syslogdb_default . '`.`' . $from_table . '`
								WHERE seq IN (' . $all_seq . ')');
						}

						$xferred += $messages_moved;
						$move_count = $messages_moved;
					}

					$debugm = sprintf('Moved   %5s - Message(s)', $move_count);
				}

				if ($sql_dlt != '') {
					// now delete the remainder that match
					syslog_db_execute($sql_dlt);
					$removed += db_affected_rows($syslog_cnn);
					$debugm   = sprintf('Deleted %5s Message(s)', $removed);
				}

				syslog_debug($debugm);
			}
		}
	}

	return ['removed' => $removed, 'xferred' => $xferred];
}

/**
 * get_hash_syslog - returns the current unique hash for an alert
 *
 * @param mixed $id
 * @param mixed $table
 *
 * @return string 128-bit hexadecimal hash
 */
function get_hash_syslog($id, $table) {
	$hash = syslog_db_fetch_cell_prepared('SELECT hash
		FROM ' . $table . '
		WHERE id = ?',
		[$id]);

	if (empty($hash)) {
		return generate_hash();
	}

	if (preg_match('/[a-fA-F0-9]{32}/', $hash)) {
		return $hash;
	} else {
		return generate_hash();
	}
}

function syslog_ia2xml($array) {
	$xml = '';

	if (cacti_sizeof($array)) {
		foreach ($array as $key=>$value) {
			if (is_array($value)) {
				$xml .= "\t<$key>" . syslog_ia2xml($value) . "</$key>\n";
			} else {
				$xml .= "\t<$key>" . html_escape($value) . "</$key>\n";
			}
		}
	}

	return $xml;
}

function syslog_array2xml($array, $tag = 'template') {
	static $index = 1;

	$xml = "<$tag$index>\n" . syslog_ia2xml($array) . "</$tag$index>\n";

	$index++;

	return $xml;
}

/**
 * syslog_execute_ticket_command - run the configured ticketing command for an alert
 *
 * @param array  $alert         The alert row from syslog_alert table
 * @param array  $hostlist      Hostnames matched by the alert
 * @param string $error_message sprintf template used if exec() returns non-zero
 *
 * @return void
 */
function syslog_execute_ticket_command($alert, $hostlist, $error_message) {
	$command = read_config_option('syslog_ticket_command');

	if ($command != '') {
		$command = trim($command);
	}

	if ($alert['open_ticket'] == 'on' && $command != '') {
		// trim surrounding quotes so paths like "/usr/bin/cmd" resolve correctly
		$cparts     = preg_split('/\s+/', trim($command));
		$executable = trim($cparts[0], '"\'');

		if (cacti_sizeof($cparts) && is_executable($executable)) {
			$command = $command .
				' --alert-name=' . cacti_escapeshellarg(clean_up_name($alert['name'])) .
				' --severity=' . cacti_escapeshellarg($alert['severity']) .
				' --hostlist=' . cacti_escapeshellarg(implode(',', $hostlist)) .
				' --message=' . cacti_escapeshellarg($alert['message']);

			$output = [];
			$return = 0;

			exec($command, $output, $return);

			if ($return !== 0) {
				cacti_log(sprintf($error_message, $alert['name'], $return, implode(', ', $output)), false, 'SYSLOG');
			}
		} else {
			$reason = (strpos($executable, DIRECTORY_SEPARATOR) === false)
				? 'PATH-based lookups are not supported; use an absolute path'
				: 'file not found or not marked executable';
			cacti_log("SYSLOG ERROR: Ticket command is not executable: '$command' -- $reason", false, 'SYSTEM');
		}
	}
}

/**
 * syslog_execute_alert_command - run the per-alert shell command for a matched result
 *
 * @param array  $alert    The alert row from syslog_alert table
 * @param array  $results  The matched syslog result row
 * @param string $hostname Resolved hostname for the source device
 *
 * @return void
 */
function syslog_execute_alert_command($alert, $results, $hostname) {
	/* alert_replace_variables() escapes each substituted token (<ALERTID>,
	 * <HOSTNAME>, <PRIORITY>, <FACILITY>, <MESSAGE>, <SEVERITY>) with
	 * cacti_escapeshellarg(). The command template itself comes from admin
	 * configuration ($alert['command']) and is trusted at that boundary.
	 * Do not introduce additional substitution paths that bypass this escaping. */
	$command = alert_replace_variables($alert, $results, $hostname);

	// trim surrounding quotes so paths like "/usr/bin/cmd" resolve correctly
	$cparts     = preg_split('/\s+/', trim($command));
	$executable = trim($cparts[0], '"\'');

	$output = [];
	$return = 0;

	if (cacti_sizeof($cparts) && is_executable($executable)) {
		exec($command, $output, $return);

		if ($return !== 0 && !empty($output)) {
			cacti_log('SYSLOG NOTICE: Alert command output: ' . implode(', ', $output), true, 'SYSTEM');
		}

		if ($return !== 0) {
			cacti_log(sprintf('ERROR: Alert command failed.  Alert:%s, Exit:%s, Output:%s', $alert['name'], $return, implode(', ', $output)), false, 'SYSLOG');
		}
	} else {
		$reason = (strpos($executable, DIRECTORY_SEPARATOR) === false)
			? 'PATH-based lookups are not supported; use an absolute path'
			: 'file not found or not marked executable';
		cacti_log("SYSLOG ERROR: Alert command is not executable: '$command' -- $reason", false, 'SYSTEM');
	}
}

/**
 * syslog_process_alerts - Process each of the Syslog Alerts
 *
 * Syslog Alerts come in essentially 4 types
 *
 * System Wide non-threshold alerts - These alerts are simply alerts that match the pattern defined by the alert
 * System Wide threshold alerts     - These alerts are syslog messages that both match the pattern and have more than the
 *                                    threshold amount that take place every collector cycle (30 seconds, 1 minutes, 5 minutes, etc)
 * Host based non-threshold alerts  - Alerts that happen on a per host basis, so you can alert for each host that the syslog message
 *                                    occurred to.
 * Host based threshold alerts      - Like the system level alert, it's an alert that happens more than x times per host.
 *
 * The advantage and reason for having host based alerts is that it allows you to target ticket generation for a specific host
 * and more importantly, to be able to have a separate re-alert cycles for that very same message as there can be similar messages
 * happening all the time at the system level, so it's hard to target a single host for re-alert rules.
 *
 * @param int  $max_seq The max_seq to process
 *
 * @return array An array of the number of alerts processed and the number of alerts generated
 */
function syslog_process_alerts($max_seq) {
	global $syslogdb_default;

	$syslog_alarms = 0;
	$syslog_alerts = 0;

	// send out the alerts
	$alerts = syslog_db_fetch_assoc('SELECT *
		FROM `' . $syslogdb_default . "`.`syslog_alert`
		WHERE enabled='on'");

	if (cacti_sizeof($alerts)) {
		$syslog_alerts = cacti_sizeof($alerts);
	}

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Alerts...');
	syslog_debug('-------------------------------------------------------------------------------------');

	syslog_debug(sprintf('Found   %5s - Alert Rule(s) to process', $syslog_alerts));

	if (cacti_sizeof($alerts)) {
		foreach ($alerts as $alert) {
			$sql      = '';
			$params   = [];

			// we roll up statistics depending on the level
			if ($alert['level'] == 1) {
				$groupBy = ' GROUP BY host';
			} else {
				$groupBy = '';
			}

			$sql_data = syslog_get_alert_sql($alert, $max_seq);

			if (!cacti_sizeof($sql_data)) {
				syslog_debug(sprintf('Error       - Unable to determine SQL for Alert \'%s\'', $alert['name']));

				continue;
			}

			$sql    = $sql_data['sql'];
			$params = $sql_data['params'];

			if ($sql != '') {
				if ($alert['level'] == '1') {
					$th_sql  = str_replace('*', 'host, COUNT(*) AS count', $sql);
					$results = syslog_db_fetch_assoc_prepared($th_sql . $groupBy, $params);

					if (cacti_sizeof($results)) {
						foreach ($results as $result) {
							$aparams   = $params;
							$aparams[] = $result['host'];

							$asql = $sql . ' AND host = ?';

							$syslog_alarms += syslog_process_alert($alert, $asql, $aparams, $result['count'], $result['host']);
						}
					}
				} elseif ($alert['method'] == '1') {
					$th_sql = str_replace('*', 'COUNT(*)', $sql);
					$count  = syslog_db_fetch_cell_prepared($th_sql . $groupBy, $params);
					$syslog_alarms += syslog_process_alert($alert, $sql, $params, $count);
				} else {
					$count = 0;
					$syslog_alarms += syslog_process_alert($alert, $sql, $params, $count);
				}
			}
		}
	}

	return ['syslog_alerts' => $syslog_alerts, 'syslog_alarms' => $syslog_alarms];
}

/**
 * syslog_process_alert - Process the Alert and generate notifications, execute commands, etc.
 *
 * @param array  $alert The alert to process
 * @param string $sql The SQL to search for the Alert
 * @param array  $params The SQL parameters to be prepared into the SQL
 * @param int    $count In the case of a threshold alert, the number of occurrents
 *                      of hosts with occurrences that were encountered through
 *                      pre-processing the message
 * @param string $hostname The hostname that this alert rule is for
 *
 * @return int 1 if the alert triggered, else 0
 */
function syslog_process_alert($alert, $sql, $params, $count, $hostname = '') {
	global $config, $severities, $syslog_levels;

	include_once($config['base_path'] . '/lib/reports.php');

	$messese  = '';
	$smsalert = '';

	$alert_count   = 0;
	$syslog_alarms = 0;
	$hostlist      = [];
	$max_alerts    = read_config_option('syslog_maxrecords');
	$report_tag    = false;
	$theme         = false;
	$format_ok     = false;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug(sprintf('Processing    - %s', $alert['name']));

	if (read_config_option('syslog_html') == 'on') {
		$html      = true;
		$format_ok = reports_load_format_file(read_config_option('syslog_format_file'), $output, $report_tag, $theme);

		syslog_debug('Format/CSS ' . ($format_ok ? 'Ok' : 'Not Ok') . ' - Report Tag ' . ($report_tag ? 'included' : 'missing'));
	} else {
		$html = false;
	}

	/**
	 * format the from Email address
	 */
	$from_email = read_config_option('settings_from_email');

	if ($from_email == '') {
		$from_email = 'Cacti@cacti.net';
	}

	$from_name  = read_config_option('settings_from_name');

	if ($from_name == '') {
		$from_name = 'Cacti Reporting';
	}

	$from = [$from_email, $from_name];

	/**
	 * format the destination Email addresses
	 */
	$alert['email'] = trim($alert['email'], ', ');

	if ($alert['notify'] > 0) {
		$additional = db_fetch_cell_prepared('SELECT emails
			FROM plugin_notification_lists
			WHERE id = ?',
			[$alert['notify']]);

		if ($additional != '') {
			$alert['email'] .= ', ' . trim($additional, ' ,');
		}
	}

	/**
	 * process the alert now.
	 */
	if (($alert['method'] == '1' && $count >= $alert['num']) || $alert['method'] == '0') {
		$at = syslog_db_fetch_assoc_prepared($sql, $params);

		/**
		 * get a date for the repeat alert
		 */
		if ($alert['repeat_alert']) {
			$date = date('Y-m-d H:i:s', time() - ($alert['repeat_alert'] * read_config_option('poller_interval')));
		} else {
			$date = '';
		}

		/**
		 * The finalized email or test message.
		 */
		$message  = '';

		/**
		 * A list of all messages from the alert
		 */
		$results = [];

		syslog_debug(sprintf('Found   %5s - Matching Records.', cacti_sizeof($at)));

		if (cacti_sizeof($at)) {
			if ($html) {
				if (!$format_ok) {
					$message .= "<style type='text/css'>";
					$message .= file_get_contents($config['base_path'] . '/plugins/syslog/syslog.css');
					$message .= '</style>';
				}

				if ($alert['method'] == '1') {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= '<h1>' . __esc('Cacti Syslog Threshold Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog') . '</h1>';
						} else {
							$message .= '<h1>' . __esc('Cacti Syslog Threshold Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
						}
					} else {
						$message .= '<table class="cactiTable"><tr><td>' . $alert['body'] . '</td></td></table>';
					}

					$message .= '<table class="cactiTable">';
					$message .= '<tr class="header_row tableHeader">
						<th>' . __('Alert Name', 'syslog') . '</th>
						<th>' . __('Severity', 'syslog') . '</th>
						<th>' . __('Threshold', 'syslog') . '</th>
						<th>' . __('Count', 'syslog') . '</th>
						<th>' . __('Match String', 'syslog') . '</th>
					</tr>';

					$message .= '<tr><td>' . html_escape($alert['name']) . '</td>';
					$message .= '<td>' . $severities[$alert['severity']] . '</td>';
					$message .= '<td>' . $alert['num'] . '</td>';
					$message .= '<td>' . sizeof($at) . '</td>';
					$message .= '<td>' . html_escape($alert['message']) . '</td></tr></table><br>';
				} else {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= '<h1>' . __esc('Cacti Syslog Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog') . '</h1>';
						} else {
							$message .= '<h1>' . __esc('Cacti Syslog Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
						}
					} else {
						$message .= '<table class="cactiTable"><tr><td>' . $alert['body'] . '</td></td></table>';
					}
				}

				$message .= '<table class="cactiTable">';
				$message .= '<tr class="header_row tableHeader">
					<th>' . __('Hostname', 'syslog') . '</th>
					<th>' . __('Date', 'syslog') . '</th>
					<th>' . __('Severity', 'syslog') . '</th>
					<th>' . __('Level', 'syslog') . '</th>
					<th>' . __('Message', 'syslog') . '</th>
				</tr>';
			} else {
				if ($alert['method'] == '1') {
					if ($alert['body'] == '') {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;

						if ($hostname != '') {
							$message .= __('WARNING: A Syslog Threshold Alert has Been Triggered for Host \'%s\'', $hostname, 'syslog') . PHP_EOL . PHP_EOL;
						} else {
							$message .= __('WARNING: A Syslog Threshold Alert has Been Triggered', 'syslog') . PHP_EOL . PHP_EOL;
						}
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= $alert['body'] . PHP_EOL;
					}

					$message .= __('Name:', 'syslog') . ' ' . html_escape($alert['name']) . PHP_EOL;
					$message .= __('Severity:', 'syslog') . ' ' . $severities[$alert['severity']] . PHP_EOL;
					$message .= __('Threshold:', 'syslog') . ' ' . $alert['num'] . PHP_EOL;
					$message .= __('Count:', 'syslog') . ' ' . sizeof($at) . PHP_EOL;
					$message .= __('Message String:', 'syslog') . ' ' . html_escape($alert['message']) . PHP_EOL;
				} else {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= __esc('Cacti Syslog Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog');
						} else {
							$message .= __esc('Cacti Syslog Alert \'%s\'', $alert['name'], 'syslog');
						}
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= $alert['body'];
					}
				}
			}

			$hmessage = $message;
			$plogged  = false;
			$flogged  = false;

			foreach ($at as $a) {
				$hostlist[]         = $a['host'];
				$results['message'] = (isset($results['message']) ? $results['message'] . ', ' : '') . $a['message'];

				if (isset($results['priority_id']) && $results['priority_id'] != $a['priority_id'] && !$plogged) {
					cacti_log(sprintf('Alert \'%s\' has more than one priority id, last one experienced will be leveraged', $alert['name']), false, 'SYSLOG');
					$plogged = true;
				}

				if (isset($results['facility_id']) && $results['facility_id'] != $a['facility_id'] && !$flogged) {
					cacti_log(sprintf('Alert \'%s\' has more than one facility id, last one experienced will be leveraged', $alert['name']), false, 'SYSLOG');
					$flogged = true;
				}

				$results['priority_id'] = $a['priority_id'];
				$results['facility_id'] = $a['facility_id'];

				if (($alert['method'] == 1 && $alert_count < $max_alerts) || $alert['method'] == 0) {
					if ($alert['method'] == 0) {
						$message = $hmessage;
					}

					if ($html) {
						$message .= '<tr>
							<td>' . html_escape($a['host']) . '</td>
							<td>' . $a['logtime'] . '</td>
							<td>' . $severities[$alert['severity']] . '</td>
							<td>' . $syslog_levels[$a['priority_id']] . '</td>
							<td>' . html_escape($a['message']) . '</td>
						</tr>';
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= __('Hostname:', 'syslog') . ' ' . html_escape($a['host']) . PHP_EOL;
						$message .= __('Date:', 'syslog') . ' ' . $a['logtime'] . PHP_EOL;
						$message .= __('Severity:', 'syslog') . ' ' . $severities[$alert['severity']] . PHP_EOL . PHP_EOL;
						$message .= __('Level:', 'syslog') . ' ' . $syslog_levels[$a['priority_id']] . PHP_EOL . PHP_EOL;
						$message .= __('Message:', 'syslog') . ' ' . PHP_EOL . $a['message'] . PHP_EOL;
					}
				}
			}

			$hostlist = array_unique($hostlist);

			$syslog_alarms++;
			$alert_count++;

			$send  = true;
			$found = false;

			/**
			 * If this is a repeat alert type threshold, then check to
			 * see if it's time to re-alert.
			 */
			if ($alert['repeat_alert'] > 0) {
				if ($hostname != '') {
					$found = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
						FROM syslog_logs
						WHERE alert_id = ?
						AND logtime > ?
						AND host = ?',
						[$alert['id'], $date, $hostname]);
				} else {
					$found = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
						FROM syslog_logs
						WHERE alert_id = ?
						AND logtime > ?
						AND host = "system"',
						[$alert['id'], $date]);
				}
			}

			if ($found) {
				$send = false;
			}

			if ($html) {
				$message .= '</table>';
			} else {
				$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
			}

			if ($html) {
				if ($format_ok) {
					if ($report_tag) {
						$message = str_replace('<REPORT>', $message, $output);
					} else {
						$message = $output . $message . '</body></html>';
					}
				} else {
					$message = '<html><body>' . $message . '</body></html>';
				}
			}

			/**
			 * This is a Traditional syslog alert where all matching messages
			 * will be reported in the notification.
			 */
			if ($alert['method'] == '0') {
				if ($send) {
					$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $a, 1, $message);

					$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Host:', 'syslog') . $a['host'] . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;

					/**
					 * Send the Email notification
					 */
					if ($alert['email'] != '' || $smsalert != '') {
						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), $message, $smsalert);
					}

					alert_setup_environment($alert, $results, $hostlist, $hostname);

					/**
					 * Open a ticket if this options have been selected.
					 */
					syslog_execute_ticket_command($alert, $hostlist, 'ERROR: Ticket Command Failed.  Alert:%s, Exit:%s, Output:%s');

					if (trim($alert['command']) != '' && !$found) {
						syslog_execute_alert_command($alert, $results, $hostname);
					}
				}
			} elseif ($alert['method'] == 1) {
				if ($send) {
					/**
					 * Send the Email notification
					 */
					if ($alert['email'] != '' || $smsalert != '') {
						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), $message, $smsalert);
					}

					$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $at[0], sizeof($at), $message, $hostlist);
					$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Count:', 'syslog') . sizeof($at) . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;

					alert_setup_environment($alert, $results, $hostlist, $hostname);

					syslog_execute_ticket_command($alert, $hostlist, 'ERROR: Command Failed.  Alert:%s, Exit:%s, Output:%s');

					if (trim($alert['command']) != '' && !$found) {
						syslog_execute_alert_command($alert, $results, $hostname);
					}
				}
			}

			syslog_debug("Alert Rule '" . $alert['name'] . "' has been triggered");
		}
	}

	return $alert_count;
}

/**
 * syslog_get_alert_sql - Get the SQL and params for the alert to
 * checi.
 *
 * @param array $alert The alert attributes to process
 * @param int   $max_seq The max sequence
 *
 * @return array The SQL and the prepared array for the SQL
 */
function syslog_get_alert_sql(&$alert, $max_seq) {
	global $syslogdb_default, $syslog_incoming_config;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if (!isset($syslog_incoming_config['programField'])) {
		$syslog_incoming_config['programField'] = 'program';
	}

	$params = [];
	$sql    = '';

	if ($alert['type'] == 'facility') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['facilityField'] . '` = ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messageb') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'] . '%';
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messagec') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = '%' . $alert['message'] . '%';
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messagee') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = '%' . $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'host') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['hostField'] . '` = ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'program') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['programField'] . '` = ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'sql') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE (' . $alert['message'] . ')
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $max_seq;
	}

	return ['sql' => $sql, 'params' => $params];
}

/**
 * syslog_preprocess_incoming_records - Generate a max_seq to allow moving of
 * records to done table and mark incoming records with the max_seq and
 * then if syslog is configured to strip domains, perform that first.
 *
 * @return int Unique id to allow syslog messages that come in randomly to
 *             be differentiate between messages to process and messages
 *             to be left till then ext polling cycle.
 */
function syslog_preprocess_incoming_records() {
	global $syslogdb_default;

	$max_seq = syslog_db_fetch_cell('SELECT MAX(seq) FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE status = 0');

	if ($max_seq > 0) {
		// flag all records with the status = 1 prior to moving
		syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
			SET `status` = 1
			WHERE `status` = 0
			AND `seq` <= ?',
			[$max_seq]);

		syslog_debug('Max Sequence ID = ' . $max_seq);
		syslog_debug('-------------------------------------------------------------------------------------');

		$syslog_incoming = syslog_db_fetch_cell_prepared('SELECT COUNT(seq)
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?',
			[$max_seq]);

		syslog_debug(sprintf('Found   %5s - New Message(s) to process', $syslog_incoming));

		// strip domains if we have requested to do so
		syslog_strip_incoming_domains($max_seq);

		api_plugin_hook('plugin_syslog_before_processing');

		return ['max_seq' => $max_seq, 'incoming' => $syslog_incoming];
	}

	return ['max_seq' => 0, 'incoming' => 0];
}

/**
 * syslog_strip_incoming_domains - If syslog is setup to strip DNS domain name suffixes do that
 * prior to processing the records.
 *
 * @param int $max_seq The max_seq records to process
 *
 * @return void
 */
function syslog_strip_incoming_domains($max_seq) {
	global $syslogdb_default;

	$syslog_domains = read_config_option('syslog_domains');

	if ($syslog_domains != '') {
		$domains = explode(',', trim($syslog_domains));

		foreach ($domains as $domain) {
			syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
				SET host = SUBSTRING_INDEX(host, \'.\', 1)
				WHERE host LIKE ?
				AND `status` = 1
				AND `seq` <= ?',
				['%' . $domain, $max_seq]);
		}
	}
}

/**
 * Check if the hostname is in the cacti hosts table
 * Some devices only send IP addresses in syslog messages, and may not be in the DNS
 * however they may be in the cacti hosts table as monitored devices.
 *
 * @param string $host The hostname to check
 * @param int    $max_seq The max_seq for syslog_incoming messages to process
 *
 * @return bool True if the host exists in the Cacti database, false otherwise
 */
function syslog_check_cacti_hosts($host, $max_seq) {
	global $syslogdb_default;

	if (empty($host)) {
		return false;
	}

	// Check if the host exists in cacti by hostname and get the description
	$cacti_host = db_fetch_row_prepared('SELECT DISTINCT description
		FROM host
		WHERE hostname = ?
		LIMIT 1',
		[$host]);

	if (cacti_sizeof($cacti_host) && !empty($cacti_host['description'])) {
		syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
			SET host = ?
			WHERE host = ?
			AND `status` = 1
			AND `seq` <= ?',
			[$cacti_host['description'], $host, $max_seq]);

		return true;
	}

	return false;
}

/**
 * syslog_update_reference_tables - There are many values in the syslog plugin
 * that for the purposes of reducing the size of the syslog table are normalized
 * the columns includes the facility, the priority, and the hostname.
 *
 * This function will add those new hostnames to the various reference tables
 * and assign an id to each of them.  This way the syslog table can be optimized
 * for size as much as possible.
 *
 * @param int $max_seq The max_seq for syslog_incoming messages to process
 *
 * @return void
 */
function syslog_update_reference_tables($max_seq) {
	global $syslogdb_default;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Updating Reference Tables from New Syslog Records');

	// Validate and resolve hostnames - check DNS first, then Cacti, then mark invalid
	if (read_config_option('syslog_resolve_hostname') == 'on') {
		$hosts = syslog_db_fetch_assoc_prepared('SELECT DISTINCT host
            FROM `' . $syslogdb_default . '`.`syslog_incoming`
            WHERE `status` = 1
			AND `seq` <= ?',
			[$max_seq]);

		foreach ($hosts as $host) {
			if (!isset($host['host']) || empty($host['host'])) {
				continue;
			}

			$resolved = false;

			// Check if hostname resolves via DNS (only if DNS is enabled)
			if (read_config_option('syslog_no_dns') != 'on') {
				if ($host['host'] != gethostbyname($host['host'])) {
					// DNS resolved successfully
					$resolved = true;
				}
			}

			// Check if hostname exists in Cacti hosts table (only if not already resolved via DNS)
			if (!$resolved) {
				$resolved = syslog_check_cacti_hosts($host['host'], $max_seq);
			}

			// If not resolved via DNS or found in Cacti, prefix the hostname
			if (!$resolved) {
				$unresolved_host = 'unresolved-' . $host['host'];
				cacti_log("SYSLOG WARNING: Hostname '" . $host['host'] . "' could not be resolved via DNS or found in Cacti hosts table, marking as '" . $unresolved_host . "'", false, 'SYSLOG');
				syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
                    SET host = ?
                    WHERE host = ?
                    AND `status` = 1
					AND `seq` <= ?',
					[$unresolved_host, $host['host'], $max_seq]);
			}
		}
	}

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_programs`
		(program, last_updated)
		SELECT DISTINCT program, NOW()
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?
		ON DUPLICATE KEY UPDATE
			program=VALUES(program),
			last_updated=VALUES(last_updated)',
		[$max_seq]);

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_hosts`
		(host, last_updated)
		SELECT DISTINCT host, NOW() AS last_updated
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?
		ON DUPLICATE KEY UPDATE
			host=VALUES(host),
			last_updated=NOW()',
		[$max_seq]);

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_host_facilities`
		(host_id, facility_id)
		SELECT host_id, facility_id
		FROM (
			(
				SELECT DISTINCT host, facility_id
				FROM `' . $syslogdb_default . '`.`syslog_incoming`
				WHERE `status` = 1
				AND `seq` <= ?
			) AS s
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON s.host = sh.host
		)
		ON DUPLICATE KEY UPDATE
			host_id=VALUES(host_id),
			last_updated=NOW()',
		[$max_seq]);
}

/**
 * syslog_update_statistics - Insert new statistics rows into the syslog statistics
 * table for post review
 *
 * @param int $max_seq The max_seq for all syslog incoming records to be processed
 *
 * @return void
 */
function syslog_update_statistics($max_seq) {
	global $syslogdb_default, $syslog_cnn;

	if (read_config_option('syslog_statistics') == 'on') {
		syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_statistics`
			(host_id, facility_id, priority_id, program_id, insert_time, records)
			SELECT host_id, facility_id, priority_id, program_id, NOW(), SUM(records) AS records
			FROM (SELECT host_id, facility_id, priority_id, program_id, COUNT(*) AS records
				FROM syslog_incoming AS si
				INNER JOIN syslog_hosts AS sh
				ON sh.host=si.host
				INNER JOIN syslog_programs AS sp
				ON sp.program=si.program
				WHERE si.`status` = 1
				AND si.`seq` <= ?
				GROUP BY host_id, priority_id, facility_id, program_id) AS merge
			GROUP BY host_id, priority_id, facility_id, program_id',
			[$max_seq]);

		$stats = db_affected_rows($syslog_cnn);

		syslog_debug('Stats   ' . $stats . " - Record(s) to the 'syslog_statistics' table");
	}
}

/**
 * syslog_incoming_to_syslog - Move incoming syslog records to the syslog table
 *
 * Once all Alerts have been processed, we need to move entries first to
 * the syslog table, and then after which we can perform various
 * removal rules against them.
 *
 * @param int $max_seq The max_seq for rows in the syslog table
 *
 * @return int The number of rows moved to the syslog table
 */
function syslog_incoming_to_syslog($max_seq) {
	global $syslogdb_default, $syslog_cnn;

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog`
		(logtime, priority_id, facility_id, program_id, host_id, message)
		SELECT logtime, priority_id, facility_id, program_id, host_id, message
		FROM (
			SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message
			FROM syslog_incoming AS si
			INNER JOIN syslog_hosts AS sh
			ON sh.host = si.host
			INNER JOIN syslog_programs AS sp
			ON sp.program = si.program
			WHERE si.`status` = 1
			AND si.`seq` <= ?
		) AS merge',
		[$max_seq]);

	$moved = db_affected_rows($syslog_cnn);

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Moving or Removing Processed Records');

	syslog_debug(sprintf('Moved   %5s - Message(s) to the syslog table', $moved));

	syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?',
		[$max_seq]);

	syslog_debug(sprintf('Deleted %5s - Already Processed Message(s) from incoming', db_affected_rows($syslog_cnn)));

	syslog_db_execute('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE logtime < DATE_SUB(NOW(), INTERVAL 1 HOUR)');

	$stale = db_affected_rows($syslog_cnn);

	syslog_debug(sprintf('Deleted %5s - Stale Message(s) from incoming', $stale));

	return ['moved' => $moved, 'stale' => $stale];
}

/**
 * syslog_postprocess_tables - Remove stale records and optimize tables after
 * message processing has been completed.
 *
 * @return void
 */
function syslog_postprocess_tables() {
	global $syslogdb_default, $syslog_cnn;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Post Processing/Maintenance of Syslog Tables');
	syslog_debug('-------------------------------------------------------------------------------------');

	$delete_date = date('Y-m-d H:i:s', time() - (read_config_option('syslog_retention') * 86400));

	// remove stats messages
	if (read_config_option('syslog_statistics') == 'on') {
		if (read_config_option('syslog_retention') > 0) {
			syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_statistics`
				WHERE insert_time < ?',
				[$delete_date]);

			syslog_debug(sprintf('Deleted %5s - Syslog Statistics Record(s)', db_affected_rows($syslog_cnn)));
		}
	} else {
		syslog_db_execute('TRUNCATE `' . $syslogdb_default . '`.`syslog_statistics`');
	}

	// remove alert log messages
	if (read_config_option('syslog_alert_retention') > 0) {
		api_plugin_hook_function('syslog_delete_hostsalarm', $delete_date);

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_logs`
			WHERE logtime < ?',
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Syslog alarm log Record(s)', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_hosts`
			WHERE last_updated < ?',
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Syslog Host Record(s)', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_programs`
			WHERE last_updated < ?',
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Old programs from programs table', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_host_facilities`
			WHERE last_updated < ?',
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Syslog Host/Facility Record(s)', db_affected_rows($syslog_cnn)));
	}

	// OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP
	if (date('G') == 0 && date('i') < 5) {
		syslog_debug('Optimizing Tables');

		if (!syslog_is_partitioned()) {
			syslog_db_execute('OPTIMIZE TABLE
				`' . $syslogdb_default . '`.`syslog_incoming`,
				`' . $syslogdb_default . '`.`syslog`,
				`' . $syslogdb_default . '`.`syslog_remove`,
				`' . $syslogdb_default . '`.`syslog_removed`,
				`' . $syslogdb_default . '`.`syslog_alert`');
		} else {
			syslog_db_execute('OPTIMIZE TABLE
				`' . $syslogdb_default . '`.`syslog_incoming`,
				`' . $syslogdb_default . '`.`syslog_remove`,
				`' . $syslogdb_default . '`.`syslog_alert`');
		}
	}
}

/**
 * syslog_process_reports - Processes all syslog reports scheduled to run
 *
 * @return array An array of total and sent reports
 */
function syslog_process_reports() {
	global $config, $syslogdb_default, $syslog_cnn, $forcer;

	include_once($config['base_path'] . '/lib/reports.php');

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Reports...');
	syslog_debug('-------------------------------------------------------------------------------------');

	$report_tag = false;
	$theme      = false;
	$format_ok  = false;

	if (read_config_option('syslog_html') == 'on') {
		$html      = true;
		$format_ok = reports_load_format_file(read_config_option('syslog_format_file'), $output, $report_tag, $theme);

		syslog_debug('Format/CSS ' . ($format_ok ? 'Ok' : 'Not Ok') . ' - Report Tag ' . ($report_tag ? 'included' : 'missing'));
	} else {
		$html = false;
	}

	// Lets run the reports
	$reports = syslog_db_fetch_assoc('SELECT *
		FROM `' . $syslogdb_default . "`.`syslog_reports`
		WHERE enabled='on'");

	$total_reports = cacti_sizeof($reports);
	$sent_reports  = 0;

	syslog_debug('We have ' . $total_reports . ' Reports in the database to check');

	if (cacti_sizeof($reports)) {
		$total_reports = cacti_sizeof($reports);

		foreach ($reports as $report) {
			syslog_debug('-------------------------------------------------------------------------------------');
			syslog_debug(sprintf('Processing    - %s', $report['name']));

			$base_start_time = $report['timepart'];
			$last_run_time   = $report['lastsent'];
			$time_span       = $report['timespan'];
			$seconds_offset  = read_config_option('cron_interval');

			$current_time = time();

			if (empty($last_run_time)) {
				$start = strtotime(date('Y-m-d 00:00', $current_time)) + $base_start_time;

				if ($current_time > $start) {
					// if timer expired within a polling interval, then poll
					if (($current_time - $seconds_offset) < $start) {
						$next_run_time = $start;
					} else {
						$next_run_time = $start + 3600 * 24;
					}
				} else {
					$next_run_time = $start;
				}
			} else {
				$next_run_time = strtotime(date('Y-m-d 00:00', $last_run_time)) + $base_start_time + $time_span;
			}

			$time_till_next_run = $next_run_time - $current_time;

			if ($time_till_next_run < 0 || $forcer) {
				syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_reports`
					SET lastsent = ?
					WHERE id = ?',
					[time(), $report['id']]);

				syslog_debug('Next Send     - Now');
				syslog_debug('Creating Report...');

				$reptext = '';

				$sql = syslog_get_report_sql($report);

				if ($sql != '') {
					$date2 = date('Y-m-d H:i:s', $current_time);
					$date1 = date('Y-m-d H:i:s', $current_time - $time_span);
					$sql .= ' AND logtime BETWEEN ? AND ?';
					$sql .= ' ORDER BY logtime DESC';
					$items = syslog_db_fetch_assoc_prepared($sql, [$data1, $date2]);

					syslog_debug('We have ' . db_affected_rows($syslog_cnn) . ' items for the Report');

					$classes = ['even', 'odd'];

					if (cacti_sizeof($items)) {
						$i = 0;

						foreach ($items as $item) {
							$class = $classes[$i % 2];

							$reptext .= '<tr class="' . $class . '">
								<td class="host">' . html_escape($item['host']) . '</td>
								<td class="date">' . $item['logtime'] . '</td>
								<td class="message">' . html_escape($item['message']) . '</td>
							</tr>';

							$i++;
						}
					}

					if ($reptext != '') {
						if (!$format_ok) {
							$message  = '<style type="text/css">';
							$message .= file_get_contents($config['base_path'] . '/plugins/syslog/syslog.css');
							$message .= '</style>';
						}

						$message .= '<h1>Cacti Syslog Report - ' . html_escape($report['name']) . '</h1>';
						$message .= '<hr>';
						$message .= '<p>' . $report['body'] . '</p>';
						$message .= '<hr>';

						$message .= '<table class="cactiTable">';

						$message .= '<tr class="header_row tableHeader">
							<th>' . __('Host', 'syslog') . '</th>
							<th>' . __('Date', 'syslog') . '</th>
							<th>' . __('Message', 'syslog') . '</th>
						</tr>';

						$message .= $reptext;

						$message .= '</table>';

						$smsalert  = '';

						$sent_reports++;

						if ($html) {
							if ($format_ok) {
								if ($report_tag) {
									$message = str_replace('<REPORT>', $message, $output);
								} else {
									$message = $output . $message . '</body></html>';
								}
							} else {
								$message = '<html><body>' . $message . '</body></html>';
							}
						}

						syslog_sendemail($report['email'], $from, __esc('Event Report - %s', $report['name'], 'syslog'), $message, $smsalert);
					}
				}
			} else {
				syslog_debug('Next Send     - ' . date('Y-m-d H:i:s', $next_run_time));
			}
		}
	}

	return ['total_reports' => $total_reports, 'sent_reports' => $sent_reports];
}

/**
 * syslog_get_report_sql - Return the SQL syntax for the report query
 *
 * @param array $report The report to process
 *
 * @return string The unprepared SQL
 */
function syslog_get_report_sql(&$report) {
	global $syslogdb_default;

	if ($report['type'] == 'messageb') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE ' . db_qstr($report['message'] . '%');
	}

	if ($report['type'] == 'messagec') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE ' . db_qstr('%' . $report['message'] . '%');
	}

	if ($report['type'] == 'messagee') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE ' . db_qstr('%' . $report['message']);
	}

	if ($report['type'] == 'host') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE sh.host = ' . db_qstr($report['message']);
	}

	if ($report['type'] == 'facility') {
		$sql = 'SELECT sl.*, sf.facility
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
			ON sl.facility_id = sf.facility_id
			WHERE sf.facility = ' . db_qstr($report['message']);
	}

	if ($report['type'] == 'program') {
		$sql = 'SELECT sl.*, sp.program
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS sp
			ON sl.program_id = sp.program_id
			WHERE sp.program = ' . db_qstr($report['message']);
	}

	if ($report['type'] == 'sql') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog`
			WHERE (' . $report['message'] . ')';
	}

	return $sql;
}

/**
 * generate a Cacti log message and save settings in the settings table for use
 * by various graph templates
 *
 * @param string $start_time The start time of the polling process
 * @param int    $deleted The number of syslog messages deleted
 * @param int    $incoming The number of syslog incoming messages
 * @param int    $removed The number of syslog messages removed
 * @param int    $xferred The number of syslog messages transferred
 * @param int    $alerts The number of alerts processed
 * @param int    $alarms The number of alerts triggered
 * @param int    $reports The number of reports sent
 *
 * @return void
 */
function syslog_process_log($start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports) {
	global $database_default, $debug;

	// record the end time
	$end_time = microtime(true);

	$stats =
		' Time:' . round($end_time - $start_time,2) .
		' Deletes:' . $deleted .
		' Incoming:' . $incoming .
		' Removes:' . $removed .
		' XFers:' . $xferred .
		' Alerts:' . $alerts .
		' Alarms:' . $alarms .
		' Reports:' . $reports;

	cacti_log('SYSLOG STATS:' . $stats, false, 'SYSTEM');

	syslog_debug('-------------------------------------------------------------------------------------');

	if ($debug) {
		syslog_debug($stats);
	} else {
		print date('H:m:s') . ' SYSLOG NOTE: ' . $stats . PHP_EOL;
	}

	set_config_option('syslog_stats',
		'time:' . round($end_time - $start_time,2) .
		' deletes:' . $deleted .
		' incoming:' . $incoming .
		' removes:' . $removed .
		' xfers:' . $xferred .
		' alerts:' . $alerts .
		' alarms:' . $alarms .
		' reports:' . $reports
	);
}

/**
 * syslog_init_variables - initialize key variables on first pass of a run
 * of the syslog plugin.  This function should not have to run more than
 * once during the syslog plugins lifecycle.
 *
 * @return void
 */
function syslog_init_variables() {
	$syslog_retention = read_config_option('syslog_retention');
	$alert_retention  = read_config_option('syslog_alert_retention');

	if ($syslog_retention == '' || $syslog_retention < 0 || $syslog_retention > 365) {
		set_config_option('syslog_retention', '30');
	}

	if ($alert_retention == '' || $alert_retention < 0 || $alert_retention > 365) {
		set_config_option('syslog_alert_retention', '30');
	}

	if (substr(read_config_option('base_url'), 0, 4) != 'http') {
		if (read_config_option('force_https') == 'on') {
			$prefix = 'https://';
		} else {
			$prefix = 'http://';
		}

		set_config_option('base_url', $prefix . read_config_option('base_url'));
	}
}

/**
 * alert_setup_environment - set's up the environment for a syslog alert
 *
 * @param array  $alert The alert definition
 * @param string $results A comma delimited list of syslog messages
 * @param array  $hostlist The list of hosts that match for the alert
 * @param string $hostname The hostname in the case of a host level alert
 *
 * @return void
 */
function alert_setup_environment(&$alert, $results, $hostlist = [], $hostname = '') {
	global $severities, $syslog_levels, $syslog_facilities;

	putenv('ALERT_ALERTID=' . cacti_escapeshellarg($alert['id']));
	putenv('ALERT_NAME=' . cacti_escapeshellarg(clean_up_name($alert['name'])));
	putenv('ALERT_MESSAGE=' . cacti_escapeshellarg($alert['message']));

	putenv('ALERT_SEVERITY=' . cacti_escapeshellarg($alert['severity']));
	putenv('ALERT_SEVERITY_TEXT=' . cacti_escapeshellarg($severities[$alert['severity']]));

	putenv('ALERT_PRIORITY=' . cacti_escapeshellarg($syslog_levels[$results['priority_id']]));
	putenv('ALERT_FACILITY=' . cacti_escapeshellarg($syslog_facilities[$results['facility_id']]));

	putenv('ALERT_HOSTLIST=' . cacti_escapeshellarg(implode(',', $hostlist)));
	putenv('ALERT_HOSTNAME=' . cacti_escapeshellarg($hostname));

	putenv('ALERT_MESSAGES=' . cacti_escapeshellarg(trim(str_replace("\0", ' ', $results['message']))));
}

/**
 * alert_replace_variables - add command line parameter to the syslog command
 *   or ticket opening script
 *
 * @param array  $alert The alert definition
 * @param string $results A comma delimited list of syslog messages
 * @param string $hostname The hostname in the case of a host level alert
 *
 * @return string The command and it'a arguments escaped
 */
function alert_replace_variables($alert, $results, $hostname = '') {
	global $severities, $syslog_levels, $syslog_facilities;

	$command = $alert['command'];

	$command = str_replace('<ALERTID>',  cacti_escapeshellarg($alert['id']), $command);
	$command = str_replace('<HOSTNAME>', cacti_escapeshellarg($hostname), $command);
	$command = str_replace('<PRIORITY>', cacti_escapeshellarg($syslog_levels[$results['priority_id']]), $command);
	$command = str_replace('<FACILITY>', cacti_escapeshellarg($syslog_facilities[$results['facility_id']]), $command);
	$command = str_replace('<MESSAGE>',  cacti_escapeshellarg($results['message']), $command);
	$command = str_replace('<SEVERITY>', cacti_escapeshellarg($severities[$alert['severity']]), $command);

	return $command;
}
