<?php

afterEach(function () {
	syslog_test_reset_globals();
});

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
	test_override('db_fetch_assoc', static function () { throw new RuntimeException('Rule saves must not query core tables'); });
	test_override('db_execute', static function () { throw new RuntimeException('Rule saves must not write core tables'); });
	test_override('db_execute_prepared', static function () { throw new RuntimeException('Rule saves must not write core tables'); });
});

it('saves rules in Syslog while leaving collector discovery to Cacti replication', function ($table) {
	$GLOBALS['config']['poller_id'] = 1;
	test_override('read_config_option', static fn() => 'on');
	$saves = [];
	$messages = [];
	test_override('sql_save', function ($data, $saved_table, $primary) use (&$saves) { $saves[] = [$data, $saved_table, $primary]; return 7; });
	test_override('raise_message', function ($id) use (&$messages) { $messages[] = $id; });
	$data = ['id' => 7, 'name' => 'Rule'];
	syslog_sync_save($data, $table, 'id');
	expect($saves)->toBe([[$data, $table, 'id']]);
	expect($messages)->toBe([1]);
})->with(['syslog_alert', 'syslog_remove', 'syslog_reports', 'syslog_device_rule']);

it('rejects rule edits on synchronized collectors', function () {
	$GLOBALS['config']['poller_id'] = 2;
	test_override('read_config_option', static fn() => 'on');
	test_override('sql_save', static function () { throw new RuntimeException('A synchronized collector cannot save rules'); });
	$messages = [];
	test_override('raise_message', function ($id) use (&$messages) { $messages[] = $id; });
	syslog_sync_save(['id' => 7], 'syslog_alert', 'id');
	expect($messages)->toBe(['syslog_denied']);
});

it('preserves local rule saves when sync is disabled and reports storage failures', function ($result) {
	$GLOBALS['config']['poller_id'] = 2;
	test_override('read_config_option', static fn() => '');
	test_override('sql_save', static fn() => $result);
	$messages = [];
	test_override('raise_message', function ($id) use (&$messages) { $messages[] = $id; });
	syslog_sync_save(['id' => 7], 'syslog_alert', 'id');
	expect($messages)->toBe([$result > 0 ? 1 : 2]);
})->with([7, false]);
