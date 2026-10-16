<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

// Range, failure and completion cases live in DigestMigrationTest.php.
afterEach(function () {
	syslog_test_reset_globals();
});

it('restarts legacy digest progress rather than trusting a potentially skipped range', function () {
	syslog_test_digest_setup();
	test_override('read_config_option', static fn ($name) => '250');
	test_override('set_config_option', function () {
		throw new RuntimeException('Digest migration must use plugin-owned status storage.');
	});

	expect(syslog_backfill_message_digest(200))->toBe(50)
		->and($GLOBALS['digest_updates'])->toBe([[0, 200]])
		->and(json_decode($GLOBALS['digest_state']['digest_backfill_syslog'], true))
		->toBe(['cursor' => 200, 'target' => 250]);
});
