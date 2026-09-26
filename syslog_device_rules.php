<?php
/* Device-wide Syslog alert handling rules. */

chdir('../../');
include('./include/auth.php');
include_once(__DIR__ . '/setup.php');
include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/database.php');

syslog_connect();

// Return only matching current devices on demand. This keeps the editor
// usable for installations with many thousands of known hosts.
if (get_request_var('action') === 'ajax_hosts') {
	global $syslogdb_default;
	$term = '%' . get_nfilter_request_var('term') . '%';
	$limit = (int) read_config_option('autocomplete_rows');
	if ($limit <= 0) {
		$limit = 100;
	}
	if ($limit > 1000) {
		$limit = 1000;
	}
	header('Content-Type: application/json; charset=UTF-8');
	$hosts = syslog_db_fetch_assoc_prepared("SELECT host FROM `$syslogdb_default`.`syslog_hosts` WHERE host LIKE ? ORDER BY host LIMIT $limit", [$term]);
	print json_encode(array_map(static fn($host) => ['label' => $host['host'], 'value' => $host['host']], $hosts));
	exit;
}

set_default_action();

switch (get_request_var('action')) {
	case 'save':
		device_rule_save();
		break;
	case 'actions':
		device_rule_actions();
		break;
	case 'export':
		device_rule_export();
		break;
	case 'edit':
		top_header();
		syslog_include_js();
		device_rule_edit();
		bottom_footer();
		break;
	default:
		top_header();
		syslog_include_js();
		device_rule_list();
		bottom_footer();
}

function device_rule_save(): void {
	if (!syslog_allow_edits() || !isset_request_var('save_component_device_rule')) {
		return;
	}
	$mode = get_nfilter_request_var('mute_mode');
	$mode = in_array($mode, ['none', 'until', 'indefinite'], true) ? $mode : 'none';
	$until = $mode === 'until' ? strtotime(get_nfilter_request_var('mute_until')) : 0;
	if ($mode === 'until' && ($until === false || $until <= time())) {
		raise_message('syslog_device_rule_time', __('Choose a future pause end date and time.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: syslog_device_rules.php?action=edit&id=' . get_filter_request_var('id'));
		return;
	}
	$host = trim(get_nfilter_request_var('host'));
	if ($host === '') {
		raise_message('syslog_device_rule_host', __('A device name is required.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: syslog_device_rules.php?action=edit&id=' . get_filter_request_var('id'));
		return;
	}
	$priority = get_filter_request_var('pass_through_priority');
	$priority = $priority >= -1 && $priority <= 7 ? $priority : -1;
	$save = [
		'id' => get_filter_request_var('id'), 'host' => $host,
		'enabled' => get_nfilter_request_var('enabled') === 'on' ? 'on' : '',
		'mute_mode' => $mode, 'mute_until' => (int) $until,
		'pass_through_priority' => $priority,
		'allow_maintenance' => get_nfilter_request_var('allow_maintenance') === 'on' ? 'on' : '',
		'notes' => form_input_validate(get_nfilter_request_var('notes'), 'notes', '', true, 3),
		'user' => get_username($_SESSION['sess_user_id']), 'date' => time()
	];
	syslog_sync_save($save, 'syslog_device_rule', 'id');
	header('Location: syslog_device_rules.php');
}

/** Render and apply the standard Cacti bulk-action confirmation workflow. */
function device_rule_actions(): void {
	global $syslog_actions, $syslogdb_default;
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[1-4]$/']]);
	if (isset_request_var('selected_items')) {
		$items = sanitize_unserialize_selected_items(get_request_var('selected_items'));
		$action = get_request_var('drp_action');
		if ($items !== false && $action === '4') {
			$_SESSION['syslog_device_rule_exporter'] = rawurlencode(serialize($items));
		} elseif ($items !== false && syslog_allow_edits()) {
			foreach ($items as $id) {
				if ((int) $id < 1) continue;
				if ($action === '1') syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_device_rule` WHERE id = ?", [$id]);
				if ($action === '2') syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_device_rule` SET enabled = '' WHERE id = ?", [$id]);
				if ($action === '3') syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_device_rule` SET enabled = 'on' WHERE id = ?", [$id]);
			}
		}
		header('Location: syslog_device_rules.php');
		return;
	}
	top_header();
	form_start('syslog_device_rules.php');
	html_start_box($syslog_actions[get_request_var('drp_action')], '60%', '', '3', 'center', '');
	$items = [];
	$list = '';
	foreach ($_POST as $name => $value) {
		if (preg_match('/^chk_([0-9]+)$/', $name, $matches)) {
			$rule = syslog_db_fetch_cell_prepared("SELECT host FROM `$syslogdb_default`.`syslog_device_rule` WHERE id = ?", [(int) $matches[1]]);
			if ($rule !== null) { $items[] = $matches[1]; $list .= '<li>' . html_escape($rule) . '</li>'; }
		}
	}
	if (!cacti_sizeof($items)) {
		raise_message(40);
		header('Location: syslog_device_rules.php');
		return;
	}
	$verb = [1 => __('Delete', 'syslog'), 2 => __('Disable', 'syslog'), 3 => __('Enable', 'syslog'), 4 => __('Export', 'syslog')][(int) get_request_var('drp_action')];
	print '<tr><td class="textArea"><p>' . __esc('Click Continue to %s the following Device Alert Rule(s).', strtolower($verb), 'syslog') . '</p><div class="itemlist"><ul>' . $list . '</ul></div></td></tr>';
	print '<tr><td align="right" class="saveRow"><input type="hidden" name="action" value="actions"><input type="hidden" name="selected_items" value="' . html_escape(serialize($items)) . '"><input type="hidden" name="drp_action" value="' . (int) get_request_var('drp_action') . '"><input type="button" value="' . __esc('Cancel', 'syslog') . '" onClick="cactiReturnTo()">&nbsp;<input type="submit" value="' . __esc('Continue', 'syslog') . '"></td></tr>';
	html_end_box();
	form_end(false);
	bottom_footer();
}

/** Download selected device alert rules as a portable JSON document. */
function device_rule_export(): void {
	global $syslogdb_default;
	$items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));
	if ($items === false) {
		return;
	}
	$rules = [];
	foreach ($items as $id) {
		if ((int) $id < 1) continue;
		$rule = syslog_db_fetch_row_prepared("SELECT * FROM `$syslogdb_default`.`syslog_device_rule` WHERE id = ?", [$id]);
		if (cacti_sizeof($rule)) {
			unset($rule['id']);
			$rules[] = $rule;
		}
	}
	$output = json_encode(['version' => SYSLOG_IMPORT_VERSION, 'generator' => 'syslog', 'table' => 'syslog_device_rule', 'templates' => $rules], JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	header('Content-Type: application/json; charset=UTF-8');
	header('Content-Disposition: attachment; filename=syslog_device_rules_export.json');
	print $output === false ? '' : $output;
}

function device_rule_edit(): void {
	global $syslog_levels, $syslogdb_default;
	$id = get_filter_request_var('id');
	$rule = $id ? syslog_db_fetch_row_prepared("SELECT * FROM `$syslogdb_default`.`syslog_device_rule` WHERE id = ?", [$id]) : [];
	if (!cacti_sizeof($rule)) {
		$rule = ['host' => get_nfilter_request_var('host'), 'enabled' => 'on', 'mute_mode' => 'none', 'mute_until' => '', 'pass_through_priority' => '-1', 'allow_maintenance' => '', 'notes' => ''];
	}
	$priorities = ['-1' => __('Do not pass any priority', 'syslog')];
	foreach ($syslog_levels as $priority => $label) {
		$priorities[$priority] = __esc('%s and more urgent', ucfirst($label), 'syslog');
	}
	$fields = [
		'spacer' => ['method' => 'spacer', 'friendly_name' => __('Device Alert Rule', 'syslog')],
		'host' => ['method' => 'drop_callback', 'action' => 'ajax_hosts', 'id' => $rule['host'], 'sql' => 'SELECT ' . db_qstr($rule['host']) . ' AS id, ' . db_qstr($rule['host']) . ' AS name', 'friendly_name' => __('Device', 'syslog'), 'description' => __('Search current Syslog devices as you type, or enter any IP address or hostname.', 'syslog'), 'value' => '|arg1:host|', 'size' => 50, 'max_length' => 64],
		'enabled' => ['method' => 'drop_array', 'friendly_name' => __('Enabled', 'syslog'), 'array' => ['on' => __('Enabled', 'syslog'), '' => __('Disabled', 'syslog')], 'value' => '|arg1:enabled|'],
		'mute_mode' => ['method' => 'drop_array', 'friendly_name' => __('Alert Handling', 'syslog'), 'description' => __('Pause all non-exempt alerts until a date, or indefinitely.', 'syslog'), 'array' => ['none' => __('Do not pause', 'syslog'), 'until' => __('Pause until', 'syslog'), 'indefinite' => __('Pause indefinitely', 'syslog')], 'value' => '|arg1:mute_mode|'],
		'mute_until' => ['method' => 'textbox', 'friendly_name' => __('Pause Ends', 'syslog'), 'description' => __('Local date and time, YYYY-MM-DD HH:MM.', 'syslog'), 'value' => '|arg1:mute_until|', 'size' => 18, 'max_length' => 16],
		'pass_through_priority' => ['method' => 'drop_array', 'friendly_name' => __('Always Allow Priority', 'syslog'), 'description' => __('These priorities pass through this device pause and maintenance windows. Critical and more urgent is a typical platinum-device choice.', 'syslog'), 'array' => $priorities, 'value' => '|arg1:pass_through_priority|'],
		'allow_maintenance' => ['method' => 'drop_array', 'friendly_name' => __('Allow During Maintenance', 'syslog'), 'description' => __('Allow all priorities from this device during an alert maintenance window. The priority exception above still applies when this is No.', 'syslog'), 'array' => ['' => __('No', 'syslog'), 'on' => __('Yes', 'syslog')], 'value' => '|arg1:allow_maintenance|'],
		'notes' => ['method' => 'textarea', 'friendly_name' => __('Notes', 'syslog'), 'value' => '|arg1:notes|', 'textarea_rows' => 4, 'textarea_cols' => 70],
		'id' => ['method' => 'hidden_zero', 'value' => '|arg1:id|'], '_id' => ['method' => 'hidden_zero', 'value' => '|arg1:id|'], 'save_component_device_rule' => ['method' => 'hidden', 'value' => '1']
	];
	if (!empty($rule['mute_until'])) {
		$rule['mute_until'] = date('Y-m-d H:i', (int) $rule['mute_until']);
	}
	form_start('syslog_device_rules.php', 'syslog_device_rule_edit');
	html_start_box($id ? __('Device Alert Rule [edit]', 'syslog') : __('Device Alert Rule [new]', 'syslog'), '100%', '', '3', 'center', '');
	draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => inject_form_variables($fields, $rule)]);
	html_end_box();
	form_save_button('syslog_device_rules.php', '', 'id');
	?>
	<script>
	(function () {
		var mode = document.getElementById('mute_mode');
		var endRow = document.getElementById('row_mute_until');
		function toggleEnd() { if (endRow && mode) endRow.style.display = mode.value === 'until' ? '' : 'none'; }
		if (mode) mode.addEventListener('change', toggleEnd); toggleEnd();
		if (window.jQuery && window.jQuery.fn.datetimepicker) window.jQuery('#mute_until').datetimepicker({minuteGrid:10, stepMinute:1, timeFormat:'HH:mm', dateFormat:'yy-mm-dd'});
		// Cacti's callback control posts a hidden value. Keep arbitrary typed
		// hostnames in sync as well as values selected from its results.
		var hostInput = document.getElementById('host_input');
		if (hostInput) {
			hostInput.maxLength = 64;
			hostInput.addEventListener('input', function () {
				document.getElementById('host').value = this.value;
			});
			document.getElementById('syslog_device_rule_edit').addEventListener('submit', function () {
				document.getElementById('host').value = hostInput.value;
			}, true);
		}
		<?php if (!syslog_allow_edits()) { ?>document.querySelectorAll('#syslog_device_rule_edit select,#syslog_device_rule_edit input,#syslog_device_rule_edit textarea').forEach(function (field) { field.disabled = true; });<?php } ?>
	}());
	</script>
	<?php
}

function device_rule_list(): void {
	global $syslogdb_default, $syslog_levels, $item_rows, $syslog_actions, $config;
	$filters = [
		'rows' => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => '-1'],
		'page' => ['filter' => FILTER_VALIDATE_INT, 'default' => '1'],
		'enabled' => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => '-1'],
		'filter' => ['filter' => FILTER_DEFAULT, 'pageset' => true, 'default' => '']
	];
	validate_store_request_vars($filters, 'sess_syslog_device_rules');
	$url = syslog_allow_edits() ? 'syslog_device_rules.php?action=edit' : '';
	html_start_box(__('Device Alert Rule Filters', 'syslog'), '100%', '', '3', 'center', $url);
	?>
	<tr class='even'><td><form id='device_rule_filter' action='syslog_device_rules.php'><table class='filterTable'><tr>
		<td><?php print __('Search', 'syslog'); ?></td><td><input type='text' id='filter' size='25' placeholder='<?php print __esc('Enter a search term', 'syslog'); ?>' value='<?php print html_escape_request_var('filter'); ?>'></td>
		<td><?php print __('Enabled', 'syslog'); ?></td><td><select id='enabled'><option value='-1'><?php print __('All', 'syslog'); ?></option><option value='1'<?php if (get_request_var('enabled') == '1') print ' selected'; ?>><?php print __('Yes', 'syslog'); ?></option><option value='0'<?php if (get_request_var('enabled') == '0') print ' selected'; ?>><?php print __('No', 'syslog'); ?></option></select></td>
		<td><?php print __('Rules', 'syslog'); ?></td><td><select id='rows'><option value='-1'><?php print __('Default', 'syslog'); ?></option><?php foreach ($item_rows as $key => $value) { print '<option value="' . $key . '"' . (get_request_var('rows') == $key ? ' selected' : '') . '>' . $value . '</option>'; } ?></select></td>
		<td><span><input id='refresh' type='button' value='<?php print __esc('Go', 'syslog'); ?>'><input id='clear' type='button' value='<?php print __esc('Clear', 'syslog'); ?>'></span></td>
	</tr></table><input type='hidden' id='page' value='<?php print get_filter_request_var('page'); ?>'></form>
	<script>
	(function () {
		var filter = document.getElementById('filter'), enabled = document.getElementById('enabled'), rows = document.getElementById('rows');
		function apply(clear) { var query = clear ? '' : new URLSearchParams({filter: filter.value, enabled: enabled.value, rows: rows.value}).toString(); window.location = 'syslog_device_rules.php' + (query ? '?' + query : ''); }
		document.getElementById('refresh').addEventListener('click', function () { apply(false); }); document.getElementById('clear').addEventListener('click', function () { apply(true); });
		enabled.addEventListener('change', function () { apply(false); }); rows.addEventListener('change', function () { apply(false); }); document.getElementById('device_rule_filter').addEventListener('submit', function (event) { event.preventDefault(); apply(false); });
	}());
	</script></td></tr>
	<?php
	html_end_box();

	$sql_where = '';
	$sql_params = [];
	if (get_request_var('filter') !== '') {
		$sql_where = 'WHERE (host LIKE ? OR notes LIKE ?)';
		$sql_params = ['%' . get_request_var('filter') . '%', '%' . get_request_var('filter') . '%'];
	}
	if (get_request_var('enabled') !== '-1') {
		$sql_where .= ($sql_where === '' ? 'WHERE ' : ' AND ') . "enabled " . (get_request_var('enabled') == '1' ? "= 'on'" : "<> 'on'");
	}
	$rows = get_request_var('rows') == '-1' ? (int) read_config_option('num_rows_table') : (int) get_request_var('rows');
	$rows = max(1, $rows);
	$total_rows = (int) syslog_db_fetch_cell_prepared("SELECT COUNT(*) FROM `$syslogdb_default`.`syslog_device_rule` $sql_where", $sql_params);
	$page = max(1, (int) get_request_var('page'));
	$offset = ($page - 1) * $rows;
	$rules = syslog_db_fetch_assoc_prepared("SELECT * FROM `$syslogdb_default`.`syslog_device_rule` $sql_where ORDER BY host LIMIT $offset, $rows", $sql_params);
	if (!is_array($rules)) {
		$rules = [];
	}
	$nav = html_nav_bar('syslog_device_rules.php?filter=' . urlencode(get_request_var('filter')) . '&enabled=' . get_request_var('enabled') . '&rows=' . $rows, MAX_DISPLAY_PAGES, $page, $rows, $total_rows, 4, __('Rules', 'syslog'), 'page', 'main');
	form_start('syslog_device_rules.php', 'chk');
	print $nav;
	html_start_box(__('Device Alert Rules', 'syslog'), '100%', '', '3', 'center', '');
	$display_text = ['host' => [__('Device', 'syslog'), 'ASC'], 'handling' => [__('Handling', 'syslog'), 'ASC'], 'pass' => [__('Pass Through', 'syslog'), 'ASC'], 'maintenance' => [__('Maintenance', 'syslog'), 'ASC']];
	html_header_sort_checkbox($display_text, 'host', 'ASC');
	if (!cacti_sizeof($rules)) {
		print '<tr><td colspan="5"><em>' . __('No device alert rules found.', 'syslog') . '</em></td></tr>';
	}
	foreach ($rules as $rule) {
		$handling = $rule['mute_mode'] === 'until' ? __esc('Paused until %s', date('Y-m-d H:i', (int) $rule['mute_until']), 'syslog') : ($rule['mute_mode'] === 'indefinite' ? __('Paused indefinitely', 'syslog') : __('Not paused', 'syslog'));
		$pass = (int) $rule['pass_through_priority'] >= 0 ? ucfirst($syslog_levels[$rule['pass_through_priority']]) . ' ' . __('and more urgent', 'syslog') : __('None', 'syslog');
		form_alternate_row('line' . $rule['id'], true);
		form_selectable_cell(filter_value(html_escape($rule['host']) . ($rule['enabled'] === 'on' ? '' : ' (' . __('Disabled', 'syslog') . ')'), get_request_var('filter'), $config['url_path'] . 'plugins/syslog/syslog_device_rules.php?action=edit&id=' . $rule['id']), $rule['id']);
		form_selectable_cell($handling, $rule['id']);
		form_selectable_cell($pass, $rule['id']);
		form_selectable_cell($rule['allow_maintenance'] === 'on' ? __('Allow all', 'syslog') : __('Use priority exception', 'syslog'), $rule['id']);
		form_checkbox_cell($rule['host'], $rule['id']);
		form_end_row();
	}
	html_end_box(false);
	if (cacti_sizeof($rules)) {
		print $nav;
	}
	draw_actions_dropdown($syslog_actions);
	form_end(false);
	if (isset($_SESSION['syslog_device_rule_exporter'])) {
		syslog_download_frame('syslog_device_rules.php?action=export&selected_items=' . $_SESSION['syslog_device_rule_exporter']);
		kill_session_var('syslog_device_rule_exporter');
		exit;
	}
}
