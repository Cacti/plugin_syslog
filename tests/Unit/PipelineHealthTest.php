<?php
/* Pipeline evaluation and notification transitions use the real helpers with fake databases. */

function pipeline_health_fixture(): void {
	syslog_test_reset_globals();
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslogdb_default'] = 'health_test';
	$GLOBALS['syslog_incoming_config'] = ['timeField' => 'received_at'];
	$GLOBALS['pipeline_fixture'] = [
		'settings' => ['syslog_enabled' => 'on', 'poller_interval' => '300'],
		'status' => ['last_end_time' => (string) time()],
		'incoming' => ['pending' => '0', 'oldest' => null],
		'outbox' => ['pending' => '0', 'oldest_pending' => ''],
		'mails' => [], 'locks' => [], 'queries' => [], 'logs' => [], 'mail_error' => '', 'lock' => 1,
	];
	test_override('read_config_option', fn ($key) => $GLOBALS['pipeline_fixture']['settings'][$key] ?? '');
	test_override('syslog_db_table_exists', fn () => true);
	test_override('syslog_db_fetch_assoc', function ($sql) {
		$GLOBALS['pipeline_fixture']['queries'][] = $sql;
		$rows = [];
		foreach ($GLOBALS['pipeline_fixture']['status'] as $key => $value) {
			$rows[] = ['name' => $key, 'value' => $value, 'updated' => time()];
		}
		return $rows;
	});
	test_override('syslog_db_fetch_row', function ($sql) {
		$GLOBALS['pipeline_fixture']['queries'][] = $sql;
		if (str_contains($sql, 'syslog_incoming')) return $GLOBALS['pipeline_fixture']['incoming'];
		if (str_contains($sql, 'syslog_replication_output')) return $GLOBALS['pipeline_fixture']['outbox'];
		return [];
	});
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params) {
		$GLOBALS['pipeline_fixture']['locks'][] = [$sql, $params];
		return $GLOBALS['pipeline_fixture']['lock'];
	});
	test_override('syslog_db_execute_prepared', function ($sql, $params) {
		if ($params[0] === 'pipeline_health_monitor') {
			if (!empty($GLOBALS['pipeline_fixture']['write_failed'])) return false;
			$GLOBALS['pipeline_fixture']['status'][$params[0]] = $params[1];
		}
		return true;
	});
	test_override('mailer', function (...$args) {
		$GLOBALS['pipeline_fixture']['mails'][] = $args;
		return $GLOBALS['pipeline_fixture']['mail_error'];
	});
	test_override('cacti_log', function ($text) { $GLOBALS['pipeline_fixture']['logs'][] = $text; });
}

it('treats an empty drained queue and recent processing as healthy without writes', function () {
	pipeline_health_fixture();
	$health = syslog_pipeline_health();
	expect($health['state'])->toBe('up')->and($health['issues'])->toBeEmpty()
		->and($GLOBALS['pipeline_fixture']['mails'])->toBeEmpty()
		->and($GLOBALS['__test_db_calls'])->toBeEmpty();
	expect(implode(' ', $GLOBALS['pipeline_fixture']['queries']))->toContain('MIN(`received_at`)')
		->toContain('`health_test`.`syslog_incoming`')->toContain("'partition_maintenance_blocked'");
});

it('detects stalled processing even with an empty queue', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['status']['last_end_time'] = (string) (time() - 1000);
	expect(syslog_pipeline_health()['issues'])->toHaveKey('processing');
});

it('allows three poller intervals before declaring delayed processing', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['poller_interval'] = '600';
	$GLOBALS['pipeline_fixture']['status']['last_end_time'] = (string) (time() - 1000);
	expect(syslog_pipeline_health()['issues'])->toBeEmpty();
});

it('gives a new processor a grace period but detects failure to ever complete', function () {
	pipeline_health_fixture();
	unset($GLOBALS['pipeline_fixture']['status']['last_end_time']);
	syslog_pipeline_health_check();
	expect(syslog_pipeline_health()['issues'])->toBeEmpty();
	$GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'] = json_encode(['first_checked' => time() - 1000]);
	expect(syslog_pipeline_health()['issues'])->toHaveKey('processing');
});

it('detects old queued events even when fresh messages are arriving', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['incoming'] = ['pending' => 10, 'oldest' => time() - 1000];
	expect(syslog_pipeline_health()['issues'])->toHaveKey('backlog');
});

it('uses the configured backlog threshold', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_backlog_threshold'] = '1000';
	$GLOBALS['pipeline_fixture']['incoming'] = ['pending' => 1001, 'oldest' => time()];
	expect(syslog_pipeline_health()['issues']['backlog'])->toContain('1,001')->toContain('1,000');
});

it('never reports unavailable metrics or unsafe column mappings as healthy', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['incoming'] = false;
	expect(syslog_pipeline_health()['issues']['backlog'])->toContain('unavailable');
	$GLOBALS['syslog_incoming_config']['timeField'] = 'bad`; DROP TABLE syslog';
	$GLOBALS['pipeline_fixture']['queries'] = [];
	expect(syslog_pipeline_health()['issues']['backlog'])->toContain('unavailable');
	expect(implode(' ', $GLOBALS['pipeline_fixture']['queries']))->not->toContain('DROP TABLE');
});

it('detects failed and delayed remote delivery without treating ordinary recovery as failure', function () {
	pipeline_health_fixture();
	$GLOBALS['config']['poller_id'] = 2;
	$GLOBALS['config']['connection'] = 'offline';
	$GLOBALS['pipeline_fixture']['settings']['syslog_remote_enabled'] = 'on';
	$GLOBALS['pipeline_fixture']['outbox'] = ['pending' => 10, 'oldest_pending' => date('Y-m-d H:i:s')];
	expect(syslog_pipeline_health()['issues'])->toBeEmpty();
	$GLOBALS['pipeline_fixture']['outbox']['oldest_pending'] = date('Y-m-d H:i:s', time() - 1000);
	expect(syslog_pipeline_health()['issues'])->toHaveKey('replication');
	$GLOBALS['pipeline_fixture']['outbox']['oldest_pending'] = date('Y-m-d H:i:s');
	$GLOBALS['pipeline_fixture']['status']['replication_last_error'] = 'Central commit failed';
	expect(syslog_pipeline_health()['issues'])->toHaveKey('replication');
	$GLOBALS['pipeline_fixture']['outbox']['pending'] = null;
	expect(syslog_pipeline_health()['issues']['replication'])->toContain('unavailable');
});

it('reports blocked partition maintenance with its reason', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['status']['partition_maintenance_blocked'] = '1';
	$GLOBALS['pipeline_fixture']['status']['partition_maintenance_reason'] = 'DDL failed';
	expect(syslog_pipeline_health()['issues']['partition'])->toContain('DDL failed');
});

it('sends one problem notification and one recovery notification without duplicate polling emails', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_email'] = 'ops@example.test';
	$GLOBALS['pipeline_fixture']['incoming'] = ['pending' => 20000, 'oldest' => time()];
	syslog_pipeline_health_check();
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(1);
	$monitor = json_decode($GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'], true);
	$monitor['last_attempt'] -= 301;
	$GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'] = json_encode($monitor);
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 0;
	syslog_pipeline_health_check();
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(2)
		->and($GLOBALS['pipeline_fixture']['mails'][1][7])->toContain('recovered');
	expect($GLOBALS['pipeline_fixture']['locks'][1][0])->toContain('RELEASE_LOCK');
});

it('retries failed mail after five minutes without claiming success', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_email'] = 'ops@example.test';
	$GLOBALS['pipeline_fixture']['incoming'] = ['pending' => 20000, 'oldest' => time()];
	$GLOBALS['pipeline_fixture']['mail_error'] = 'SMTP unavailable';
	syslog_pipeline_health_check();
	syslog_pipeline_health_check();
	$monitor = json_decode($GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'], true);
	expect($monitor['notification'])->toBe('failed')->and($monitor)->not->toHaveKey('last_notified')
		->and($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(1);
	$monitor['last_attempt'] -= 301;
	$GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'] = json_encode($monitor);
	$GLOBALS['pipeline_fixture']['mail_error'] = '';
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(2);
	expect(json_decode($GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'], true)['notification'])->toBe('sent');
});

it('reminds for ongoing problems only after the configured interval', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings'] += ['syslog_pipeline_health_email' => 'ops@example.test', 'syslog_pipeline_health_repeat' => '900'];
	$GLOBALS['pipeline_fixture']['incoming'] = ['pending' => 20000, 'oldest' => time()];
	syslog_pipeline_health_check();
	$monitor = json_decode($GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'], true);
	$monitor['last_attempt'] -= 901;
	$monitor['last_notified'] -= 901;
	$GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'] = json_encode($monitor);
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(2);
});

it('checks and logs health with email disabled', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['incoming'] = ['pending' => 20000, 'oldest' => time()];
	syslog_pipeline_health_check();
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['logs'])->toHaveCount(1)
		->and($GLOBALS['pipeline_fixture']['mails'])->toBeEmpty();
});

it('does not alert for intentionally disabled processing and gives re-enablement a grace period', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings'] += ['syslog_pipeline_health_email' => 'ops@example.test'];
	$GLOBALS['pipeline_fixture']['settings']['syslog_enabled'] = '';
	$GLOBALS['pipeline_fixture']['status']['last_end_time'] = (string) (time() - 1000);
	syslog_pipeline_health_check();
	expect(syslog_pipeline_health()['state'])->toBe('disabled')->and($GLOBALS['pipeline_fixture']['mails'])->toBeEmpty();
	$GLOBALS['pipeline_fixture']['settings']['syslog_enabled'] = 'on';
	syslog_pipeline_health_check();
	expect(syslog_pipeline_health()['issues'])->toBeEmpty();
});

it('skips notifications when another monitor owns the lock', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['lock'] = 0;
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['queries'])->toBeEmpty()->and($GLOBALS['pipeline_fixture']['mails'])->toBeEmpty();
});

it('runs the monitor from the independent poller before launching the processor', function () {
	$source = plugin_test_read_source('includes/processing.php');
	expect(strpos($source, 'syslog_pipeline_health_check();'))->toBeLessThan(strpos($source, 'exec_background($command_string, $extra_args);'));
});


it('does not send email when the retry state cannot be persisted', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_email'] = 'ops@example.test';
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 20000;
	$GLOBALS['pipeline_fixture']['write_failed'] = true;
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toBeEmpty()
		->and($GLOBALS['pipeline_fixture']['locks'][1][0])->toContain('RELEASE_LOCK');
});

it('keeps mail exceptions from blocking processing and releases the monitor lock', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_email'] = 'ops@example.test';
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 20000;
	test_override('mailer', function () { throw new RuntimeException('Mail transport failed'); });
	syslog_pipeline_health_check();
	expect(json_decode($GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'], true)['notification'])->toBe('failed')
		->and($GLOBALS['pipeline_fixture']['locks'][1][0])->toContain('RELEASE_LOCK');
});

it('renders pipeline problems and notification status in the existing Status card with escaped details', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['status']['partition_maintenance_blocked'] = '1';
	$GLOBALS['pipeline_fixture']['status']['partition_maintenance_reason'] = '<script>alert(1)</script>';
	$GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'] = json_encode(['checked' => time(), 'notification' => 'failed']);
	$source = plugin_test_read_source('syslog.php');
	foreach (['syslog_status_data', 'syslog_status_workers', 'syslog_status_pipeline', 'syslog_status_card_meta', 'syslog_status_card_body', 'syslog_status_collector_health', 'syslog_status_collector_unavailable', 'syslog_status_format_age', 'syslog_status_format_count', 'syslog_status_format_time'] as $name) {
		if (!function_exists($name)) {
			$start = strpos($source, "function $name(");
			$end = strpos($source, "\n}", $start);
			eval(substr($source, $start, $end - $start + 2));
		}
	}
	if (!defined('SYSLOG_COLLECTOR_STALE_SECONDS')) define('SYSLOG_COLLECTOR_STALE_SECONDS', 300);
	if (!defined('SYSLOG_COLLECTOR_BACKLOG_THRESHOLD')) define('SYSLOG_COLLECTOR_BACKLOG_THRESHOLD', 10000);
	$html = syslog_status_card_body('collector_health');
	expect(syslog_status_card_meta()['collector_health']['title'])->toBe('Pipeline health')
		->and(syslog_status_card_meta()['collector_health']['state'])->toBe('down')
		->and($html)->toContain('Attention required')->toContain('Partition maintenance')
		->toContain('Failed; retry pending')->toContain('Last health check')
		->toContain('&lt;script&gt;')->not->toContain('<script>');
});

it('uses an enabled notification list without requiring explicit recipients and preserves BCC', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_notify'] = '7';
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 20000;
	test_override('db_table_exists', fn ($table) => $table === 'plugin_notification_lists');
	test_override('db_column_exists', fn () => true);
	test_override('db_fetch_row_prepared', function ($sql, $params) {
		expect($sql)->toContain('plugin_notification_lists')->toContain('enabled = ?');
		expect($params)->toBe([7, 'on']);
		return ['emails' => 'list@example.test', 'bcc_emails' => 'private@example.test'];
	});
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(1)
		->and($GLOBALS['pipeline_fixture']['mails'][0][1])->toBe('list@example.test')
		->and($GLOBALS['pipeline_fixture']['mails'][0][3])->toBe('private@example.test');
});

it('combines explicit addresses with the current list membership on each check', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings'] += ['syslog_pipeline_health_notify' => '7', 'syslog_pipeline_health_email' => ' extra@example.test, '];
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 20000;
	$list_email = 'old@example.test';
	test_override('db_table_exists', fn () => true);
	test_override('db_column_exists', fn () => true);
	test_override('db_fetch_row_prepared', function () use (&$list_email) { return ['emails' => $list_email]; });
	syslog_pipeline_health_check();
	$list_email = 'new@example.test';
	$monitor = json_decode($GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'], true);
	$monitor['last_attempt'] -= 3601;
	$monitor['last_notified'] -= 3601;
	$GLOBALS['pipeline_fixture']['status']['pipeline_health_monitor'] = json_encode($monitor);
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'][0][1])->toBe('extra@example.test, old@example.test')
		->and($GLOBALS['pipeline_fixture']['mails'][1][1])->toBe('extra@example.test, new@example.test');
});

it('supports a BCC-only notification list', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_notify'] = '7';
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 20000;
	test_override('db_table_exists', fn () => true);
	test_override('db_column_exists', fn () => true);
	test_override('db_fetch_row_prepared', fn () => ['emails' => '', 'bcc_emails' => 'private@example.test']);
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(1)
		->and($GLOBALS['pipeline_fixture']['mails'][0][1])->toBe('')
		->and($GLOBALS['pipeline_fixture']['mails'][0][3])->toBe('private@example.test');
});

it('does not notify through a missing or disabled list and keeps explicit recipients working without Thold', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_notify'] = '7';
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 20000;
	test_override('db_table_exists', fn () => true);
	test_override('db_fetch_row_prepared', fn () => []);
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toBeEmpty();
	test_override('db_table_exists', fn () => false);
	test_override('db_fetch_row_prepared', function () { throw new RuntimeException('Must not query a missing Thold table'); });
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_email'] = 'extra@example.test';
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'])->toHaveCount(1)
		->and($GLOBALS['pipeline_fixture']['mails'][0][1])->toBe('extra@example.test');
});

it('supports older notification tables without a BCC column', function () {
	pipeline_health_fixture();
	$GLOBALS['pipeline_fixture']['settings']['syslog_pipeline_health_notify'] = '7';
	$GLOBALS['pipeline_fixture']['incoming']['pending'] = 20000;
	test_override('db_table_exists', fn () => true);
	test_override('db_column_exists', fn () => false);
	test_override('db_fetch_row_prepared', function ($sql) {
		expect($sql)->toContain("'' AS bcc_emails");
		return ['emails' => 'list@example.test', 'bcc_emails' => ''];
	});
	syslog_pipeline_health_check();
	expect($GLOBALS['pipeline_fixture']['mails'][0][1])->toBe('list@example.test');
});

it('offers existing notification lists and a None choice in pipeline settings', function () {
	pipeline_health_fixture();
	$source = plugin_test_read_source('includes/settings.php');
	$start = strpos($source, '$notification_lists =');
	$end = strpos($source, '$temp =', $start);
	test_override('db_table_exists', fn () => true);
	test_override('db_fetch_assoc', fn () => [['id' => 7, 'name' => 'Operations']]);
	eval(substr($source, $start, $end - $start));
	preg_match("/'syslog_pipeline_health_notify' => \\[.*?\\n\\t\\t\\],/s", $source, $match);
	$field = eval('return [' . $match[0] . '];')['syslog_pipeline_health_notify'];
	expect($field['method'])->toBe('drop_array')->and($field['default'])->toBe('0')
		->and($field['array'])->toBe([0 => 'None', 7 => 'Operations']);
	test_override('db_table_exists', fn () => false);
	test_override('db_fetch_assoc', function () { throw new RuntimeException('Must not query missing notification lists'); });
	eval(substr($source, $start, $end - $start));
	expect($notification_lists)->toBe([0 => 'None']);
});
