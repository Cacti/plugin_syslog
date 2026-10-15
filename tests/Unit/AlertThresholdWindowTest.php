<?php

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['threshold_buckets'] = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) {
		if (str_starts_with($sql, 'DELETE FROM syslog_alert_counts')) {
			[$id, $cutoff, $hash] = $params;
			$GLOBALS['threshold_buckets'] = array_values(array_filter($GLOBALS['threshold_buckets'], static fn($b) => $b[0] !== $id || ($b[3] > $cutoff && $b[1] === $hash)));
		} elseif (str_starts_with($sql, 'INSERT INTO syslog_alert_counts')) {
			$GLOBALS['threshold_buckets'][] = $params;
		}
		return true;
	});
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params) {
		[$id, $hash, $scope, $cutoff, $now] = $params;
		return array_sum(array_map(static fn($b) => $b[4], array_filter($GLOBALS['threshold_buckets'], static fn($b) => $b[0] === $id && $b[1] === $hash && $b[2] === $scope && $b[3] > $cutoff && $b[3] <= $now)));
	});
});

function threshold_rule_fixture(): array {
	return ['id' => 7, 'method' => 1, 'level' => 0, 'num' => 5, 'type' => 'sql', 'message' => 'error', 'threshold_window' => 15, 'threshold_unit' => 'minute'];
}

it('accumulates five matches across cycles and expires the oldest batch at the boundary', function () {
	$rule = threshold_rule_fixture();
	expect(syslog_alert_window_count($rule, 2, '', 1000))->toBe(2);
	expect(syslog_alert_window_count($rule, 3, '', 1300))->toBe(5);
	expect(syslog_alert_window_count($rule, 1, '', 1900))->toBe(4);
	expect(syslog_alert_window_count($rule, 1, '', 2200))->toBe(2);
});

it('isolates devices and rules and discards counts after a definition change', function () {
	$rule = threshold_rule_fixture();
	$rule['level'] = 1;
	expect(syslog_alert_window_count($rule, 2, 'host-a', 1000))->toBe(2);
	expect(syslog_alert_window_count($rule, 3, 'host-b', 1000))->toBe(3);
	expect(syslog_alert_window_count($rule, 1, 'host-a', 1100))->toBe(3);
	$rule['id'] = 8;
	expect(syslog_alert_window_count($rule, 1, 'host-a', 1100))->toBe(1);
	$rule['id'] = 7;
	$rule['message'] = 'different';
	expect(syslog_alert_window_count($rule, 1, 'host-a', 1200))->toBe(1);
});

it('preserves per-cycle and individual rules and avoids firing during empty cycles', function () {
	$rule = threshold_rule_fixture();
	expect(syslog_alert_window_count($rule, 5, '', 1000))->toBe(5);
	expect(syslog_alert_window_count($rule, 0, '', 1100))->toBe(0);
	$rule['threshold_window'] = 0;
	expect(syslog_alert_window_count($rule, 2, '', 1200))->toBe(2);
	$rule['threshold_window'] = 15;
	$rule['method'] = 0;
	expect(syslog_alert_window_count($rule, 2, '', 1200))->toBe(2);
});

it('converts minutes hours and days and rejects invalid window settings', function () {
	foreach (['minute' => 60, 'hour' => 3600, 'day' => 86400] as $unit => $seconds) {
		expect(syslog_alert_window_seconds(['threshold_window' => 15, 'threshold_unit' => $unit]))->toBe(15 * $seconds);
	}
	foreach (['-1', '1.5', 'abc', '4294967296'] as $value) {
		expect(syslog_alert_window_seconds(['threshold_window' => $value]))->toBe(0);
	}
	expect(syslog_alert_window_seconds(['threshold_window' => 1, 'threshold_unit' => 'week']))->toBe(0);
});

it('does not notify using partial state when a count write fails', function () {
	test_override('syslog_db_execute_prepared', static fn() => false);
	expect(syslog_alert_window_count(threshold_rule_fixture(), 5, '', 1000))->toBe(0);
});

it('repairs window columns without requiring a version change', function () {
	$GLOBALS['config']['poller_id'] = 1;
	syslog_load_plugin_source('includes/schema.php');
	$columns = [];
	test_override('syslog_db_table_exists', static fn() => true);
	test_override('db_update_table', function ($table, $definition) use (&$columns) {
		if ($table === 'syslog_alert') {
			$columns = array_column($definition['columns'], 'name');
		}
		return true;
	});
	expect(syslog_ensure_table_structures())->toBeTrue();
	expect($columns)->toBe(['threshold_window', 'threshold_unit', 'threshold_breached']);
});
