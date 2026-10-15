<?php

it('marks new messages during an active breach without notifying again below the trigger count', function () {
	syslog_load_plugin_source('includes/functions.php');
	$writes = [];
	test_override('syslog_db_fetch_assoc_prepared', static fn() => [['seq' => 123]]);
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$writes) { $writes[] = $sql; return true; });
	$alert = ['id' => 1, 'name' => 'Quiet recovery', 'method' => 1, 'num' => 5, 'email' => '', 'notify' => 0, 'repeat_alert' => 0,
		'_threshold_state' => ['source_poller_id' => 1, 'breach_key' => str_repeat('a', 32), 'new_breach' => false]];
	expect(syslog_process_alert($alert, 'SELECT * FROM syslog_incoming', [], 1))->toBe(0);
	expect(implode("\n", $writes))->toContain('INSERT IGNORE INTO syslog_threshold_messages')->toContain('UPDATE syslog_incoming');
});
