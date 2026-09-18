<?php
// Panel resize persistence: the resize endpoint must re-verify ownership
// and store clamped sizes without touching the panel definition.
$_SESSION['sess_user_id'] = 1;

function __($text, ...$args) { return $text; }
function get_filter_request_var($name, $filter = null) {
	return $GLOBALS['request'][$name] ?? false;
}
function get_nfilter_request_var($name) {
	return $GLOBALS['request'][$name] ?? '';
}
function get_username($id) { return 'admin'; }
function syslog_dashboard_load($id) {
	// Only dashboard 5 exists for the current user.
	return $id == 5 ? ['id' => 5, 'user' => 'admin', 'is_global' => ''] : null;
}
function syslog_dashboard_load_panel($id) {
	$panels = [
		7 => [
			'id' => 7, 'dashboard_id' => 5, 'source' => 'syslog', 'kind' => 'timeseries',
			'chart' => 'line', 'field' => 'host', 'interval' => 'dashboard',
			'timespan' => 'dashboard', 'removal' => '1', 'top_n' => 10,
			'width' => 1, 'height' => 0,
			'dashboard_user' => 'admin', 'dashboard_global' => ''
		],
		9 => [
			'id' => 9, 'dashboard_id' => 6, 'source' => 'syslog', 'kind' => 'timeseries',
			'chart' => 'line', 'field' => 'host', 'interval' => 'dashboard',
			'timespan' => 'dashboard', 'removal' => '1', 'top_n' => 10,
			'width' => 1, 'height' => 0,
			'dashboard_user' => 'admin', 'dashboard_global' => ''
		]
	];
	return $panels[$id] ?? null;
}
$GLOBALS['updates'] = [];
function syslog_db_execute_prepared($sql, $params) {
	$GLOBALS['updates'][] = ['sql' => $sql, 'params' => $params];
	return true;
}
// The dialog path validates the expression; stub it out for resize tests.
function syslog_parse_logical_search($input) { return ['term', $input]; }
function syslog_logical_search_sql($tree, $field) { return '1'; }
function check($actual, $expected, $label) {
	if ($actual !== $expected) {
		throw new RuntimeException($label . ': ' . var_export($actual, true));
	}
}

$source = file_get_contents(__DIR__ . '/../../lib/syslog_dashboard.php');

// Pull in the allowlist helpers, settings validation, and save endpoint.
$helpers = [
	'function syslog_dashboard_sources()',
	'function syslog_dashboard_kinds()',
	'function syslog_dashboard_charts(',
	'function syslog_dashboard_fields()',
	'function syslog_dashboard_intervals()',
	'function syslog_dashboard_timespans()',
	'function syslog_dashboard_removals()',
	'function syslog_dashboard_top_n_cap()',
	'function syslog_dashboard_width_cap()',
	'function syslog_dashboard_height_min()',
	'function syslog_dashboard_height_cap()',
	'function syslog_dashboard_panel_settings(',
	'function syslog_dashboard_username(',
	'function syslog_dashboard_can_view(',
	'function syslog_dashboard_can_edit(',
	'function syslog_dashboard_panel_owner('
];

// functions.php provides these realm helpers; ownership of an owned row
// short-circuits before they are consulted, so a simple stub suffices.
if (!function_exists('syslog_dashboard_admin')) {
	function syslog_dashboard_admin() {
		return false;
	}
}
if (!function_exists('syslog_dashboard_share')) {
	function syslog_dashboard_share() {
		return false;
	}
}

foreach ($helpers as $marker) {
	$from = strpos($source, $marker);
	$to   = strpos($source, "\n}", $from) + 2;
	eval(substr($source, $from, $to - $from));
}

$start = strpos($source, 'function syslog_dashboard_panel_save()');
$end   = strpos($source, '/**', $start);
eval(substr($source, $start, $end - $start));

// A resize on an owned panel persists the clamped size.
$GLOBALS['request'] = [
	'dashboard_id' => 5, 'panel_id' => 7, 'panel_resize' => '1',
	'width' => '3', 'height' => '480'
];
$result = json_decode(syslog_dashboard_panel_save(), true);
check($result, ['id' => 7, 'width' => 3, 'height' => 480], 'resize result');
check($GLOBALS['updates'][0]['params'], [3, 480, 7], 'resize update');

// Out-of-range sizes are rejected.
$GLOBALS['request'] = [
	'dashboard_id' => 5, 'panel_id' => 7, 'panel_resize' => '1',
	'width' => '9', 'height' => '99999'
];
$result = json_decode(syslog_dashboard_panel_save(), true);
check(isset($result['error']), true, 'oversize rejected');

// A resize on another user's panel is refused.
$GLOBALS['request'] = [
	'dashboard_id' => 5, 'panel_id' => 9, 'panel_resize' => '1',
	'width' => '2', 'height' => '400'
];
$result = json_decode(syslog_dashboard_panel_save(), true);
check($result, ['error' => 'Dashboard panel not found.'], 'foreign panel refused');

// A dialog save without size fields keeps the persisted size.
$GLOBALS['request'] = [
	'dashboard_id' => 5, 'panel_id' => 7, 'panel_resize' => '',
	'title' => 'Kept', 'source' => 'syslog', 'kind' => 'timeseries', 'chart' => 'line',
	'field' => 'host', 'interval' => 'dashboard', 'timespan' => 'dashboard',
	'removal' => '1', 'top_n' => '10'
];
$result = json_decode(syslog_dashboard_panel_save(), true);
check($result, ['id' => 7], 'dialog save result');
$last = end($GLOBALS['updates']);
check($last['params'][10], 1, 'width preserved');
check($last['params'][11], 0, 'height preserved');

echo "panel_resize_test passed\n";