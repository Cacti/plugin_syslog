<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The invalid-database-name guards on partition maintenance: an illegal
 * $syslogdb_default must abort the state report, create, and remove paths
 * without building DDL identifiers from it.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

it('aborts the partition state report for an invalid database name', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'sys`log';

	expect(syslog_partition_report_state('syslog'))->toBeFalse();
});

it('aborts partition creation for an invalid database name', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'sys-log';

	expect(syslog_partition_create('syslog', 1735689600))->toBeFalse();
});

it('aborts partition removal for an invalid database name', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog; DROP TABLE syslog';

	expect(syslog_partition_remove('syslog'))->toBe(0);
});