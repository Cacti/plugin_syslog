<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/**
 * syslog_message_digest_ready() keeps a static cache.  Other tests in the
 * same suite prime it with the positive case; this file runs on a fresh
 * include of includes/functions.php and exercises the false branch, which
 * is what the patch-coverage gate measures at line 5946.
 */
afterEach(function () {
	syslog_test_reset_globals();
});

it('reports not ready when the removed table or its digest column is missing', function () {
	syslog_load_plugin_source('includes/functions.php');

	// syslogs table has the column, syslog_removed does not; first call seeds
	// the cache with the falsy value and the second call re-reads it (5946).
	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', static fn() => false);

	expect(syslog_message_digest_ready())->toBeFalse()
		->and(syslog_message_digest_ready())->toBeFalse()
		->and(syslog_message_digest_ready('syslog_removed'))->toBeFalse();
});