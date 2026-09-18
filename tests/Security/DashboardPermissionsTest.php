<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Every dashboard write path must re-verify ownership before touching the
 * database: renaming/deleting a dashboard and creating/updating/deleting/
 * moving a panel all fail closed when the requested id does not belong to
 * the requesting user.
 */

it('denies dashboard writes for missing or foreign dashboards', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$_SESSION['sess_user_id'] = 42;

	$GLOBALS['request'] = [
		'name'            => 'Network Trends',
		'id'              => '9',
		'dashboard_delete' => '1'
	];

	test_override('get_username', function ($id) {
		return 'alice';
	});

	// The dashboard lookup fails: it belongs to someone else (or is absent).
	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) {
		return false;
	});

	$executed = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$executed) {
		$executed[] = $sql;

		return true;
	});

	$result = json_decode(syslog_dashboard_save(), true);

	expect($result['error'])->toBe('Dashboard not found.', 'Deleting a foreign dashboard is refused');
	expect($executed)->toBe([], 'No delete statements run without ownership');

	// Rename hits the same ownership wall.
	$GLOBALS['request']['dashboard_delete'] = '';
	$GLOBALS['request']['id'] = '9';

	$result = json_decode(syslog_dashboard_save(), true);

	expect($result['error'])->toBe('Dashboard not found.', 'Renaming a foreign dashboard is refused');
	expect($executed)->toBe([], 'No update statements run without ownership');

	// Valid names are required for creation.
	$GLOBALS['request']['id'] = '';
	$GLOBALS['request']['name'] = str_repeat('x', 129);

	$result = json_decode(syslog_dashboard_save(), true);

	expect($result['error'])->toBe('A name of up to 128 characters is required.', 'Overlong names are refused');
	expect($executed)->toBe([], 'Nothing is inserted for an invalid name');
});

it('denies panel writes for panels outside the owned dashboard', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$_SESSION['sess_user_id'] = 42;

	test_override('get_username', function ($id) {
		return 'alice';
	});

	// The dashboard itself is owned, but the panel is not part of it.
	$owned_dashboard = ['id' => 4, 'name' => 'Trends', 'user' => 'alice'];

	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) use ($owned_dashboard) {
		if (str_contains($sql, 'syslog_dashboards') && str_contains($sql, 'INNER JOIN') === false) {
			return $owned_dashboard;
		}

		return false;
	});

	$executed = [];

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$executed) {
		$executed[] = $sql;

		return true;
	});

	$GLOBALS['request'] = [
		'dashboard_id' => '4',
		'panel_id'     => '77',
		'panel_delete' => '1'
	];

	$result = json_decode(syslog_dashboard_panel_save(), true);

	expect($result['error'])->toBe('Dashboard panel not found.', 'Deleting a foreign panel is refused');
	expect($executed)->toBe([], 'No delete statements run without panel ownership');

	// Moving a foreign panel is refused the same way.
	$GLOBALS['request']['panel_delete'] = '';
	$GLOBALS['request']['panel_move'] = 'up';

	$result = json_decode(syslog_dashboard_panel_save(), true);

	expect($result['error'])->toBe('Dashboard panel not found.', 'Moving a foreign panel is refused');
	expect($executed)->toBe([], 'No update statements run without panel ownership');
});

it('rejects panel definitions that fail validation or the search DSL', function () {
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslog';

	$_SESSION['sess_user_id'] = 42;

	test_override('get_username', function ($id) {
		return 'alice';
	});

	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) {
		if (str_contains($sql, 'syslog_dashboards') && str_contains($sql, 'INNER JOIN') === false) {
			return ['id' => 4, 'name' => 'Trends', 'user' => 'alice'];
		}

		return false;
	});

	$GLOBALS['request'] = [
		'dashboard_id' => '4',
		'source'       => 'syslog',
		'kind'         => 'timeseries',
		'chart'        => 'line',
		'field'        => 'host',
		'interval'     => 'dashboard',
		'timespan'     => 'dashboard',
		'removal'      => '1',
		'top_n'        => '10',
		'title'        => 'Errors over time',
		'expression'   => 'message contains "error" OR'
	];

	// The DSL must reject the dangling operator before any write.
	$result = json_decode(syslog_dashboard_panel_save(), true);

	expect($result)->toHaveKey('error');
	expect($result['error'])->toContain('Invalid logical search');

	// A valid expression with an invalid dimension is still refused.
	$GLOBALS['request']['expression'] = 'message contains "error"';
	$GLOBALS['request']['chart'] = 'donut';

	$result = json_decode(syslog_dashboard_panel_save(), true);

	expect($result)->toHaveKey('error');
	expect($result['error'])->toBe('Invalid dashboard chart type.');
});