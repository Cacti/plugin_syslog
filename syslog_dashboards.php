<?php
chdir('../../');
include('./include/auth.php');
include_once('./plugins/syslog/functions.php');
include_once('./plugins/syslog/database.php');

// The page is part of the Syslog Administration realm. Accept the explicit
// realm lookup too so pre-existing installs work before realm repair runs.
if (!api_plugin_user_realm_auth('syslog_dashboards.php') && !api_plugin_user_realm_auth('syslog_alerts.php')) {
	die(__('Permission denied.', 'syslog'));
}

syslog_connect();
$db = $syslogdb_default;
$error = '';
$edit = get_filter_request_var('edit', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('save_dashboard')) {
	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	$name = trim((string) get_nfilter_request_var('name'));
	$owner = trim((string) get_nfilter_request_var('owner'));
	$edit = $id;
	if ($id > 0 && $name !== '' && strlen($name) <= 128) {
		$usernames = array_rekey(db_fetch_assoc('SELECT username FROM user_auth'), 'username', 'username');
		if ($owner === '' || !isset($usernames[$owner])) {
			$error = __('Select a valid owner.', 'syslog');
		} else {
			if (!syslog_db_execute_prepared("UPDATE $db.syslog_dashboards SET name = ?, `user` = ?, updated = ? WHERE id = ?", [$name, $owner, time(), $id])) {
				$error = __('Unable to save the dashboard. Please try again.', 'syslog');
			} else {
				// Replace the user/group grants with the posted selections.
				syslog_save_item_shares('dashboard', $id,
					syslog_parse_share_ids('shared_users'),
					syslog_parse_share_ids('shared_groups'));
			}
		}
	} else {
		$error = __('Enter a dashboard name between 1 and 128 characters.', 'syslog');
	}
	if ($error === '') {
		header('Location: syslog_dashboards.php');
		exit;
	}
}

if (isset_request_var('action') && get_nfilter_request_var('action') === 'actions') {
	syslog_dashboard_actions();
	exit;
}

$row = $edit > 0 ? syslog_db_fetch_row_prepared("SELECT id, name, `user`, is_global, updated FROM $db.syslog_dashboards WHERE id = ?", [$edit]) : false;
if ($row && $error !== '') {
	$row['name'] = $name;
}

top_header();
syslog_include_js();
if ($row) {
	syslog_dashboard_edit($row, $error);
} else {
	syslog_dashboard_list();
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

function syslog_dashboard_list($rows = null) {
	global $db;

	if ($rows === null) {
		$rows = syslog_db_fetch_assoc("SELECT d.id, d.name, d.`user`, d.is_global, d.updated,
			(SELECT COUNT(*) FROM $db.syslog_dashboard_panels p WHERE p.dashboard_id = d.id) AS panels
			FROM $db.syslog_dashboards d
			ORDER BY name");
	}

	// Flag dashboards whose owner no longer exists so admins can clean up
	// after deleting a Cacti user.
	$usernames = [];
	$known = db_fetch_assoc('SELECT username FROM user_auth');
	if ($known) {
		$usernames = array_rekey($known, 'username', 'username');
	}

	form_start('syslog_dashboards.php', 'chk');

	html_start_box(__('Dashboards', 'syslog'), '100%', '', '3', 'center', '');

	html_header_checkbox([__('Name', 'syslog'), __('Owner', 'syslog'), __('Shared', 'syslog'), __('Panels', 'syslog'), __('Updated', 'syslog')], false);

	foreach ($rows as $dashboard) {
		$id = (int) $dashboard['id'];
		$owner = html_escape($dashboard['user']);
		if (isset($usernames) && cacti_sizeof($usernames) && !isset($usernames[$dashboard['user']])) {
			$owner .= ' (' . __('deleted user', 'syslog') . ')';
		}
		form_alternate_row('line' . $id, true);
		print "<td><a class='linkEditMain' href='syslog_dashboards.php?edit=$id'>" . html_escape($dashboard['name']) . '</a></td>';
		print '<td>' . $owner . '</td>';
		print '<td>' . ($dashboard['is_global'] === 'on' ? __('Yes', 'syslog') : __('No', 'syslog')) . '</td>';
		print '<td>' . (int) $dashboard['panels'] . '</td>';
		print '<td>' . ((int) $dashboard['updated'] > 0 ? date('Y-m-d H:i:s', (int) $dashboard['updated']) : __('Never', 'syslog')) . '</td>';
		form_checkbox_cell($dashboard['name'], $id);
		form_end_row();
	}
	if (!$rows) {
		print "<tr><td colspan='6'><em>" . __('No dashboards yet. Users create them from the Dashboard tab of Syslog.', 'syslog') . '</em></td></tr>';
	}
	html_end_box(false);

	draw_actions_dropdown([1 => __('Delete', 'syslog')]);

	form_end();
}

function syslog_dashboard_actions() {
	global $db;

	$actions = [1 => __('Delete', 'syslog')];

	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP,
		['options' => ['regexp' => '/^([a-zA-Z0-9_]+)$/']]);

	if (!isset($actions[get_request_var('drp_action')])) {
		header('Location: syslog_dashboards.php');
		exit;
	}

	// if we are to save this form, instead of display it
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_request_var('selected_items'));

		syslog_apply_selected_items_action($selected_items, get_request_var('drp_action'), [
			'1' => 'api_syslog_dashboard_remove'
		]);

		header('Location: syslog_dashboards.php?header=false');

		exit;
	}

	top_header();

	form_start('syslog_dashboards.php');

	html_start_box($actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');

	// loop through each of the dashboards selected on the previous page and get more info about them
	$dashboard_list  = '';
	$dashboard_array = [];

	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			// ================= input validation =================
			input_validate_input_number($matches[1]);
			// ====================================================

			$name = syslog_db_fetch_cell_prepared("SELECT name
				FROM $db.syslog_dashboards
				WHERE id = ?",
				[$matches[1]]);

			if ($name !== false && $name !== null) {
				$dashboard_list .= '<li>' . html_escape($name) . '</li>';
				$dashboard_array[] = $matches[1];
			}
		}
	}

	if (cacti_sizeof($dashboard_array)) {
		print "<tr>
			<td class='textArea'>
				<p>" . __('Click \'Continue\' to Delete the following Dashboard(s) and all of their Panels.', 'syslog') . "</p>
				<div class='itemlist'><ul>$dashboard_list</ul></div>
			</td>
		</tr>";

		$title = __esc('Delete Dashboard(s)', 'syslog');

		$save_html = "<input type='button' value='" . __esc('Cancel', 'syslog') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __esc('Continue', 'syslog') . "' title='$title'";
	} else {
		raise_message(40);
		header('Location: syslog_dashboards.php?header=false');
		exit;
	}

	print "<tr>
		<td align='right' class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . serialize($dashboard_array) . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function api_syslog_dashboard_remove($id) {
	global $db;
	// Panels first, then the dashboard itself.
	syslog_db_execute_prepared("DELETE FROM $db.syslog_dashboard_panels WHERE dashboard_id = ?", [$id]);
	syslog_db_execute_prepared("DELETE FROM $db.syslog_dashboards WHERE id = ?", [$id]);
	syslog_db_execute_prepared("DELETE FROM $db.syslog_dashboards_perm WHERE dashboard_id = ?", [$id]);
}

function syslog_dashboard_edit($row, $error) {
	// Cacti accounts and groups to grant this dashboard to, beyond its owner
	// and the global share flag. The 'all' entry shares it with every
	// signed-in user.
	$usernames = array_rekey(db_fetch_assoc('SELECT username FROM user_auth ORDER BY username'), 'username', 'username');
	$users  = array_rekey(db_fetch_assoc('SELECT id, username FROM user_auth ORDER BY username'), 'id', 'username');
	$groups = array_rekey(db_fetch_assoc('SELECT id, name FROM user_auth_group ORDER BY name'), 'id', 'name');
	$shares = syslog_fetch_item_shares('dashboard', (int) $row['id']);

	$users  = ['all' => __('All Users', 'syslog')] + $users;
	$groups = ['all' => __('All Groups', 'syslog')] + $groups;

	form_start('syslog_dashboards.php', 'syslog_dashboard_form');
	html_start_box(__('Edit Dashboard', 'syslog'), '100%', '', '3', 'center', '');
	draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
		'name' => ['friendly_name' => __('Name', 'syslog'), 'method' => 'textbox', 'value' => $row['name'], 'max_length' => 128, 'size' => 60, 'description' => __('The name shown in the Dashboards select of the Syslog Dashboard tab.', 'syslog')],
		'owner' => ['friendly_name' => __('Owner', 'syslog'), 'method' => 'drop_array', 'array' => $usernames, 'value' => $row['user'], 'description' => __('The account that owns this dashboard and can manage its panels.', 'syslog')],
		'shared' => ['friendly_name' => __('Shared', 'syslog'), 'method' => 'static', 'value' => $row['is_global'] === 'on' ? __('Yes', 'syslog') : __('No', 'syslog')],
		'shared_users' => ['friendly_name' => __('Shared With Users', 'syslog'), 'method' => 'drop_multi', 'array' => $users, 'value' => $shares['users'], 'description' => __('Users who can view this dashboard in addition to its owner and any global sharing. All Users shares it with everyone.', 'syslog')],
		'shared_groups' => ['friendly_name' => __('Shared With Groups', 'syslog'), 'method' => 'drop_multi', 'array' => $groups, 'value' => $shares['groups'], 'description' => __('Groups who can view this dashboard in addition to its owner and any global sharing. All Groups shares it with everyone.', 'syslog')],
		'id' => ['method' => 'hidden', 'value' => (int) $row['id']],
		'save_dashboard' => ['method' => 'hidden', 'value' => '1']
	]]);
	html_end_box();
	if ($error !== '') {
		print "<p class='ui-state-error ui-corner-all' role='alert'>" . html_escape($error) . '</p>';
	}
	print "<p class='textInfo'>" . __('Renaming applies to every viewer of this dashboard. Panels are managed by their owners from the Syslog Dashboard tab.', 'syslog') . '</p>';
	form_save_button('syslog_dashboards.php', '', 'edit', false);
}