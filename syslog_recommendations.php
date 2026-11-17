<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

// Console management of the syslog message recommendations.

chdir('../../');
include('./include/auth.php');
include_once('./plugins/syslog/includes/functions.php');
include_once('./plugins/syslog/includes/database.php');
include_once('./plugins/syslog/includes/recommendations.php');

if (!syslog_realm_allowed('Syslog Administration')) {
	die(__('Permission denied.', 'syslog'));
}

syslog_connect();
set_default_action();

if ((isset_request_var('import') || isset_request_var('save_component_import')) && syslog_allow_edits()) {
	set_request_var('action', 'import');
}

switch (get_request_var('action')) {
	case 'save':
		recommendation_save();

		break;
	case 'actions':
		recommendation_actions();

		break;
	case 'export_all':
		recommendation_export_all();

		break;
	case 'import':
		if (isset_request_var('save_component_import')) {
			recommendation_import();
		} else {
			top_header();
			syslog_include_js();
			recommendation_import_form();
			bottom_footer();
		}

		break;
	case 'edit':
		top_header();
		syslog_include_js();
		recommendation_edit();
		bottom_footer();

		break;
	default:
		top_header();
		syslog_include_js();
		recommendation_list();
		bottom_footer();
}

/**
 * The bulk actions offered on the list.
 *
 * @return array<int, string> The action labels.
 */
function recommendation_actions_list(): array {
	return [1 => __('Delete', 'syslog'), 2 => __('Export', 'syslog')];
}

/**
 * The request filters shared by the list and the filtered export.
 *
 * @return array<string, array<string, mixed>> The filter definitions.
 */
function recommendation_filters(): array {
	return [
		'rows'           => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => '-1'],
		'page'           => ['filter' => FILTER_VALIDATE_INT, 'default' => '1'],
		'filter'         => ['filter' => FILTER_DEFAULT, 'pageset' => true, 'default' => ''],
		'vendor'         => ['filter' => FILTER_DEFAULT, 'pageset' => true, 'default' => '-1'],
		'severity'       => ['filter' => FILTER_VALIDATE_INT, 'pageset' => true, 'default' => '-1'],
		'sort_column'    => ['filter' => FILTER_CALLBACK, 'default' => 'identifier', 'options' => ['options' => 'sanitize_search_string']],
		'sort_direction' => ['filter' => FILTER_CALLBACK, 'default' => 'ASC', 'options' => ['options' => 'sanitize_search_string']]
	];
}

/**
 * Build the WHERE clause for the current filters.
 *
 * @return array{0: string, 1: list<mixed>} The clause (with leading WHERE, or empty) and its bind values.
 */
function recommendation_where(): array {
	$where  = [];
	$params = [];

	if ((string) get_request_var('filter') !== '') {
		$like     = '%' . get_request_var('filter') . '%';
		$where[]  = '(identifier LIKE ? OR vendor LIKE ? OR component LIKE ? OR message LIKE ? OR explanation LIKE ? OR recommended_action LIKE ?)';
		$params   = array_merge($params, array_fill(0, 6, $like));
	}

	if ((string) get_request_var('vendor') !== '-1') {
		$where[]  = 'vendor = ?';
		$params[] = (string) get_request_var('vendor');
	}

	if ((string) get_request_var('severity') !== '-1') {
		$where[]  = 'severity = ?';
		$params[] = (int) get_request_var('severity');
	}

	return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

/**
 * Add or update a recommendation from the edit form.
 *
 * @return void
 */
function recommendation_save(): void {
	if (!syslog_allow_edits() || !isset_request_var('save_component_recommendation')) {
		header('Location: syslog_recommendations.php');

		return;
	}

	$id     = (int) get_filter_request_var('id');
	$return = 'syslog_recommendations.php?action=edit&id=' . $id;
	$input  = [];

	foreach (['vendor', 'identifier', 'component', 'severity', 'message', 'explanation', 'recommended_action', 'reference'] as $field) {
		$input[$field] = trim((string) get_nfilter_request_var($field));
	}
	$record = syslog_recommendation_normalize($input);

	if ($record === null) {
		raise_message('syslog_recommendation_invalid', __('Enter an identifier and at least an explanation or a recommended action.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: ' . $return);

		return;
	}

	$duplicate = syslog_db_fetch_cell_prepared('SELECT id FROM syslog_recommendations WHERE vendor = ? AND identifier = ? AND id <> ?',
		[$record['vendor'], $record['identifier'], $id]);

	if (!empty($duplicate)) {
		raise_message('syslog_recommendation_duplicate', __('A recommendation for this vendor and identifier already exists.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: ' . $return);

		return;
	}

	$values = [$record['vendor'], $record['identifier'], $record['component'], $record['severity'], $record['message'],
		$record['explanation'], $record['recommended_action'], $record['reference']];

	if ($id > 0) {
		$ok = syslog_db_execute_prepared('UPDATE syslog_recommendations SET vendor = ?, identifier = ?, component = ?, severity = ?,
			message = ?, explanation = ?, recommended_action = ?, reference = ? WHERE id = ?', array_merge($values, [$id]));
	} else {
		$ok = syslog_db_execute_prepared('INSERT INTO syslog_recommendations
			(vendor, identifier, component, severity, message, explanation, recommended_action, reference)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $values);
	}

	if ($ok) {
		raise_message(1);
		header('Location: syslog_recommendations.php');
	} else {
		raise_message(2);
		header('Location: ' . $return);
	}
}

/**
 * Confirm, then apply, the bulk delete and export actions.
 *
 * @return void
 */
function recommendation_actions(): void {
	$actions = recommendation_actions_list();

	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[1-2]$/']]);

	if (!isset($actions[get_request_var('drp_action')])) {
		header('Location: syslog_recommendations.php');

		return;
	}

	if (isset_request_var('selected_items')) {
		$items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if (is_array($items)) {
			$ids = array_values(array_filter(array_map('intval', $items), static fn ($id) => $id > 0));

			if ($ids && get_request_var('drp_action') == '2') {
				recommendation_send_export('WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);

				return;
			}

			if ($ids && syslog_allow_edits()) {
				syslog_db_execute_prepared('DELETE FROM syslog_recommendations WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
			}
		}
		header('Location: syslog_recommendations.php');

		return;
	}

	$list  = '';
	$items = [];

	foreach ($_POST as $name => $value) {
		if (preg_match('/^chk_([0-9]+)$/', $name, $matches)) {
			$identifier = syslog_db_fetch_cell_prepared('SELECT identifier FROM syslog_recommendations WHERE id = ?', [(int) $matches[1]]);

			if ($identifier !== false && $identifier !== null) {
				$items[] = (int) $matches[1];
				$list   .= '<li>' . html_escape($identifier) . '</li>';
			}
		}
	}

	if (!cacti_sizeof($items)) {
		raise_message(40);
		header('Location: syslog_recommendations.php');

		return;
	}

	$export = get_request_var('drp_action') == '2';

	top_header();
	form_start('syslog_recommendations.php');
	html_start_box($actions[get_request_var('drp_action')], '60%', false, 3, 'center', '');
	print '<tr><td class="textArea"><p>' . ($export
		? __('Click \'Continue\' to Export the following Recommendation(s).', 'syslog')
		: __('Click \'Continue\' to Delete the following Recommendation(s).', 'syslog')) . '</p><div class="itemlist"><ul>' . $list . '</ul></div></td></tr>';
	print '<tr><td align="right" class="saveRow"><input type="hidden" name="action" value="actions"><input type="hidden" name="selected_items" value="' . html_escape(serialize($items)) . '"><input type="hidden" name="drp_action" value="' . (int) get_request_var('drp_action') . '"><input type="button" value="' . __esc('Cancel', 'syslog') . '" class="cactiReturnTo">&nbsp;<input type="submit" value="' . __esc('Continue', 'syslog') . '"></td></tr>';
	html_end_box();
	syslog_export_form_end($export);
	bottom_footer();
}

/**
 * Send recommendations as a JSON download.
 *
 * @param string          $where  The WHERE clause, or ''.
 * @param array<int,mixed> $params The bind values.
 *
 * @return void
 */
function recommendation_send_export(string $where, array $params): void {
	header('Content-Type: application/json; charset=UTF-8');
	header('Content-Disposition: attachment; filename=syslog_recommendations_export.json');
	syslog_recommendations_export($where, $params);
}

/**
 * Export every recommendation that matches the list filters.
 *
 * @return void
 */
function recommendation_export_all(): void {
	validate_store_request_vars(recommendation_filters(), 'sess_syslog_recommendations');
	[$where, $params] = recommendation_where();

	recommendation_send_export($where, $params);
}

/**
 * Receive an import and report what happened.
 *
 * @return void
 */
function recommendation_import(): void {
	if (!syslog_allow_edits()) {
		header('Location: syslog_recommendations.php');

		return;
	}

	if (function_exists('set_time_limit')) {
		@set_time_limit(0);
	}

	$payload = syslog_recommendations_read_payload();
	$parsed  = $payload === false ? false : syslog_recommendations_parse($payload);

	if ($parsed === false || !$parsed['records']) {
		raise_message('syslog_import_error', __('Import rejected: the file is empty, too large, not valid JSON, or contains no usable recommendations.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: syslog_recommendations.php?action=import');

		return;
	}

	$result = syslog_recommendations_store($parsed['records'], get_nfilter_request_var('duplicates') === 'overwrite');

	raise_message('syslog_info', __esc('NOTE: Imported %d, updated %d and skipped %d existing Recommendation(s).', $result['imported'], $result['updated'], $result['skipped'], 'syslog'), MESSAGE_LEVEL_INFO);

	if ($result['failed'] || $parsed['invalid']) {
		raise_message('syslog_error', __esc('ERROR: %d Recommendation(s) were invalid or failed to import.', $result['failed'] + $parsed['invalid'], 'syslog'), MESSAGE_LEVEL_ERROR);
	}

	header('Location: syslog_recommendations.php');
}

/**
 * Display the import form.
 *
 * @return void
 */
function recommendation_import_form(): void {
	if (!syslog_allow_edits()) {
		header('Location: syslog_recommendations.php');
		exit;
	}

	$fields = [
		'import_file' => [
			'friendly_name' => __('Import Recommendations from Local File', 'syslog'),
			'description'   => __('Select a JSON file on your local machine. Use the Export function to see the expected format: a list of records with vendor, identifier, component, severity, message, explanation, recommended_action and reference.', 'syslog'),
			'method'        => 'file'
		],
		'import_text' => [
			'method'        => 'textarea',
			'friendly_name' => __('Import Recommendations from Text', 'syslog'),
			'description'   => __('Alternatively, paste the JSON document into this box.', 'syslog'),
			'value'         => '',
			'default'       => '',
			'textarea_rows' => '10',
			'textarea_cols' => '80',
			'class'         => 'textAreaNotes'
		],
		'duplicates' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Existing Recommendations', 'syslog'),
			'description'   => __('A recommendation is the same as an existing one when its vendor and identifier match.', 'syslog'),
			'array'         => ['skip' => __('Keep the existing recommendation', 'syslog'), 'overwrite' => __('Replace it with the imported one', 'syslog')],
			'value'         => 'skip',
			'default'       => 'skip'
		]
	];

	print "<form method='post' action='syslog_recommendations.php' enctype='multipart/form-data'>";

	html_start_box(__('Import Recommendations', 'syslog'), '100%', false, 3, 'center', '');
	draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => $fields]);
	html_end_box();

	form_hidden_box('save_component_import', '1', '');
	form_save_button('', 'import', 'id', false);
}

/**
 * Display the edit form.
 *
 * @return void
 */
function recommendation_edit(): void {
	$id  = (int) get_filter_request_var('id');
	$row = $id > 0 ? syslog_db_fetch_row_prepared('SELECT * FROM syslog_recommendations WHERE id = ?', [$id]) : [];

	if (!cacti_sizeof($row)) {
		$id  = 0;
		$row = ['vendor' => '', 'identifier' => '', 'component' => '', 'severity' => '', 'message' => '',
			'explanation' => '', 'recommended_action' => '', 'reference' => ''];
	}

	$severities = ['' => __('Not specified', 'syslog')];

	foreach (syslog_recommendation_severity_names() as $level => $name) {
		$severities[$level] = $name;
	}

	$text = static fn (string $name, string $friendly, string $description, int $rows = 4): array => [
		'method' => 'textarea', 'friendly_name' => $friendly, 'description' => $description,
		'value'  => '|arg1:' . $name . '|', 'textarea_rows' => $rows, 'textarea_cols' => 80, 'class' => 'textAreaNotes'
	];

	$fields = [
		'spacer0'    => ['method' => 'spacer', 'friendly_name' => __('Recommendation', 'syslog')],
		'vendor'     => ['method' => 'textbox', 'friendly_name' => __('Vendor', 'syslog'), 'description' => __('The supplier of the device or software that logs this message, for example Cisco or Juniper.', 'syslog'), 'value' => '|arg1:vendor|', 'size' => 40, 'max_length' => 64],
		'identifier' => ['method' => 'textbox', 'friendly_name' => __('Identifier', 'syslog'), 'description' => __('The message code that appears in the syslog message, for example %LINK-3-UPDOWN or RPD_OSPF_NBRDOWN. The match is not case sensitive.', 'syslog'), 'value' => '|arg1:identifier|', 'size' => 60, 'max_length' => 190],
		'component'  => ['method' => 'textbox', 'friendly_name' => __('Component', 'syslog'), 'description' => __('The facility, module or subsystem that raises the message.', 'syslog'), 'value' => '|arg1:component|', 'size' => 40, 'max_length' => 64],
		'severity'   => ['method' => 'drop_array', 'friendly_name' => __('Severity', 'syslog'), 'description' => __('The severity the vendor documents for this message.', 'syslog'), 'array' => $severities, 'value' => '|arg1:severity|', 'default' => ''],
		'message'            => $text('message', __('Message', 'syslog'), __('The message format as documented, including its variable placeholders.', 'syslog'), 3),
		'explanation'        => $text('explanation', __('Explanation', 'syslog'), __('What the message means.', 'syslog')),
		'recommended_action' => $text('recommended_action', __('Recommended Action', 'syslog'), __('What an operator should do about it.', 'syslog')),
		'reference'          => $text('reference', __('Reference', 'syslog'), __('Links to the vendor documentation, one per line.', 'syslog'), 2),
		'id'                 => ['method' => 'hidden_zero', 'value' => '|arg1:id|'],
		'save_component_recommendation' => ['method' => 'hidden', 'value' => '1']
	];

	$row['id'] = $id;

	form_start('syslog_recommendations.php', 'syslog_recommendation_edit');
	html_start_box($id ? __('Recommendation [edit]', 'syslog') : __('Recommendation [new]', 'syslog'), '100%', false, 3, 'center', '');
	draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => inject_form_variables($fields, $row)]);
	html_end_box();
	form_save_button('syslog_recommendations.php', '', 'id');

	if (!syslog_allow_edits()) {
		?>
		<script <?php print plugin_syslog_csp_nonce(); ?>>
		document.querySelectorAll('#syslog_recommendation_edit select,#syslog_recommendation_edit input,#syslog_recommendation_edit textarea').forEach(function (field) { field.disabled = true; });
		</script>
		<?php
	}
}

/**
 * Display the filterable list of recommendations.
 *
 * @return void
 */
function recommendation_list(): void {
	global $item_rows;

	validate_store_request_vars(recommendation_filters(), 'sess_syslog_recommendations');

	$columns = ['identifier' => 'identifier', 'vendor' => 'vendor', 'component' => 'component', 'severity' => 'severity'];

	if (!isset($columns[get_request_var('sort_column')])) {
		set_request_var('sort_column', 'identifier');
	}

	if (!in_array(strtoupper((string) get_request_var('sort_direction')), ['ASC', 'DESC'], true)) {
		set_request_var('sort_direction', 'ASC');
	}

	$vendors    = array_column(syslog_db_fetch_assoc('SELECT DISTINCT vendor FROM syslog_recommendations ORDER BY vendor') ?: [], 'vendor');
	$severities = syslog_recommendation_severity_names();
	$edits      = syslog_allow_edits();

	html_start_box(__('Syslog Recommendations', 'syslog'), '100%', false, 3, 'center', $edits ? 'syslog_recommendations.php?action=edit' : '');
	?>
	<tr class='even'><td><form id='recommendation_filter' action='syslog_recommendations.php'><table class='filterTable' role='presentation'><tr>
		<td><label for='filter'><?php print __('Search', 'syslog'); ?></label></td>
		<td><input type='text' id='filter' size='25' value='<?php print html_escape_request_var('filter'); ?>'></td>
		<td><label for='vendor'><?php print __('Vendor', 'syslog'); ?></label></td>
		<td><select id='vendor'>
			<option value='-1'<?php print (string) get_request_var('vendor') === '-1' ? ' selected' : ''; ?>><?php print __('All', 'syslog'); ?></option>
			<?php foreach ($vendors as $vendor) { ?>
			<option value='<?php print html_escape($vendor); ?>'<?php print (string) get_request_var('vendor') === $vendor ? ' selected' : ''; ?>><?php print $vendor === '' ? __('None', 'syslog') : html_escape($vendor); ?></option>
			<?php } ?>
		</select></td>
		<td><label for='severity'><?php print __('Severity', 'syslog'); ?></label></td>
		<td><select id='severity'>
			<option value='-1'<?php print (string) get_request_var('severity') === '-1' ? ' selected' : ''; ?>><?php print __('All', 'syslog'); ?></option>
			<?php foreach ($severities as $level => $name) { ?>
			<option value='<?php print $level; ?>'<?php print (string) get_request_var('severity') === (string) $level ? ' selected' : ''; ?>><?php print html_escape($name); ?></option>
			<?php } ?>
		</select></td>
		<td><label for='rows'><?php print __('Recommendations', 'syslog'); ?></label></td>
		<td><select id='rows'><option value='-1'<?php print get_request_var('rows') == '-1' ? ' selected' : ''; ?>><?php print __('Default', 'syslog'); ?></option><?php
			foreach ($item_rows as $key => $value) {
				print '<option value="' . $key . '"' . (get_request_var('rows') == $key ? ' selected' : '') . '>' . $value . '</option>';
			}
	?></select></td>
		<td><span>
			<input id='refresh' type='button' value='<?php print __esc('Go', 'syslog'); ?>'>
			<input id='clear' type='button' value='<?php print __esc('Clear', 'syslog'); ?>'>
			<?php if ($edits) { ?><input id='import' type='button' value='<?php print __esc('Import', 'syslog'); ?>'><?php } ?>
			<input id='export' type='button' value='<?php print __esc('Export', 'syslog'); ?>' title='<?php print __esc('Export every recommendation that matches the filters', 'syslog'); ?>'>
		</span></td>
	</tr></table></form>
	<script <?php print plugin_syslog_csp_nonce(); ?>>
	(function () {
		var filter = document.getElementById('filter'), vendor = document.getElementById('vendor'),
			severity = document.getElementById('severity'), rows = document.getElementById('rows');
		function query() {
			return new URLSearchParams({filter: filter.value, vendor: vendor.value, severity: severity.value, rows: rows.value});
		}
		function apply(clear) {
			var q = clear ? 'clear=1' : query().toString();
			window.location = 'syslog_recommendations.php?' + q;
		}
		document.getElementById('refresh').addEventListener('click', function () { apply(false); });
		document.getElementById('clear').addEventListener('click', function () { apply(true); });
		[vendor, severity, rows].forEach(function (el) { el.addEventListener('change', function () { apply(false); }); });
		document.getElementById('recommendation_filter').addEventListener('submit', function (event) { event.preventDefault(); apply(false); });
		var importButton = document.getElementById('import');
		if (importButton) importButton.addEventListener('click', function () { window.location = 'syslog_recommendations.php?action=import'; });
		// Download in a hidden frame so Cacti's page-unload spinner never starts.
		document.getElementById('export').addEventListener('click', function () {
			var q = query(); q.set('action', 'export_all');
			document.getElementById('syslog_download').src = 'syslog_recommendations.php?' + q.toString();
		});
	}());
	</script></td></tr>
	<?php
	html_end_box();

	[$where, $params] = recommendation_where();

	$rows = get_request_var('rows') == '-1' ? (int) read_config_option('num_rows_table') : (int) get_request_var('rows');
	$rows = max(1, $rows);
	$page = max(1, (int) get_request_var('page'));

	$total_rows = (int) syslog_db_fetch_cell_prepared("SELECT COUNT(*) FROM syslog_recommendations $where", $params);
	$sort       = $columns[get_request_var('sort_column')];
	$direction  = strtoupper((string) get_request_var('sort_direction')) === 'DESC' ? 'DESC' : 'ASC';
	$records    = syslog_db_fetch_assoc_prepared("SELECT id, vendor, identifier, component, severity, explanation, recommended_action
		FROM syslog_recommendations $where
		ORDER BY $sort $direction, id
		LIMIT " . (($page - 1) * $rows) . ", $rows", $params);
	$records = is_array($records) ? $records : [];

	$nav = html_nav_bar('syslog_recommendations.php?filter=' . urlencode((string) get_request_var('filter')), MAX_DISPLAY_PAGES, $page, $rows, $total_rows, 6, __('Recommendations', 'syslog'), 'page', 'main');

	form_start('syslog_recommendations.php', 'chk');
	print $nav;
	html_start_box('', '100%', false, 3, 'center', '');

	$display_text = [
		'identifier'         => [__('Identifier', 'syslog'), 'ASC'],
		'vendor'             => [__('Vendor', 'syslog'), 'ASC'],
		'component'          => [__('Component', 'syslog'), 'ASC'],
		'severity'           => [__('Severity', 'syslog'), 'ASC'],
		'nosort_explanation' => ['display' => __('Explanation', 'syslog')],
		'nosort_action'      => ['display' => __('Recommended Action', 'syslog')]
	];
	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	if (!$records) {
		print '<tr><td colspan="7"><em>' . __('No recommendations found.', 'syslog') . '</em></td></tr>';
	}

	foreach ($records as $record) {
		$id = (int) $record['id'];
		form_alternate_row('line' . $id, true);
		form_selectable_cell(filter_value(html_escape($record['identifier']), get_request_var('filter'), 'syslog_recommendations.php?action=edit&id=' . $id), $id);
		form_selectable_cell(html_escape($record['vendor']), $id);
		form_selectable_cell(html_escape($record['component']), $id);
		form_selectable_cell($record['severity'] === null ? '' : html_escape($severities[(int) $record['severity']] ?? ''), $id);
		form_selectable_cell(html_escape(title_trim(str_replace("\n", ' ', (string) $record['explanation']), 90)), $id);
		form_selectable_cell(html_escape(title_trim(str_replace("\n", ' ', (string) $record['recommended_action']), 90)), $id);
		form_checkbox_cell($record['identifier'], $id);
		form_end_row();
	}
	html_end_box(false);

	if ($records) {
		print $nav;
	}

	draw_actions_dropdown(recommendation_actions_list());
	form_end(false);
	syslog_download_frame();
}
