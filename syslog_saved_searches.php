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
$db = $syslogdb_default;

if (isset_request_var('save_template')) {
	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	$name = trim((string) get_nfilter_request_var('name'));
	$search = (string) get_nfilter_request_var('search');
	if ($id > 0 && $name !== '' && strlen($name) <= 128) {
		try {
			syslog_parse_logical_search($search);
			syslog_db_execute_prepared("UPDATE $db.syslog_saved_searches SET name = ?, search = ? WHERE id = ? AND is_global = 'on'", [$name, $search, $id]);
		} catch (InvalidArgumentException $error) {
			// Leave the template unchanged when its expression is invalid.
		}
	}
	header('Location: syslog_saved_searches.php');
	exit;
}

if (isset_request_var('template_action')) {
	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	if ($id > 0 && get_request_var('template_action') === 'purge') {
		syslog_db_execute_prepared("UPDATE $db.syslog_saved_searches SET is_global = '' WHERE id = ? AND is_global = 'on'", [$id]);
	} elseif ($id > 0 && get_request_var('template_action') === 'delete') {
		syslog_db_execute_prepared("DELETE FROM $db.syslog_saved_searches WHERE id = ? AND is_global = 'on'", [$id]);
	}
	header('Location: syslog_saved_searches.php');
	exit;
}

$edit = get_filter_request_var('edit', FILTER_VALIDATE_INT);
$row = $edit > 0 ? syslog_db_fetch_row_prepared("SELECT id, name, search, removal, grouping, user FROM $db.syslog_saved_searches WHERE id = ? AND is_global = 'on'", [$edit]) : false;

top_header();
html_start_box(__('Shared Search Templates', 'syslog'), '100%', '', '3', 'center', '');
if ($row !== false) {
	form_start('syslog_saved_searches.php');
	print "<input type='hidden' name='id' value='" . (int) $row['id'] . "'>";
	print "<tr><td><label for='name'>" . __('Name', 'syslog') . "</label></td><td><input id='name' name='name' value='" . html_escape($row['name']) . "' maxlength='128'></td></tr>";
	print "<tr><td><label for='search'>" . __('Search', 'syslog') . "</label></td><td><textarea id='search' name='search' rows='4' cols='80'>" . html_escape($row['search']) . "</textarea></td></tr>";
	print "<tr><td colspan='2'><input type='submit' name='save_template' value='" . __esc('Save', 'syslog') . "'> <a href='syslog_saved_searches.php'>" . __esc('Cancel', 'syslog') . '</a></td></tr>';
	form_end();
} else {
	$rows = syslog_db_fetch_assoc("SELECT id, name, search, user FROM $db.syslog_saved_searches WHERE is_global = 'on' ORDER BY name");
	print "<tr><th>" . __('Name', 'syslog') . "</th><th>" . __('Owner', 'syslog') . "</th><th>" . __('Search', 'syslog') . "</th><th>" . __('Actions', 'syslog') . "</th></tr>";
	foreach ($rows as $template) {
		// Cacti's CSRF output handler injects the token into each POST form.
		print "<tr><td>" . html_escape($template['name']) . "</td><td>" . html_escape($template['user']) . "</td><td>" . html_escape($template['search']) . "</td><td><a href='syslog_saved_searches.php?edit=" . (int) $template['id'] . "'>" . __('Edit', 'syslog') . "</a> <form method='post' style='display:inline'><input type='hidden' name='id' value='" . (int) $template['id'] . "'><button name='template_action' value='purge'>" . __('Purge', 'syslog') . "</button> <button name='template_action' value='delete'>" . __('Delete', 'syslog') . '</button></form></td></tr>';
	}
}
html_end_box();
bottom_footer();
