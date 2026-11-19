<?php
// Render the real message-row markup with marked, unmarked, and grouped fixtures.
require dirname(__DIR__) . '/bootstrap-unit.php';
syslog_load_plugin_source('includes/functions.php');
syslog_load_plugin_source('includes/recommendations.php');
function form_selectable_cell($value, $id, $width = '', $class = '') { print '<td class="' . html_escape($class) . '" style="width:' . html_escape($width) . '">' . $value . '</td>'; }
function form_end_row() { print '</tr>'; }
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$recommendation_header = ['display' => '<i class="fa fa-lightbulb syslogRecommendationHeading" role="img" aria-label="Recommendations"></i>', 'align' => 'center'];
$recommendations = [true, false];
$start = strpos($source, "\t\t\$display_text = [", strpos($source, 'function syslog_messages('));
$end = strpos($source, "\n\t\t// Add count column", $start);
eval(substr($source, $start, $end - $start));
$start = strpos($source, "\t\tif (cacti_sizeof(\$syslog_messages)) {");
$end = strpos($source, "\n\t\thtml_end_box(false);", $start);
$rows = substr($source, $start, $end - $start);
$syslogdb_default = 'syslog';
$breach_sql = syslog_message_breach_sql();
$hosts = [1 => 'router']; $facilities = [1 => 'user']; $priorities = [3 => 'err'];
$syslog_incoming_config = ['id' => 'seq', 'textField' => 'message'];
$base = ['seq' => 101, 'facility_id' => 1, 'priority_id' => 3, 'program' => 'app', 'host_id' => 1, 'logtime' => '2026-10-15 12:00:00', 'message' => 'Example message', 'mtype' => 'main'];
$syslog_messages = [$base + ['threshold_breached' => 1], array_replace($base, ['seq' => 102, 'threshold_breached' => null])];
$grouping_enabled = false;
$trim = 0;
function fixture_log_headers($headers) {
	foreach ($headers as $key => $header) {
		$label = $header['display'] ?? $header[0];
		print '<th title="' . html_escape($header['tip'] ?? '') . '">';
		print str_starts_with($key, 'nosort') ? $label : '<div class="sortinfo" sort-column="' . html_escape($key) . '"><div class="textSubHeaderDark">' . html_escape($label) . '</div></div>';
		print '</th>';
	}
}
print '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/main.css"><link rel="stylesheet" href="/jquery-ui.css"><link rel="stylesheet" href="/search.css"><style>body{font:14px Arial}table{border-collapse:collapse;width:100%}td,th{padding:8px;border:1px solid #ddd}th{text-align:left}</style><script src="/jquery.js"></script><script src="/jquery-ui.js"></script><script src="/table-sizing.js"></script><script src="/functions.js"></script></head><body><div id="syslog_workspace"><div class="syslogResultsMain"><div class="cactiTable"><table id="individual" class="cactiTable"><thead><tr class="tableHeader">';
fixture_log_headers($display_text);
print '</tr></thead><tbody>';
eval($rows);
print '</tbody></table></div><table id="grouped"><tbody>';
$grouping_enabled = true;
$sql_where = '';
$syslog_messages = [$base + ['threshold_breached' => 1, 'occurrence_count' => 1000000, 'latest_seq' => 102]];
$group_token = syslog_group_context($syslog_messages, 'WHERE logtime BETWEEN \'2026-10-15 00:00:00\' AND \'2026-10-15 23:59:59\'', 0, [true]);
test_override('syslog_db_fetch_assoc_prepared', static function () { throw new RuntimeException('Group members must not be queried while rendering summaries'); });
eval($rows);
print '</tbody></table>';
$start = strpos($source, "\t\t\$display_text = [", $end);
$end = strpos($source, "\n\t\t\$nav =", $start);
eval(substr($source, $start, $end - $start));
print '<div class="cactiTable"><table id="alerts" class="cactiTable"><thead><tr class="tableHeader">';
fixture_log_headers($display_text);
print '</tr></thead><tbody>';
$syslog_messages = [[
	'seq' => 301, 'logtime' => $base['logtime'], 'host' => 'router.example.test',
	'severity' => 2, 'name' => str_repeat('LongAlertName', 12), 'count' => 12345,
	'facility' => 'local7', 'priority' => 'warning', 'logmsg' => str_repeat('Alert message with details ', 40)
]];
$severities = [2 => 'Critical'];
$config['url_path'] = '/';
$start = strpos($source, "\t\tif (cacti_sizeof(\$syslog_messages)) {", $end);
$end = strpos($source, "\n\t\thtml_end_box(false);", $start);
$alert_rows = substr($source, $start, $end - $start);
eval($alert_rows);
$trim = -1;
$syslog_messages[0]['seq'] = 302;
eval($alert_rows);
print '</tbody></table></div></div>';
$details = [];
foreach (range(201, 252) as $seq) {
	$details[] = ['seq' => $seq, 'logtime' => $base['logtime'], 'message' => $base['message'], 'host' => 'router', 'program' => 'app', 'facility' => 'user', 'priority' => 'err', 'threshold_breached' => $seq % 2];
}
print '<template id="group-page-0">' . syslog_group_detail_rows(array_slice($details, 0, 50), 'main', 0, true) . '</template>';
print '<template id="group-page-50">' . syslog_group_detail_rows(array_slice($details, 50), 'main', 0, true) . '</template>';
$start = strpos($source, "<aside id='syslog_message_details'");
$end = strpos($source, '</aside></div>', $start) + strlen('</aside></div>');
eval('?>' . substr($source, $start, $end - $start));
print '<script>var csrfMagicToken="fixture";$(function(){applyTableSizing();});initSyslogMessagesDisplay();initSyslogWorkspace();initSyslogValueFilters();</script></body></html>';
