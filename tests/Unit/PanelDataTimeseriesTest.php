<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The timeseries branch of syslog_dashboard_panel_data(): the query runs
 * through the bucketed SQL builder and bucket epochs are formatted through
 * syslog_dashboard_bucket_label(), while the breakdown branch is already
 * covered by DashboardPanelSettingsTest.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

it('renders a timeseries panel payload with bucket labels and totals', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['request']['dashboard_timespan'] = '86400';

	$captured = [];

	test_override('syslog_db_fetch_assoc', function ($sql) use (&$captured) {
		$captured[] = $sql;

		return [
			['bucket' => 1735689600, 'records' => '3'],
			['bucket' => 1735693200, 'records' => '5']
		];
	});

	$panel = [
		'id'         => 1,
		'expression' => 'message contains "error"',
		'source'     => 'syslog',
		'kind'       => 'timeseries',
		'chart'      => 'line',
		'field'      => 'host',
		'interval'   => 'auto',
		'timespan'   => '86400',
		'removal'    => '-1',
		'top_n'      => 10
	];

	$data = syslog_dashboard_panel_data($panel, 86400);

	expect($data['kind'])->toBe('timeseries')
		->and($data['chart'])->toBe('line')
		->and($data['series'][0])->toBe([3, 5])
		->and($data['total'])->toBe(8)
		->and($data['labels'])->toBe([
			date('Y-m-d H:i', 1735689600),
			date('Y-m-d H:i', 1735693200)
		])
		->and(cacti_sizeof($captured))->toBe(1)
		->and($captured[0])->toContain('syslog` AS syslog')
		->toContain('FLOOR(UNIX_TIMESTAMP(syslog.logtime)');
});

it('labels day-sized buckets with dates only', function () {
	expect(syslog_dashboard_bucket_label(1735689600, 86400))->toBe(date('Y-m-d', 1735689600))
		->and(syslog_dashboard_bucket_label(1735689600, 3600))->toBe(date('Y-m-d H:i', 1735689600));
});