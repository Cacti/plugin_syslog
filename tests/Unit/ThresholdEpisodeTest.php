<?php

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 1;
});

it('opens and extends a device episode and resets expired or edited state', function () {
	$rule = ['id' => 7, 'method' => 1, 'level' => 1, 'num' => 5, 'date' => 100];
	$state = [];
	$writes = [];
	test_override('syslog_db_fetch_row_prepared', function () use (&$state) { return $state; });
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$writes) { $writes[] = [$sql, $params]; return true; });
	expect(syslog_threshold_evaluate($rule, 2, 'router', 1000))->toBe(2);
	expect($writes)->toBe([]);
	expect(syslog_threshold_evaluate($rule, 5, 'router', 1000))->toBe(5);
	expect($rule['_threshold_state']['new_breach'])->toBeTrue();
	$key = $rule['_threshold_state']['breach_key'];
	$state = ['expires_at' => 1300, 'rule_hash' => syslog_threshold_rule_hash($rule), 'breach_key' => $key, 'breached_at' => 1000];
	expect(syslog_threshold_evaluate($rule, 1, 'router', 1100))->toBe(1);
	expect($rule['_threshold_state'])->toMatchArray(['breach_key' => $key, 'new_breach' => false]);
	expect(end($writes)[1])->toBe([1, 7, 'router', syslog_threshold_rule_hash($rule), 100, $key, 1000, 1100, 1400]);
	expect(syslog_threshold_evaluate($rule, 5, 'router', 1300))->toBe(5);
	expect($rule['_threshold_state']['breach_key'])->not->toBe($key);
	expect(implode('\n', array_column($writes, 0)))->toContain('DELETE FROM syslog_alert_counts', 'DELETE FROM syslog_threshold_messages', 'DELETE FROM syslog_threshold_state');
});

it('fails closed on episode reads and writes', function ($failure) {
	$rule = ['id' => 7, 'method' => 1, 'level' => 0, 'num' => 5];
	test_override('syslog_db_fetch_row_prepared', static fn() => $failure === 'read' ? false : ['expires_at' => 1, 'rule_hash' => '', 'breach_key' => 'old']);
	test_override('syslog_db_execute_prepared', static fn($sql) => !str_contains($sql, $failure));
	expect(syslog_threshold_evaluate($rule, 5, '', 1000))->toBe(0);
	expect($rule)->not->toHaveKey('_threshold_state');
})->with(['read', 'DELETE FROM syslog_alert_counts', 'DELETE FROM syslog_threshold_messages', 'DELETE FROM syslog_threshold_state', 'INSERT INTO syslog_threshold_state']);

it('bypasses episode changes for empty batches and individual rules', function () {
	$rule = ['method' => 0];
	expect(syslog_threshold_evaluate($rule, 3))->toBe(3);
	$rule['method'] = 1;
	expect(syslog_threshold_evaluate($rule, 0))->toBe(0);
});

it('stops quiet recovery at the first failed deletion', function ($failure, $expected) {
	$calls = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$calls, $failure) { $calls[] = [$sql, $params]; return count($calls) !== $failure; });
	syslog_threshold_recover(1000);
	expect($calls)->toHaveCount($expected);
	expect($calls[0][1])->toBe([1, 1000]);
})->with([[1, 1], [2, 2], [0, 3]]);

it('fetches replication episodes in a batch and propagates lookup failures', function () {
	$event = ['source_poller_id' => 2, 'source_event_id' => 3, 'threshold_breached' => 1];
	$row = $event + ['breach_key' => 'key'];
	test_override('syslog_db_fetch_assoc_prepared', static function ($sql, $params) use ($row) {
		expect($params)->toBe([2, 3]);
		return [$row];
	});
	expect(syslog_threshold_replication_states([$event]))->toBe(['2:3' => [$row]]);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => false);
	expect(syslog_threshold_replication_states([$event]))->toBeFalse();
	expect(syslog_message_breach_sql())->toContain('ts.expires_at > UNIX_TIMESTAMP()', 'tm.source_event_id = syslog.replication_source_event_id');
});

it('imports central episodes and stops when any persistence step fails', function ($failure) {
	$event = ['source_poller_id' => 2, 'source_event_id' => 3, 'threshold_states' => [
		['alert_id' => 7, 'scope_key' => 'router', 'breach_key' => 'key', 'last_match' => 1000, 'expires_at' => 1300, 'rule_hash' => 'hash', 'rule_date' => 100]
	]];
	$calls = [];
	test_override('db_execute_prepared', function ($sql, $params) use (&$calls, $failure) { $calls[] = [$sql, $params]; return count($calls) !== $failure; });
	expect(syslog_threshold_accept_central($event, new stdClass(), 'central'))->toBe($failure === 0);
	expect($calls)->toHaveCount($failure ?: 3);
})->with([0, 1, 2, 3]);

it('fails closed when storing the rolling count fails after cleanup', function () {
	test_override('syslog_db_execute_prepared', static fn($sql) => !str_starts_with($sql, 'INSERT'));
	expect(syslog_alert_window_count(['id' => 7, 'method' => 1, 'level' => 0, 'threshold_window' => 1], 5, '', 1000))->toBe(0);
});

it('composes threshold notifications with the rolling count in text and HTML', function ($html) {
	$GLOBALS['config']['base_path'] = dirname(__DIR__) . '/Fixtures/threshold_notifications';
	$GLOBALS['severities'] = [0 => 'Warning'];
	$GLOBALS['syslog_levels'] = [3 => 'Error'];
	$GLOBALS['syslog_facilities'] = [1 => 'User'];
	test_override('read_config_option', static fn($key) => ['syslog_html' => $html ? 'on' : '', 'syslog_maxrecords' => 10][$key] ?? '');
	test_override('syslog_db_fetch_assoc_prepared', static fn() => [['seq' => 1, 'host' => 'router', 'message' => 'error', 'logtime' => '2026-01-01 00:00:00', 'priority_id' => 3, 'facility_id' => 1]]);
	$saved = [];
	test_override('sql_save', function ($row) use (&$saved) { $saved[] = $row; return 1; });
	$rule = ['id' => 7, 'name' => 'Threshold', 'method' => 1, 'num' => 5, 'severity' => 0, 'message' => 'error', 'body' => '', 'email' => '', 'notify' => 0, 'repeat_alert' => 0, 'command' => '', 'ticket' => '', 'open_ticket' => ''];
	expect(syslog_process_alert($rule, 'SELECT * FROM syslog_incoming', [], 7))->toBe(1);
	expect($saved[0]['count'])->toBe(7);
	expect($saved[0]['html'])->toContain($html ? '<td>7</td>' : 'Count: 7');
	expect($saved[0]['host'])->toBe('router');
})->with([false, true]);

it('evaluates device and shared threshold rules through the poller', function ($level) {
	$GLOBALS['config']['base_path'] = dirname(__DIR__) . '/Fixtures/threshold_notifications';
	$rule = ['id' => 7, 'name' => 'Threshold', 'method' => 1, 'level' => $level, 'num' => 5, 'type' => 'messagec', 'message' => 'error', 'maintenance_mode' => 'disabled', 'email' => '', 'notify' => 0, 'repeat_alert' => 0];
	test_override('syslog_db_fetch_assoc', static fn($sql) => str_contains($sql, 'syslog_status') ? [] : [$rule]);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => [['host' => 'router', 'count' => 1]]);
	test_override('syslog_db_fetch_cell_prepared', static fn() => 1);
	expect(syslog_process_alerts(100))->toBe(['syslog_alerts' => 1, 'syslog_alarms' => 0]);
})->with([0, 1]);

it('preserves the breach marker while archiving a structured removal match', function () {
	$rule = ['id' => 7, 'name' => 'Archive', 'type' => 'filter', 'method' => 'move', 'message' => json_encode(['version' => 1, 'conditions' => [['field' => 'message', 'operator' => 'contains', 'value' => 'error']]])];
	test_override('syslog_db_fetch_assoc', static fn($sql) => str_contains($sql, 'syslog_status') ? [] : [$rule]);
	$sql = [];
	test_override('syslog_db_execute_prepared', function ($query) use (&$sql) { $sql[] = $query; return true; });
	syslog_remove_items('syslog_incoming', 100);
	expect(implode('\n', $sql))->toContain('si.threshold_breached', 'INSERT INTO');
});

it('does not mark messages when the episode association cannot be saved', function () {
	$calls = [];
	test_override('syslog_db_execute_prepared', function ($sql) use (&$calls) { $calls[] = $sql; return false; });
	syslog_mark_threshold_messages(['id' => 7, 'method' => 1, 'num' => 5, '_threshold_state' => ['source_poller_id' => 1, 'breach_key' => 'key']], 5, [['seq' => 1]]);
	expect($calls)->toHaveCount(1);
	expect($calls[0])->toStartWith('INSERT IGNORE INTO syslog_threshold_messages');
});

it('retains the outbox when replication episode lookup fails', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$GLOBALS['syslog_cnn'] = new stdClass();
	$GLOBALS['database_default'] = 'central';
	unset($GLOBALS['syslog_replication_connection_failed']);
	test_override('read_config_option', static fn($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
	test_override('syslog_db_fetch_assoc', static fn() => [['source_poller_id' => 2, 'source_event_id' => 3, 'threshold_breached' => 1]]);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => false);
	$writes = [];
	test_override('db_execute_prepared', function ($sql) use (&$writes) { $writes[] = $sql; return true; });
	expect(syslog_replication_deliver_online())->toBe(0);
	expect($writes)->toBe([]);
});

it('rejects a central event when its episode cannot be persisted', function () {
	$event = ['source_poller_id' => 2, 'source_event_id' => 3, 'delivery_batch_id' => 'batch', 'disposition' => 'syslog', 'program' => 'app', 'host' => 'router', 'message' => 'error', 'logtime_epoch' => 1000, 'priority_id' => 3, 'facility_id' => 1,
		'threshold_states' => [['alert_id' => 7, 'scope_key' => 'router', 'breach_key' => 'key', 'last_match' => 1000]]];
	test_override('db_affected_rows', static fn() => 1);
	test_override('db_execute_prepared', static fn($sql) => !str_contains($sql, 'DELETE m FROM'));
	expect(syslog_replication_accept_central($event, new stdClass(), 'central'))->toBeFalse();
});
