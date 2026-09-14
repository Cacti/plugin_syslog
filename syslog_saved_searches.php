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
$error = '';
$edit = get_filter_request_var('edit', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('save_template')) {
	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	$name = trim((string) get_nfilter_request_var('name'));
	$search = (string) get_nfilter_request_var('search');
	$edit = $id;
	if ($id > 0 && $name !== '' && strlen($name) <= 128) {
		try {
			syslog_parse_logical_search($search);
			if (!syslog_db_execute_prepared("UPDATE $db.syslog_saved_searches SET name = ?, search = ? WHERE id = ? AND is_global = 'on'", [$name, $search, $id])) {
				$error = __('Unable to save the template. Please try again.', 'syslog');
			}
		} catch (InvalidArgumentException $error) {
			$error = $error->getMessage();
		}
	} else {
		$error = __('Enter a template name between 1 and 128 characters.', 'syslog');
	}
	if ($error === '') {
		header('Location: syslog_saved_searches.php');
		exit;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('template_action')) {
	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	if ($id > 0 && get_request_var('template_action') === 'delete') {
		syslog_db_execute_prepared("DELETE FROM $db.syslog_saved_searches WHERE id = ? AND is_global = 'on'", [$id]);
	}
	header('Location: syslog_saved_searches.php');
	exit;
}

$row = $edit > 0 ? syslog_db_fetch_row_prepared("SELECT id, name, search, removal, grouping, user FROM $db.syslog_saved_searches WHERE id = ? AND is_global = 'on'", [$edit]) : false;
if ($row && $error !== '') {
	$row['name'] = $name;
	$row['search'] = $search;
}

top_header();
syslog_include_js();
if ($row) {
	syslog_template_edit($row, $error);
} else {
	$rows = syslog_db_fetch_assoc("SELECT id, name, search, user FROM $db.syslog_saved_searches WHERE is_global = 'on' ORDER BY name");
	syslog_template_list($rows);
}
?>
<script type='text/javascript'>
$(function() { initSyslogTemplates(); });
</script>
<?php
bottom_footer();

function syslog_template_list($rows) {
	html_start_box(__('Saved Search Templates', 'syslog'), '100%', '', '3', 'center', '');
	html_header([__('Name', 'syslog'), __('Owner', 'syslog'), __('Query', 'syslog'), __('Actions', 'syslog')]);
	foreach ($rows as $template) {
		$id = (int) $template['id'];
		form_alternate_row('template_' . $id);
		print "<td><a class='linkEditMain' href='syslog_saved_searches.php?edit=$id'>" . html_escape($template['name']) . '</a></td>';
		print '<td>' . html_escape($template['user']) . "</td><td class='syslogTemplateQuery'>" . html_escape($template['search']) . '</td>';
		print "<td class='syslogTemplateActions'><form method='post' action='syslog_saved_searches.php' class='syslogTemplateDelete' data-confirm='" . __esc('Delete this shared template? It will no longer be available to any user.', 'syslog') . "' data-title='" . __esc('Delete Template', 'syslog') . "' data-delete='" . __esc('Delete', 'syslog') . "' data-cancel='" . __esc('Cancel', 'syslog') . "'>";
		// Cacti injects CSRF tokens into POST forms.
		print "<input type='hidden' name='id' value='$id'><input type='hidden' name='template_action' value='delete'>";
		print "<button type='submit' class='ui-button ui-corner-all ui-widget'>" . __('Delete', 'syslog') . '</button></form></td>';
		form_end_row();
	}
	if (!$rows) {
		print "<tr><td colspan='4'><em>" . __('No shared search templates. Save a search for all users from Syslog to create one.', 'syslog') . '</em></td></tr>';
	}
	html_end_box();
}

function syslog_template_edit($row, $error) {
	$tree = null;
	try {
		$tree = syslog_parse_logical_search($row['search']);
	} catch (InvalidArgumentException $exception) {
		$error = $error ?: $exception->getMessage();
	}
	form_start('syslog_saved_searches.php', 'syslog_template_form');
	html_start_box(__('Edit Saved Search Template', 'syslog'), '100%', '', '3', 'center', '');
	draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => [
		'name' => ['friendly_name' => __('Name', 'syslog'), 'method' => 'textbox', 'value' => $row['name'], 'max_length' => 128, 'size' => 60, 'description' => __('The name shown to all users in Saved Searches.', 'syslog')],
		'owner' => ['friendly_name' => __('Owner', 'syslog'), 'method' => 'static', 'value' => html_escape($row['user'])],
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
