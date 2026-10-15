<?php
require dirname(__DIR__) . '/bootstrap-unit.php';
syslog_load_plugin_source('lib/syslog_thresholds.php');
$config['url_path'] = '/cacti/';
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$start = strpos($source, 'function syslog_display_tabs(');
$end = strpos($source, "\n/**", $start);
eval(substr($source, $start, $end - $start));
$enabled = false;
$count = 0;
test_override('syslog_db_fetch_cell', static function ($sql) use (&$enabled, &$count) { return str_contains($sql, 'EXISTS') ? $enabled : $count; });
test_override('html_start_box', static function ($title) { print '<table><caption>' . html_escape($title) . '</caption>'; });
test_override('html_header', static function ($headers) { print '<tr>'; foreach ($headers as $header) { print '<th>' . html_escape($header) . '</th>'; } print '</tr>'; });
test_override('html_end_box', static function () { print '</table>'; });
print '<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px Arial}table{width:100%;border-collapse:collapse}td,th{padding:8px;text-align:left;border-bottom:1px solid #ddd}.tabs ul{display:flex;gap:16px;list-style:none}.selected{font-weight:bold}</style></head><body><section id="disabled">';
syslog_display_tabs('syslog');
print '</section><section id="healthy">';
$enabled = true;
syslog_display_tabs('breached');
test_override('syslog_db_fetch_assoc', static fn() => []);
syslog_breached_thresholds();
print '</section><section id="active">';
$count = 2;
syslog_display_tabs('breached');
test_override('syslog_db_fetch_assoc', static fn() => [
	['name' => 'Authentication failures', 'alert_id' => 7, 'level' => 1, 'scope_key' => 'server01', 'host_id' => 1, 'source_poller_id' => 2, 'breached_at' => 1792087200, 'last_match' => 1792087500, 'expires_at' => 1792088100],
	['name' => 'Shared threshold', 'alert_id' => 8, 'level' => 0, 'scope_key' => '', 'host_id' => null, 'source_poller_id' => 1, 'breached_at' => null, 'last_match' => 1792087500, 'expires_at' => 1792088100]
]);
syslog_breached_thresholds();
print '</section></body></html>';
