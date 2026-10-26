<?php
// Native API boundary fixture; validates outgoing events with Alarm Manager's real validator.
$config = ['poller_id' => 2];
$syslogdb_default = 'syslog';
$options = ['syslog_alarmmanager_enabled' => '', 'base_url' => 'https://cacti.example/'];
$installed = $enabled = $hook = true;
$response = 'accepted';
$submitted = [];
$logs = [];
function read_config_option($name) { return $GLOBALS['options'][$name] ?? ''; }
function api_plugin_installed($name) { return $GLOBALS['installed']; }
function api_plugin_is_enabled($name) { return $GLOBALS['enabled']; }
function db_fetch_cell_prepared(...$args) { return $GLOBALS['hook'] ? 1 : 0; }
function syslog_db_execute_prepared(...$args) { return true; }
function cacti_log($message, ...$args) { $GLOBALS['logs'][] = $message; }
function api_plugin_hook_function($name, $envelope) {
	if ($name !== 'alarmmanager_submit') { return $envelope; }
	$GLOBALS['submitted'][] = $envelope;
	alarmmanager_validate_event($envelope['event']);
	$response = $GLOBALS['response'];
	if ($response === 'throws') { throw new RuntimeException('secret message'); }
	if (!is_string($response)) { return $response; }
	$envelope['status'] = $response;
	if (in_array($response, ['accepted', 'already_accepted'], true)) { $envelope['event_row_id'] = 42; }
	return $envelope;
}
require dirname(__DIR__, 2) . '/includes/alarmmanager.php';
$validator = $argv[1] ?? dirname(__DIR__, 3) . '/plugin_alarmmanager/includes/events.php';
require $validator;
require dirname($validator) . '/lifecycle.php';
$checks = 0;
function check($condition, $message) { $GLOBALS['checks']++; if (!$condition) { throw new RuntimeException($message); } }
$rule = ['alarm_destination' => 'alarmmanager', 'id' => 5, 'name' => 'Test rule', 'method' => 0, 'severity' => 2, 'num' => 1];
$matches = [['seq' => 100, 'logtime' => '2026-10-26 10:00:00', 'host' => 'router.example', 'message' => 'sensitive test message', 'priority_id' => 3]];
check(!syslog_alarmmanager_forward($rule, $matches, 1) && !$submitted, 'Disabled forwarding keeps email');
$options['syslog_alarmmanager_enabled'] = 'on';
$syslog_rule = $rule;
unset($syslog_rule['alarm_destination']);
check(!syslog_alarmmanager_forward($syslog_rule, $matches, 1) && !$submitted, 'Existing rules default to Syslog Alarming even when forwarding is enabled');
foreach (['installed', 'enabled', 'hook'] as $availability) {
	$GLOBALS[$availability] = false;
	check(!syslog_alarmmanager_forward($rule, $matches, 1) && !$submitted, "$availability unavailable keeps email without calling hook");
	$GLOBALS[$availability] = true;
}
foreach (['throws', 'failed', 'rejected', 'unhandled', true, ['status' => 'accepted'], ['status' => 'accepted', 'event_row_id' => 42]] as $response) {
	check(!syslog_alarmmanager_forward($rule, $matches, 1), 'Non-acceptance keeps email');
}
$response = 'accepted';
check(syslog_alarmmanager_forward($rule, $matches, 1), 'Committed acceptance suppresses email');
$first = end($submitted)['event'];
check($first['severity'] === 'critical' && $first['transition'] === 'occurrence', 'Severity and occurrence mapping');
$response = 'already_accepted';
check(syslog_alarmmanager_forward($rule, $matches, 1), 'Durable duplicate suppresses email');
check(end($submitted)['event'] === $first, 'Retry sends identical event');
$response = 'accepted';
$matches[0]['seq']++;
check(syslog_alarmmanager_forward($rule, $matches, 1), 'Independent occurrence accepted');
$second = end($submitted)['event'];
check($second['event_id'] !== $first['event_id'] && $second['alarm_key'] !== $first['alarm_key'], 'Independent occurrence has distinct event and stream');
$empty_state = ['last_sequence' => 0, 'episode_id' => '', 'active' => 0, 'acknowledged' => 0];
check(alarmmanager_transition($empty_state, $first)[1] === 'occurrence' && alarmmanager_transition($empty_state, $second)[1] === 'occurrence', 'Real Alarm Manager lifecycle accepts each independent stream');
$rule['method'] = 1;
$rule['_threshold_state'] = ['source_poller_id' => 2, 'breach_key' => str_repeat('a', 40)];
check(syslog_alarmmanager_forward($rule, $matches, 5), 'Threshold raise accepted');
$raised = end($submitted)['event'];
check(syslog_alarmmanager_forward($rule, $matches, 0, true, 1793010000), 'Recovery accepted');
$clear = end($submitted)['event'];
check($clear['alarm_key'] === $raised['alarm_key'] && $clear['episode_id'] === $raised['episode_id'] && $clear['sequence'] > $raised['sequence'] && $clear['transition'] === 'recovered', 'Recovery correlates and advances sequence');
[$active_state, $disposition] = alarmmanager_transition($empty_state, $raised);
check($disposition === 'applied' && alarmmanager_transition($active_state, $clear)[1] === 'applied', 'Real Alarm Manager lifecycle applies correlated raise and clear');
check(syslog_alarmmanager_forward($rule, $matches, 0, true, 1793010000) && end($submitted)['event'] === $clear, 'Recovery retry is stable');
$before = count($submitted);
$rule['_threshold_state']['source_poller_id'] = 1;
check(!syslog_alarmmanager_forward($rule, $matches, 5) && count($submitted) === $before, 'Foreign breach owner cannot submit');
unset($rule['_threshold_state']);
$matches[0]['replication_source_poller_id'] = 1;
check(!syslog_alarmmanager_forward($rule, $matches, 1) && count($submitted) === $before, 'Replicated messages cannot resubmit');
check(!str_contains(implode(' ', $logs), 'secret') && !str_contains(implode(' ', $logs), 'sensitive'), 'Diagnostics exclude message and exception contents');
// Exercise the real notification call sites, with mail and Cacti persistence stubbed.
function __($text, ...$args) { return $text; }
function __esc($text, ...$args) { return $text; }
function html_escape($value) { return htmlspecialchars((string) $value); }
function cacti_sizeof($value) { return count($value); }
function cacti_escapeshellarg($value) { return escapeshellarg((string) $value); }
function clean_up_name($value) { return $value; }
function syslog_sql_save(...$args) { return 42; }
function mailer(...$args) { $GLOBALS['emails']++; }
require dirname(__DIR__, 2) . '/includes/functions.php';
$fixture = sys_get_temp_dir() . '/syslog-am-' . bin2hex(random_bytes(6));
mkdir($fixture . '/lib', 0700, true);
file_put_contents($fixture . '/lib/reports.php', '<?php');
$config['base_path'] = $fixture;
$severities = [2 => 'Critical'];
$syslog_levels = [3 => 'Error'];
$syslog_facilities = [1 => 'User'];
$debug = false;
$emails = 0;
$rule += ['email' => 'fixture@example.invalid', 'notify' => 0, 'repeat_alert' => 0, 'body' => '', 'message' => 'test', 'command' => '', 'level' => 0, 'open_ticket' => ''];
$matches[0]['facility_id'] = 1;
unset($matches[0]['replication_source_poller_id']);
try {
	foreach ([0, 1] as $method) {
		$rule['method'] = $method;
		foreach (['disabled', 'missing', 'plugin_disabled', 'no_hook', 'throws', 'failed', 'rejected', 'accepted', 'already_accepted'] as $case) {
			$options['syslog_alarmmanager_enabled'] = $case === 'disabled' ? '' : 'on';
			$installed = $case !== 'missing';
			$enabled = $case !== 'plugin_disabled';
			$hook = $case !== 'no_hook';
			$response = $case;
			$before = $emails;
			syslog_process_alert($rule, '', [], 1, '', $matches);
			check($emails === $before + (in_array($case, ['accepted', 'already_accepted'], true) ? 0 : 1), "Real email call site method $method: $case");
		}
	}
	$rule['alarm_destination'] = 'alarmmanager';
	$rule['command'] = '/bin/false';
	check(syslog_execute_alert_command($rule, ['message' => 'fixture', 'priority_id' => 3, 'facility_id' => 1], '') === true, 'Alarm Manager skips alert commands');
	check(syslog_execute_alert_command($rule, ['message' => 'fixture', 'priority_id' => 3, 'facility_id' => 1], '', 'clear') === true, 'Alarm Manager skips recovery commands');
	$rule['open_ticket'] = 'on';
	$options['syslog_ticket_command'] = '/bin/false';
	$before_logs = count($logs);
	syslog_execute_ticket_command($rule, ['router.example'], 'ticket failure %s %s %s');
	check(count($logs) === $before_logs, 'Alarm Manager skips configured ticket commands');
	$rule['open_ticket'] = '';
	$before = $emails;
	$rule['alarm_destination'] = 'syslog';
	$rule['command'] = '';
	$options['syslog_alarmmanager_enabled'] = 'on';
	$response = 'accepted';
	syslog_process_alert($rule, '', [], 1, '', $matches);
	check($emails === $before + 1, 'Explicit Syslog Alarming retains its email when Alarm Manager is available');
} finally {
	unlink($fixture . '/lib/reports.php');
	rmdir($fixture . '/lib');
	rmdir($fixture);
}
echo "Alarm Manager forwarding: $checks checks passed.\n";
