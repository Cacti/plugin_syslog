<?php

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 2;
	$GLOBALS['syslogdb_default'] = 'syslog';
	$GLOBALS['alarmmanager_test_events'] = [];
	test_override('read_config_option', static fn($name) => $name === 'syslog_alarmmanager_enabled' ? 'on' : 'https://cacti.example/');
	test_override('api_plugin_installed', static fn() => true);
	test_override('api_plugin_is_enabled', static fn() => true);
	test_override('db_fetch_cell_prepared', static fn() => 1);
	test_override('api_plugin_hook_function', static function ($hook, $envelope) {
		expect($hook)->toBe('alarmmanager_submit');
		$GLOBALS['alarmmanager_test_events'][] = $envelope['event'];
		return $envelope + ['status' => 'accepted', 'event_row_id' => 42];
	});
});

function alarmmanager_test_rule(): array {
	return ['id' => 7, 'name' => 'Rule', 'method' => 1, 'num' => 5, 'severity' => 2, 'alarm_destination' => 'alarmmanager'];
}

function alarmmanager_test_matches(): array {
	return [['seq' => 9, 'logtime' => '2026-10-26 12:00:00', 'host' => 'router', 'message' => 'fixture', 'priority_id' => 3, 'facility_id' => 1]];
}

it('retains Syslog Alarming for existing rules and the disabled master setting', function () {
	$rule = alarmmanager_test_rule();
	unset($rule['alarm_destination']);
	expect(syslog_alarmmanager_forward($rule, alarmmanager_test_matches(), 1))->toBeFalse();
	test_override('read_config_option', static fn() => '');
	expect(syslog_alarmmanager_forward(alarmmanager_test_rule(), alarmmanager_test_matches(), 1))->toBeFalse();
	expect($GLOBALS['alarmmanager_test_events'])->toBe([]);
});

it('keeps fallback when a native availability check fails', function ($api) {
	test_override($api, static fn() => false);
	expect(syslog_alarmmanager_forward(alarmmanager_test_rule(), alarmmanager_test_matches(), 1))->toBeFalse();
	expect($GLOBALS['alarmmanager_test_events'])->toBe([]);
})->with(['api_plugin_installed', 'api_plugin_is_enabled', 'db_fetch_cell_prepared']);

it('rejects missing source identity and foreign collector ownership', function ($case) {
	$rule = alarmmanager_test_rule();
	$matches = alarmmanager_test_matches();
	if ($case === 'collector') { $GLOBALS['config']['poller_id'] = 0; }
	if ($case === 'breach') { $rule['_threshold_state'] = ['source_poller_id' => 1]; }
	if ($case === 'replica') { $matches[0]['replication_source_poller_id'] = 1; }
	if ($case === 'identity') { $matches[0]['seq'] = 0; }
	if ($case === 'empty') { $matches = []; }
	expect(syslog_alarmmanager_forward($rule, $matches, 1))->toBeFalse();
	expect($GLOBALS['alarmmanager_test_events'])->toBe([]);
})->with(['collector', 'breach', 'replica', 'identity', 'empty']);

it('requires a complete explicit durable acceptance result', function ($status, $row, $expected) {
	test_override('api_plugin_hook_function', static fn($hook, $envelope) => $envelope + ['status' => $status, 'event_row_id' => $row]);
	expect(syslog_alarmmanager_forward(alarmmanager_test_rule(), alarmmanager_test_matches(), 1))->toBe($expected);
})->with([
	['accepted', 42, true], ['already_accepted', 42, true], ['rejected', null, false],
	['failed', null, false], ['unhandled', null, false], ['accepted', '42', false], ['accepted', 0, false]
]);

it('rejects truthy and altered hook results', function ($altered) {
	test_override('api_plugin_hook_function', static function ($hook, $envelope) use ($altered) {
		if (!$altered) { return true; }
		$envelope['event']['summary'] = 'changed';
		return $envelope + ['status' => 'accepted', 'event_row_id' => 42];
	});
	expect(syslog_alarmmanager_forward(alarmmanager_test_rule(), alarmmanager_test_matches(), 1))->toBeFalse();
})->with([false, true]);

it('handles hook exceptions and invalid timestamps without logging sensitive contents', function ($bad_time) {
	$matches = alarmmanager_test_matches();
	if ($bad_time) { $matches[0]['logtime'] = 'invalid timestamp'; }
	test_override('api_plugin_hook_function', static fn() => throw new RuntimeException('sensitive provider token'));
	$logs = [];
	test_override('cacti_log', function ($message) use (&$logs) { $logs[] = $message; });
	expect(syslog_alarmmanager_forward(alarmmanager_test_rule(), $matches, 1))->toBeFalse();
	expect(implode(' ', $logs))->not->toContain('sensitive provider token');
})->with([false, true]);

it('keeps retry payloads stable and gives independent occurrences independent streams', function () {
	$rule = alarmmanager_test_rule();
	$matches = alarmmanager_test_matches();
	expect(syslog_alarmmanager_forward($rule, $matches, 1))->toBeTrue();
	expect(syslog_alarmmanager_forward($rule, $matches, 1))->toBeTrue();
	$matches[0]['seq']++;
	expect(syslog_alarmmanager_forward($rule, $matches, 1))->toBeTrue();
	[$first, $retry, $next] = $GLOBALS['alarmmanager_test_events'];
	expect($retry)->toBe($first);
	expect($first['event_id'])->not->toBe($next['event_id']);
	expect($first['alarm_key'])->not->toBe($next['alarm_key']);
});

it('correlates threshold recovery and records acceptance without resending on local failure', function ($write) {
	$rule = alarmmanager_test_rule();
	$rule['_threshold_state'] = ['source_poller_id' => 2, 'breach_key' => str_repeat('a', 40)];
	test_override('syslog_db_execute_prepared', static function () use ($write) {
		if ($write === 'throws') { throw new RuntimeException('private database detail'); }
		return $write;
	});
	expect(syslog_alarmmanager_forward($rule, alarmmanager_test_matches(), 5))->toBeTrue();
	expect(syslog_alarmmanager_forward($rule, alarmmanager_test_matches(), 0, true, 1793010000))->toBeTrue();
	[$raise, $clear] = $GLOBALS['alarmmanager_test_events'];
	expect($clear['episode_id'])->toBe($raise['episode_id']);
	expect($clear['transition'])->toBe('recovered');
	expect($clear['sequence'])->toBeGreaterThan($raise['sequence']);
})->with([true, false, 'throws']);

it('refuses a recovery without a breach and clear timestamp', function () {
	expect(syslog_alarmmanager_forward(alarmmanager_test_rule(), alarmmanager_test_matches(), 0, true))->toBeFalse();
});

it('does not execute hidden command actions for Alarm Manager rules', function () {
	$rule = alarmmanager_test_rule() + ['command' => '/bin/false', 'open_ticket' => 'on'];
	expect(syslog_execute_alert_command($rule, [], 'router'))->toBeTrue();
	syslog_execute_ticket_command($rule, [], 'failure');
	expect($GLOBALS['alarmmanager_test_events'])->toBe([]);
});

it('persists validated destinations through the existing page API', function ($destination) {
	$save = syslog_test_page_function('syslog_alerts.php', 'api_syslog_alert_save');
	$_SESSION['sess_user_id'] = 1;
	test_override('syslog_db_fetch_cell_prepared', static fn() => str_repeat('a', 32));
	test_override('syslog_db_fetch_assoc_prepared', static fn() => []);
	$saved = [];
	test_override('sql_save', function ($row) use (&$saved) { $saved = $row; return 7; });
	$args = [7, 'Rule', 1, 1, 5, 'messagec', 'error', '', '', 'on', 1, '', 0, '', 0, '', 0, 0, 'disabled', '1', '00:00', '00:00', '', '', 0, 'minute', 5, 'minute', ''];
	if ($destination !== null) { $args[] = $destination; }
	$result = $save(...$args);
	if ($destination === 'invalid') {
		expect($result)->toBeFalse();
		expect($saved)->toBe([]);
	} else {
		expect($result)->toBeNull();
		expect($saved['alarm_destination'])->toBe($destination ?? 'syslog');
	}
})->with(['syslog', 'alarmmanager', 'invalid', null]);

it('uses a stable schema-width change fingerprint including the destination', function () {
	$rule = alarmmanager_test_rule();
	$hash = syslog_threshold_rule_hash($rule);
	expect(strlen($hash))->toBe(40);
	expect(syslog_threshold_rule_hash($rule))->toBe($hash);
	$rule['alarm_destination'] = 'syslog';
	expect(syslog_threshold_rule_hash($rule))->not->toBe($hash);
});

it('forwards a queued threshold recovery to Alarm Manager and completes the queue entry', function () {
	$GLOBALS['severities'] = [2 => 'Warning'];
	$GLOBALS['syslog_levels'] = [3 => 'Error'];
	$GLOBALS['syslog_facilities'] = [1 => 'User'];
	$rule = alarmmanager_test_rule() + ['message' => 'fixture', 'command' => '', 'severity' => 2,
		'_threshold_state' => ['source_poller_id' => 2, 'breach_key' => str_repeat('a', 40)]];
	$context = json_encode([
		'alert' => $rule, 'results' => alarmmanager_test_matches()[0], 'hostname' => 'router', 'hostlist' => ['router'],
		'alarmmanager_accepted' => true, 'alarmmanager_matches' => alarmmanager_test_matches(), 'cleared_at' => 1793010000
	]);
	$writes = [];
	test_override('syslog_db_fetch_cell_prepared', static fn() => 1);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => [['breach_key' => str_repeat('a', 40), 'context' => $context, 'attempts' => 0]]);
	test_override('syslog_db_execute_prepared', function ($sql) use (&$writes) { $writes[] = $sql; return true; });
	syslog_threshold_deliver_recoveries(1000);
	expect($GLOBALS['alarmmanager_test_events'][0]['transition'])->toBe('recovered');
	expect(end($writes))->toStartWith('DELETE FROM syslog_threshold_recovery_queue WHERE');
});

it('sends fallback email only when Alarm Manager does not accept a message alert', function ($status, $emails) {
	$GLOBALS['severities'] = [2 => 'Warning'];
	$GLOBALS['syslog_levels'] = [3 => 'Error'];
	$GLOBALS['syslog_facilities'] = [1 => 'User'];
	$GLOBALS['config']['base_path'] = dirname(__DIR__) . '/Fixtures/threshold_notifications';
	$sent = 0;
	$rule = array_replace(alarmmanager_test_rule(), ['method' => 0, 'num' => 1, 'type' => 'messagec', 'message' => 'fixture',
		'repeat_alert' => 0, 'notify' => 0, 'email' => 'ops@example.com', 'body' => '', 'command' => '', 'open_ticket' => '',
		'maintenance_mode' => 'disabled', 'level' => 0, 'date' => 1]);
	$matches = alarmmanager_test_matches();
	test_override('read_config_option', static fn($name) => match ($name) {
		'syslog_alarmmanager_enabled' => 'on', 'syslog_html' => '', 'poller_interval' => 300, default => ''
	});
	test_override('api_plugin_hook_function', static fn($hook, $envelope) => $hook === 'alarmmanager_submit' ? $envelope + ['status' => $status, 'event_row_id' => 42] : $envelope);
	test_override('syslog_db_execute_prepared', static fn() => true);
	test_override('syslog_db_fetch_row_prepared', static fn() => []);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => []);
	test_override('syslog_db_fetch_cell_prepared', static fn() => 1);
	test_override('sql_save', static fn() => 1);
	test_override('mailer', function () use (&$sent) { $sent++; });
	syslog_process_alert($rule, 'SELECT 1', [], 1, 'router', $matches);
	expect($sent)->toBe($emails);
})->with([['accepted', 0], ['rejected', 1]]);
