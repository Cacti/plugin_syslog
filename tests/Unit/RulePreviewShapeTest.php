<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for the rule preview result shape: bounding, row
 * projection onto the configured incoming-table fields, and the error
 * paths.  Security/authorization coverage lives in RulePreviewTest.
 */

function preview_capture(array &$calls, array $overrides = []): void {
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params = []) use (&$calls, $overrides) {
		$calls[] = ['fn' => 'fetch_cell_prepared', 'sql' => $sql, 'params' => $params];

		return $overrides['count'] ?? '0';
	});

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = []) use (&$calls, $overrides) {
		$calls[] = ['fn' => 'fetch_assoc_prepared', 'sql' => $sql, 'params' => $params];

		return $overrides['sample'] ?? [];
	});

	test_override('syslog_db_fetch_cell', function ($sql) use (&$calls, $overrides) {
		$calls[] = ['fn' => 'fetch_cell', 'sql' => $sql, 'params' => []];

		return $overrides['facility'] ?? 0;
	});
}

function preview_setup(): void {
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

it('projects preview rows onto the configured incoming fields', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls, [
		'count'  => '2',
		'sample' => [
			['seq' => '5', 'logtime' => '2026-09-21 09:00:00', 'host' => 'h1', 'program' => 'p1', 'message' => 'm1', 'status' => '1', 'priority_id' => '3'],
			['seq' => '4', 'logtime' => '2026-09-21 08:59:00', 'host' => 'h2', 'program' => 'p2', 'message' => 'm2']
		]
	]);

	$preview = syslog_rule_preview(['type' => 'messagec', 'message' => 'm'], 'alert', 10);

	expect($preview['error'])->toBe('');
	expect($preview['count'])->toBe(2);
	expect($preview['rows'])->toHaveCount(2);

	// Only the display projection is returned; no raw row passthrough.
	expect($preview['rows'][0])->toBe([
		'seq'     => '5',
		'logtime' => '2026-09-21 09:00:00',
		'host'    => 'h1',
		'program' => 'p1',
		'message' => 'm1'
	]);

	// Newest first, ordered by seq.
	expect($preview['rows'][0]['seq'])->toBe('5');
	expect($preview['rows'][1]['seq'])->toBe('4');
});

it('returns empty rows for a rule that matches nothing', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls, ['count' => '0']);

	$preview = syslog_rule_preview(['type' => 'messagec', 'message' => 'none'], 'alert', 10);

	expect($preview['error'])->toBe('');
	expect($preview['count'])->toBe(0);
	expect($preview['rows'])->toBe([]);
});

it('reports an error when the filter document is invalid', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls);

	$preview = syslog_rule_preview(['type' => 'filter', 'message' => 'not-json'], 'alert', 10);

	expect($preview['error'])->not->toBe('');
	expect($preview['count'])->toBe(0);
	expect($preview['rows'])->toBe([]);
	expect($calls)->toBe([]);
});

it('reports an error when the count query fails', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls, ['count' => false]);

	$preview = syslog_rule_preview(['type' => 'messagec', 'message' => 'm'], 'alert', 10);

	expect($preview['error'])->not->toBe('');
	expect($preview['count'])->toBe(0);
});

it('previews removal rules against the incoming table', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls, [
		'count'  => '4',
		'sample' => [
			['seq' => '7', 'logtime' => '2026-09-21 10:00:00', 'host' => 'h', 'program' => 'p', 'message' => 'noise']
		]
	]);

	$rule = [
		'type'    => 'messagec',
		'message' => 'noise',
		'method'  => 'del'
	];

	$preview = syslog_rule_preview($rule, 'removal', 5);

	expect($preview['error'])->toBe('');
	expect($preview['count'])->toBe(4);
	expect($preview['rows'])->toHaveCount(1);

	// The preview SELECTs only.
	foreach ($calls as $call) {
		expect(str_starts_with(trim($call['sql']), 'SELECT'))->toBeTrue();
	}
});

it('previews a structured removal filter through the shared compiler', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls, ['count' => '1']);

	$rule = [
		'type'    => 'filter',
		'message' => json_encode(['version' => 1, 'conditions' => [
			['join' => 'AND', 'negative' => false, 'field' => 'message', 'operator' => 'begins', 'value' => 'systemd']
		]]),
		'method'  => 'trans',
		'name'    => 'Systemd noise'
	];

	$preview = syslog_rule_preview($rule, 'removal', 10);

	expect($preview['error'])->toBe('');
	expect($preview['count'])->toBe(1);

	// The compiled SQL uses the removal compiler's parameterized shape.
	$sample_sql = $calls[1]['sql'];

	expect(str_contains($sample_sql, 'LIKE ? ESCAPE'))->toBeTrue('The compiled filter must keep its ESCAPE clause.');
	expect(str_contains($sample_sql, 'systemd'))->toBeFalse('The filter value must be bound, not interpolated.');
	expect(str_contains($sample_sql, 'FROM'))->toBeTrue('The compiled WHERE must select from the incoming table.');
});

it('resolves legacy facility rules through the reference table', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls, [
		'facility' => '4',
		'count'    => '2'
	]);

	$preview = syslog_rule_preview(['type' => 'facility', 'message' => 'daemon'], 'removal', 10);

	expect($preview['error'])->toBe('');
	expect($preview['count'])->toBe(2);

	// The first call resolves the facility name to its id.
	$lookup = $calls[0];

	expect($lookup['fn'])->toBe('fetch_cell_prepared');
	expect(str_contains($lookup['sql'], 'syslog_facilities'))->toBeTrue();
	expect($lookup['params'])->toBe(['daemon']);
});

it('previews the sql type under the trusted-admin model', function () {
	syslog_load_plugin_source('functions.php');
	preview_setup();

	$calls = [];
	preview_capture($calls, ['count' => '1']);

	$rule = [
		'type'    => 'sql',
		'message' => "`message` LIKE '%keepalive%'"
	];

	$preview = syslog_rule_preview($rule, 'alert', 10);

	expect($preview['error'])->toBe('');
	expect($preview['count'])->toBe(1);

	// The hand written expression is embedded as-is; that is the
	// existing trusted-admin model for stored rules.
	expect(str_contains($calls[0]['sql'], "LIKE '%keepalive%'"))->toBeTrue();
});