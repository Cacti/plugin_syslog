<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('adds the message digest column to archived tables on upgrade', function () {
	$schema = plugin_test_read_source('includes/schema.php');

	expect($schema)->toContain("message_digest char(32) NOT NULL default ''")
		->toContain('function syslog_ensure_message_digest_columns(): void')
		->toContain("['syslog', 'syslog_removed'] as \$table")
		->toContain('syslog_ensure_message_digest_columns();');
});

it('keys grouped viewer queries and counts by the digest, with a hash fallback', function () {
	$syslog = plugin_test_read_source('syslog.php');

	expect($syslog)->toContain('syslog_message_digest_ready()')
		->toContain('IF(syslog.message_digest = \'\', MD5(syslog.message), syslog.message_digest)')
		->toContain("IF(message_digest = '', MD5(message), message_digest)");

	// the plain message group key survives only as the fallback branch of the
	// ternary, never inside a query string
	expect(substr_count($syslog, 'GROUP BY syslog.host_id, syslog.message'))->toBe(1);
	expect($syslog)->toContain("\n\t\t\t\t: 'GROUP BY syslog.host_id, syslog.message");
});

it('writes digests at every archived message entry point', function () {
	$functions = plugin_test_read_source('includes/functions.php');
	$process   = plugin_test_read_source('syslog_process.php');

	expect($functions)->toContain('(logtime, priority_id, facility_id, program_id, host_id, message, message_digest)')
		->toContain('MD5(message)')->toContain('MD5(si.message)')
		->toContain('function syslog_backfill_message_digest')
		->toContain('function syslog_message_digest_ready');

	expect($process)->toContain('syslog_ensure_message_digest_columns();')
		->toContain('syslog_backfill_message_digest();');
});