<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

afterEach(function () {
	syslog_test_reset_globals();
});

it('reports not ready when the removed table or its digest column is missing', function () {
	syslog_load_plugin_source('includes/functions.php');

	// Neither archived table has the digest column.
	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', static fn() => false);

	expect(syslog_message_digest_ready())->toBeFalse()
		->and(syslog_message_digest_ready())->toBeFalse()
		->and(syslog_message_digest_ready('syslog_removed'))->toBeFalse();
});

it('caches missing digest metadata without probing storage again', function () {
	syslog_load_plugin_source('includes/functions.php');
	$probes = 0;
	test_override('syslog_db_table_exists', function () use (&$probes) {
		$probes++;
		return false;
	});

	// Use a distinct table key so other tests cannot prime this static entry.
	expect(syslog_message_digest_ready('missing_digest_table'))->toBeFalse()
		->and(syslog_message_digest_ready('missing_digest_table'))->toBeFalse()
		->and($probes)->toBe(1);
});


it('reports ready when the digest column exists and caches that result', function () {
	syslog_load_plugin_source('includes/functions.php');
	$probes = 0;
	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', function () use (&$probes) {
		$probes++;
		return true;
	});

	expect(syslog_message_digest_ready('ready_digest_table'))->toBeTrue()
		->and(syslog_message_digest_ready('ready_digest_table'))->toBeTrue()
		->and($probes)->toBe(1);
});
