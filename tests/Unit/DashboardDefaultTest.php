<?php

it('seeds valid starter panels atomically and does not reseed an existing default', function () {
	syslog_load_plugin_source('setup.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');
	test_override('syslog_db_table_exists', fn () => true);
	test_override('syslog_db_column_exists', fn () => true);
	$GLOBALS['syslogdb_default'] = 'syslog';
	test_override('syslog_dashboard_username', fn () => 'admin');
	test_override('syslog_db_fetch_insert_id', fn () => 42);
	$existing = false;
	$calls = [];
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params) use (&$existing) {
		return str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK') ? 1 : ($existing ? 42 : 0);
	});
	test_override('syslog_db_execute', function ($sql) use (&$calls) {
		$calls[] = $sql;
		return true;
	});
	$fail = false;
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$calls, &$fail) {
		$calls[] = [$sql, $params];
		return !$fail || !str_contains($sql, 'syslog_dashboard_panels');
	});

	syslog_dashboard_seed_default();
	expect($calls[0])->toBe('START TRANSACTION');
	expect($calls[1][1][1])->toBe('default');
	expect($calls[1][1][2])->toBe('');
	expect($calls[1][1][3])->toBe('on');
	expect(end($calls))->toBe('COMMIT');
	$panels = array_slice($calls, 2, 5);
	expect($panels)->toHaveCount(5);
	foreach ($panels as [$sql, $params]) {
		expect(syslog_dashboard_panel_settings([
			'source' => $params[3], 'removal' => (string) $params[4],
			'kind' => $params[5], 'chart' => $params[6], 'field' => $params[7],
			'interval' => $params[8], 'timespan' => $params[9], 'top_n' => $params[10]
		]))->toBeArray();
		expect($params[2])->toBe('');
	}
	expect($panels[1][1][9])->toBe('3600');

	$calls = [];
	$existing = true;
	syslog_dashboard_seed_default();
	expect($calls)->toBe([]);

	$existing = false;
	$fail = true;
	syslog_dashboard_seed_default();
	expect(end($calls))->toBe('ROLLBACK');
	expect($calls)->not->toContain('COMMIT');
});
