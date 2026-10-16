<?php

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 2;
	$GLOBALS['severities'] = [1 => 'Warning'];
	$GLOBALS['syslog_levels'] = [3 => 'Error'];
	$GLOBALS['syslog_facilities'] = [1 => 'User'];
});

function threshold_command_context(string $command): array {
	return [
		'alert' => ['id' => 7, 'name' => 'Threshold', 'message' => 'error', 'severity' => 1, 'command' => $command,
			'_threshold_state' => ['source_poller_id' => 2, 'breach_key' => str_repeat('a', 32), 'new_breach' => true]],
		'results' => ['message' => 'device error', 'priority_id' => 3, 'facility_id' => 1],
		'hostname' => 'router', 'hostlist' => ['router']
	];
}

it('gives breach and recovery scripts the same identity and escapes command arguments', function () {
	$file = tempnam(sys_get_temp_dir(), 'syslog-command-');
	$command = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/threshold_command.php') . ' ' . escapeshellarg($file) . ' <HOSTNAME> <MESSAGE>';
	$context = threshold_command_context($command);
	$hostname = 'router; echo unsafe';
	putenv('SYSLOG_EVENT=original');
	try {
		foreach (['breach', 'clear'] as $event) {
			expect(syslog_execute_alert_command($context['alert'], $context['results'], $hostname, $event))->toBeTrue();
			expect(getenv('SYSLOG_EVENT'))->toBe('original');
		}
		$records = array_map(static fn($line) => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES));
		expect(array_column($records, 'event'))->toBe(['breach', 'clear']);
		expect($records[0])->toMatchArray(['breach_id' => str_repeat('a', 32), 'alert_id' => '7', 'hostname' => $hostname, 'poller_id' => '2', 'arguments' => [$hostname, 'device error']]);
		expect($records[1]['breach_id'])->toBe($records[0]['breach_id']);
	} finally {
		putenv('SYSLOG_EVENT');
		unlink($file);
	}
});

it('reports failed and missing commands without leaking transition variables', function () {
	$file = tempnam(sys_get_temp_dir(), 'syslog-command-');
	$context = threshold_command_context(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/threshold_command.php') . ' ' . escapeshellarg($file) . ' --fail');
	try {
		expect(syslog_execute_alert_command($context['alert'], $context['results'], 'router', 'clear'))->toBeFalse();
		expect(getenv('SYSLOG_EVENT'))->toBeFalse();
		$context['alert']['command'] = '/nonexistent/syslog-command';
		expect(syslog_execute_alert_command($context['alert'], $context['results'], 'router', 'clear'))->toBeFalse();
	} finally {
		unlink($file);
	}
});

it('snapshots the recovery command and matched device context before marking a breach', function ($level) {
	$context = threshold_command_context('/bin/true');
	$alert = $context['alert'] + ['method' => 1, 'level' => $level, 'num' => 5, 'recovery_command' => '/bin/false'];
	$writes = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$writes) { $writes[] = [$sql, $params]; return true; });
	expect(syslog_mark_threshold_messages($alert, 5, [$context['results'] + ['seq' => 9, 'host' => 'router']]))->toBeTrue();
	$saved = json_decode($writes[0][1][0], true);
	expect($writes[0][0])->toContain('recovery_context IS NULL');
	expect($saved['alert']['command'])->toBe('/bin/false');
	expect($saved['hostname'])->toBe($level === 1 ? 'router' : '');
	expect($saved['results'])->toEqual($context['results']);
})->with([0, 1]);

it('does not raise an alarm when its recovery context cannot be saved', function () {
	$context = threshold_command_context('/bin/true');
	$alert = $context['alert'] + ['method' => 1, 'level' => 1, 'num' => 5, 'recovery_command' => '/bin/true', 'repeat_alert' => 0, 'notify' => 0, 'email' => ''];
	$GLOBALS['config']['base_path'] = dirname(__DIR__) . '/Fixtures/threshold_notifications';
	test_override('syslog_db_fetch_assoc_prepared', static fn() => [$context['results'] + ['seq' => 9, 'host' => 'router']]);
	test_override('syslog_db_execute_prepared', static fn() => false);
	expect(syslog_process_alert($alert, 'SELECT * FROM syslog_incoming', [], 5, 'router'))->toBe(0);
});

it('preserves expired episodes whose clear could not yet be queued', function () {
	$rule = ['id' => 7, 'method' => 1, 'level' => 0];
	test_override('syslog_db_fetch_row_prepared', static fn() => ['expires_at' => 100, 'rule_hash' => syslog_threshold_rule_hash($rule), 'recovery_context' => '{}']);
	expect(syslog_threshold_evaluate($rule, 5, '', 200))->toBe(0);
});

it('rolls recovery back when the transaction cannot start or commit', function ($failure) {
	$transactions = [];
	test_override('syslog_db_execute_prepared', static fn() => true);
	test_override('syslog_db_execute', function ($sql) use (&$transactions, $failure) { $transactions[] = $sql; return $sql !== $failure; });
	syslog_threshold_recover(1000);
	expect($transactions)->toBe($failure === 'START TRANSACTION' ? ['START TRANSACTION'] : ['START TRANSACTION', 'COMMIT', 'ROLLBACK']);
})->with(['START TRANSACTION', 'COMMIT']);

it('delivers successful clears and retains failed or malformed events with bounded backoff', function ($command, $attempts, $delay) {
	$context = $command === 'malformed' ? 'null' : json_encode(threshold_command_context($command));
	$locks = [];
	$writes = [];
	test_override('syslog_db_fetch_cell_prepared', function ($sql) use (&$locks) { $locks[] = $sql; return 1; });
	test_override('syslog_db_fetch_assoc_prepared', static fn() => [['breach_key' => str_repeat('a', 32), 'context' => $context, 'attempts' => $attempts]]);
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$writes) { $writes[] = [$sql, $params]; return true; });
	syslog_threshold_deliver_recoveries(1000);
	expect($locks)->toBe(['SELECT GET_LOCK(?, 0)', 'SELECT RELEASE_LOCK(?)']);
	if ($delay === 0) {
		expect($writes[1])->toBe(['DELETE FROM syslog_threshold_recovery_queue WHERE source_poller_id = ? AND breach_key = ?', [2, str_repeat('a', 32)]]);
	} else {
		expect($writes[1][0])->toStartWith('UPDATE syslog_threshold_recovery_queue SET attempts = attempts + 1');
		expect($writes[1][1])->toBe([1000 + $delay, 2, str_repeat('a', 32)]);
	}
})->with([['/bin/true', 0, 0], ['/bin/false', 0, 60], ['/bin/false', 10, 3600], ['malformed', 1, 120]]);

it('does not deliver concurrently or when cancellation cleanup fails', function ($locked) {
	$reads = [];
	test_override('syslog_db_fetch_cell_prepared', function ($sql) use (&$reads, $locked) { $reads[] = $sql; return $locked ? 1 : 0; });
	test_override('syslog_db_execute_prepared', static fn() => false);
	syslog_threshold_deliver_recoveries(1000);
	expect($reads)->toHaveCount($locked ? 2 : 1);
})->with([true, false]);

it('repairs recovery storage without replacing historical message tables', function ($failed_table) {
	syslog_load_plugin_source('includes/schema.php');
	test_override('syslog_db_table_exists', static fn() => true);
	// the digest migration hooks run inside the same repair pass
	test_override('syslog_db_column_exists', static fn() => true);
	test_override('syslog_db_add_column', static fn($table, $column) => !($table === $failed_table && $column['name'] === 'recovery_context'));
	expect(syslog_ensure_table_structures())->toBe($failed_table === 'none');
})->with(['syslog_threshold_state', 'none']);

it('saves both commands on the same alert rule and preserves blank recovery commands', function ($recovery_command) {
	if (!function_exists('api_syslog_alert_save')) {
		$source = plugin_test_read_source('syslog_alerts.php');
		$start = strpos($source, 'function api_syslog_alert_save(');
		eval(substr($source, $start, strpos($source, 'function api_syslog_alert_remove(', $start) - $start));
	}
	$_SESSION['sess_user_id'] = 1;
	test_override('syslog_db_fetch_cell_prepared', static fn() => str_repeat('a', 32));
	test_override('syslog_db_fetch_assoc_prepared', static fn() => []);
	$saved = [];
	test_override('sql_save', function ($row, $table) use (&$saved) { $saved = $row; expect($table)->toBe('syslog_alert'); return 7; });
	expect(api_syslog_alert_save(7, 'Threshold', 1, 1, 5, 'messagec', 'error', '', '', 'on', 1, '/bin/true', 0, '',
		0, '', 0, 0, 'disabled', '1,2,3,4,5', '00:00', '00:00', '', '', 0, 'minute', 5, 'minute', $recovery_command))->toBeNull();
	expect($saved)->toMatchArray(['id' => 7, 'command' => '/bin/true', 'recovery_command' => $recovery_command]);
})->with(['/bin/false', '']);
