<?php
function get_selected_theme() { return 'classic'; }
function __($text, ...$args) { return $text; }
function __esc($text, ...$args) { return htmlspecialchars($text, ENT_QUOTES); }
function html_escape($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
$values = ['tab' => 'syslog', 'rows' => '-1', 'trimval' => '0', 'refresh' => '300', 'removal' => '1', 'grouping' => '0', 'page' => '1', 'rfilter' => 'host = "10.0.0.5" AND logtime last "86400"'];
function get_request_var($key) { global $values; return $values[$key] ?? ''; }
function get_nfilter_request_var($key) { return get_request_var($key); }
function get_filter_request_var($key) { return get_request_var($key); }
function get_request_var_request($key) { return get_request_var($key); }
function html_escape_request_var($key) { return html_escape(get_request_var($key)); }
function cacti_sizeof($value) { return count($value); }
function api_plugin_hook($name) {}
function api_plugin_user_realm_auth($page) { return true; }
require dirname(__DIR__, 2) . '/functions.php';
$saved_choices_json = '{}';
$saved_fields_json = html_escape(json_encode(['host'=>'Host','message'=>'Message','program'=>'Program','logtime'=>'Date','priority'=>'Priority']));
$saved_tree_json = html_escape(json_encode(['AND',['predicate','host','=','10.0.0.5'],['predicate','logtime','last','86400']]));
$saved_searches = [['id'=>7,'name'=>'Router investigation','is_global'=>'','user'=>'tester']];
$saved_active = 7; $saved_admin = true;
$item_rows = [50=>'50',100=>'100'];$trimvals = [0=>'Fit column',75=>'75 Chars',1024=>'All Text'];$page_refresh_interval = [0=>'Paused',300=>'5 Minutes'];
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$start = strpos($source, "<form id='syslog_form'"); $end = strpos($source, '</form>', $start)+7;
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/main.css"><link rel="stylesheet" href="/jquery-ui.css"><link rel="stylesheet" href="/search.css"><style>body{width:auto;margin:8px}table{border-collapse:collapse}th{text-align:left;padding:8px}</style><script src="/jquery.js"></script><script src="/jquery-ui.js"></script><script src="/jquery.timepicker.js"></script><script src="/functions.js"></script></head><body><h2>System Logs &nbsp; | &nbsp; Alert Logs</h2>';
eval('?>'.substr($source,$start,$end-$start));
echo '<div id="syslog_workspace"><div class="syslogResultsMain"><table><thead><tr><th>Time</th><th>Device</th><th>Program</th><th>Message</th><th>Facility</th><th>Severity</th></tr></thead><tbody>';
$priorities = ['emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug'];
foreach(range(1,17) as $i) echo '<tr class="syslogRow '.syslog_priority_class($i % 8).'"><td>2026-09-13 00:12:42</td><td>10.0.0.5</td><td>kernel</td><td class="syslogMessage">'.syslog_message_button('nf_conntrack: table full, dropping packet <script>alert("unsafe")</script>','10.0.0.5','kernel','kern',$priorities[$i % 8],'2026-09-13 00:12:42', 100 + $i, $i === 17 ? 'removed' : 'main').'</td><td>kern</td><td>'.$priorities[$i % 8].'</td></tr>';
echo '</tbody></table></div>';
$start = strpos($source,"<aside id='syslog_message_details'");$end = strpos($source,'</aside></div>', $start)+strlen('</aside></div>');
eval('?>'.substr($source,$start,$end-$start));
echo '<script>var csrfMagicToken="fixture"; var Pace={stop(){}}; $(function() { $("button, input[type=button]").button(); $("select").selectmenu({change:function(event,ui){$(this).val(ui.item.value).change()}}); }); initSyslogMain({pageTab:"syslog"}); initSyslogWorkspace(); window.posts=[];postSyslog=function(data){posts.push(data)};</script></body></html>';
