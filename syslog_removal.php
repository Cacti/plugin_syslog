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

/* redefine the syslog actions for removal rules */
$syslog_actions = array(
	1 => __('Delete', 'syslog'),
	2 => __('Disable', 'syslog'),
	3 => __('Enable', 'syslog'),
	4 => __('Reprocess', 'syslog'),
	5 => __('Export', 'syslog'),
);

/* set default action */
set_default_action();

if (isset_request_var('import') && syslog_allow_edits()) {
	set_request_var('action', 'import');
}

switch (get_request_var('action')) {
	case 'save':
		if (isset_request_var('save_component_import')) {
			removal_import();
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
		removal_export();

		break;
	case 'edit':
	case 'newedit':
		top_header();

		syslog_action_edit();

		bottom_footer();
		break;
	default:
		top_header();

		syslog_removal();

		bottom_footer();
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset_request_var('save_component_removal')) && (isempty_request_var('add_dq_y'))) {
		$removalid = api_syslog_removal_save(get_filter_request_var('id'), get_nfilter_request_var('name'),
			get_nfilter_request_var('type'), get_nfilter_request_var('message'),
			get_nfilter_request_var('rmethod'), get_nfilter_request_var('notes'), get_nfilter_request_var('enabled'));

		if ((is_error_message()) || (get_filter_request_var('id') != get_filter_request_var('_id'))) {
			header('Location: syslog_removal.php?header=false&action=edit&id=' . (empty($removalid) ? get_request_var('id') : $removalid));
		} else {
			header('Location: syslog_removal.php?header=false');
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
					api_syslog_removal_remove($selected_items[$i]);
				}
			} else if (get_request_var('drp_action') == '2') { /* disable */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_removal_disable($selected_items[$i]);
				}
			} else if (get_request_var('drp_action') == '3') { /* enable */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_removal_enable($selected_items[$i]);
				}
			} else if (get_request_var('drp_action') == '4') { /* reprocess */
				for ($i=0; $i<count($selected_items); $i++) {
					api_syslog_removal_reprocess($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '5') { /* export */
				$_SESSION['exporter'] = get_nfilter_request_var('selected_items');
			}
		}

		header('Location: syslog_removal.php?header=false');

		exit;
	}

	top_header();

	form_start('syslog_removal.php');

	html_start_box($syslog_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	/* setup some variables */
	$removal_array = array(); $removal_list = '';

	/* loop through each of the clusters selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$removal_info = syslog_db_fetch_cell("SELECT name
				FROM `" . $syslogdb_default . "`.`syslog_remove`
				WHERE id=" . $matches[1]);

			$removal_list  .= '<li>' . $removal_info . '</li>';
			$removal_array[] = $matches[1];
		}
	}

	if (cacti_sizeof($removal_array)) {
		if (get_request_var('drp_action') == '1') { /* delete */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Delete the following Syslog Removal Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$removal_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Delete Syslog Removal Rule(s)', 'syslog');
		} else if (get_request_var('drp_action') == '2') { /* disable */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Disable the following Syslog Removal Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$removal_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Disable Syslog Removal Rule(s)', 'syslog');
		} else if (get_request_var('drp_action') == '3') { /* enable */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Enable the following Syslog Removal Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$removal_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Enable Syslog Removal Rule(s)', 'syslog');
		} else if (get_request_var('drp_action') == '4') { /* reprocess */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Re-process the following Syslog Removal Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$removal_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Retroactively Process Syslog Removal Rule(s)', 'syslog');
		} elseif (get_request_var('drp_action') == '5') { /* export */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to Export the following Syslog Removal Rule(s).', 'syslog') . "</p>
					<div class='itemlist'><ul>$removal_list</ul></div>";
					print "</td></tr>
				</td>
			</tr>\n";

			$title = __esc('Export Syslog Removal Rule(s)', 'syslog');
		}

		$save_html = "<input type='button' value='" . __esc('Cancel', 'syslog') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __esc('Continue', 'syslog') . "' title='$title'";
	} else {
		raise_message(40);
		header('Location: syslog_removal.php?header=false');
		exit;
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($removal_array) ? serialize($removal_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function removal_export() {
	include(SYSLOG_CONFIG);

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			$output = '<templates>' . PHP_EOL;
			foreach ($selected_items as $id) {
				if ($id > 0) {
					$data = syslog_db_fetch_row_prepared('SELECT *
						FROM `' . $syslogdb_default . '`.`syslog_remove`
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
			header('Content-Disposition: attachment; filename=syslog_removal_export.xml');
			print $output;
		}
	}
}

function api_syslog_removal_save($id, $name, $type, $message, $rmethod, $notes, $enabled) {
	global $config;

	include(SYSLOG_CONFIG);

	/* get the username */
	$username = db_fetch_cell('SELECT username FROM user_auth WHERE id=' . $_SESSION['sess_user_id']);

	if ($id) {
		$save['id'] = $id;
	} else {
		$save['id'] = '';
	}

	$save['hash']    = get_hash_syslog($save['id'], 'syslog_remove');
	$save['name']    = form_input_validate($name,    'name',    '', false, 3);
	$save['type']    = form_input_validate($type,    'type',    '', false, 3);
	$save['message'] = form_input_validate($message, 'message', '', false, 3);
	$save['method']  = form_input_validate($rmethod,  'rmethod',  '', false, 3);
	$save['notes']   = form_input_validate($notes,   'notes',   '', true, 3);
	$save['enabled'] = ($enabled == 'on' ? 'on':'');
	$save['date']    = time();
	$save['user']    = $username;

	$id = 0;
	if (!is_error_message()) {
		$id = syslog_sync_save($save, 'syslog_remove', 'id');
	}

	return $id;
}

function api_syslog_removal_remove($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog_remove` WHERE id='" . $id . "'");
}

function api_syslog_removal_disable($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_remove` SET enabled='' WHERE id='" . $id . "'");
}

function api_syslog_removal_enable($id) {
	include(SYSLOG_CONFIG);
	syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_remove` SET enabled='on' WHERE id='" . $id . "'");
}

function api_syslog_removal_reprocess($id) {
	/* remove records retroactively */
	$syslog_items   = syslog_remove_items('syslog', $id);
	$syslog_removed = $syslog_items['removed'];
	$syslog_xferred = $syslog_items['xferred'];

	$name = syslog_db_fetch_cell_prepared('SELECT name
		FROM syslog_remove
		WHERE id = ?',
		array($id));

	raise_message('syslog_info' . $id, __('Rule \'%s\' resulted in %s/%s messages removed/transferred', $name, $syslog_removed, $syslog_xferred, 'syslog'), MESSAGE_LEVEL_INFO);
}

/* ---------------------
    Removal Functions
   --------------------- */

function syslog_get_removal_records(&$sql_where, $rows) {
	include(SYSLOG_CONFIG);

	if (get_request_var('filter') != '') {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') .
			'(message LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . '
			OR notes LIKE '  . db_qstr('%' . get_request_var('filter') . '%') . '
			OR name LIKE '   . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	if (get_request_var('enabled') == '-1') {
		// Display all status'
	} elseif (get_request_var('enabled') == '1') {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') .
			"enabled='on'";
	} else {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') .
			"enabled=''";
	}

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	$query_string = "SELECT *
		FROM `" . $syslogdb_default . "`.`syslog_remove`
		$sql_where
		$sql_order
		$sql_limit";

	return syslog_db_fetch_assoc($query_string);
}

function syslog_action_edit() {
	global $message_types;

	include(SYSLOG_CONFIG);

	/* ================= input validation ================= */
	get_filter_request_var('id');
	get_filter_request_var('type');
	get_filter_request_var('date');
	/* ==================================================== */

	if (isset_request_var('id') && get_nfilter_request_var('action') == 'edit') {
		$removal = syslog_db_fetch_row('SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_remove`
			WHERE id=' . get_request_var('id'));

		if (cacti_sizeof($removal)) {
			$header_label = __esc('Removal Rule Edit [edit: %s]', $removal['name'], 'syslog');
		} else {
			$header_label = __('Removal Rule Edit [new]', 'syslog');

			$removal['name'] = __('New Removal Record', 'syslog');
		}
	} else if (isset_request_var('id') && get_nfilter_request_var('action') == 'newedit') {
		$syslog_rec = syslog_db_fetch_row('SELECT * FROM `' . $syslogdb_default . '`.`syslog` WHERE seq=' . get_request_var('id') . (isset_request_var('date') ? " AND logtime='" . get_request_var('date') . "'":""));

		$header_label = __('Removal Rule Edit [new]', 'syslog');
		if (cacti_sizeof($syslog_rec)) {
			$removal['message'] = $syslog_rec['message'];
		}
		$removal['name'] = __('New Removal Rule', 'syslog');
	} else {
		$header_label = '[new]';

		$removal['name'] = __('New Removal Record', 'syslog');
	}

	$fields_syslog_removal_edit = array(
		'spacer0' => array(
			'method' => 'spacer',
			'friendly_name' => __('Removal Rule Details', 'syslog')
		),
		'name' => array(
			'method' => 'textbox',
			'friendly_name' => __('Removal Rule Name', 'syslog'),
			'description' => __('Please describe this Removal Rule.', 'syslog'),
			'value' => '|arg1:name|',
			'max_length' => '250',
			'size' => 80
		),
		'enabled' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Enabled?', 'syslog'),
			'description' => __('Is this Removal Rule Enabled?', 'syslog'),
			'value' => '|arg1:enabled|',
			'array' => array('on' => __('Enabled', 'syslog'), '' => __('Disabled', 'syslog')),
			'default' => 'on'
		),
		'type' => array(
			'method' => 'drop_array',
			'friendly_name' => __('String Match Type', 'syslog'),
			'description' => __('Define how you would like this string matched.  If using the SQL Expression type you may use any valid SQL expression to generate the alarm.  Available fields include \'message\', \'facility\', \'priority\', and \'host\'.', 'syslog'),
			'value' => '|arg1:type|',
			'array' => $message_types,
			'on_change' => 'changeTypes()',
			'default' => 'matchesc'
		),
		'message' => array(
			'friendly_name' => __('Syslog Message Match String', 'syslog'),
			'description' => __('Enter the matching component of the syslog message, the facility or host name, or the SQL where clause if using the SQL Expression Match Type.', 'syslog'),
			'method' => 'textarea',
			'textarea_rows' => '2',
			'textarea_cols' => '70',
			'class' => 'textAreaNotes',
			'value' => '|arg1:message|',
			'default' => '',
		),
		'rmethod' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Method of Removal', 'syslog'),
			'value' => '|arg1:method|',
			'array' => array('del' => __('Deletion', 'syslog'), 'trans' => __('Transferal', 'syslog')),
			'default' => 'del'
		),
		'notes' => array(
			'friendly_name' => __('Removal Rule Notes', 'syslog'),
			'textarea_rows' => '5',
			'textarea_cols' => '70',
			'description' => __('Space for Notes on the Removal rule', 'syslog'),
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
		'save_component_removal' => array(
			'method' => 'hidden',
			'value' => '1'
		)
	);

	form_start('syslog_removal.php', 'syslog_edit');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($fields_syslog_removal_edit, (isset($removal) ? $removal : array()))
		)
	);

	html_end_box();

	form_save_button('syslog_removal.php', '', 'id');

	?>
	<script type='text/javascript'>

	var allowEdits=<?php print syslog_allow_edits() ? 'true':'false';?>;

	function changeTypes() {
		if ($('#type').val == 'sql') {
			$('#message').prop('rows', 5);
		} else {
			$('#message').prop('rows', 2);
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
	});

	</script>
	<?php
}

function syslog_filter() {
	global $config, $item_rows;

	?>
	<tr class='even'>
		<td>
		<form id='removal' action='syslog_removal.php'>
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
						<?php print __('Rules', 'syslog');?>
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
			strURL = 'syslog_removal.php?filter='+$('#filter').val()+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
			loadPageNoHeader(strURL);
		}

		function clearFilter() {
			strURL = 'syslog_removal.php?clear=1&header=false';
			loadPageNoHeader(strURL);
		}

		function importRemoval() {
			strURL = 'syslog_removal.php?action=import&header=false';
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
				importRemoval();
			});

			$('#removal').submit(function(event) {
				event.preventDefault();
				applyFilter();
			});
		});

		</script>
		</td>
	</tr>
	<?php
}

function syslog_removal() {
	global $syslog_actions, $message_types, $config;

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

    validate_store_request_vars($filters, 'sess_syslogr');
    /* ================= input validation ================= */

	if (syslog_allow_edits()) {
		$url = 'syslog_removal.php?action=edit&type=1';
	} else {
		$url = '';
	}

	html_start_box(__('Syslog Removal Rule Filters', 'syslog'), '100%', '', '3', 'center', $url);

	syslog_filter();

	html_end_box();

	$sql_where = '';

	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = get_request_var('rows');
	}

	$removals = syslog_get_removal_records($sql_where, $rows);

	$rows_query_string = "SELECT COUNT(*)
		FROM `" . $syslogdb_default . "`.`syslog_remove`
		$sql_where";

	$total_rows = syslog_db_fetch_cell($rows_query_string);

	$display_text = array(
		'name'    => array(__('Removal Name', 'syslog'), 'ASC'),
		'enabled' => array(__('Enabled', 'syslog'), 'ASC'),
		'type'    => array(__('Match Type', 'syslog'), 'ASC'),
		'message' => array(__('Search String', 'syslog'), 'ASC'),
		'method'  => array(__('Method', 'syslog'), 'DESC'),
		'date'    => array(__('Last Modified', 'syslog'), 'ASC'),
		'user'    => array(__('By User', 'syslog'), 'DESC')
	);

	form_start('syslog_removal.php', 'chk');

	$nav = html_nav_bar('syslog_removal.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, cacti_sizeof($display_text) + 1, __('Rules', 'syslog'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

	if (cacti_sizeof($removals)) {
		foreach ($removals as $removal) {
			form_alternate_row('line' . $removal['id'], true);
			form_selectable_cell(filter_value(title_trim($removal['name'], read_config_option('max_title_length')), get_request_var('filter'), $config['url_path'] . 'plugins/syslog/syslog_removal.php?action=edit&id=' . $removal['id']), $removal['id']);
			form_selectable_cell((($removal['enabled'] == 'on') ? __('Yes', 'syslog'):__('No', 'syslog')), $removal['id']);
			form_selectable_cell($message_types[$removal['type']], $removal['id']);
			form_selectable_ecell($removal['message'], $removal['id']);
			form_selectable_cell((($removal['method'] == 'del') ? __('Deletion', 'syslog'): __('Transfer', 'syslog')), $removal['id']);
			form_selectable_cell(date('Y-m-d H:i:s', $removal['date']), $removal['id']);
			form_selectable_cell($removal['user'], $removal['id']);
			form_checkbox_cell($removal['name'], $removal['id']);
			form_end_row();
		}
	} else {
		print "<tr><td colspan='" . (cacti_sizeof($display_text)+1) . "'><em>" . __('No Syslog Removal Rules Defined', 'syslog'). "</em></td></tr>";
	}

	html_end_box(false);

	if (cacti_sizeof($removals)) {
		print $nav;
	}

	draw_actions_dropdown($syslog_actions);

	form_end();

	if (isset($_SESSION['exporter'])) {
		print "<script type='text/javascript'>
			$(function() {
				setTimeout(function() {
					document.location = 'syslog_removal.php?action=export&selected_items=" . $_SESSION['exporter'] . "';
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
			'friendly_name' => __('Import Removal Rule from Local File', 'syslog'),
			'description' => __('If the XML file containing the Removal Rule definition data is located on your local machine, select it here.', 'syslog'),
			'method' => 'file'
		),
		'import_text' => array(
			'method' => 'textarea',
			'friendly_name' => __('Import Removal Rule from Text', 'syslog'),
			'description' => __('If you have the XML file containing the Removal Rule definition data as text, you can paste it into this box to import it.', 'syslog'),
			'value' => '',
			'default' => '',
			'textarea_rows' => '10',
			'textarea_cols' => '80',
			'class' => 'textAreaNotes'
		)
	);

	print "<form method='post' action='syslog_removal.php' enctype='multipart/form-data'>";

	html_start_box(__('Import Removal Rule', 'syslog'), '100%', false, '3', 'center', '');

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

function removal_import() {
	if (trim(get_nfilter_request_var('import_text') != '')) {
		/* textbox input */
		$xml_data = get_nfilter_request_var('import_text');
	} elseif (($_FILES['import_file']['tmp_name'] != 'none') && ($_FILES['import_file']['tmp_name'] != '')) {
		/* file upload */
		$fp = fopen($_FILES['import_file']['tmp_name'],'r');
		$xml_data = fread($fp, filesize($_FILES['import_file']['tmp_name']));
		fclose($fp);
	} else {
		header('Location: syslog_removal.php?header=false');
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
						$found = syslog_db_fetch_cell_prepared('SELECT id
							FROM syslog_remove
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
						if (syslog_db_column_exists('syslog_remove', $name)) {
							$save[$name] = $value;
						}

						break;
					}
				}
			}

			if (!$error) {
				$id = sql_save($save, 'syslog_remove');

				if ($id) {
					raise_message('syslog_info' . $id, __('NOTE: Removal Rule \'%s\' %s!', $tname, ($save['id'] > 0 ? __('Updated', 'syslog'):__('Imported', 'syslog')), 'syslog'), MESSAGE_LEVEL_INFO);
				} else {
					raise_message('syslog_info' . $id, __('ERROR: Removal Rule \'%s\' %s Failed!', $tname, ($save['id'] > 0 ? __('Update', 'syslog'):__('Import', 'syslog')), 'syslog'), MESSAGE_LEVEL_ERROR);
				}
			}
		}
	}

	header('Location: syslog_removal.php');
}

