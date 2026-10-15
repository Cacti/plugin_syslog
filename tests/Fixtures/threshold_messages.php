<?php
// Render the real message-row markup with marked, unmarked, and grouped fixtures.
require dirname(__DIR__) . '/bootstrap-unit.php';
syslog_load_plugin_source('includes/functions.php');
function form_selectable_cell($value, ...$args) { print '<td>' . $value . '</td>'; }
function form_end_row() { print '</tr>'; }
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$start = strpos($source, "\t\t\$display_text = [", strpos($source, 'function syslog_messages('));
$end = strpos($source, "\n\t\t// Add count column", $start);
eval(substr($source, $start, $end - $start));
$start = strpos($source, "\t\tif (cacti_sizeof(\$syslog_messages)) {");
$end = strpos($source, "\n\t\thtml_end_box(false);", $start);
$rows = substr($source, $start, $end - $start);
$syslogdb_default = 'syslog';
$hosts = [1 => 'router']; $facilities = [1 => 'user']; $priorities = [3 => 'err'];
$syslog_incoming_config = ['id' => 'seq', 'textField' => 'message'];
$base = ['seq' => 101, 'facility_id' => 1, 'priority_id' => 3, 'program' => 'app', 'host_id' => 1, 'logtime' => '2026-10-15 12:00:00', 'message' => 'Example message', 'mtype' => 'main'];
$syslog_messages = [$base + ['threshold_breached' => 1], array_replace($base, ['seq' => 102, 'threshold_breached' => null])];
$grouping_enabled = false;
print '<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px Arial}table{border-collapse:collapse}td,th{padding:8px;border:1px solid #ddd}th{text-align:left}</style></head><body><table id="individual"><thead><tr>';
foreach ($display_text as $header) { print '<th>' . html_escape($header[0]) . '</th>'; }
print '</tr></thead><tbody>';
eval($rows);
print '</tbody></table><table id="grouped"><tbody>';
$grouping_enabled = true;
$syslog_messages = [$base + ['threshold_breached' => 1, 'occurrence_count' => 2, 'seq_list' => '101,102']];
test_override('syslog_db_fetch_assoc', static fn() => [$base + ['threshold_breached' => 1], array_replace($base, ['seq' => 102, 'threshold_breached' => null])]);
eval($rows);
print '</tbody></table></body></html>';
