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

chdir('../../');
include('./include/auth.php');
include_once('./lib/xml.php');
include_once(__DIR__ . '/setup.php');
include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/database.php');

syslog_connect();

set_default_action();

// The "Test rule" action runs a read-only preview of the rule currently
// in the editor and returns JSON without touching any database data.
if (get_request_var('action') == 'test') {
	header('Content-Type: application/json; charset=UTF-8');
	print syslog_rule_test_action('alert');
	exit;
}

if (isset_request_var('import') && syslog_allow_edits()) {
	set_request_var('action', 'import');
}

switch (get_request_var('action')) {
	case 'save':
		if (isset_request_var('save_component_import')) {
			alert_import();
		} else {
			form_save();
		}

		break;
	case 'actions':
		form_actions();

		break;
	case 'import':
		top_header();
		syslog_include_js();
		import();
		bottom_footer();

		break;
	case 'export':
		alert_export();

		break;
	case 'edit':
	case 'newedit':
		top_header();
		syslog_include_js();

		syslog_action_edit();

		bottom_footer();

		break;
	default:
		top_header();
		syslog_include_js();

		syslog_alerts();

		bottom_footer();

		break;
}

/**
 * Save the alert rule submitted by the edit form and redirect back.
 *
 * @return void
 */
function form_save(): void {
	if ((isset_request_var('save_component_alert')) && (isempty_request_var('add_dq_y'))) {
		$alertid = api_syslog_alert_save(get_nfilter_request_var('id'), get_nfilter_request_var('name'),
			get_nfilter_request_var('report_method'), get_filter_request_var('level'),
			get_nfilter_request_var('num'), get_nfilter_request_var('type'),
			get_nfilter_request_var('message'), get_nfilter_request_var('email'),
			get_nfilter_request_var('notes'), get_nfilter_request_var('enabled'),
			get_nfilter_request_var('severity'), get_nfilter_request_var('command'),
			get_nfilter_request_var('repeat_alert'), get_nfilter_request_var('open_ticket'),
			get_nfilter_request_var('notify'), get_nfilter_request_var('body'),
			get_nfilter_request_var('cooldown_minutes'), get_nfilter_request_var('deduplication_minutes'),
			get_nfilter_request_var('maintenance_mode'), get_nfilter_request_var('maintenance_days'),
			get_nfilter_request_var('maintenance_start'), get_nfilter_request_var('maintenance_end'),
			get_nfilter_request_var('maintenance_datetime_start'), get_nfilter_request_var('maintenance_datetime_end'));

		if ((is_error_message()) || (get_filter_request_var('id') != get_filter_request_var('_id')) || $alertid === false) {
			header('Location: syslog_alerts.php?header=false&action=edit&id=' . (empty($alertid) ? get_filter_request_var('id') : $alertid));
		} else {
			header('Location: syslog_alerts.php?header=false');
		}
	}
}

/**
 * Render the bulk action confirmation form and apply the selected action.
 *
 * @return void
 */
function form_actions(): void {
	global $config, $syslog_actions, $fields_syslog_action_edit;
	global $syslogdb_default;

	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP,
		['options' => ['regexp' => '/^([a-zA-Z0-9_]+)$/']]);

	// if we are to save this form, instead of display it
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_request_var('selected_items'));
		$drp_action     = get_request_var('drp_action');

		// PHP normalizes the numeric-string keys below to integers, matching the
		// action ids of the dropdown.
		$action_map = [
			'1' => 'api_syslog_alert_remove',
			'2' => 'api_syslog_alert_disable',
			'3' => 'api_syslog_alert_enable'
		];

		syslog_apply_selected_items_action(
			$selected_items,
			$drp_action,
			$action_map,
			'4',
			get_nfilter_request_var('selected_items')
		);

		header('Location: syslog_alerts.php?header=false');

		exit;
	}

	top_header();

	form_start('syslog_alerts.php');

	html_start_box($syslog_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	// setup some variables
	$alert_array = [];
	$alert_list  = '';
	$title       = '';

	// loop through each of the clusters selected on the previous page and get more info about them
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			// ================= input validation =================
			input_validate_input_number($matches[1]);
			// ====================================================

			$alert_info = syslog_db_fetch_cell_prepared("SELECT name
				FROM `$syslogdb_default`.`syslog_alert`
				WHERE id = ?",
				[$matches[1]]);

			$alert_list .= '<li>' . html_escape($alert_info) . '</li>';
			$alert_array[] = $matches[1];
		}
	}

	if (cacti_sizeof($alert_array)) {
		if (get_request_var('drp_action') == '1') { // delete
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Delete the following Syslog Alert Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$alert_list</ul></div>";
			print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Delete Syslog Alert Rule(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '2') { // disable
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Disable the following Syslog Alert Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$alert_list</ul></div>";
			print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Disable Syslog Alert Rule(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '3') { // enable
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Enable the following Syslog Alert Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$alert_list</ul></div>";
			print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Enable Syslog Alert Rule(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '4') { // export
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Export the following Syslog Alert Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$alert_list</ul></div>";
			print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Export Syslog Alert Rule(s)', 'syslog');
		}

		$save_html = "<input type='button' value='" . __esc('Cancel', 'syslog') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __esc('Continue', 'syslog') . "' title='$title'";
	} else {
		raise_message(40);
		header('Location: syslog_alerts.php?header=false');
		exit;
	}

	print "<tr>
		<td align='right' class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . serialize($alert_array) . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

/**
 * Export the selected alert rules as a JSON download.
 *
 * @return void
 */
function alert_export(): void {
	global $syslogdb_default;

	// if we are to save this form, instead of display it
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			$rules = [];

			foreach ($selected_items as $id) {
				if ($id > 0) {
					$data = syslog_db_fetch_row_prepared("SELECT *
						FROM `$syslogdb_default`.`syslog_alert`
						WHERE id = ?",
						[$id]);

					if (cacti_sizeof($data)) {
						$rules[] = $data;
					}
				}
			}

			$output = syslog_rules_array2json('syslog_alert', $rules);
			header('Content-type: application/json');
			header('Content-Disposition: attachment; filename=syslog_alert_export.json');
			print $output;
		}
	}
}

/**
 * Validate and save a syslog alert rule.
 *
 * @param string|int|null $id           The id of the alert rule to update, empty for a new rule.
 * @param string          $name         The name of the alert rule.
 * @param string|int      $method       The reporting method, 0 for Individual, 1 for Threshold.
 * @param string|int|null $level        The reporting level, 0 for System, 1 for Device.
 * @param string|int      $num          The threshold count for the Threshold method.
 * @param string          $type         The match type, either 'filter' or 'sql'.
 * @param string          $message      The match string or the filter rule document.
 * @param string          $email        The comma delimited list of email addresses to notify.
 * @param string          $notes        The notes for the alert rule.
 * @param string          $enabled      'on' when the alert rule is enabled, otherwise empty.
 * @param string|int      $severity     The severity level of the alert rule.
 * @param string          $command      The command to run when the alert is triggered.
 * @param string|int      $repeat_alert The re-alert cycle in poller cycles.
 * @param string          $open_ticket  'on' to open a help desk ticket, otherwise empty.
 * @param string|int      $notify       The id of the notification list to use, 0 for none.
 * @param string          $body         The body text for the alert email.
 * @param string|int      $cooldown_minutes Per-rule notification cooldown; 0 inherits the global setting.
 * @param string|int      $deduplication_minutes Per-rule duplicate suppression window; 0 inherits the global setting.
 * @param string          $maintenance_mode Whether this rule inherits, overrides, or disables maintenance muting.
 * @param string          $maintenance_days Weekdays selected for the rule maintenance window.
 * @param string          $maintenance_start Local start time for the rule maintenance window.
 * @param string          $maintenance_end Local end time for the rule maintenance window.
 * @param string          $maintenance_datetime_start Optional one-time local start date/time.
 * @param string          $maintenance_datetime_end Optional one-time local end date/time.
 *
 * @return false|null Null when the alert rule was saved, false when validation or the SQL check failed.
 */
function api_syslog_alert_save($id, $name, $method, $level, $num, $type, $message, $email, $notes,
	$enabled, $severity, $command, $repeat_alert, $open_ticket, $notify = 0, $body = '', $cooldown_minutes = 0,
	$deduplication_minutes = 0, $maintenance_mode = 'inherit', $maintenance_days = '1,2,3,4,5',
	$maintenance_start = '00:00', $maintenance_end = '00:00', $maintenance_datetime_start = '',
	$maintenance_datetime_end = ''): false|null {
	global $syslogdb_default;

	// get the username
	$username = get_username($_SESSION['sess_user_id']);

	if ($id) {
		$save['id'] = $id;
	} else {
		$save['id'] = '';
	}

	$save['hash']         = get_hash_syslog($save['id'], 'syslog_alert');

	$save['name']         = form_input_validate($name,         'name',     '', false, 3);
	$save['num']          = form_input_validate($num,          'num',      '', false, 3);
	$save['message']      = form_input_validate($message,      'message',  '', false, 3);
	$save['body']         = form_input_validate($body,         'body',     '', true, 3);
	$save['email']        = form_input_validate(trim($email),  'email',    '', true, 3);
	$save['command']      = form_input_validate($command,      'command',  '', true, 3);
	$save['notes']        = form_input_validate($notes,        'notes',    '', true, 3);
	$save['enabled']      = ($enabled == 'on' ? 'on' : '');
	$save['repeat_alert'] = form_input_validate($repeat_alert, 'repeat_alert', '', true, 3);
	$save['cooldown_minutes'] = max(-1, (int) $cooldown_minutes);
	$save['deduplication_minutes'] = max(-1, (int) $deduplication_minutes);
	$save['maintenance_mode']  = in_array($maintenance_mode, ['inherit', 'custom', 'disabled'], true) ? $maintenance_mode : 'inherit';
	$save['maintenance_days']  = preg_match('/^[1-7](,[1-7])*$/', $maintenance_days) ? $maintenance_days : '1,2,3,4,5';
	$save['maintenance_start'] = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $maintenance_start) ? $maintenance_start : '00:00';
	$save['maintenance_end']   = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $maintenance_end) ? $maintenance_end : '00:00';
	$save['maintenance_datetime_start'] = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $maintenance_datetime_start) ? $maintenance_datetime_start : '';
	$save['maintenance_datetime_end']   = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $maintenance_datetime_end) ? $maintenance_datetime_end : '';
	$save['open_ticket']  = ($open_ticket == 'on' ? 'on' : '');
	$save['type']         = $type;
	$save['severity']     = $severity;
	$save['method']       = $method;
	$save['level']        = $level;
	$save['notify']       = $notify;
	$save['user']         = $username;
	$save['date']         = time();

	if (!is_error_message()) {
		$sql = syslog_get_alert_sql($save, 100);

		if (cacti_sizeof($sql)) {
			$db_sql     = (string) str_replace('%', '|||||', $sql['sql']);
			$db_sql     = str_replace('?', '%s', $db_sql);
			$approx_sql = vsprintf($db_sql, $sql['params']);
			$approx_sql = str_replace('|||||', '%', $approx_sql);

			$results = syslog_db_fetch_assoc_prepared($sql['sql'], $sql['params'], false);

			if ($results === false) {
				raise_message('sql_error', __('The SQL Syntax Entered is invalid.  Please correct your SQL.<br>', 'syslog'), MESSAGE_LEVEL_ERROR);
				raise_message('sql_detail', __('The Pre-processed SQL is:<br><br> %s', $approx_sql, 'syslog'), MESSAGE_LEVEL_INFO);

				return false;
			} else {
				syslog_sync_save($save, 'syslog_alert', 'id');

				// syslog_sync_save() raises its own success/failure message and
				// returns no id, so signal success without one.
				return null;
			}
		} else {
			if ($save['type'] === 'filter' && !empty($GLOBALS['syslog_rule_filter_error'])) {
				raise_message('filter_error', __('The filter is invalid: %s', $GLOBALS['syslog_rule_filter_error'], 'syslog'), MESSAGE_LEVEL_ERROR);
			} else {
				raise_message('sql_error', __('The processed SQL was invalid.  Please correct your SQL', 'syslog'), MESSAGE_LEVEL_ERROR);
			}

			return false;
		}
	}

	return false;
}

/**
 * Remove the given alert rule from the database.
 *
 * @param int|string $id The id of the alert rule to remove.
 *
 * @return void
 */
function api_syslog_alert_remove($id) {
	global $syslogdb_default;
	syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_alert` WHERE id = ?", [$id]);
}

/**
 * Disable the given alert rule.
 *
 * @param int|string $id The id of the alert rule to disable.
 *
 * @return void
 */
function api_syslog_alert_disable($id) {
	global $syslogdb_default;
	syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_alert` SET enabled = '' WHERE id = ?", [$id]);
}

/**
 * Enable the given alert rule.
 *
 * @param int|string $id The id of the alert rule to enable.
 *
 * @return void
 */
function api_syslog_alert_enable($id) {
	global $syslogdb_default;
	syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_alert` SET enabled = 'on' WHERE id = ?", [$id]);
}

/**
 * Fetch the alert rule records matching the current page filter.
 *
 * @param string             $sql_where  The WHERE clause to append the filter to, passed by reference.
 * @param array<int, string> $sql_params The prepared SQL parameters to append to, passed by reference.
 * @param int                $rows       The number of rows per page to fetch.
 *
 * @return array<int, array<string, mixed>> The alert rule records found.
 */
function syslog_get_alert_records(string &$sql_where, array &$sql_params, int $rows): array {
	global $syslogdb_default;

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND ' : 'WHERE ') .
			'(message LIKE ? OR email LIKE ? OR notes LIKE ? OR name LIKE ?)';

		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	if (get_request_var('enabled') != '-1') {
		if (get_request_var('enabled') == '1') {
			$sql_where .= (strlen($sql_where) ? ' AND ' : 'WHERE ') . "enabled='on'";
		} else {
			$sql_where .= (strlen($sql_where) ? ' AND ' : 'WHERE ') . "enabled=''";
		}
	}

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;

	$query_string = "SELECT *
		FROM `$syslogdb_default`.`syslog_alert`
		$sql_where
		$sql_order
		$sql_limit";

	return syslog_db_fetch_assoc_prepared($query_string, $sql_params);
}

/**
 * Build the re-alert cycle dropdown options based on the poller interval.
 *
 * @return array<int, string> The re-alert cycle options in poller cycles.
 */
function get_repeat_array(): array {
	$poller_interval = (int) read_config_option('poller_interval');

	if ($poller_interval < 1) {
		$poller_interval = 300;
	}

	$multiplier = 300 / $poller_interval;

	$repeatarray = [
		$multiplier * 0    => __('Not Set', 'syslog'),
	];

	if ($multiplier > 1) {
		$repeatarray += [
			round($multiplier / 5,0) => __('1 Minute', 'syslog'),
		];
	}

	$repeatarray += [
		$multiplier * 1    => __('%d Minutes', 5, 'syslog'),
		$multiplier * 2    => __('%d Minutes', 10, 'syslog'),
		$multiplier * 3    => __('%d Minutes', 15, 'syslog'),
		$multiplier * 4    => __('%d Minutes', 20, 'syslog'),
		$multiplier * 6    => __('%d Minutes', 30, 'syslog'),
		$multiplier * 8    => __('%d Minutes', 45, 'syslog'),
		$multiplier * 12   => __('%d Hour', 1, 'syslog'),
		$multiplier * 24   => __('%d Hours', 2, 'syslog'),
		$multiplier * 36   => __('%d Hours', 3, 'syslog'),
		$multiplier * 48   => __('%d Hours', 4, 'syslog'),
		$multiplier * 72   => __('%d Hours', 6, 'syslog'),
		$multiplier * 96   => __('%d Hours', 8, 'syslog'),
		$multiplier * 144  => __('%d Hours', 12, 'syslog'),
		$multiplier * 288  => __('%d Day', 1, 'syslog'),
		$multiplier * 576  => __('%d Days', 2, 'syslog'),
		$multiplier * 2016 => __('%d Week', 1, 'syslog'),
		$multiplier * 4032 => __('%d Weeks', 2, 'syslog'),
		$multiplier * 8640 => __('1 Month', 'syslog')
	];

	$alert_retention = read_config_option('syslog_alert_retention');

	if ($alert_retention != '' && $alert_retention > 0 && $alert_retention < 365) {
		$repeat_end = ((int) $alert_retention * 24 * 60 * $multiplier) / 5;
	}

	if (isset($repeat_end)) {
		foreach ($repeatarray as $i => $value) {
			if ($i > $repeat_end) {
				unset($repeatarray[$i]);
			}
		}
	}

	return $repeatarray;
}

/**
 * Render the alert rule edit or creation form.
 *
 * @return void
 */
function syslog_action_edit(): void {
	global $message_types, $severities;
	global $syslogdb_default;

	// ================= input validation =================
	get_filter_request_var('id');
	get_filter_request_var('type');
	get_filter_request_var('date', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/']]);
	// ====================================================

	if (!isempty_request_var('id') && get_nfilter_request_var('action') == 'edit') {
		$alert = syslog_db_fetch_row_prepared("SELECT *
			FROM `$syslogdb_default`.`syslog_alert`
			WHERE id = ?",
			[get_request_var('id')]);

		if (cacti_sizeof($alert)) {
			$header_label = __esc('Alert Edit [edit: %s]', $alert['name'], 'syslog');
		} else {
			$header_label = __('Alert Edit [new]', 'syslog');
			$alert = [
				'name' => __('New Alert Rule', 'syslog'),
				'type' => 'filter'
			];
		}
	} elseif (isset_request_var('id') && get_nfilter_request_var('action') == 'newedit') {
		$sql_params   = [];
		$sql_where    = 'WHERE seq = ?';
		$sql_params[] = get_request_var('id');

		if (isset_request_var('date')) {
			$sql_where   .= ' AND logtime = ?';
			$sql_params[] = get_request_var('date');
		}

		$syslog_rec = syslog_db_fetch_row_prepared("SELECT *
			FROM `$syslogdb_default`.`syslog`
			$sql_where",
			$sql_params);

		$header_label = __('Alert Edit [new]', 'syslog');

		if (cacti_sizeof($syslog_rec)) {
			$alert['message'] = $syslog_rec['message'];
		}

		$alert['name'] = __('New Alert Rule', 'syslog');
		$alert['type'] = 'filter';
	} else {
		$header_label = __('Alert Edit [new]', 'syslog');

		$alert['name'] = __('New Alert Rule', 'syslog');
		$alert['type'] = 'filter';
	}

	if (db_table_exists('plugin_notification_lists')) {
		$lists = array_rekey(
			db_fetch_assoc('SELECT id, name
				FROM plugin_notification_lists
				ORDER BY name'),
			'id', 'name'
		);
	} else {
		$lists = ['0' => __('N/A', 'syslog')];
	}

	$repeatarray = get_repeat_array();
	// Present older simple rules as their equivalent SQL expression. Reuse the
	// existing compiler so configured columns and LIKE wildcard semantics are
	// preserved. This changes the editor only; storage changes on explicit Save.
	if (in_array($alert['type'] ?? '', ['messageb', 'messagec', 'messagee', 'host', 'program', 'facility'], true)) {
		$legacy_query = syslog_get_alert_sql($alert, 0);
		if (preg_match('/WHERE\s+(`[^`]+`\s+(?:LIKE|=)\s+)\?/', $legacy_query['sql'], $legacy_match)) {
			$alert['message'] = $legacy_match[1] . db_qstr($legacy_query['params'][0]);
			$alert['type'] = 'sql';
		}
	}
	$filter_conditions = [];
	if (($alert['type'] ?? '') === 'filter') {
		$filter_document = json_decode($alert['message'] ?? '', true);
		if (is_array($filter_document) && ($filter_document['version'] ?? null) === 1 && is_array($filter_document['conditions'] ?? null)) {
			$filter_conditions = $filter_document['conditions'];
		} elseif (get_nfilter_request_var('action') === 'newedit' && !empty($alert['message'])) {
			$filter_conditions = [[
				'join' => 'AND',
				'negative' => false,
				'field' => 'message',
				'operator' => 'contains',
				'value' => $alert['message']
			]];
		}
	}

	$fields_syslog_alert_edit = [
		'spacer0' => [
			'method'        => 'spacer',
			'friendly_name' => __('Details', 'syslog')
		],
		'name' => [
			'method'        => 'textbox',
			'friendly_name' => __('Name', 'syslog'),
			'description'   => __('Please describe this Alert.', 'syslog'),
			'value'         => '|arg1:name|',
			'max_length'    => '250',
			'size'          => 80
		],
		'severity' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Severity', 'syslog'),
			'description'   => __('What is the Severity Level of this Alert?', 'syslog'),
			'value'         => '|arg1:severity|',
			'array'         => $severities,
			'default'       => '1'
		],
		'level' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Reporting Level', 'syslog'),
			'description'   => __('For recording Re-Alert Cycles, should the Alert be tracked at the System or Device level.', 'syslog'),
			'value'         => '|arg1:level|',
			'array'         => ['0' => __('System', 'syslog'), '1' => __('Device', 'syslog')],
			'default'       => '0'
		],
		'report_method' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Reporting Method', 'syslog'),
			'description'   => __('Define how to Alert on the syslog messages.', 'syslog'),
			'value'         => '|arg1:method|',
			'array'         => ['0' => __('Individual', 'syslog'), '1' => __('Threshold', 'syslog')],
			'default'       => '0'
		],
		'num' => [
			'method'        => 'textbox',
			'friendly_name' => __('Threshold', 'syslog'),
			'description'   => __('For the \'Threshold\' method, If the number seen is above this value an Alert will be triggered.', 'syslog'),
			'value'         => '|arg1:num|',
			'size'          => '4',
			'max_length'    => '10',
			'default'       => '1'
		],
		'type' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Match Type', 'syslog'),
			'description'   => __('Choose Filter Builder for safe multi-condition alarm logic. SQL Expression is retained for legacy rules.', 'syslog'),
			'value'         => '|arg1:type|',
			'array'         => [
				'filter' => __('Filter Builder', 'syslog'),
				'sql' => __('SQL Expression', 'syslog')
			],
			'on_change'     => 'changeTypes()',
			'default'       => 'filter'
		],
		'message' => [
			'friendly_name' => __('Message Match String', 'syslog'),
			'description'   => __('Build filter conditions or enter an SQL expression to match syslog messages.', 'syslog'),
			'textarea_rows' => '2',
			'textarea_cols' => '70',
			'method'        => 'textarea',
			'class'         => 'textAreaNotes',
			'value'         => '|arg1:message|',
			'default'       => ''
		],
		'enabled' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Enabled', 'syslog'),
			'description'   => __('Is this Alert Enabled?', 'syslog'),
			'value'         => '|arg1:enabled|',
			'array'         => ['on' => __('Enabled', 'syslog'), '' => __('Disabled', 'syslog')],
			'default'       => 'on'
		],
		'repeat_alert' => [
			'friendly_name' => __('Re-Alert Cycle', 'syslog'),
			'method'        => 'drop_array',
			'array'         => $repeatarray,
			'default'       => '0',
			'description'   => __('Do not resend this alert again for the same host, until this amount of time has elapsed. For threshold based alarms, this applies to all hosts.', 'syslog'),
			'value'         => '|arg1:repeat_alert|'
		],
		'cooldown_minutes' => [
			'friendly_name' => __('Notification Cooldown', 'syslog'),
			'method'        => 'drop_array',
			'description'   => __('Minutes to suppress every subsequent notification from this rule and reporting scope.', 'syslog'),
			'array'         => ['-1' => __('Use global setting', 'syslog'), '0' => __('Disabled', 'syslog'), '1' => __('1 minute', 'syslog'), '5' => __('5 minutes', 'syslog'), '15' => __('15 minutes', 'syslog'), '30' => __('30 minutes', 'syslog'), '60' => __('1 hour', 'syslog'), '240' => __('4 hours', 'syslog'), '1440' => __('1 day', 'syslog')],
			'value'         => '|arg1:cooldown_minutes|',
			'default'       => '-1'
		],
		'deduplication_minutes' => [
			'friendly_name' => __('Duplicate Suppression', 'syslog'),
			'method'        => 'drop_array',
			'description'   => __('Minutes to suppress the same matched message set.', 'syslog'),
			'array'         => ['-1' => __('Use global setting', 'syslog'), '0' => __('Disabled', 'syslog'), '1' => __('1 minute', 'syslog'), '5' => __('5 minutes', 'syslog'), '15' => __('15 minutes', 'syslog'), '30' => __('30 minutes', 'syslog'), '60' => __('1 hour', 'syslog'), '240' => __('4 hours', 'syslog'), '1440' => __('1 day', 'syslog')],
			'value'         => '|arg1:deduplication_minutes|',
			'default'       => '-1'
		],
		'maintenance_mode' => [
			'friendly_name' => __('Maintenance Window', 'syslog'),
			'method'        => 'drop_array',
			'description'   => __('Choose whether this rule uses the global maintenance timeframe or its own timeframe.', 'syslog'),
			'array'         => ['inherit' => __('Use global timeframe', 'syslog'), 'custom' => __('Use rule timeframe', 'syslog'), 'disabled' => __('Do not mute this rule', 'syslog')],
			'value'         => '|arg1:maintenance_mode|',
			'default'       => 'inherit'
		],
		'maintenance_days' => [
			'friendly_name' => __('Rule Maintenance Days', 'syslog'),
			'method'        => 'drop_array',
			'description'   => __('Days for this rule timeframe, when Use rule timeframe is selected.', 'syslog'),
			'array'         => syslog_alert_maintenance_day_options(),
			'value'         => '|arg1:maintenance_days|',
			'default'       => '1,2,3,4,5'
		],
		'maintenance_start' => [
			'friendly_name' => __('Rule Maintenance Starts', 'syslog'),
			'method'        => 'drop_array',
			'description'   => __('Local start time for this rule timeframe.', 'syslog'),
			'array'         => syslog_alert_maintenance_time_options(),
			'value'         => '|arg1:maintenance_start|',
			'default'       => '00:00'
		],
		'maintenance_end' => [
			'friendly_name' => __('Rule Maintenance Ends', 'syslog'),
			'method'        => 'drop_array',
			'description'   => __('Local end time for this rule timeframe.', 'syslog'),
			'array'         => syslog_alert_maintenance_time_options(),
			'value'         => '|arg1:maintenance_end|',
			'default'       => '00:00'
		],
		'maintenance_datetime_start' => [
			'friendly_name' => __('One-time Rule Maintenance Starts', 'syslog'),
			'method'        => 'textbox', 'size' => '18', 'max_length' => '16', 'class' => 'syslogMaintenanceDateTime',
			'description'   => __('Optional local date and time to begin muting this rule. Format: YYYY-MM-DD HH:MM.', 'syslog'),
			'value'         => '|arg1:maintenance_datetime_start|', 'default' => ''
		],
		'maintenance_datetime_end' => [
			'friendly_name' => __('One-time Rule Maintenance Ends', 'syslog'),
			'method'        => 'textbox', 'size' => '18', 'max_length' => '16', 'class' => 'syslogMaintenanceDateTime',
			'description'   => __('Optional local date and time to stop muting this rule. Format: YYYY-MM-DD HH:MM.', 'syslog'),
			'value'         => '|arg1:maintenance_datetime_end|', 'default' => ''
		],
		'notes' => [
			'friendly_name' => __('Notes', 'syslog'),
			'textarea_rows' => '5',
			'textarea_cols' => '70',
			'description'   => __('Space for Notes on the Alert', 'syslog'),
			'method'        => 'textarea',
			'class'         => 'textAreaNotes',
			'value'         => '|arg1:notes|',
			'default'       => '',
		],
		'header_email' => [
			'method'        => 'spacer',
			'friendly_name' => __('Email Options', 'syslog')
		],
		'notify' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Notification List', 'syslog'),
			'description'   => __('Use the contents of this Notification List to dictate who should be notified and how.', 'syslog'),
			'value'         => '|arg1:notify|',
			'array'         => $lists,
			'none_value'    => __('None', 'syslog'),
			'default'       => '0'
		],
		'email' => [
			'method'        => 'textarea',
			'friendly_name' => __('Emails to Notify', 'syslog'),
			'textarea_rows' => '5',
			'textarea_cols' => '70',
			'description'   => __('Please enter a comma delimited list of Email addresses to inform.  If you wish to send out Email to a recipient in SMS format, please prefix that recipient\'s Email address with <b>\'sms@\'</b>.  For example, if the recipients SMS address is <b>\'2485551212@mycarrier.net\'</b>, you would enter it as <b>\'sms@2485551212@mycarrier.net\'</b> and it will be formatted as an SMS message.', 'syslog'),
			'class'         => 'textAreaNotes',
			'value'         => '|arg1:email|',
			'max_length'    => '255'
		],
		'body' => [
			'friendly_name' => __('Email Body Text', 'syslog'),
			'textarea_rows' => '6',
			'textarea_cols' => '80',
			'description'   => __('This information will appear in the body of the Alert just before the Alert details.', 'syslog'),
			'method'        => 'textarea',
			'class'         => 'textAreaNotes',
			'value'         => '|arg1:body|',
			'default'       => '',
		],
		'spacer1' => [
			'method'        => 'spacer',
			'friendly_name' => __('Actions', 'syslog')
		],
		'open_ticket' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Open Ticket', 'syslog'),
			'description'   => __('Should a Help Desk Ticket be opened for this Alert.  NOTE: The Ticket command script will be populated with several \'ALERT_\' environment variables for convenience.', 'syslog'),
			'value'         => '|arg1:open_ticket|',
			'array'         => ['on' => __('Yes', 'syslog'), '' => __('No', 'syslog')],
			'default'       => ''
		],
		'command' => [
			'friendly_name' => __('Command', 'syslog'),
			'textarea_rows' => '5',
			'textarea_cols' => '70',
			'description'   => __('When an Alert is triggered, run the following command.  The following replacement variables are available <b>\'&lt;HOSTNAME&gt;\'</b>, <b>\'&lt;ALERTID&gt;\'</b>, <b>\'&lt;MESSAGE&gt;\'</b>, <b>\'&lt;FACILITY&gt;\'</b>, <b>\'&lt;PRIORITY&gt;\'</b>, <b>\'&lt;SEVERITY&gt;\'</b>.  Please note that <b>\'&lt;HOSTNAME&gt;\'</b> is only available on individual thresholds.  These replacement values can appear on the command line, or be gathered from the environment of the script.  When used from the environment, those variables will be prefixed with \'ALERT_\'.', 'syslog'),
			'method'        => 'textarea',
			'class'         => 'textAreaNotes',
			'value'         => '|arg1:command|',
			'default'       => '',
		],
		'id' => [
			'method' => 'hidden_zero',
			'value'  => '|arg1:id|'
		],
		'_id' => [
			'method' => 'hidden_zero',
			'value'  => '|arg1:id|'
		],
		'save_component_alert' => [
			'method' => 'hidden',
			'value'  => '1'
		]
	];

	form_start('syslog_alerts.php', 'syslog_edit');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	draw_edit_form(
		[
			'config' => ['no_form_tag' => true],
			'fields' => inject_form_variables($fields_syslog_alert_edit, (cacti_sizeof($alert) ? $alert : []))
		]
	);

	html_end_box();

	form_save_button('syslog_alerts.php', '', 'id');

	if (syslog_allow_edits()) {
		print "<input id='syslog_rule_test' class='ui-button ui-corner-all ui-widget' type='button' value='" . __esc('Test rule', 'syslog') . "'>";
		print "<div id='syslog_rule_test_dialog' style='display:none;' title='" . __esc('Rule Test Preview', 'syslog') . "'></div>";
	}

	?>
	<script type='text/javascript'>

	var allowEdits=<?php print syslog_allow_edits() ? 'true' : 'false'; ?>;
	var notifyExists=<?php print db_table_exists('plugin_notification_lists') ? 'true' : 'false'; ?>;
	var alertFilterBuilder;
	var alertFilterConfig = {
		fields: <?php print syslog_json_safe([
			'message' => __('Message', 'syslog'),
			'host' => __('Host', 'syslog'),
			'program' => __('Program', 'syslog'),
			'facility_id' => __('Facility', 'syslog'),
			'priority_id' => __('Priority', 'syslog')
		]); ?>,
		operators: {
			message: ['contains', 'begins', 'ends', '=', '!='],
			host: ['=', '!=', 'contains', 'begins', 'ends'],
			program: ['=', '!=', 'contains', 'begins', 'ends'],
			facility_id: ['=', '!='],
			priority_id: ['=', '!=']
		},
		choices: <?php
			$filter_choices = syslog_search_choices();
			print syslog_json_safe([
				'facility_id' => $filter_choices['facility_id'] ?? [],
				'priority_id' => $filter_choices['priority_id'] ?? []
			]);
		?>,
		conditions: <?php print syslog_json_safe($filter_conditions); ?>,
		labels: {
			message: <?php print syslog_json_safe(__('Value', 'syslog')); ?>,
			placeholder: <?php print syslog_json_safe(__('Enter a value', 'syslog')); ?>,
			match: <?php print syslog_json_safe(__('Match', 'syslog')); ?>,
			exclude: <?php print syslog_json_safe(__('Exclude', 'syslog')); ?>,
			remove: <?php print syslog_json_safe(__('Remove condition', 'syslog')); ?>,
			integer: <?php print syslog_json_safe(__('Enter a nonnegative integer', 'syslog')); ?>
		}
	};

	function changeTypes() {
		var filterMode = $('#type').val() == 'filter';
		$('#message').toggle(!filterMode);
		$('#syslog_alert_filter_panel').prop('hidden', !filterMode);
		if ($('#type').val() == 'sql') {
			$('#message').prop('rows', 6);
		} else {
			$('#message').prop('rows', 2);
		}
	}

	function changeMethod() {
		if ($('#report_method').val() == 0) {
			$('#row_num').hide();
		} else {
			$('#row_num').show();
		}
	}

	function changeMaintenanceMode() {
		var customWindowRows = $('#row_maintenance_days, #row_maintenance_start, #row_maintenance_end, #row_maintenance_datetime_start, #row_maintenance_datetime_end');
		customWindowRows.toggle($('#maintenance_mode').val() === 'custom');
	}

	$(function() {
		$('#maintenance_datetime_start, #maintenance_datetime_end').datetimepicker({
			minuteGrid: 10,
			stepMinute: 1,
			showAnim: 'slideDown',
			numberOfMonths: 1,
			timeFormat: 'HH:mm',
			dateFormat: 'yy-mm-dd',
			showButtonPanel: false
		});

		var message = document.getElementById('message');
		var panel = document.createElement('section');
		panel.id = 'syslog_alert_filter_panel';
		panel.className = 'syslogRuleFilterPanel ui-widget-content ui-corner-all';
		panel.setAttribute('aria-label', <?php print syslog_json_safe(__('Alarm filter conditions', 'syslog')); ?>);
		var builder = document.createElement('div');
		builder.id = 'syslog_alert_filter_builder';
		builder.className = 'syslogSearchBuilder syslogRuleFilterBuilder';
		builder.setAttribute('aria-label', <?php print syslog_json_safe(__('Alarm filter conditions', 'syslog')); ?>);
		panel.appendChild(builder);
		message.insertAdjacentElement('afterend', panel);
		if (!alertFilterConfig.conditions.length && message.value && $('#type').val() != 'filter') {
			alertFilterConfig.conditions = [{join: 'AND', negative: false, field: 'message', operator: 'contains', value: message.value}];
		}
		alertFilterBuilder = new SyslogFilterBuilder(builder, alertFilterConfig);
		$('#type').off('change.syslogAlertFilter').on('change.syslogAlertFilter', changeTypes);
		document.getElementById('syslog_edit').addEventListener('submit', function(event) {
			if ($('#type').val() == 'filter' && !alertFilterBuilder.syncTo(message)) {
				event.preventDefault();
				event.stopImmediatePropagation();
			}
		}, true);
		changeTypes();

		if (!allowEdits) {
			$('#syslog_edit').find('select, input, textarea, submit').not(':button').prop('disabled', true);
			alertFilterBuilder.setDisabled(true);
			$('#syslog_edit').find('select').each(function() {
				if ($(this).selectmenu('instance')) {
					$(this).selectmenu('refresh');
				}
			});
		}

		if (!notifyExists) {
			$('#row_notify').hide();
		}

		$('#report_method').change(function() {
			changeMethod();
		});
		$('#maintenance_mode').on('change.syslogMaintenance', changeMaintenanceMode);

		changeMethod();
		changeMaintenanceMode();

		$('#syslog_rule_test').on('click.syslogRuleTest', function() {
			testSyslogRule('#syslog_edit', '#syslog_rule_test_dialog', <?php print syslog_json_safe(__('Rule Test Preview', 'syslog')); ?>);
		});

		var testRuleButton = $('#syslog_rule_test');
		var saveRow = $('#syslog_edit .saveRow');
		var cancelButton = saveRow.find('.cactiReturnTo');

		if (cancelButton.length) {
			testRuleButton.insertAfter(cancelButton);
		} else {
			testRuleButton.prependTo(saveRow);
		}
	});

	</script>
	<?php
}

/**
 * Render the alert rule filter bar.
 *
 * @return void
 */
function syslog_alerts_filter(): void {
	global $config, $item_rows;

	?>
	<tr class='even'>
		<td>
		<form id='alert' action='syslog_alerts.php' method='get'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'syslog'); ?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter'); ?>'>
					</td>
					<td>
						<?php print __('Enabled', 'syslog'); ?>
					</td>
					<td>
						<select id='enabled' onChange='applyFilterAlerts()'>
							<option value='-1'<?php if (get_request_var('enabled') == '-1') {?> selected<?php }?>><?php print __('All', 'syslog'); ?></option>
							<option value='1'<?php if (get_request_var('enabled') == '1') {?> selected<?php }?>><?php print __('Yes', 'syslog'); ?></option>
							<option value='0'<?php if (get_request_var('enabled') == '0') {?> selected<?php }?>><?php print __('No', 'syslog'); ?></option>
						</select>
					</td>
					<td>
						<?php print __('Rows', 'syslog'); ?>
					</td>
					<td>
						<select id='rows' onChange='applyFilterAlerts()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'syslog'); ?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print '<option value="' . $key . '"';

									if (get_request_var('rows') == $key) {
										print ' selected';
									} print '>' . $value . "</option>\n";
								}
							}
	?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' value='<?php print __esc('Go', 'syslog'); ?>'>
							<input id='clear' type='button' value='<?php print __esc('Clear', 'syslog'); ?>'>
							<?php if (syslog_allow_edits()) {?><input id='import' type='button' value='<?php print __esc('Import', 'syslog'); ?>'><?php } ?>
						</span>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_filter_request_var('page'); ?>'>
		</form>
		<script type='text/javascript'>
		initSyslogAlerts();
		</script>
		</td>
	</tr>
	<?php
}

/**
 * Render the main alert rule list page.
 *
 * @return void
 */
function syslog_alerts(): void {
	global $syslog_actions, $config, $message_types, $severities;
	global $syslogdb_default;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
			],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
			],
		'id' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
			],
		'enabled' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
			],
		'filter' => [
			'filter'  => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'name',
			'options' => ['options' => 'sanitize_search_string']
			],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
			]
	];

	validate_store_request_vars($filters, 'sess_sysloga');
	// ================= input validation =================

	if (syslog_allow_edits()) {
		$url = 'syslog_alerts.php?action=edit';
	} else {
		$url = '';
	}

	html_start_box(__('Syslog Alert Filters', 'syslog'), '100%', '', '3', 'center', $url);

	syslog_alerts_filter();

	html_end_box();

	$sql_where  = '';
	$sql_params = [];

	if (get_request_var('rows') == '-1') {
		$rows = (int) read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = (int) get_request_var('rows');
	}

	$alerts = syslog_get_alert_records($sql_where, $sql_params, $rows);

	$rows_query_string = "SELECT COUNT(*)
		FROM `$syslogdb_default`.`syslog_alert`
		$sql_where";

	$total_rows = syslog_db_fetch_cell_prepared($rows_query_string, $sql_params);

	$display_text = [
		'name'     => [__('Alert Name', 'syslog'), 'ASC'],
		'severity' => [__('Severity', 'syslog'), 'ASC'],
		'method'   => [__('Method', 'syslog'), 'ASC'],
		'num'      => [__('Threshold Count', 'syslog'), 'ASC'],
		'enabled'  => [__('Enabled', 'syslog'), 'ASC'],
		'type'     => [__('Match Type', 'syslog'), 'ASC'],
		'message'  => [__('Search String', 'syslog'), 'ASC'],
		'email'    => [__('Email Addresses', 'syslog'), 'DESC'],
		'date'     => [__('Last Modified', 'syslog'), 'ASC'],
		'user'     => [__('By User', 'syslog'), 'DESC']
	];

	$nav = html_nav_bar('syslog_alerts.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, cacti_sizeof($display_text) + 1, __('Alerts', 'syslog'), 'page', 'main');

	form_start('syslog_alerts.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

	if (cacti_sizeof($alerts)) {
		foreach ($alerts as $alert) {
			form_alternate_row('line' . $alert['id'], true);
			form_selectable_cell(filter_value($alert['name'], get_request_var('filter'), $config['url_path'] . 'plugins/syslog/syslog_alerts.php?action=edit&id=' . $alert['id']), $alert['id']);
			form_selectable_cell($severities[$alert['severity']], $alert['id']);
			form_selectable_cell(($alert['method'] == 1 ? __('Threshold', 'syslog') : __('Individual', 'syslog')), $alert['id']);
			form_selectable_cell(($alert['method'] == 1 ? $alert['num'] : __('N/A', 'syslog')), $alert['id']);
			form_selectable_cell((($alert['enabled'] == 'on') ? __('Yes', 'syslog') : __('No', 'syslog')), $alert['id']);
			form_selectable_cell($message_types[$alert['type']], $alert['id']);
			form_selectable_cell(title_trim(html_escape($alert['message']),60), $alert['id']);
			$email = (string) ($alert['email'] ?? '');
			form_selectable_cell((substr_count($email, ',') ? __('Multiple', 'syslog') : html_escape($email)), $alert['id']);
			form_selectable_cell(date('Y-m-d H:i:s', $alert['date']), $alert['id']);
			form_selectable_cell($alert['user'], $alert['id']);
			form_checkbox_cell($alert['name'], $alert['id']);
			form_end_row();
		}
	} else {
		print "<tr><td colspan='" . (cacti_sizeof($display_text) + 1) . "'><em>" . __('No Syslog Alerts Defined', 'syslog') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($alerts)) {
		print $nav;
	}

	draw_actions_dropdown($syslog_actions);

	form_end();

	if (isset($_SESSION['exporter'])) {
		syslog_download_frame('syslog_alerts.php?action=export&selected_items=' . $_SESSION['exporter']);

		kill_session_var('exporter');
		exit;
	}
}

/**
 * Render the alert rule import form.
 *
 * @return void
 */
function import(): void {
	$form_data = [
		'import_file' => [
			'friendly_name' => __('Import Alert Rule from Local File', 'syslog'),
			'description'   => __('If the XML file containing the Alert Rule definition data is located on your local machine, select it here.', 'syslog'),
			'method'        => 'file'
		],
		'import_text' => [
			'method'        => 'textarea',
			'friendly_name' => __('Import Alert Rule from Text', 'syslog'),
			'description'   => __('If you have the XML file containing the Alert Ruledefinition data as text, you can paste it into this box to import it.', 'syslog'),
			'value'         => '',
			'default'       => '',
			'textarea_rows' => '10',
			'textarea_cols' => '80',
			'class'         => 'textAreaNotes'
		]
	];

	print "<form method='post' action='syslog_alerts.php' enctype='multipart/form-data'>";

	html_start_box(__('Import Alert Rule', 'syslog'), '100%', false, '3', 'center', '');

	draw_edit_form(
		[
			'config' => ['no_form_tag' => true],
			'fields' => $form_data
		]
	);

	html_end_box();

	form_hidden_box('save_component_import', '1', '');

	form_save_button('', 'import', 'id', false);
}

/**
 * Import alert rule definitions from an uploaded file or pasted payload.
 *
 * @return void
 */
function alert_import(): void {
	$import_data = syslog_get_import_xml_payload('syslog_alerts.php');

	$import_array = syslog_parse_rule_import($import_data, 'syslog_alert');

	if ($import_array === false || !$import_array) {
		raise_message('syslog_import_error', __('Import rejected: the file is empty, invalid, or contains a different type of Syslog object.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: syslog_alerts.php');
		return;
	}

	$debug_data = [];

	if ($import_array !== false && cacti_sizeof($import_array)) {
		foreach ($import_array as $template => $contents) {
			$save  = [];;
			$tname = '';

			if (cacti_sizeof($contents)) {
				foreach ($contents as $name => $value) {
					switch($name) {
						case 'hash':
							// See if the hash exists, if it does, update the alert
							$found = db_fetch_cell_prepared('SELECT id
							FROM syslog_alert
							WHERE hash = ?',
								[$value]);

							if (!empty($found)) {
								$save['hash'] = $value;
								$save['id']   = $found;
							} else {
								$save['hash'] = $value;
								$save['id']   = 0;
							}

							break;
						case 'name':
							$tname        = $value;
							$save['name'] = $value;

							break;
						default:
							if (syslog_db_column_exists('syslog_alert', $name)) {
								$save[$name] = $value;
							}

							break;
					}
				}
			}

			$id = sql_save($save, 'syslog_alert');

			if ($id) {
				raise_message('syslog_info' . $id, __esc('NOTE: Alert \'%s\' %s!', $tname, ($save['id'] > 0 ? __('Updated', 'syslog') : __('Imported', 'syslog')), 'syslog'), MESSAGE_LEVEL_INFO);
			} else {
				raise_message('syslog_info' . $id, __esc('ERROR: Alert \'%s\' %s Failed!', $tname, ($save['id'] > 0 ? __('Update', 'syslog') : __('Import', 'syslog')), 'syslog'), MESSAGE_LEVEL_ERROR);
			}
		}
	}

	header('Location: syslog_alerts.php');
}
