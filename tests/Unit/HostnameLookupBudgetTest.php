<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Hostname lookups stop after a time budget and leave the remaining names as received.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function resolve_setup(array $hosts): stdClass {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$state = new stdClass();

	$state->updates = [];
	$state->logs    = [];

	test_override('read_config_option', static fn($key) => ['syslog_resolve_hostname' => 'on', 'syslog_no_dns' => ''][$key] ?? '');
	test_override('syslog_db_fetch_assoc_prepared', static fn() => $hosts);
	test_override('db_fetch_row_prepared', static fn() => []);
	test_override('syslog_db_execute_prepared', function ($sql, $params) use ($state) {
		$state->updates[] = $params;

		return true;
	});
	test_override('cacti_log', function ($message) use ($state) {
		$state->logs[] = $message;
	});

	return $state;
}


it('leaves hostnames as received once the lookup budget is spent', function () {
	$state = resolve_setup([['host' => 'router-one'], ['host' => 'router-two']]);

	expect(syslog_resolve_incoming_hosts(100, 0, 0, 0))->toBe(0)
		->and($state->updates)->toBe([])
		->and($state->logs)->toHaveCount(1)
		->and($state->logs[0])->toContain('budget');
});


it('resolves a hostname within the lookup budget', function () {
	$state = resolve_setup([['host' => 'localhost']]);

	expect(syslog_resolve_incoming_hosts(100))->toBe(1)
		->and($state->updates)->toBe([])
		->and($state->logs)->toBe([]);
});


it('still marks a name unresolved when the lookup was made and failed', function () {
	$state = resolve_setup([['host' => 'no-such-sender.invalid']]);

	expect(syslog_resolve_incoming_hosts(100))->toBe(0)
		->and($state->updates)->toBe([['unresolved-no-such-sender.invalid', 'no-such-sender.invalid', 100]]);
});


it('resolves a name from the Cacti device list even when lookups were skipped', function () {
	$state = resolve_setup([['host' => 'router-one']]);
	test_override('db_fetch_row_prepared', static fn() => ['description' => 'Router One']);

	expect(syslog_resolve_incoming_hosts(100, 0, 0, 0))->toBe(1)
		->and($state->updates)->toBe([['Router One', 'router-one', 100]]);
});
