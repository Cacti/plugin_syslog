<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The All records dashboard queries group each log table before the totals are summed.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


it('groups each branch of the combined dashboard queries', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$series    = syslog_dashboard_timeseries_sql('syslog', '1', '1 = 1', 3600, 0);
	$breakdown = syslog_dashboard_breakdown_sql('syslog', 'host', '1', '1 = 1', 0);

	expect(substr_count($series, 'GROUP BY bucket'))->toBe(3)
		->and(substr_count($breakdown, 'GROUP BY label'))->toBe(3);
});
