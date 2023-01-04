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
			report_import();
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
		report_export();

		break;
	case 'edit':
		top_header();

		syslog_action_edit();

		bottom_footer();
		break;
	default:
		top_header();

		syslog_report();

		bottom_footer();
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset_request_var('save_component_report')) && (isempty_request_var('add_dq_y'))) {
		$reportid = api_syslog_report_save(get_filter_request_var('id'), get_nfilter_request_var('name'),
			get_nfilter_request_var('type'), get_nfilter_request_var('message'),
			get_nfilter_request_var('timespan'), get_nfilter_request_var('timepart'),
			get_nfilter_request_var('body'), get_nfilter_request_var('email'),
			get_nfilter_request_var('notes'), get_nfilter_request_var('enabled'),
			get_nfilter_request_var('notify'));

		if ((is_error_message()) || (get_filter_request_var('id') != get_filter_request_var('_id')) || $reportid === false) {
			header('Location: syslog_reports.php?header=false&action=edit&id=' . (empty($reportid) ? get_request_var('id') : $reportid));
		} else {
			header('Location: syslog_reports.php?header=false');
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
        $selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

        if ($selected_items != false) {
			if (get_request_var('drp_action') == '1') { /* delete */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_report_remove($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '2') { /* disable */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_report_disable($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '3') { /* enable */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_report_enable($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '4') { /* export */
				$_SESSION['exporter'] = get_nfilter_request_var('selected_items');
			}
		}

		header('Location: syslog_reports.php?header=false');

		exit;
	}

	top_header();

	form_start('syslog_reports.php');

	html_start_box($syslog_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	/* setup some variables */
	$report_array = array(); $report_list = '';

	/* loop through each of the clusters selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$report_info = syslog_db_fetch_cell('SELECT name
				FROM `' . $syslogdb_default . '`.`syslog_reports`
				WHERE id=' . $matches[1]);

			$report_list  .= '<li>' . $report_info . '</li>';
			$report_array[] = $matches[1];
		}
	}

	if (cacti_sizeof($report_array)) {
		if (get_request_var('drp_action') == '1') { /* delete */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Delete the following Syslog Report(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$report_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Delete Syslog Report(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '2') { /* disable */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Disable the following Syslog Report(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$report_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Disable Syslog Report(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '3') { /* enable */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Enable the following Syslog Report(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$report_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Enable Syslog Report(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '4') { /* export */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Export the following Syslog Report Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$report_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Export Syslog Report Rule(s)', 'syslog');
		}

		$save_html = "<input type='button' value='" . __esc('Cancel', 'syslog') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __esc('Continue', 'syslog') . "' title='$title'";
	} else {
		print "<tr><td class='odd'><span class='textError'>" . __('You must select at least one Syslog Report.', 'syslog') . "</span></td></tr>\n";
		$save_html = "<input type='button' value='" . __esc('Return', 'syslog') . "' onClick='cactiReturnTo()'>";
	}

	print "<tr>
		<td align='right' class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($report_array) ? serialize($report_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

function report_export() {
	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			$output = '<templates>' . PHP_EOL;
			foreach ($selected_items as $id) {
				if ($id > 0) {
					$data = db_fetch_row_prepared('SELECT *
						FROM syslog_reports
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
			header('Content-Disposition: attachment; filename=syslog_reports_export.xml');
			print $output;
		}
	}
}

function api_syslog_report_save($id, $name, $type, $message, $timespan, $timepart, $body,
	$email, $notes, $enabled, $notify = 0) {
	global $config;

	include(SYSLOG_CONFIG);

	/* get the username */
	$username = db_fetch_cell('SELECT username FROM user_auth WHERE id=' . $_SESSION['sess_user_id']);

	if ($id) {
		$save['id'] = $id;
	} else {
		$save['id'] = '';
	}

	$hour   = intval($timepart / 60);
	$minute = $timepart % 60;

	$save['hash']     = get_hash_syslog($save['id'], 'syslog_reports');
	$save['name']     = form_input_validate($name,     'name',     '', false, 3);
	$save['type']     = form_input_validate($type,     'type',     '', false, 3);
	$save['message']  = form_input_validate($message,  'message',  '', false, 3);
	$save['timespan'] = form_input_validate($timespan, 'timespan', '', false, 3);
	$save['timepart'] = form_input_validate($timepart, 'timepart', '', false, 3);
	$save['body']     = form_input_validate($body,     'body',     '', true, 3);
	$save['email']    = form_input_validate($email,    'email',    '', true, 3);
	$save['notes']    = form_input_validate($notes,    'notes',    '', true, 3);
	$save['enabled']  = ($enabled == 'on' ? 'on':'');
	$save['date']     = time();
	$save['user']     = $username;
	$save['notify']   = $notify;

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
				$id = syslog_sync_save($save, 'syslog_reports', 'id');

				return $id;
			}
		} else {
			raise_message('sql_error', __('The processed SQL was invalid.  Please correct your SQL', 'syslog'), MESSAGE_LEVEL_ERROR);

			return false;
		}
	}

	return false;
}

function api_syslog_report_remove($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute('DELETE FROM `' . $syslogdb_default . '`.`syslog_reports` WHERE id=' . $id);
}

function api_syslog_report_disable($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute('UPDATE `' . $syslogdb_default . "`.`syslog_reports` SET enabled='' WHERE id=" . $id);
}

function api_syslog_report_enable($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute('UPDATE `' . $syslogdb_default . "`.`syslog_reports` SET enabled='on' WHERE id=" . $id);
}

/* ---------------------
    Reports Functions
   --------------------- */

function syslog_get_report_records(&$sql_where, $rows) {
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

	$query_string = 'SELECT *
		FROM `' . $syslogdb_default . "`.`syslog_reports`
		$sql_where
		$sql_order
		$sql_limit";

	return syslog_db_fetch_assoc($query_string);
}

function syslog_action_edit() {
	global $message_types, $syslog_freqs, $syslog_times;

	include(SYSLOG_CONFIG);

	/* ================= input validation ================= */
	get_filter_request_var('id');
	get_filter_request_var('type');
	/* ==================================================== */

	if (isset_request_var('id')) {
		$report = syslog_db_fetch_row('SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_reports`
			WHERE id=' . get_request_var('id'));

		if (cacti_sizeof($report)) {
			$header_label = __esc('Report Edit [edit: %s]', $report['name'], 'syslog');
		} else {
			$header_label = __('Report Edit [new]', 'syslog');

			$report['name'] = __('New Report Record', 'syslog');
		}
	} else {
		$header_label = __('Report Edit [new]', 'syslog');

		$report['name'] = __('New Report Record', 'syslog');
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

	$fields_syslog_report_edit = array(
		'spacer0' => array(
			'method' => 'spacer',
			'friendly_name' => __('Details', 'syslog')
		),
		'name' => array(
			'method' => 'textbox',
			'friendly_name' => __('Name', 'syslog'),
			'description' => __('Please describe this Report.', 'syslog'),
			'value' => '|arg1:name|',
			'max_length' => '250'
		),
		'enabled' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Enabled?', 'syslog'),
			'description' => __('Is this Report Enabled?', 'syslog'),
			'value' => '|arg1:enabled|',
			'array' => array('on' => __('Enabled', 'syslog'), '' => __('Disabled', 'syslog')),
			'default' => 'on'
		),
		'type' => array(
			'method' => 'drop_array',
			'friendly_name' => __('String Match Type', 'syslog'),
			'description' => __('Define how you would like this string matched.', 'syslog'),
			'value' => '|arg1:type|',
			'array' => $message_types,
			'default' => 'matchesc'
		),
		'message' => array(
			'method' => 'textbox',
			'friendly_name' => __('Message Match String', 'syslog'),
			'description' => __('The matching component of the syslog message.', 'syslog'),
			'value' => '|arg1:message|',
			'default' => '',
			'max_length' => '255'
		),
		'timespan' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Frequency', 'syslog'),
			'description' => __('How often should this Report be sent to the distribution list?', 'syslog'),
			'value' => '|arg1:timespan|',
			'array' => $syslog_freqs,
			'default' => 'del'
		),
		'timepart' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Send Time', 'syslog'),
			'description' => __('What time of day should this report be sent?', 'syslog'),
			'value' => '|arg1:timepart|',
			'array' => $syslog_times,
			'default' => 'del'
		),
		'spacer1' => array(
			'method' => 'spacer',
			'friendly_name' => __('Report Format', 'syslog')
		),
		'message' => array(
			'friendly_name' => __('Message Match String', 'syslog'),
			'description' => __('The matching component of the syslog message.', 'syslog'),
			'method' => 'textbox',
			'max_length' => '255',
			'value' => '|arg1:message|',
			'default' => '',
		),
		'body' => array(
			'friendly_name' => __('Email Body Text', 'syslog'),
			'textarea_rows' => '6',
			'textarea_cols' => '80',
			'description' => __('The information that will be contained in the body of the Report.', 'syslog'),
			'method' => 'textarea',
			'class' => 'textAreaNotes',
			'value' => '|arg1:body|',
			'default' => '',
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
			'friendly_name' => __('Email Addresses', 'syslog'),
			'textarea_rows' => '3',
			'textarea_cols' => '60',
			'description' => __('Comma delimited list of Email addresses to send the report to.', 'syslog'),
			'method' => 'textarea',
			'class' => 'textAreaNotes',
			'value' => '|arg1:email|',
			'default' => '',
		),
		'notes' => array(
			'friendly_name' => __('Notes', 'syslog'),
			'textarea_rows' => '3',
			'textarea_cols' => '60',
			'description' => __('Space for Notes on the Report', 'syslog'),
			'method' => 'textarea',
			'class' => 'textAreaNotes',
			'value' => '|arg1:notes|',
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
		'save_component_report' => array(
			'method' => 'hidden',
			'value' => '1'
		)
	);

	form_start('syslog_reports.php', 'syslog_edit');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($fields_syslog_report_edit, (isset($report) ? $report : array()))
		)
	);

	html_end_box();

	form_save_button('syslog_reports.php', '', 'id');

	?>
	<script type='text/javascript'>

	var allowEdits=<?php print syslog_allow_edits() ? 'true':'false';?>;
	var notifyExists=<?php print db_table_exists('plugin_notification_lists') ? 'true':'false';?>;

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
	});

	</script>
	<?php
}

function syslog_filter() {
	global $config, $item_rows;
	?>
	<tr class='even'>
		<td>
		<form id='reports'>
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
										print '<option value="' . $key . '"'; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . '</option>';
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
		</td>
		<input type='hidden' id='page' value='<?php print get_filter_request_var('page');?>'>
		</form>
		<script type='text/javascript'>

		function applyFilter() {
			strURL = 'syslog_reports.php?filter='+$('#filter').val()+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
			loadPageNoHeader(strURL);
		}

		function clearFilter() {
			strURL = 'syslog_reports.php?clear=1&header=false';
			loadPageNoHeader(strURL);
		}

		function importReport() {
			strURL = 'syslog_reports.php?action=import&header=false';
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
				importReport();
			});

			$('#reports').submit(function(event) {
				event.preventDefault();
				applyFilter();
			});
		});

		</script>
	</tr>
	<?php
}

function syslog_report() {
	global $syslog_actions, $message_types, $syslog_freqs, $syslog_times, $config;

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

    validate_store_request_vars($filters, 'sess_syslogrep');
    /* ================= input validation ================= */

	if (syslog_allow_edits()) {
		$url = 'syslog_reports.php?action=edit&type=1';
	} else {
		$url = '';
	}

	html_start_box(__('Syslog Report Filters', 'syslog'), '100%', '', '3', 'center', $url);

	syslog_filter();

	html_end_box();

	$sql_where = '';

	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	}elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = get_request_var('rows');
	}

	$reports   = syslog_get_report_records($sql_where, $rows);

	$rows_query_string = 'SELECT COUNT(*)
		FROM `' . $syslogdb_default . "`.`syslog_reports`
		$sql_where";

	$total_rows = syslog_db_fetch_cell($rows_query_string);

	$display_text = array(
		'name'     => array(__('Report Name', 'syslog'), 'ASC'),
		'enabled'  => array(__('Enabled', 'syslog'), 'ASC'),
		'type'     => array(__('Match Type', 'syslog'), 'ASC'),
		'message'  => array(__('Search String', 'syslog'), 'ASC'),
		'timespan' => array(__('Frequency', 'syslog'), 'ASC'),
		'timepart' => array(__('Send Time', 'syslog'), 'ASC'),
		'lastsent' => array(__('Last Sent', 'syslog'), 'ASC'),
		'date'     => array(__('Last Modified', 'syslog'), 'ASC'),
		'user'     => array(__('By User', 'syslog'), 'DESC')
	);

	$nav = html_nav_bar('syslog_reports.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, cacti_sizeof($display_text) + 1, __('Reports', 'syslog'), 'page', 'main');

	form_start('syslog_reports.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

	if (cacti_sizeof($reports)) {
		foreach ($reports as $report) {
			form_alternate_row('line' . $report['id']);
			form_selectable_cell(filter_value(title_trim($report['name'], read_config_option('max_title_length')), get_request_var('filter'), $config['url_path'] . 'plugins/syslog/syslog_reports.php?action=edit&id=' . $report['id']), $report['id']);
			form_selectable_cell((($report['enabled'] == 'on') ? __('Yes', 'syslog'):__('No', 'syslog')), $report['id']);
			form_selectable_cell($message_types[$report['type']], $report['id']);
			form_selectable_cell($report['message'], $report['id']);
			form_selectable_cell($syslog_freqs[$report['timespan']], $report['id']);
			form_selectable_cell($syslog_times[$report['timepart']], $report['id']);
			form_selectable_cell(($report['lastsent'] == 0 ? __('Never', 'syslog'): date('Y-m-d H:i:s', $report['lastsent'])), $report['id']);
			form_selectable_cell(date('Y-m-d H:i:s', $report['date']), $report['id']);
			form_selectable_cell($report['user'], $report['id']);
			form_checkbox_cell($report['name'], $report['id']);
			form_end_row();
		}
	} else {
		print "<tr><td colspan='" . (cacti_sizeof($display_text) + 1) . "'><em>" . __('No Syslog Reports Defined', 'syslog') . "</em></td></tr>";
	}

	html_end_box(false);

	if (cacti_sizeof($reports)) {
		print $nav;
	}

	draw_actions_dropdown($syslog_actions);

	form_end();

	if (isset($_SESSION['exporter'])) {
		print "<script type='text/javascript'>
			$(function() {
				setTimeout(function() {
					document.location = 'syslog_reports.php?action=export&selected_items=" . $_SESSION['exporter'] . "';
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
			'friendly_name' => __('Import Report Rule from Local File', 'syslog'),
			'description' => __('If the XML file containing the Report Rule definition data is located on your local machine, select it here.', 'syslog'),
			'method' => 'file'
		),
		'import_text' => array(
			'method' => 'textarea',
			'friendly_name' => __('Import Report Rule from Text', 'syslog'),
			'description' => __('If you have the XML file containing the Report Rule definition data as text, you can paste it into this box to import it.', 'syslog'),
			'value' => '',
			'default' => '',
			'textarea_rows' => '10',
			'textarea_cols' => '80',
			'class' => 'textAreaNotes'
		)
	);

	print "<form method='post' action='syslog_reports.php' enctype='multipart/form-data'>";

	html_start_box(__('Import Report Data', 'syslog'), '100%', false, '3', 'center', '');

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

function report_import() {
	if (trim(get_nfilter_request_var('import_text') != '')) {
		/* textbox input */
		$xml_data = get_nfilter_request_var('import_text');
	} elseif (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
		/* file upload */
		$fp = fopen($_FILES['import_file']['tmp_name'],'r');
		$xml_data = fread($fp, filesize($_FILES['import_file']['tmp_name']));
		fclose($fp);
	} else {
		header('Location: syslog_reports.php?header=false');
		exit;
	}

	/* obtain debug information if it's set */
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
							FROM syslog_reports
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
						if (syslog_db_column_exists('syslog_reports', $name)) {
							$save[$name] = $value;
						}

						break;
					}
				}
			}

			if (!$error) {
				$id = sql_save($save, 'syslog_reports');

				if ($id) {
					raise_message('syslog_info' . $id, __('NOTE: Report Rule \'%s\' %s!', $tname, ($save['id'] > 0 ? __('Updated', 'syslog'):__('Imported', 'syslog')), 'syslog'), MESSAGE_LEVEL_INFO);
				} else {
					raise_message('syslog_info' . $id, __('ERROR: Report Rule \'%s\' %s Failed!', $tname, ($save['id'] > 0 ? __('Update', 'syslog'):__('Import', 'syslog')), 'syslog'), MESSAGE_LEVEL_ERROR);
				}
			}
		}
	}

	header('Location: syslog_reports.php');
}

