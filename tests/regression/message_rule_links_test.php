<?php
require dirname(__DIR__, 2) . '/functions.php';
$allowed = [];
function api_plugin_user_realm_auth($page) { global $allowed; return in_array($page, $allowed, true); }
function rule_assert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
rule_assert(syslog_message_rule_links(42, 'main', '2026-09-13 00:12:42') === [], 'Readers have no rule actions');
$allowed = ['syslog_alerts.php'];
$links = syslog_message_rule_links(42, 'main', '2026-09-13 00:12:42');
rule_assert(isset($links['alarm']) && !isset($links['removal']), 'Each rule action checks its own realm');
$allowed[] = 'syslog_removal.php';
$links = syslog_message_rule_links(43, 'main', '2026-09-13 00:12:42');
foreach (['alarm' => 'syslog_alerts.php', 'removal' => 'syslog_removal.php'] as $action => $page) {
 rule_assert(strpos($links[$action], $page . '?') === 0, 'Correct editor');
 parse_str(parse_url($links[$action], PHP_URL_QUERY), $query);
 rule_assert($query['id'] === '43' && $query['action'] === 'newedit' && $query['date'] === '2026-09-13 00:12:42', 'Editor targets the selected record');
}
foreach (['removed', 'alerts', ''] as $source) rule_assert(syslog_message_rule_links(42, $source, '') === [], 'Unsupported source must not resolve an unrelated main record');
rule_assert(syslog_message_rule_links('42&evil=1', 'main', '') === [], 'IDs must be numeric');
foreach ([0 => 'logEmergency', 1 => 'logAlert', 2 => 'logCritical', 3 => 'logError', 4 => 'logWarning', 5 => 'logNotice', 6 => 'logInfo', 7 => 'logDebug'] as $id => $class) rule_assert(syslog_priority_class($id) === $class, 'Severity colors retained');
rule_assert(syslog_search_has_time(syslog_parse_logical_search('host = "router" OR logtime last "604800"')), 'Authored date condition preserved');
rule_assert(!syslog_search_has_time(syslog_parse_logical_search('message contains "logtime"')), 'Literal date field name is not a date predicate');
echo "message_rule_links_test passed\n";
