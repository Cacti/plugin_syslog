<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Sender names are checked with A and AAAA records, and IPv4 and IPv6 addresses
 * need no lookup. IPv6 text is stored in one canonical form.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
});

it('recognizes address literals and writes them in canonical form', function ($name, $expected) {
	expect(syslog_host_address($name))->toBe($expected);
})->with([
	'ipv4'                 => ['203.0.113.5', '203.0.113.5'],
	'ipv6 compressed'      => ['2001:db8::1', '2001:db8::1'],
	'ipv6 expanded'        => ['2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1'],
	'ipv6 mixed case'      => ['2001:DB8::AbC', '2001:db8::abc'],
	'ipv6 brackets'        => ['[2001:db8::0001]', '2001:db8::1'],
	'ipv6 mapped ipv4'     => ['::FFFF:203.0.113.5', '::ffff:203.0.113.5'],
	'ipv6 zone'            => ['FE80::1%eth0', 'fe80::1%eth0'],
	'ipv6 unspecified'     => ['0:0:0:0:0:0:0:0', '::'],
	'name'                 => ['router1.example.net', false],
	'name with digits'     => ['203.0.113.5.example.net', false],
	'out of range ipv4'    => ['256.1.1.1', false],
	'bad ipv6'             => ['2001:db8::g', false],
	'ipv4 with zone'       => ['203.0.113.5%eth0', false],
	'empty'                => ['', false],
	'several brackets'     => ['[[2001:db8::1]]', false],
]);

it('counts an A record, an AAAA record or both as resolving', function ($records, $legacy, $expected) {
	$answers = static fn($name, $types) => $records;
	$old     = static fn($name) => $legacy ?? $name;

	expect(syslog_host_has_address('sender.example.net', $answers, $old))->toBe($expected);
})->with([
	'A only'                 => [[['type' => 'A', 'ip' => '203.0.113.5']], null, true],
	'AAAA only'              => [[['type' => 'AAAA', 'ipv6' => '2001:db8::1']], null, true],
	'both'                   => [[['type' => 'A', 'ip' => '203.0.113.5'], ['type' => 'AAAA', 'ipv6' => '2001:db8::1']], null, true],
	'none'                   => [[], null, false],
	'failed query'           => [false, null, false],
	'hosts file entry only'  => [[], '203.0.113.9', true],
	'failed query, hosts file' => [false, '203.0.113.9', true],
]);

it('asks for both record types and keeps a failed query out of the log', function () {
	$asked = [];

	$records = function ($name, $types) use (&$asked) {
		$asked[] = [$name, $types];
		trigger_error('DNS Query failed', E_USER_WARNING);

		return false;
	};

	expect(syslog_host_has_address('missing.example.net', $records, static fn($name) => $name))->toBeFalse()
		->and($asked)->toBe([['missing.example.net', DNS_A | DNS_AAAA]]);
});

it('resolves a name from the hosts file with the system resolver', function () {
	expect(syslog_host_has_address('localhost'))->toBeTrue();
});

function resolve_hosts_setup(array $hosts, array $options = [], array $devices = []): stdClass {
	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$state          = new stdClass();
	$state->updates = [];
	$state->logs    = [];

	test_override('read_config_option', static fn($key) => ($options + ['syslog_resolve_hostname' => 'on', 'syslog_no_dns' => ''])[$key] ?? '');
	test_override('syslog_db_fetch_assoc_prepared', static fn() => $hosts);
	test_override('db_fetch_row_prepared', static fn($sql, $params) => isset($devices[$params[0]]) ? ['description' => $devices[$params[0]]] : []);
	test_override('syslog_db_execute_prepared', function ($sql, $params) use ($state) {
		$state->updates[] = $params;

		return true;
	});
	test_override('cacti_log', function ($message) use ($state) {
		$state->logs[] = $message;
	});

	return $state;
}

it('treats addresses as resolved without a lookup and leaves a canonical one alone', function ($name) {
	$state = resolve_hosts_setup([['host' => $name]]);

	expect(syslog_resolve_incoming_hosts(100))->toBe(1)
		->and($state->updates)->toBe([])
		->and($state->logs)->toBe([]);
})->with(['203.0.113.5', '2001:db8::1', '::1']);

it('stores one canonical spelling for an IPv6 sender', function ($name) {
	$state = resolve_hosts_setup([['host' => $name]]);

	expect(syslog_resolve_incoming_hosts(100))->toBe(1)
		->and($state->updates)->toBe([['2001:db8::1', $name, 100]]);
})->with(['2001:DB8::1', '2001:0db8:0000:0000:0000:0000:0000:0001', '[2001:db8::1]']);

it('limits the canonical rename to a slice of messages', function () {
	$state = resolve_hosts_setup([['host' => '2001:DB8::1']]);

	syslog_resolve_incoming_hosts(100, 10, 20);

	expect($state->updates)->toBe([['2001:db8::1', '2001:DB8::1', 10, 20]]);
});

it('names a device found in Cacti by the spelling it uses', function ($sent, $stored, $renames) {
	$state = resolve_hosts_setup([['host' => $sent]], [], [$stored => 'Core Router']);

	expect(syslog_resolve_incoming_hosts(100))->toBe(1)
		->and($state->updates)->toBe($renames);
})->with([
	'ipv4'                          => ['203.0.113.5', '203.0.113.5', [['Core Router', '203.0.113.5', 100]]],
	'ipv6 as sent'                  => ['2001:DB8::1', '2001:DB8::1', [['Core Router', '2001:DB8::1', 100]]],
	'ipv6 stored in canonical form' => ['2001:DB8::1', '2001:db8::1', [['2001:db8::1', '2001:DB8::1', 100], ['Core Router', '2001:db8::1', 100]]],
]);

it('marks a name that does not resolve and is not a device', function () {
	$state = resolve_hosts_setup([['host' => 'no-such-sender.invalid']]);

	expect(syslog_resolve_incoming_hosts(100))->toBe(0)
		->and($state->updates)->toBe([['unresolved-no-such-sender.invalid', 'no-such-sender.invalid', 100]])
		->and($state->logs)->toHaveCount(1);
});

it('keeps a name that resolves', function () {
	$state = resolve_hosts_setup([['host' => 'localhost']]);

	expect(syslog_resolve_incoming_hosts(100))->toBe(1)
		->and($state->updates)->toBe([]);
});

it('skips the lookup when DNS is turned off but still resolves addresses and devices', function () {
	$state = resolve_hosts_setup(
		[['host' => 'localhost'], ['host' => '2001:DB8::2'], ['host' => 'known.example.net']],
		['syslog_no_dns' => 'on'],
		['known.example.net' => 'Known Device']
	);

	expect(syslog_resolve_incoming_hosts(100))->toBe(2)
		->and($state->updates)->toBe([
			['unresolved-localhost', 'localhost', 100],
			['2001:db8::2', '2001:DB8::2', 100],
			['Known Device', 'known.example.net', 100],
		]);
});

it('does nothing when name checking is off or the host is empty', function () {
	$state = resolve_hosts_setup([['host' => '']], ['syslog_resolve_hostname' => '']);

	expect(syslog_resolve_incoming_hosts(100))->toBe(0)
		->and($state->updates)->toBe([]);

	$state = resolve_hosts_setup([['host' => ''], ['other' => 'x']]);

	expect(syslog_resolve_incoming_hosts(100))->toBe(0)
		->and($state->updates)->toBe([]);
});

it('limits a marked name to a slice of messages', function () {
	$state = resolve_hosts_setup([['host' => 'no-such-sender.invalid']]);

	syslog_resolve_incoming_hosts(100, 10, 20);

	expect($state->updates)->toBe([['unresolved-no-such-sender.invalid', 'no-such-sender.invalid', 10, 20]]);
});

function aaaa_only_answers(array &$asked): callable {
	return function ($name, $types) use (&$asked) {
		$asked[] = $name;

		return [['type' => 'AAAA', 'ipv6' => '2001:db8::53']];
	};
}

it('counts a name with only an AAAA record as resolved within the budget', function () {
	$asked = [];
	$state = resolve_hosts_setup([['host' => 'v6-one.example.net'], ['host' => 'v6-two.example.net']]);

	expect(syslog_resolve_incoming_hosts(100, 0, 0, 60, aaaa_only_answers($asked)))->toBe(2)
		->and($asked)->toBe(['v6-one.example.net', 'v6-two.example.net'])
		->and($state->updates)->toBe([])
		->and($state->logs)->toBe([]);
});

it('stops looking up AAAA-only names once the budget is spent and leaves them as received', function () {
	$asked = [];
	$state = resolve_hosts_setup([['host' => 'v6-one.example.net'], ['host' => 'v6-two.example.net']]);

	expect(syslog_resolve_incoming_hosts(100, 0, 0, 0, aaaa_only_answers($asked)))->toBe(0)
		->and($asked)->toBe([])
		->and($state->updates)->toBe([])
		->and($state->logs)->toHaveCount(1)
		->and($state->logs[0])->toContain('budget');
});

it('does not charge address literals to the budget', function () {
	$asked = [];
	$state = resolve_hosts_setup([['host' => '203.0.113.5'], ['host' => '2001:DB8::7']]);

	expect(syslog_resolve_incoming_hosts(100, 0, 0, 0, aaaa_only_answers($asked)))->toBe(2)
		->and($asked)->toBe([])
		->and($state->updates)->toBe([['2001:db8::7', '2001:DB8::7', 100]])
		->and($state->logs)->toBe([]);
});

it('resolves literals and devices after the budget is spent and reports the skipped name once', function () {
	$asked = [];
	$state = resolve_hosts_setup(
		[['host' => 'v6-one.example.net'], ['host' => '2001:DB8::7'], ['host' => 'known.example.net'], ['host' => 'v6-two.example.net']],
		[],
		['known.example.net' => 'Known Device']
	);

	expect(syslog_resolve_incoming_hosts(100, 0, 0, 0, aaaa_only_answers($asked)))->toBe(2)
		->and($asked)->toBe([])
		->and($state->updates)->toBe([['2001:db8::7', '2001:DB8::7', 100], ['Known Device', 'known.example.net', 100]])
		->and($state->logs)->toHaveCount(1);
});

it('never asks DNS when lookups are disabled, whatever the budget', function ($budget) {
	$asked = [];
	$state = resolve_hosts_setup([['host' => 'v6-one.example.net']], ['syslog_no_dns' => 'on']);

	expect(syslog_resolve_incoming_hosts(100, 0, 0, $budget, aaaa_only_answers($asked)))->toBe(0)
		->and($asked)->toBe([])
		->and($state->updates)->toBe([['unresolved-v6-one.example.net', 'v6-one.example.net', 100]])
		->and(array_filter($state->logs, static fn($line) => str_contains($line, 'budget')))->toBe([]);
})->with([0, 60]);
