<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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
include_once('./plugins/syslog/functions.php');
include_once('./plugins/syslog/database.php');

syslog_determine_config();
include(SYSLOG_CONFIG);
syslog_connect();

set_default_action();

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
		import();
		bottom_footer();

		break;
	case 'export':
		alert_export();

		break;
	case 'edit':
	case 'newedit':
		top_header();

		syslog_action_edit();

		bottom_footer();
		break;
	default:
		top_header();

		syslog_alerts();

		bottom_footer();
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset_request_var('save_component_alert')) && (isempty_request_var('add_dq_y'))) {
		$alertid = api_syslog_alert_save(get_nfilter_request_var('id'), get_nfilter_request_var('name'),
			get_nfilter_request_var('report_method'), get_filter_request_var('level'),
			get_nfilter_request_var('num'), get_nfilter_request_var('type'),
			get_nfilter_request_var('message'), get_nfilter_request_var('email'),
			get_nfilter_request_var('notes'), get_nfilter_request_var('enabled'),
			get_nfilter_request_var('severity'), get_nfilter_request_var('command'),
			get_nfilter_request_var('repeat_alert'), get_nfilter_request_var('open_ticket'),
			get_nfilter_request_var('notify'), get_nfilter_request_var('body'));

		if ((is_error_message()) || (get_filter_request_var('id') != get_filter_request_var('_id')) || $alertid === false) {
			header('Location: syslog_alerts.php?header=false&action=edit&id=' . (empty($alertid) ? get_filter_request_var('id') : $alertid));
		} else {
			header('Location: syslog_alerts.php?header=false');
		}
	}
}

/* ------------------------
    The 'actions' function
   ------------------------ */

function form_actions() {
	global $config, $syslog_actions, $fields_syslog_action_edit;

	include(SYSLOG_CONFIG);

	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP,
		 array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_request_var('drp_action') == '1') { /* delete */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_alert_remove($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '2') { /* disable */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_alert_disable($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '3') { /* enable */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_alert_enable($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '4') { /* export */
				$_SESSION['exporter'] = get_nfilter_request_var('selected_items');
			}
		}

		header('Location: syslog_alerts.php?header=false');

		exit;
	}

	top_header();

	form_start('syslog_alerts.php');

	html_start_box($syslog_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	/* setup some variables */
	$alert_array = array(); $alert_list = '';

	/* loop through each of the clusters selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$alert_info = syslog_db_fetch_cell('SELECT name FROM `' . $syslogdb_default . '`.`syslog_alert` WHERE id=' . $matches[1]);
			$alert_list .= '<li>' . html_escape($alert_info) . '</li>';
			$alert_array[] = $matches[1];
		}
	}

	if (cacti_sizeof($alert_array)) {
		if (get_request_var('drp_action') == '1') { /* delete */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Delete the following Syslog Alert Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$alert_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Delete Syslog Alert Rule(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '2') { /* disable */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Disable the following Syslog Alert Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$alert_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Disable Syslog Alert Rule(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '3') { /* enable */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Enable the following Syslog Alert Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$alert_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Enable Syslog Alert Rule(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '4') { /* export */
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
			<input type='hidden' name='selected_items' value='" . (isset($alert_array) ? serialize($alert_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function alert_export() {
	include(SYSLOG_CONFIG);

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			$output = '<templates>' . PHP_EOL;
			foreach ($selected_items as $id) {
				if ($id > 0) {
					$data = syslog_db_fetch_row_prepared('SELECT *
						FROM `' . $syslogdb_default . '`.`syslog_alert`
						WHERE id = ?',
						array($id));

					if (cacti_sizeof($data)) {
						unset($data['id']);
						$output .= syslog_array2xml($data);
					}
				}
			}

			$output .= '</templates>' . PHP_EOL;
			header('Content-type: application/xml');
			header('Content-Disposition: attachment; filename=syslog_alert_export.xml');
			print $output;
		}
	}
}

function api_syslog_alert_save($id, $name, $method, $level, $num, $type, $message, $email, $notes,
	$enabled, $severity, $command, $repeat_alert, $open_ticket, $notify = 0, $body = '') {

	include(SYSLOG_CONFIG);

	/* get the username */
	$username = db_fetch_cell('SELECT username FROM user_auth WHERE id=' . $_SESSION['sess_user_id']);

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
	$save['enabled']      = ($enabled == 'on' ? 'on':'');
	$save['repeat_alert'] = form_input_validate($repeat_alert, 'repeat_alert', '', true, 3);
	$save['open_ticket']  = ($open_ticket == 'on' ? 'on':'');
	$save['type']         = $type;
	$save['severity']     = $severity;
	$save['method']       = $method;
	$save['level']        = $level;
	$save['notify']       = $notify;
	$save['user']         = $username;
	$save['date']         = time();

	$id = 0;
	if (!is_error_message()) {
		$sql = syslog_get_alert_sql($save, 100);

		if (cacti_sizeof($sql)) {
			$db_sql = str_replace('%', '|||||', $sql['sql']);
			$db_sql = str_replace('?', '%s', $db_sql);
			$approx_sql = vsprintf($db_sql, $sql['params']);
			$approx_sql = str_replace('|||||', '%', $approx_sql);

			$results = syslog_db_fetch_assoc_prepared($sql['sql'], $sql['params'], false);

			if ($results === false) {
				raise_message('sql_error', __('The SQL Syntax Entered is invalid.  Please correct your SQL.<br>', 'syslog'), MESSAGE_LEVEL_ERROR);
				raise_message('sql_detail', __('The Pre-processed SQL is:<br><br> %s', $approx_sql, 'syslog'), MESSAGE_LEVEL_INFO);

				return false;
			} else {
				$id = syslog_sync_save($save, 'syslog_alert', 'id');

				return $id;
			}
		} else {
			raise_message('sql_error', __('The processed SQL was invalid.  Please correct your SQL', 'syslog'), MESSAGE_LEVEL_ERROR);

			return false;
		}
	}

	return false;
}

function api_syslog_alert_remove($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog_alert` WHERE id='" . $id . "'");
}

function api_syslog_alert_disable($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_alert` SET enabled='' WHERE id='" . $id . "'");
}

function api_syslog_alert_enable($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_alert` SET enabled='on' WHERE id='" . $id . "'");
}

/* ---------------------
    Alert Functions
   --------------------- */

function syslog_get_alert_records(&$sql_where, $rows) {
	include(SYSLOG_CONFIG);

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') .
			'(message LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR email LIKE '  . db_qstr('%' . get_request_var('filter') . '%') . '
			OR notes LIKE '  . db_qstr('%' . get_request_var('filter') . '%') . '
			OR name LIKE '   . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	if (get_request_var('enabled') == '-1') {
		// Display all status'
	}elseif (get_request_var('enabled') == '1') {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') .
			"enabled='on'";
	} else {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') .
			"enabled=''";
	}

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	$query_string = "SELECT *
		FROM `" . $syslogdb_default . "`.`syslog_alert`
		$sql_where
		$sql_order
		$sql_limit";

	return syslog_db_fetch_assoc($query_string);
}

function get_repeat_array() {
	$poller_interval = read_config_option('poller_interval');

	$multiplier = 300 / $poller_interval;

	$repeatarray = array(
		$multiplier * 0    => __('Not Set', 'syslog'),
	);

	if ($multiplier > 1) {
		$repeatarray += array(
			round($multiplier / 5,0) => __('1 Minute', 'syslog'),
		);
	}

	$repeatarray += array(
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
	);

	$alert_retention = read_config_option('syslog_alert_retention');
	if ($alert_retention != '' && $alert_retention > 0 && $alert_retention < 365) {
		$repeat_end = ($alert_retention * 24 * 60 * $multiplier) / 5;
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

function syslog_action_edit() {
	global $message_types, $severities;

	include(SYSLOG_CONFIG);

	/* ================= input validation ================= */
	get_filter_request_var('id');
	get_filter_request_var('type');
	get_filter_request_var('date');
	/* ==================================================== */

	if (!isempty_request_var('id') && get_nfilter_request_var('action') == 'edit') {
		$alert = syslog_db_fetch_row('SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_alert`
			WHERE id=' . get_request_var('id'));

		if (cacti_sizeof($alert)) {
			$header_label = __esc('Alert Edit [edit: %s]', $alert['name'], 'syslog');
		} else {
			$header_label = __('Alert Edit [new]', 'syslog');
		}
	} elseif (isset_request_var('id') && get_nfilter_request_var('action') == 'newedit') {
		$syslog_rec = syslog_db_fetch_row("SELECT *
			FROM `" . $syslogdb_default . "`.`syslog`
			WHERE seq=" . get_request_var("id") . (isset_request_var('date') ? " AND logtime='" . get_request_var("date") . "'":""));

		$header_label = __('Alert Edit [new]', 'syslog');
		if (cacti_sizeof($syslog_rec)) {
			$alert['message'] = $syslog_rec['message'];
		}

		$alert['name'] = __('New Alert Rule', 'syslog');
	} else {
		$header_label = __('Alert Edit [new]', 'syslog');

		$alert['name'] = __('New Alert Rule', 'syslog');
	}

	if (db_table_exists('plugin_notification_lists')) {
		$lists = array_rekey(
			db_fetch_assoc('SELECT id, name
				FROM plugin_notification_lists
				ORDER BY name'),
			'id', 'name'
		);
	} else {
		$lists = array('0' => __('N/A', 'syslog'));
	}

	$repeatarray = get_repeat_array();

	$fields_syslog_alert_edit = array(
		'spacer0' => array(
			'method' => 'spacer',
			'friendly_name' => __('Details', 'syslog')
		),
		'name' => array(
			'method' => 'textbox',
			'friendly_name' => __('Name', 'syslog'),
			'description' => __('Please describe this Alert.', 'syslog'),
			'value' => '|arg1:name|',
			'max_length' => '250',
			'size' => 80
		),
		'severity' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Severity', 'syslog'),
			'description' => __('What is the Severity Level of this Alert?', 'syslog'),
			'value' => '|arg1:severity|',
			'array' => $severities,
			'default' => '1'
		),
		'level' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Reporting Level', 'syslog'),
			'description' => __('For recording Re-Alert Cycles, should the Alert be tracked at the System or Device level.', 'syslog'),
			'value' => '|arg1:level|',
			'array' => array('0' => __('System', 'syslog'), '1' => __('Device', 'syslog')),
			'default' => '0'
		),
		'report_method' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Reporting Method', 'syslog'),
			'description' => __('Define how to Alert on the syslog messages.', 'syslog'),
			'value' => '|arg1:method|',
			'array' => array('0' => __('Individual', 'syslog'), '1' => __('Threshold', 'syslog')),
			'default' => '0'
		),
		'num' => array(
			'method' => 'textbox',
			'friendly_name' => __('Threshold', 'syslog'),
			'description' => __('For the \'Threshold\' method, If the number seen is above this value an Alert will be triggered.', 'syslog'),
			'value' => '|arg1:num|',
			'size' => '4',
			'max_length' => '10',
			'default' => '1'
		),
		'type' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Match Type', 'syslog'),
			'description' => __('Define how you would like this string matched.  If using the SQL Expression type you may use any valid SQL expression to generate the alarm.  Available fields include \'message\', \'facility\', \'priority\', and \'host\'.', 'syslog'),
			'value' => '|arg1:type|',
			'array' => $message_types,
			'on_change' => 'changeTypes()',
			'default' => 'matchesc'
		),
		'message' => array(
			'friendly_name' => __('Message Match String', 'syslog'),
			'description' => __('Enter the matching component of the syslog message, the facility or host name, or the SQL where clause if using the SQL Expression Match Type.', 'syslog'),
			'textarea_rows' => '2',
			'textarea_cols' => '70',
			'method' => 'textarea',
			'class' => 'textAreaNotes',
			'value' => '|arg1:message|',
			'default' => ''
		),
		'enabled' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Enabled', 'syslog'),
			'description' => __('Is this Alert Enabled?', 'syslog'),
			'value' => '|arg1:enabled|',
			'array' => array('on' => __('Enabled', 'syslog'), '' => __('Disabled', 'syslog')),
			'default' => 'on'
		),
		'repeat_alert' => array(
			'friendly_name' => __('Re-Alert Cycle', 'syslog'),
			'method' => 'drop_array',
			'array' => $repeatarray,
			'default' => '0',
 			'description' => __('Do not resend this alert again for the same host, until this amount of time has elapsed. For threshold based alarms, this applies to all hosts.', 'syslog'),
			'value' => '|arg1:repeat_alert|'
		),
		'notes' => array(
			'friendly_name' => __('Notes', 'syslog'),
			'textarea_rows' => '5',
			'textarea_cols' => '70',
			'description' => __('Space for Notes on the Alert', 'syslog'),
			'method' => 'textarea',
			'class' => 'textAreaNotes',
			'value' => '|arg1:notes|',
			'default' => '',
		),
		'header_email' => array(
			'method' => 'spacer',
			'friendly_name' => __('Email Options', 'syslog')
		),
		'notify' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Notification List', 'syslog'),
			'description' => __('Use the contents of this Notification List to dictate who should be notified and how.', 'syslog'),
			'value' => '|arg1:notify|',
			'array' => $lists,
			'none_value' => __('None', 'syslog'),
			'default' => '0'
		),
		'email' => array(
			'method' => 'textarea',
			'friendly_name' => __('Emails to Notify', 'syslog'),
			'textarea_rows' => '5',
			'textarea_cols' => '70',
			'description' => __('Please enter a comma delimited list of Email addresses to inform.  If you wish to send out Email to a recipient in SMS format, please prefix that recipient\'s Email address with <b>\'sms@\'</b>.  For example, if the recipients SMS address is <b>\'2485551212@mycarrier.net\'</b>, you would enter it as <b>\'sms@2485551212@mycarrier.net\'</b> and it will be formatted as an SMS message.', 'syslog'),
			'class' => 'textAreaNotes',
			'value' => '|arg1:email|',
			'max_length' => '255'
		),
		'body' => array(
			'friendly_name' => __('Email Body Text', 'syslog'),
			'textarea_rows' => '6',
			'textarea_cols' => '80',
			'description' => __('This information will appear in the body of the Alert just before the Alert details.', 'syslog'),
			'method' => 'textarea',
			'class' => 'textAreaNotes',
			'value' => '|arg1:body|',
			'default' => '',
		),
		'spacer1' => array(
			'method' => 'spacer',
			'friendly_name' => __('Actions', 'syslog')
		),
		'open_ticket' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Open Ticket', 'syslog'),
			'description' => __('Should a Help Desk Ticket be opened for this Alert.  NOTE: The Ticket command script will be populated with several \'ALERT_\' environment variables for convenience.', 'syslog'),
			'value' => '|arg1:open_ticket|',
			'array' => array('on' => __('Yes', 'syslog'), '' => __('No', 'syslog')),
			'default' => ''
		),
		'command' => array(
			'friendly_name' => __('Command', 'syslog'),
			'textarea_rows' => '5',
			'textarea_cols' => '70',
			'description' => __('When an Alert is triggered, run the following command.  The following replacement variables are available <b>\'&lt;HOSTNAME&gt;\'</b>, <b>\'&lt;ALERTID&gt;\'</b>, <b>\'&lt;MESSAGE&gt;\'</b>, <b>\'&lt;FACILITY&gt;\'</b>, <b>\'&lt;PRIORITY&gt;\'</b>, <b>\'&lt;SEVERITY&gt;\'</b>.  Please note that <b>\'&lt;HOSTNAME&gt;\'</b> is only available on individual thresholds.  These replacement values can appear on the command line, or be gathered from the environment of the script.  When used from the environment, those variables will be prefixed with \'ALERT_\'.', 'syslog'),
			'method' => 'textarea',
			'class' => 'textAreaNotes',
			'value' => '|arg1:command|',
			'default' => '',
		),
		'id' => array(
			'method' => 'hidden_zero',
			'value' => '|arg1:id|'
		),
		'_id' => array(
			'method' => 'hidden_zero',
			'value' => '|arg1:id|'
		),
		'save_component_alert' => array(
			'method' => 'hidden',
			'value' => '1'
		)
	);

	form_start('syslog_alerts.php', 'syslog_edit');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($fields_syslog_alert_edit, (cacti_sizeof($alert) ? $alert : array()))
		)
	);

	html_end_box();

	form_save_button('syslog_alerts.php', '', 'id');

	?>
	<script type='text/javascript'>

	var allowEdits=<?php print syslog_allow_edits() ? 'true':'false';?>;
	var notifyExists=<?php print db_table_exists('plugin_notification_lists') ? 'true':'false';?>;

	function changeTypes() {
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

	$(function() {
		if (!allowEdits) {
			$('#syslog_edit').find('select, input, textarea, submit').not(':button').prop('disabled', true);
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

		changeMethod();
	});

	</script>
	<?php
}

function syslog_filter() {
	global $config, $item_rows;

	?>
	<tr class='even'>
		<td>
		<form id='alert' action='syslog_alerts.php' method='get'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'syslog');?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Enabled', 'syslog');?>
					</td>
					<td>
						<select id='enabled' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('enabled') == '-1') {?> selected<?php }?>><?php print __('All', 'syslog');?></option>
							<option value='1'<?php if (get_request_var('enabled') == '1') {?> selected<?php }?>><?php print __('Yes', 'syslog');?></option>
							<option value='0'<?php if (get_request_var('enabled') == '0') {?> selected<?php }?>><?php print __('No', 'syslog');?></option>
						</select>
					</td>
					<td>
						<?php print __('Rows', 'syslog');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'syslog');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print '<option value="' . $key . '"'; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input id='refresh' type='button' value='<?php print __esc('Go', 'syslog');?>'>
							<input id='clear' type='button' value='<?php print __esc('Clear', 'syslog');?>'>
							<?php if (syslog_allow_edits()) {?><input id='import' type='button' value='<?php print __esc('Import', 'syslog');?>'><?php } ?>
						</span>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_filter_request_var('page');?>'>
		</form>
		<script type='text/javascript'>

		function applyFilter() {
			strURL = 'syslog_alerts.php?filter='+$('#filter').val()+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
			loadPageNoHeader(strURL);
		}

		function clearFilter() {
			strURL = 'syslog_alerts.php?clear=1&header=false';
			loadPageNoHeader(strURL);
		}

		function importAlert() {
			strURL = 'syslog_alerts.php?action=import&header=false';
			loadPageNoHeader(strURL);
		}

		$(function() {
			$('#refresh').click(function() {
				applyFilter();
			});

			$('#clear').click(function() {
				clearFilter();
			});

			$('#import').click(function() {
				importAlert();
			});

			$('#alert').submit(function(event) {
				event.preventDefault();
				applyFilter();
			});
		});

		</script>
		</td>
	</tr>
	<?php
}

function syslog_alerts() {
	global $syslog_actions, $config, $message_types, $severities;

	include(SYSLOG_CONFIG);

    /* ================= input validation and session storage ================= */
    $filters = array(
        'rows' => array(
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => '-1',
            ),
        'page' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => '1'
            ),
        'id' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => '1'
            ),
        'enabled' => array(
            'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
            'default' => '-1'
			),
        'filter' => array(
            'filter' => FILTER_DEFAULT,
            'pageset' => true,
            'default' => ''
            ),
        'sort_column' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'name',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'sort_direction' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'ASC',
            'options' => array('options' => 'sanitize_search_string')
            )
    );

    validate_store_request_vars($filters, 'sess_sysloga');
    /* ================= input validation ================= */

	if (syslog_allow_edits()) {
		$url = 'syslog_alerts.php?action=edit';
	} else {
		$url = '';
	}

	html_start_box(__('Syslog Alert Filters', 'syslog'), '100%', '', '3', 'center', $url);

	syslog_filter();

	html_end_box();

	$sql_where = '';

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	}elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = get_request_var('rows');
	}

	$alerts = syslog_get_alert_records($sql_where, $rows);

	$rows_query_string = "SELECT COUNT(*)
		FROM `" . $syslogdb_default . "`.`syslog_alert`
		$sql_where";

	$total_rows = syslog_db_fetch_cell($rows_query_string);

	$display_text = array(
		'name'     => array(__('Alert Name', 'syslog'), 'ASC'),
		'severity' => array(__('Severity', 'syslog'), 'ASC'),
		'method'   => array(__('Method', 'syslog'), 'ASC'),
		'num'      => array(__('Threshold Count', 'syslog'), 'ASC'),
		'enabled'  => array(__('Enabled', 'syslog'), 'ASC'),
		'type'     => array(__('Match Type', 'syslog'), 'ASC'),
		'message'  => array(__('Search String', 'syslog'), 'ASC'),
		'email'    => array(__('Email Addresses', 'syslog'), 'DESC'),
		'date'     => array(__('Last Modified', 'syslog'), 'ASC'),
		'user'     => array(__('By User', 'syslog'), 'DESC')
	);

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
			form_selectable_cell(($alert['method'] == 1 ? __('Threshold', 'syslog'):__('Individual', 'syslog')), $alert['id']);
			form_selectable_cell(($alert['method'] == 1 ? $alert['num']:__('N/A', 'syslog')), $alert['id']);
			form_selectable_cell((($alert['enabled'] == 'on') ? __('Yes', 'syslog'):__('No', 'syslog')), $alert['id']);
			form_selectable_cell($message_types[$alert['type']], $alert['id']);
			form_selectable_cell(title_trim(html_escape($alert['message']),60), $alert['id']);
			form_selectable_cell((substr_count($alert['email'], ',') ? __('Multiple', 'syslog'):html_escape($alert['email'])), $alert['id']);
			form_selectable_cell(date('Y-m-d H:i:s', $alert['date']), $alert['id']);
			form_selectable_cell($alert['user'], $alert['id']);
			form_checkbox_cell($alert['name'], $alert['id']);
			form_end_row();
		}
	} else {
		print "<tr><td colspan='" . (cacti_sizeof($display_text) + 1) . "'><em>" . __('No Syslog Alerts Defined', 'syslog') . "</em></td></tr>";
	}

	html_end_box(false);

	if (cacti_sizeof($alerts)) {
		print $nav;
	}

	draw_actions_dropdown($syslog_actions);

	form_end();

	if (isset($_SESSION['exporter'])) {
		print "<script type='text/javascript'>
			$(function() {
				setTimeout(function() {
					document.location = 'syslog_alerts.php?action=export&selected_items=" . $_SESSION['exporter'] . "';
					Pace.stop();
				}, 250);
			});
			</script>";

		kill_session_var('exporter');
		exit;
	}
}

function import() {
	$form_data = array(
		'import_file' => array(
			'friendly_name' => __('Import Alert Rule from Local File', 'syslog'),
			'description' => __('If the XML file containing the Alert Rule definition data is located on your local machine, select it here.', 'syslog'),
			'method' => 'file'
		),
		'import_text' => array(
			'method' => 'textarea',
			'friendly_name' => __('Import Alert Rule from Text', 'syslog'),
			'description' => __('If you have the XML file containing the Alert Ruledefinition data as text, you can paste it into this box to import it.', 'syslog'),
			'value' => '',
			'default' => '',
			'textarea_rows' => '10',
			'textarea_cols' => '80',
			'class' => 'textAreaNotes'
		)
	);

	print "<form method='post' action='syslog_alerts.php' enctype='multipart/form-data'>";

	html_start_box(__('Import Alert Rule', 'syslog'), '100%', false, '3', 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => $form_data
		)
	);

	html_end_box();

	form_hidden_box('save_component_import', '1', '');

	form_save_button('', 'import');
}

function alert_import() {
	if (trim(get_nfilter_request_var('import_text') != '')) {
		/* textbox input */
		$xml_data = get_nfilter_request_var('import_text');
	} elseif (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
		/* file upload */
		$fp = fopen($_FILES['import_file']['tmp_name'],'r');
		$xml_data = fread($fp, filesize($_FILES['import_file']['tmp_name']));
		fclose($fp);
	} else {
		header('Location: syslog_alerts.php?header=false');
		exit;
	}

	$xml_array = xml2array($xml_data);

	$debug_data = array();

	if (cacti_sizeof($xml_array)) {
		foreach ($xml_array as $template => $contents) {
			$error = false;
			$save  = array();

			if (cacti_sizeof($contents)) {
				foreach ($contents as $name => $value) {
					switch($name) {
					case 'hash':
						// See if the hash exists, if it does, update the alert
						$found = db_fetch_cell_prepared('SELECT id
							FROM syslog_alert
							WHERE hash = ?',
							array($value));

						if (!empty($found)) {
							$save['hash'] = $value;
							$save['id']   = $found;
						} else {
							$save['hash'] = $value;
							$save['id']   = 0;
						}

						break;
					case 'name':
						$tname = $value;
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

			if (!$error) {
				$id = sql_save($save, 'syslog_alert');

				if ($id) {
					raise_message('syslog_info' . $id, __esc('NOTE: Alert \'%s\' %s!', $tname, ($save['id'] > 0 ? __('Updated', 'syslog'):__('Imported', 'syslog')), 'syslog'), MESSAGE_LEVEL_INFO);
				} else {
					raise_message('syslog_info' . $id, __esc('ERROR: Alert \'%s\' %s Failed!', $tname, ($save['id'] > 0 ? __('Update', 'syslog'):__('Import', 'syslog')), 'syslog'), MESSAGE_LEVEL_ERROR);
				}
			}
		}
	}

	header('Location: syslog_alerts.php');
}

