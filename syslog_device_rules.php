<?php
/* Device-wide Syslog alert handling rules. */

chdir('../../');
include('./include/auth.php');
include_once(__DIR__ . '/setup.php');
include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/database.php');

syslog_connect();
set_default_action();

switch (get_request_var('action')) {
	case 'save':
		device_rule_save();
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
		header('Location: syslog_device_rules.php?header=false&action=edit&id=' . get_filter_request_var('id'));
		return;
	}
	$host = trim(get_nfilter_request_var('host'));
	if ($host === '') {
		raise_message('syslog_device_rule_host', __('A device name is required.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: syslog_device_rules.php?header=false&action=edit&id=' . get_filter_request_var('id'));
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
	header('Location: syslog_device_rules.php?header=false');
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
		'host' => ['method' => 'textbox', 'friendly_name' => __('Device', 'syslog'), 'description' => __('This rule applies to every Syslog alert matching this device.', 'syslog'), 'value' => '|arg1:host|', 'size' => 50, 'max_length' => 64],
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
	$(function () {
		$('#mute_until').datetimepicker({minuteGrid:10, stepMinute:1, timeFormat:'HH:mm', dateFormat:'yy-mm-dd'});
		function toggleEnd() { $('#row_mute_until').toggle($('#mute_mode').val() === 'until'); }
		$('#mute_mode').on('change', toggleEnd); toggleEnd();
		<?php if (!syslog_allow_edits()) { ?>$('#syslog_device_rule_edit').find('select,input,textarea').prop('disabled', true);<?php } ?>
	});
	</script>
	<?php
}

function device_rule_list(): void {
	global $syslogdb_default, $syslog_levels;
	$rules = syslog_db_fetch_assoc("SELECT * FROM `$syslogdb_default`.`syslog_device_rule` ORDER BY host");
	$url = syslog_allow_edits() ? 'syslog_device_rules.php?action=edit' : '';
	html_start_box(__('Device Alert Rules', 'syslog'), '100%', '', '3', 'center', $url);
	html_header([__('Device', 'syslog'), __('Handling', 'syslog'), __('Pass Through', 'syslog'), __('Maintenance', 'syslog'), __('Actions', 'syslog')], 2);
	if (!cacti_sizeof($rules)) {
		print '<tr><td colspan="5"><em>' . __('No device alert rules found.', 'syslog') . '</em></td></tr>';
	}
	foreach ($rules as $rule) {
		$handling = $rule['mute_mode'] === 'until' ? __esc('Paused until %s', date('Y-m-d H:i', (int) $rule['mute_until']), 'syslog') : ($rule['mute_mode'] === 'indefinite' ? __('Paused indefinitely', 'syslog') : __('Not paused', 'syslog'));
		$pass = (int) $rule['pass_through_priority'] >= 0 ? ucfirst($syslog_levels[$rule['pass_through_priority']]) . ' ' . __('and more urgent', 'syslog') : __('None', 'syslog');
		$actions = '<a href="syslog_device_rules.php?action=edit&id=' . (int) $rule['id'] . '">' . __('Edit', 'syslog') . '</a>';
		form_alternate_row();
		print '<td>' . html_escape($rule['host']) . ($rule['enabled'] === 'on' ? '' : ' (' . __('Disabled', 'syslog') . ')') . '</td><td>' . $handling . '</td><td>' . $pass . '</td><td>' . ($rule['allow_maintenance'] === 'on' ? __('Allow all', 'syslog') : __('Use priority exception', 'syslog')) . '</td><td>' . $actions . '</td>';
		form_end_row();
	}
	html_end_box();
}
