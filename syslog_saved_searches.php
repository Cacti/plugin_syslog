<?php
chdir('../../');
include('./include/auth.php');
include_once('./plugins/syslog/functions.php');
include_once('./plugins/syslog/database.php');

// The page was originally registered in its own realm and is now part of
// Syslog Administration. Accept both mappings so existing installations do
// not lose access when the realm registration changes.
if (!api_plugin_user_realm_auth('syslog_saved_searches.php') && !api_plugin_user_realm_auth('syslog_alerts.php')) {
	die(__('Permission denied.', 'syslog'));
}

syslog_connect();
set_default_action();

$db = $syslogdb_default;
$error = '';
$edit = get_filter_request_var('edit', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('save_template')) {
	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	$name = trim((string) get_nfilter_request_var('name'));
	$search = (string) get_nfilter_request_var('search');
	$owner = trim((string) get_nfilter_request_var('owner'));
	$edit = $id;
	if ($id > 0 && $name !== '' && strlen($name) <= 128) {
		$usernames = array_rekey(db_fetch_assoc('SELECT username FROM user_auth'), 'username', 'username');
		if ($owner === '' || !isset($usernames[$owner])) {
			$error = __('Select a valid owner.', 'syslog');
		} else {
			try {
				syslog_parse_logical_search($search);
				if (!syslog_db_execute_prepared("UPDATE $db.syslog_saved_searches SET name = ?, search = ?, `user` = ? WHERE id = ? AND is_global = 'on'", [$name, $search, $owner, $id])) {
					$error = __('Unable to save the template. Please try again.', 'syslog');
				} else {
					// Replace the user/group grants with the posted selections.
					syslog_save_item_shares('saved_search', $id,
						syslog_parse_share_ids('shared_users'),
						syslog_parse_share_ids('shared_groups'));
				}
			} catch (InvalidArgumentException $error) {
				$error = $error->getMessage();
			}
		}
	} else {
		$error = __('Enter a template name between 1 and 128 characters.', 'syslog');
	}
	if ($error === '') {
		header('Location: syslog_saved_searches.php');
		exit;
	}
}

if (isset_request_var('import') && syslog_allow_edits()) {
	set_request_var('action', 'import');
}

$row = $edit > 0 ? syslog_db_fetch_row_prepared("SELECT id, name, search, removal, grouping, user FROM $db.syslog_saved_searches WHERE id = ? AND is_global = 'on'", [$edit]) : false;
if ($row && $error !== '') {
	$row['name'] = $name;
	$row['search'] = $search;
}

switch (get_request_var('action')) {
	case 'actions':
		syslog_template_actions();

		exit;
	case 'export':
		syslog_saved_search_export();

		exit;
	case 'import':
		if (isset_request_var('save_component_import')) {
			syslog_saved_search_import();
		} else {
			top_header();
			syslog_include_js();
			syslog_saved_search_import_form();
			bottom_footer();
		}

		exit;
	case 'edit':
	default:
		top_header();
		syslog_include_js();

		if ($row) {
			syslog_template_edit($row, $error);
		} else {
			syslog_saved_searches();
		}

		break;
}
?>
<script type='text/javascript'>
$(function() {
	initSyslogTemplates();
	<?php if ($row) { ?>
	$('#shared_users').multiselect({
		selectedList: 7,
		noneSelectedText: '<?php print __esc('Share with users…', 'syslog'); ?>',
		header: false,
		height: 200,
		menuWidth: 300
	});
	$('#shared_groups').multiselect({
		selectedList: 7,
		noneSelectedText: '<?php print __esc('Share with groups…', 'syslog'); ?>',
		header: false,
		height: 200,
		menuWidth: 300
	});
	<?php } ?>
});
</script>
<?php
bottom_footer();

function syslog_saved_searches() {
	global $db, $config;

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

	validate_store_request_vars($filters, 'sess_syslogsst');
	// ================= input validation =================

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = get_request_var('rows');
	}

	$sql_where  = "WHERE is_global = 'on'";
	$sql_params = [];

	if (get_request_var('filter') != '') {
		$sql_where .= ' AND (name LIKE ? OR `user` LIKE ? OR search LIKE ?)';
		$filter = '%' . get_request_var('filter') . '%';
		$sql_params = [$filter, $filter, $filter];
	}

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;

	$templates = syslog_db_fetch_assoc_prepared("SELECT id, name, search, user
		FROM $db.syslog_saved_searches
		$sql_where
		$sql_order
		$sql_limit", $sql_params);

	$total_rows = syslog_db_fetch_cell_prepared("SELECT COUNT(*)
		FROM $db.syslog_saved_searches
		$sql_where", $sql_params);

	$nav = html_nav_bar('syslog_saved_searches.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 4, __('Templates', 'syslog'), 'page', 'main');

	html_start_box(__('Saved Search Templates', 'syslog'), '100%', '', '3', 'center', '');

	syslog_saved_search_filter();

	html_end_box();

	syslog_template_list($templates, $nav);
}

function syslog_saved_search_filter() {
	global $config, $item_rows;

	?>
	<tr class='even'>
		<td>
		<form id='saved_searches' action='syslog_saved_searches.php' method='get'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'syslog'); ?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter'); ?>'>
					</td>
					<td>
						<?php print __('Rows', 'syslog'); ?>
					</td>
					<td>
						<select id='rows' onChange='applyFilterSavedSearches()'>
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
		initSyslogSavedSearches();
		</script>
		</td>
	</tr>
	<?php
}

function syslog_template_list($rows, $nav = '') {
	$actions = [1 => __('Delete', 'syslog'), 2 => __('Export', 'syslog')];

	form_start('syslog_saved_searches.php', 'chk');

	if ($nav !== '') {
		print $nav;
	}

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_checkbox([__('Name', 'syslog'), __('Owner', 'syslog'), __('Query', 'syslog')], false);

	if (cacti_sizeof($rows)) {
		foreach ($rows as $template) {
			$id = (int) $template['id'];
			form_alternate_row('line' . $id, true);
			print "<td><a class='linkEditMain' href='syslog_saved_searches.php?edit=$id'>" . html_escape($template['name']) . '</a></td>';
			print '<td>' . html_escape($template['user']) . "</td><td class='syslogTemplateQuery'>" . html_escape($template['search']) . '</td>';
			form_checkbox_cell($template['name'], $id);
			form_end_row();
		}
	} else {
		print "<tr><td colspan='4'><em>" . __('No shared search templates. Save a search for all users from Syslog to create one.', 'syslog') . '</em></td></tr>';
	}
	html_end_box(false);

	if ($nav !== '') {
		print $nav;
	}

	draw_actions_dropdown($actions);

	// Bulk actions can return a JSON attachment; bypass Cacti's HTML AJAX handler.
	form_end(false);
}

function syslog_template_actions() {
	global $db;

	$actions = [1 => __('Delete', 'syslog'), 2 => __('Export', 'syslog')];

	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP,
		['options' => ['regexp' => '/^([a-zA-Z0-9_]+)$/']]);

	if (!isset($actions[get_request_var('drp_action')])) {
		header('Location: syslog_saved_searches.php');
		exit;
	}

	// if we are to save this form, instead of display it
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false && get_request_var('drp_action') == '2') {
			syslog_saved_search_export();
			exit;
		}

		syslog_apply_selected_items_action($selected_items, get_request_var('drp_action'), [
			'1' => 'api_syslog_saved_search_remove'
		]);

		header('Location: syslog_saved_searches.php');

		exit;
	}

	top_header();

	form_start('syslog_saved_searches.php');

	html_start_box($actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	// loop through each of the templates selected on the previous page and get more info about them
	$template_list  = '';
	$template_array = [];

	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			// ================= input validation =================
			input_validate_input_number($matches[1]);
			// ====================================================

			$name = syslog_db_fetch_cell_prepared("SELECT name
				FROM $db.syslog_saved_searches
				WHERE id = ? AND is_global = 'on'",
				[$matches[1]]);

			if ($name !== false && $name !== null) {
				$template_list .= '<li>' . html_escape($name) . '</li>';
				$template_array[] = $matches[1];
			}
		}
	}

	if (cacti_sizeof($template_array)) {
		$export = get_request_var('drp_action') == '2';
		print "<tr>
			<td class='textArea'>
				<p>" . ($export
					? __('Click \'Continue\' to Export the following Saved Search Template(s).', 'syslog')
					: __('Click \'Continue\' to Delete the following Saved Search Template(s).', 'syslog')) . "</p>
				<div class='itemlist'><ul>$template_list</ul></div>
			</td>
		</tr>";

		$title = $export ? __esc('Export Saved Search Template(s)', 'syslog') : __esc('Delete Saved Search Template(s)', 'syslog');

		$save_html = "<input type='button' value='" . __esc('Cancel', 'syslog') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' class='export' value='" . __esc('Continue', 'syslog') . "' title='$title'>";
	} else {
		raise_message(40);
		header('Location: syslog_saved_searches.php');
		exit;
	}

	print "<tr>
		<td align='right' class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . serialize($template_array) . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	// Bulk actions can return a JSON attachment; bypass Cacti's HTML AJAX handler.
	form_end(false);

	bottom_footer();
}

function api_syslog_saved_search_remove($id) {
	global $db;
	syslog_db_execute_prepared("DELETE FROM $db.syslog_saved_searches WHERE id = ? AND is_global = 'on'", [$id]);
	syslog_db_execute_prepared("DELETE FROM $db.syslog_saved_searches_perm WHERE search_id = ?", [$id]);
}

function syslog_saved_search_export() {
	global $db;

	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			$templates = [];

			foreach ($selected_items as $id) {
				if ($id <= 0) {
					continue;
				}

				$template = syslog_db_fetch_row_prepared("SELECT *
					FROM $db.syslog_saved_searches
					WHERE id = ? AND is_global = 'on'",
					[$id]);

				if (!cacti_sizeof($template)) {
					continue;
				}

				unset($template['id']);

				$template['shared_users']  = array_column(syslog_fetch_item_shares('saved_search', $id)['users'], 'id');
				$template['shared_groups'] = array_column(syslog_fetch_item_shares('saved_search', $id)['groups'], 'id');

				$templates[] = $template;
			}

			$output = syslog_rules_array2json('syslog_saved_searches', $templates);
			header('Content-type: application/json');
			header('Content-Disposition: attachment; filename=syslog_saved_searches_export.json');
			print $output;
		}
	}
}

function syslog_saved_search_import() {
	global $db;

	$import_data = syslog_get_import_payload('syslog_saved_searches.php');
	$import_array = syslog_parse_rule_import($import_data);

	$imported = 0;
	$updated  = 0;
	$failed   = 0;

	if ($import_array !== false && cacti_sizeof($import_array)) {
		foreach ($import_array as $contents) {
			if (!is_array($contents)) {
				continue;
			}

			$save = [
				'name'      => $contents['name'] ?? '',
				'search'    => $contents['search'] ?? '',
				'removal'   => $contents['removal'] ?? 1,
				'grouping'  => $contents['grouping'] ?? 0,
				'user'      => $contents['user'] ?? get_username($_SESSION['sess_user_id']),
				'is_global' => 'on',
				'date'      => $contents['date'] ?? time(),
			];

			if (isset($contents['hash']) && $contents['hash'] !== '') {
				$save['hash'] = $contents['hash'];
				$found = syslog_db_fetch_cell_prepared("SELECT id
					FROM $db.syslog_saved_searches
					WHERE hash = ? AND is_global = 'on'",
					[$contents['hash']]);

				if (!empty($found)) {
					$save['id'] = $found;
				} else {
					$save['id'] = 0;
				}
			} else {
				$save['hash'] = generate_hash();
				$save['id']   = 0;
			}

			if ($save['name'] === '' || strlen($save['name']) > 128) {
				$failed++;
				continue;
			}

			if (isset($contents['shared_users']) && is_array($contents['shared_users'])) {
				$shared_users = $contents['shared_users'];
			} else {
				$shared_users = [];
			}

			if (isset($contents['shared_groups']) && is_array($contents['shared_groups'])) {
				$shared_groups = $contents['shared_groups'];
			} else {
				$shared_groups = [];
			}

			unset($contents['shared_users'], $contents['shared_groups']);

			foreach ($contents as $name => $value) {
				if (syslog_db_column_exists('syslog_saved_searches', $name) && !isset($save[$name])) {
					$save[$name] = $value;
				}
			}

			$id = syslog_sql_save($save, 'syslog_saved_searches', 'id');

			if (!$id) {
				$failed++;
				continue;
			}

			if (is_array($shared_users) || is_array($shared_groups)) {
				syslog_save_item_shares('saved_search', $id, $shared_users, $shared_groups);
			}

			if ($save['id'] > 0) {
				$updated++;
			} else {
				$imported++;
			}
		}
	}

	if ($imported || $updated) {
		raise_message('syslog_info', __esc('NOTE: Imported %d and updated %d Saved Search Template(s).', $imported, $updated, 'syslog'), MESSAGE_LEVEL_INFO);
	}

	if ($failed) {
		raise_message('syslog_error', __esc('ERROR: %d Saved Search Template(s) failed to import.', $failed, 'syslog'), MESSAGE_LEVEL_ERROR);
	}

	header('Location: syslog_saved_searches.php');
}

function syslog_saved_search_import_form() {
	if (!syslog_allow_edits()) {
		header('Location: syslog_saved_searches.php');
		exit;
	}

	$form_data = [
		'import_file' => [
			'friendly_name' => __('Import Saved Search Template from Local File', 'syslog'),
			'description'   => __('If the JSON file containing the Saved Search Template definition data is located on your local machine, select it here.', 'syslog'),
			'method'        => 'file'
		],
		'import_text' => [
			'method'        => 'textarea',
			'friendly_name' => __('Import Saved Search Template from Text', 'syslog'),
			'description'   => __('If you have the JSON file containing the Saved Search Template definition data as text, you can paste it into this box to import it.', 'syslog'),
			'value'         => '',
			'default'       => '',
			'textarea_rows' => '10',
			'textarea_cols' => '80',
			'class'         => 'textAreaNotes'
		]
	];

	print "<form method='post' action='syslog_saved_searches.php' enctype='multipart/form-data'>";

	html_start_box(__('Import Saved Search Template', 'syslog'), '100%', false, '3', 'center', '');

	draw_edit_form(
		[
			'config' => ['no_form_tag' => true],
			'fields' => $form_data
		]
	);

	html_end_box();

	form_hidden_box('save_component_import', '1', '');
	form_hidden_box('action', 'import', '');

	form_save_button('', 'import');
}

function syslog_template_edit($row, $error) {
	$tree = null;
	try {
		$tree = syslog_parse_logical_search($row['search']);
	} catch (InvalidArgumentException $exception) {
		$error = $error ?: $exception->getMessage();
	}

	// Cacti accounts and groups to grant this template to, beyond global
	// availability. The 'all' entry shares it with every signed-in user.
	$usernames = array_rekey(db_fetch_assoc('SELECT username FROM user_auth ORDER BY username'), 'username', 'username');
	$users  = array_rekey(db_fetch_assoc('SELECT id, username FROM user_auth ORDER BY username'), 'id', 'username');
	$groups = array_rekey(db_fetch_assoc('SELECT id, name FROM user_auth_group ORDER BY name'), 'id', 'name');
	$shares = syslog_fetch_item_shares('saved_search', (int) $row['id']);

	$users  = ['all' => __('All Users', 'syslog')] + $users;
	$groups = ['all' => __('All Groups', 'syslog')] + $groups;

	form_start('syslog_saved_searches.php', 'syslog_template_form');
	html_start_box(__('Edit Saved Search Template', 'syslog'), '100%', '', '3', 'center', '');
	draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
		'name' => ['friendly_name' => __('Name', 'syslog'), 'method' => 'textbox', 'value' => $row['name'], 'max_length' => 128, 'size' => 60, 'description' => __('The name shown to all users in Saved Searches.', 'syslog')],
		'owner' => ['friendly_name' => __('Owner', 'syslog'), 'method' => 'drop_array', 'array' => $usernames, 'value' => $row['user'], 'description' => __('The account that owns this template and can manage it in Saved Searches.', 'syslog')],
		'shared_users' => ['friendly_name' => __('Shared With Users', 'syslog'), 'method' => 'drop_multi', 'array' => $users, 'value' => $shares['users'], 'description' => __('Users who can use this template in addition to its owner. All Users shares it with everyone.', 'syslog')],
		'shared_groups' => ['friendly_name' => __('Shared With Groups', 'syslog'), 'method' => 'drop_multi', 'array' => $groups, 'value' => $shares['groups'], 'description' => __('Groups who can use this template in addition to its owner. All Groups shares it with everyone.', 'syslog')],
		'id' => ['method' => 'hidden', 'value' => (int) $row['id']],
		'save_template' => ['method' => 'hidden', 'value' => '1']
	]]);
	html_end_box();
	html_start_box(__('Search Query', 'syslog'), '100%', '', '3', 'center', '');
	print "<tr><td class='syslogTemplateEditor'>";
	if ($error !== '') {
		print "<p class='ui-state-error ui-corner-all' role='alert'>" . html_escape($error) . '</p>';
	}
	// Keep malformed legacy expressions editable instead of silently replacing them.
	if ($tree === null && trim($row['search']) !== '') {
		print "<label for='template_search'>" . __('Correct the query syntax to use the query builder.', 'syslog') . "</label><textarea id='template_search' name='search' rows='4' class='textAreaNotes'>" . html_escape($row['search']) . '</textarea>';
	} else {
		print "<input type='hidden' id='template_search' name='search' value='" . html_escape($row['search']) . "'>";
		print "<div id='syslog_template_builder' class='syslogSearchBuilder' data-theme='cacti' data-fields='" . html_escape(json_encode(syslog_search_fields())) . "' data-choices='" . html_escape(json_encode(syslog_search_choices())) . "' data-tree='" . html_escape(json_encode($tree)) . "' data-message='" . __esc('Message', 'syslog') . "' data-placeholder='" . __esc('Enter message text…', 'syslog') . "' data-remove='" . __esc('Remove condition', 'syslog') . "' data-match='" . __esc('Match group', 'syslog') . "' data-exclude='" . __esc('Exclude group', 'syslog') . "'></div>";
	}
	print "<p class='textInfo'>" . __('Choose a field, operator, and value. Use AND, OR, NOT, or Group to build the query. Changes apply to this shared template for all users.', 'syslog') . '</p></td></tr>';
	html_end_box();
	form_save_button('syslog_saved_searches.php', '', 'edit', false);
}
