<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Per phase processing telemetry: serialization through the syslog_status
 * mechanism and the Status tab rendering helpers.
 */

function phase_telemetry_status_store(array &$values): void {
	test_override('syslog_db_table_exists', function ($table) {
		return $table === 'syslog_status';
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$values) {
		if (str_contains($sql, 'syslog_status')) {
			$values[$params[0]] = $params[1];
		}

		return true;
	});
}

it('records phase telemetry through the syslog status mechanism', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';
	$values = [];
	phase_telemetry_status_store($values);

	expect(syslog_status_record_phase('transfer', 100, 105, 5.25, 1200))->toBeTrue();

	expect($values)->toHaveKey('phase_transfer');

	$decoded = json_decode($values['phase_transfer'], true);

	expect($decoded)->toBe([
		'start'   => 100,
		'end'     => 105,
		'seconds' => 5.25,
		'count'   => 1200
	]);
});

it('rejects unknown phase names and inverted time ranges', function () {
	syslog_load_plugin_source('functions.php');

	$values = [];
	phase_telemetry_status_store($values);

	expect(syslog_status_record_phase('not-a-phase', 100, 105, 5.0, 1))->toBeFalse();
	expect(syslog_status_record_phase('transfer', 105, 100, -5.0, 1))->toBeFalse();
	expect($values)->toBe([]);
});

it('rounds the phase duration to three decimals', function () {
	syslog_load_plugin_source('functions.php');

	$values = [];
	phase_telemetry_status_store($values);

	syslog_status_record_phase('alerts', 200, 203, 2.123456, 7);

	$decoded = json_decode($values['phase_alerts'], true);

	expect($decoded['seconds'])->toBe(2.123);
});

it('exposes the full phase list including all six processing phases', function () {
	syslog_load_plugin_source('functions.php');

	expect(syslog_status_phase_names())->toBe([
		'partition',
		'references',
		'removal',
		'alerts',
		'transfer',
		'reports'
	]);
});

it('reads back recorded telemetry and leaves unrecorded phases empty', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';

	$stored = [
		'phase_transfer' => '{"start":10,"end":14,"seconds":4.5,"count":99}'
	];

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = []) use (&$stored) {
		expect(str_contains($sql, 'syslog_status'))->toBeTrue();
		expect(str_contains($sql, "LIKE 'phase"))->toBeTrue(); // pattern starts with phase

		return array_map(function ($name, $value) {
			return ['name' => $name, 'value' => $value];
		}, array_keys($stored), $stored);
	});

	$telemetry = syslog_status_phase_telemetry();

	expect($telemetry['transfer'])->toBe([
		'start'   => 10,
		'end'     => 14,
		'seconds' => 4.5,
		'count'   => 99
	]);

	// Phases without a record read as null.
	expect($telemetry['partition'])->toBeNull();
	expect($telemetry['reports'])->toBeNull();
});

it('ignores malformed stored phase documents', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';

	$stored = [
		'phase_alerts'    => 'not-json',
		'phase_partition' => '{"start":1,"end":2}',
		'phase_unknown'   => '{"start":1,"end":2,"seconds":1,"count":1}'
	];

	test_override('syslog_db_fetch_assoc_prepared', function () use (&$stored) {
		return array_map(function ($name, $value) {
			return ['name' => $name, 'value' => $value];
		}, array_keys($stored), $stored);
	});

	$telemetry = syslog_status_phase_telemetry();

	expect($telemetry['alerts'])->toBeNull();
	expect($telemetry['partition'])->toBeNull();
	expect($telemetry['transfer'])->toBeNull();
});

it('renders phase timings with the slowest phase highlighted', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog';

	// The rendering path is a view over syslog_status_phase_telemetry();
	// assert the source renders the slowest phase summary and row class.
	$source = plugin_test_read_source('syslog.php');

	expect(str_contains($source, 'syslogStatusPhaseSlowest'))->toBeTrue();
	expect(str_contains($source, 'syslog_status_phase_telemetry()'))->toBeTrue();
	expect(str_contains($source, __('Slowest phase', 'syslog') === 'Slowest phase' ? 'Slowest phase' : 'Slowest phase'))->toBeTrue();

	// And the worker script records every phase.
	$process_source = plugin_test_read_source('syslog_process.php');

	foreach (['partition', 'references', 'removal', 'alerts', 'transfer', 'reports'] as $phase) {
		expect(str_contains($process_source, "syslog_status_record_phase('$phase'"))->toBeTrue("The poller must record telemetry for the '$phase' phase.");
	}
});