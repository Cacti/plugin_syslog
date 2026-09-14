<?php
require_once dirname(__DIR__, 2) . '/functions.php';

function values_assert($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}
function syslog_db_fetch_assoc($sql) {
	return [['id' => 4, 'name' => 'warning']];
}
function syslog_db_fetch_assoc_prepared($sql, $params) {
	$GLOBALS['lookup_sql'] = $sql;
	$GLOBALS['lookup_params'] = $params;
	return [['value' => 4, 'label' => 'warning']];
}
$GLOBALS['syslogdb_default'] = 'syslog';
$choices = syslog_search_choices();
values_assert($choices['priority_id'] === [['4', 'warning (4)']], 'Dropdown stores ID with readable label');
values_assert($choices['facility'] === [['warning', 'warning']], 'Named dropdown stores database name');
values_assert(isset($choices['program_id'], $choices['facility_id']), 'ID dropdowns populated');
$results = syslog_search_suggestions('host', "a%_!'", 'syslog', '-1');
values_assert($results === [['value' => '4', 'label' => 'warning']], 'Suggestion values normalized to strings');
values_assert($GLOBALS['lookup_params'] === ["%a!%!_!!'%", "%a!%!_!!'%"], 'Literal search safely parameterized');
values_assert(str_contains($GLOBALS['lookup_sql'], 'syslog_hosts') && str_contains($GLOBALS['lookup_sql'], 'LIMIT 30'), 'Host suggestions bounded');
syslog_search_suggestions('message', 'error', 'syslog', '1');
values_assert(str_contains($GLOBALS['lookup_sql'], 'syslog_removed') && str_contains($GLOBALS['lookup_sql'], 'UNION ALL'), 'Both record sources suggested');
values_assert(substr_count($GLOBALS['lookup_sql'], 'LIMIT 1000') === 2, 'Message sample bounded per source');
syslog_search_suggestions('message', 'error', 'alerts', '-1');
values_assert(str_contains($GLOBALS['lookup_sql'], 'logmsg') && str_contains($GLOBALS['lookup_sql'], 'syslog_logs'), 'Alerts use alert message column');
$last_sql = $GLOBALS['lookup_sql'];
values_assert(syslog_search_suggestions('password', '', 'syslog', '-1') === [], 'Unknown fields rejected');
values_assert(syslog_search_suggestions('host_id', '', 'alerts', '-1') === [], 'Unavailable alert host ID rejected');
values_assert($GLOBALS['lookup_sql'] === $last_sql, 'Invalid fields do not execute SQL');
print "search_values_test passed\n";
