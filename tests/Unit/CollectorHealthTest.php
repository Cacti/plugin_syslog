<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Collector health metrics on the Syslog Status tab.
 *
 * syslog.php is a page, not a library, so the functions under test are
 * extracted from the source and evaluated (the pattern
 * DefaultDateReappliedTest and the status_storage regression script use).
 * The queries must run against the real Syslog database connection with
 * the configured incoming-table field mappings, degrade to 'Unavailable'
 * instead of invented values when data cannot be read, and warn when the
 * data looks stale or the backlog exceeds the configured threshold.
 */

function collector_health_extract_function(string $name): string {
	static $source = null;

	if ($source === null) {
		$source = plugin_test_read_source('syslog.php');
	}

	$start = strpos($source, "function $name(");

	if ($start === false) {
		throw new RuntimeException("Function $name not found in syslog.php");
	}

	$end = strpos($source, "\n}", $start);

	if ($end === false) {
		throw new RuntimeException("Function body of $name not terminated");
	}

	return substr($source, $start, $end - $start + 2);
}

function collector_health_load_functions(): void {
	static $loaded = false;

	if ($loaded) {
		return;
	}

	foreach (['syslog_status_collector_health', 'syslog_status_collector_unavailable', 'syslog_status_format_age', 'syslog_status_format_age_value', 'syslog_status_format_count'] as $name) {
		$code = collector_health_extract_function($name);

		if (!function_exists($name)) {
			eval($code);
		}
	}

	// The constants live at page scope; declare them for the tests.
	if (!defined('SYSLOG_COLLECTOR_STALE_SECONDS')) {
		define('SYSLOG_COLLECTOR_STALE_SECONDS', 300);
	}

	if (!defined('SYSLOG_COLLECTOR_BACKLOG_THRESHOLD')) {
		define('SYSLOG_COLLECTOR_BACKLOG_THRESHOLD', 10000);
	}

	$loaded = true;
}

function collector_health_setup_config(): void {
	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = [
		'timeField'     => 'logtime',
		'id'            => 'seq',
		'hostField'     => 'host',
		'programField'  => 'program',
		'textField'     => 'message',
		'facilityField' => 'facility_id',
		'priorityField' => 'priority_id'
	];
}

function collector_health_capture_db(array &$calls, array $overrides = []): void {
	test_override('syslog_db_fetch_cell', function ($sql) use (&$calls, $overrides) {
		$calls[] = $sql;

		if (str_contains($sql, 'MAX(')) {
			return $overrides['max'] ?? false;
		}

		if (str_contains($sql, 'MIN(')) {
			return $overrides['min'] ?? false;
		}

		if (str_contains($sql, 'COUNT(*)')) {
			return $overrides['count'] ?? false;
		}

		return false;
	});

	// syslog_status_get() is a real function; drive it through the
	// syslog_status table stubs so the processed metric reads 42.
	test_override('syslog_db_table_exists', function ($table) {
		return $table === 'syslog_status';
	});

	$values = ['last_record_count' => '42'];

	test_override('syslog_db_fetch_assoc', function () use (&$values) {
		return array_map(function ($name, $value) {
			return ['name' => $name, 'value' => $value, 'updated' => time()];
		}, array_keys($values), $values);
	});
}

it('formats healthy collector metrics from the configured incoming table', function () {
	syslog_load_plugin_source('functions.php');
	collector_health_load_functions();
	collector_health_setup_config();

	$fresh  = date('Y-m-d H:i:s', time() - 30);
	$oldest = date('Y-m-d H:i:s', time() - 120);

	$calls = [];
	collector_health_capture_db($calls, [
		'max'   => $fresh,
		'min'   => $oldest,
		'count' => '5'
	]);

	$health = syslog_status_collector_health();

	expect($health['last_received'])->toBe($fresh);
	expect($health['backlog'])->toBe('5');
	expect($health['processed'])->toBe('42');
	expect($health['warning'])->toBeFalse();
	// The age is computed against a moving clock; assert the shape.
	expect($health['oldest_age'])->toContain('minute');

	// Every metric must come from the configured database and columns.
	expect(cacti_sizeof($calls))->toBe(3);

	foreach ($calls as $sql) {
		expect($sql)->toContain('`syslogdb`.`syslog_incoming`');
	}

	// The timestamp metrics use the configured time field.
	$timestamp_queries = array_filter($calls, function ($sql) {
		return str_contains($sql, 'MAX(') || str_contains($sql, 'MIN(');
	});

	expect(cacti_sizeof($timestamp_queries))->toBe(2);

	foreach ($timestamp_queries as $sql) {
		expect($sql)->toContain('`logtime`');
	}
});

it('marks collector metrics unavailable when the database cannot answer', function () {
	syslog_load_plugin_source('functions.php');
	collector_health_load_functions();
	collector_health_setup_config();

	$calls = [];
	collector_health_capture_db($calls, [
		'max'   => false,
		'min'   => false,
		'count' => false
	]);

	$health = syslog_status_collector_health();

	$unavailable = __('Unavailable', 'syslog');

	expect($health['last_received'])->toBe($unavailable);
	expect($health['oldest_age'])->toBe($unavailable);
	expect($health['backlog'])->toBe($unavailable);
	expect($health['warning'])->toBeFalse();
});

it('warns when the incoming backlog exceeds the configured threshold', function () {
	syslog_load_plugin_source('functions.php');
	collector_health_load_functions();
	collector_health_setup_config();

	$fresh = date('Y-m-d H:i:s', time() - 30);

	$calls = [];
	collector_health_capture_db($calls, [
		'max'   => $fresh,
		'min'   => $fresh,
		'count' => '25000'
	]);

	$health = syslog_status_collector_health();

	expect($health['warning'])->toBeTrue();
	expect($health['warning_text'])->toContain('25,000');
});

it('uses the default thresholds when the settings are unset', function () {
	syslog_load_plugin_source('functions.php');
	collector_health_load_functions();
	collector_health_setup_config();

	// Just over the 10000 default.
	$fresh = date('Y-m-d H:i:s', time() - 30);

	$calls = [];
	collector_health_capture_db($calls, [
		'max'   => $fresh,
		'min'   => $fresh,
		'count' => '10001'
	]);

	$health = syslog_status_collector_health();

	expect($health['warning'])->toBeTrue();
	expect($health['warning_text'])->toContain('10,001');
});

it('does not warn when metrics are within thresholds', function () {
	syslog_load_plugin_source('functions.php');
	collector_health_load_functions();
	collector_health_setup_config();

	$fresh = date('Y-m-d H:i:s', time() - 30);

	$calls = [];
	collector_health_capture_db($calls, [
		'max'   => $fresh,
		'min'   => $fresh,
		'count' => '10'
	]);

	$health = syslog_status_collector_health();

	expect($health['warning'])->toBeFalse();
	expect($health['warning_text'])->toBe('');
});

it('rejects unsafe incoming table column mappings as unavailable', function () {
	syslog_load_plugin_source('functions.php');
	collector_health_load_functions();

	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = [
		'timeField' => 'logtime; DROP TABLE syslog',
		'id'        => 'seq'
	];

	$health = syslog_status_collector_health();

	$unavailable = __('Unavailable', 'syslog');

	expect($health['last_received'])->toBe($unavailable);
	expect($health['backlog'])->toBe($unavailable);
});

it('formats compact human readable ages', function () {
	syslog_load_plugin_source('functions.php');
	collector_health_load_functions();

	expect(syslog_status_format_age(45))->toContain('second');
	expect(syslog_status_format_age(90))->toContain('minute');
	expect(syslog_status_format_age(5400))->toContain('hour');
	expect(syslog_status_format_age(90000))->toContain('day');
});