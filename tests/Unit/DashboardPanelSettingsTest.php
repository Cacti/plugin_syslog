<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Panel settings are the trust boundary between the database/request and
 * the SQL builders: every dimension (source, kind, chart, field, interval,
 * timespan, removal, top_n) must be allowlisted, and the "Other" slice on
 * breakdowns must fold the remainder beyond the top-N.
 */

it('accepts valid panel settings and normalizes the removal default', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$settings = syslog_dashboard_panel_settings([
		'source'   => 'syslog',
		'kind'     => 'timeseries',
		'chart'    => 'line',
		'field'    => 'host',
		'interval' => 'dashboard',
		'timespan' => 'dashboard',
		'removal'  => '2',
		'top_n'    => 10
	]);

	expect($settings)->toBeArray('A fully valid panel returns settings');
	expect($settings['removal'])->toBe('2', 'An explicit record type is preserved');

	// Alerts have no record-type switch and always normalize to '1'.
	$settings = syslog_dashboard_panel_settings([
		'source'   => 'alerts',
		'kind'     => 'timeseries',
		'chart'    => 'line',
		'field'    => 'host',
		'interval' => 'dashboard',
		'timespan' => 'dashboard',
		'removal'  => '',
		'top_n'    => 10
	]);

	expect($settings['removal'])->toBe('1', 'Alerts normalize the record type');
});

it('rejects every invalid panel dimension', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$valid = [
		'source'   => 'syslog',
		'kind'     => 'timeseries',
		'chart'    => 'line',
		'field'    => 'host',
		'interval' => 'dashboard',
		'timespan' => 'dashboard',
		'removal'  => '1',
		'top_n'    => 10
	];

	$invalid = [
		'source'   => 'syslog_logs; DROP TABLE syslog',
		'kind'     => 'histogram',
		'chart'    => 'scatter',
		'field'    => 'message',
		'interval' => 'fortnight',
		'timespan' => 'year',
		'removal'  => '3',
		'top_n'    => 0
	];

	foreach ($invalid as $key => $value) {
		$panel = $valid;
		$panel[$key] = $value;

		$result = syslog_dashboard_panel_settings($panel);

		expect($result)->toBeString("Invalid $key must be rejected");
	}

	// Breakdowns only allow donut charts; timeseries never allows donut.
	expect(syslog_dashboard_panel_settings(['kind' => 'breakdown', 'chart' => 'bar'] + $valid))->toBeString('Breakdown chart must be a donut');
	expect(syslog_dashboard_panel_settings(['kind' => 'timeseries', 'chart' => 'donut'] + $valid))->toBeString('Timeseries cannot use a donut');

	// The top count is capped server side.
	expect(syslog_dashboard_panel_settings(['top_n' => 51] + $valid))->toBeString('Top count above the cap must be rejected');
	expect(syslog_dashboard_panel_settings(['top_n' => '50'] + $valid))->toBeArray('Top count at the cap is valid');

	// A missing removal is invalid for the syslog source (empty string).
	expect(syslog_dashboard_panel_settings(['removal' => ''] + $valid))->toBeString('Empty record type is rejected for system logs');
});

it('folds breakdown rows beyond the top-n into an Other slice', function () {
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslog';

	// The breakdown payload builder folds everything past the top-N.
	$panel = [
		'id'        => 1,
		'expression' => 'message contains "error"',
		'source'    => 'syslog',
		'kind'      => 'breakdown',
		'chart'     => 'donut',
		'field'     => 'host',
		'interval'  => 'dashboard',
		'timespan'  => '86400',
		'removal'   => '-1',
		'top_n'     => 2
	];

	$rows = [
		['label' => 'router-01', 'records' => 40],
		['label' => 'router-02', 'records' => 35],
		['label' => 'router-03', 'records' => 20],
		['label' => 'router-04', 'records' => 5]
	];

	test_override('syslog_db_fetch_assoc', function ($sql) use ($rows) {
		return $rows;
	});

	$GLOBALS['request']['dashboard_timespan'] = '86400';

	$data = syslog_dashboard_panel_data($panel, 86400);

	expect($data['kind'])->toBe('breakdown');
	expect($data['labels'])->toBe(['router-01', 'router-02', 'Other'], 'Only the top-N labels plus Other');
	expect($data['series'][0])->toBe([40, 35, 25], 'Other folds the remainder');
	expect($data['total'])->toBe(100, 'Total counts every row');
});