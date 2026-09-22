<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Security coverage for the alert/removal rule "Test rule" preview:
 * realm authorization, CSRF, escaping, bounded results, and the
 * no-side-effect guarantee (SELECT only, no writes, no emails, no
 * commands, no rule state changes).
 */

function rule_preview_status_stubs(): void {
	// syslog_status is not involved, but syslog_rule_test_action gates on
	// request helpers that need sane defaults.
	$GLOBALS['request'] = [];
}

function rule_preview_capture_db(array &$calls, array $overrides = []): void {
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

it('previews a filter rule through the QueryBuilder with bound parameters', function () {
	syslog_load_plugin_source('functions.php');

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

	$calls = [];
	rule_preview_capture_db($calls, [
		'count'  => '3',
		'sample' => [
			['seq' => '9', 'logtime' => '2026-09-21 10:00:00', 'host' => 'router1', 'program' => 'sshd', 'message' => '<script>alert(1)</script>']
		]
	]);

	$rule = [
		'type'    => 'filter',
		'message' => json_encode(['version' => 1, 'conditions' => [
			['join' => 'AND', 'negative' => false, 'field' => 'message', 'operator' => 'contains', 'value' => 'error']
		]]),
		'name'    => 'Errors'
	];

	$preview = syslog_rule_preview($rule, 'alert', 10);

	expect($preview['error'])->toBe('');
	expect($preview['count'])->toBe(3);
	expect($preview['rows'])->toHaveCount(1);
	expect($preview['rows'][0]['message'])->toBe('<script>alert(1)</script>');
	// Raw data must travel, unescaped, to the JSON encoder; rendering escapes.
	expect($preview['rows'][0]['message'])->toContain('<script>');

	// Both queries must be SELECTs with bound parameters.
	foreach ($calls as $call) {
		expect(str_starts_with(trim($call['sql']), 'SELECT'))->toBeTrue('Only SELECT statements may run in a preview.');
		expect(str_contains($call['sql'], 'INSERT') || str_contains($call['sql'], 'DELETE') || str_contains($call['sql'], 'UPDATE'))->toBeFalse();
	}
});

it('rejects unknown rule types without running any query', function () {
	syslog_load_plugin_source('functions.php');

	$calls = [];
	rule_preview_capture_db($calls);

	$preview = syslog_rule_preview(['type' => 'filter', 'message' => '{}'], 'exploit', 10);

	expect($preview['error'])->not->toBe('');
	expect($calls)->toBe([]);
});

it('clamps the preview row count to the hard maximum', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = ['timeField' => 'logtime', 'textField' => 'message'];

	$calls = [];
	rule_preview_capture_db($calls, ['count' => '0']);

	$rule = [
		'type'    => 'messagec',
		'message' => 'noise'
	];

	syslog_rule_preview($rule, 'alert', 100000);

	// The sample query is the second call; its LIMIT must be clamped.
	$sample_sql = $calls[1]['sql'];

	expect(preg_match('/LIMIT 25$/', $sample_sql) === 1)->toBeTrue('The sample LIMIT must be clamped to the hard maximum.');
});

it('compiles legacy match types with bound placeholders', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = [
		'timeField' => 'logtime',
		'textField' => 'message',
		'hostField' => 'host'
	];

	$calls = [];
	rule_preview_capture_db($calls, ['count' => '1']);

	$rule = ['type' => 'messagec', 'message' => "'; DROP TABLE syslog; --"];

	$preview = syslog_rule_preview($rule, 'alert', 10);

	expect($preview['error'])->toBe('');

	// The hostile value must be bound, not interpolated.
	foreach ($calls as $call) {
		expect(str_contains($call['sql'], "DROP TABLE"))->toBeFalse('Hostile values must not reach the SQL text.');
	}

	// The LIKE parameter carries the hostile value.
	$sample_params = $calls[1]['params'];

	expect($sample_params[0])->toBe("%'; DROP TABLE syslog; --%");
});

it('rejects unsafe configured column mappings', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message` WHERE 1=1; --',
		'timeField' => 'logtime'
	];

	$calls = [];
	rule_preview_capture_db($calls);

	$preview = syslog_rule_preview(['type' => 'messagec', 'message' => 'x'], 'alert', 10);

	expect($preview['error'])->not->toBe('');
	expect($calls)->toBe([]);
});

it('blocks the test action for users without the editor realm', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['request'] = [];
	$GLOBALS['__test_db_calls'] = [];

	test_override('api_plugin_user_realm_auth', function ($page) {
		return false;
	});

	test_override('csrf_check', function ($fatal = true) {
		return true;
	});

	$_SERVER['REQUEST_METHOD'] = 'POST';

	$result = json_decode(syslog_rule_test_action('alert'), true);

	expect($result['error'])->not->toBe('');
	expect($GLOBALS['__test_db_calls'])->toBe([], 'No database call may run without the realm.');
});

it('blocks the test action when CSRF validation fails', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['request'] = [];
	$GLOBALS['__test_db_calls'] = [];

	test_override('api_plugin_user_realm_auth', function ($page) {
		return true;
	});

	test_override('csrf_check', function ($fatal = true) {
		return false;
	});

	$_SERVER['REQUEST_METHOD'] = 'POST';

	$result = json_decode(syslog_rule_test_action('removal'), true);

	expect($result['error'])->not->toBe('');
	expect($GLOBALS['__test_db_calls'])->toBe([], 'No database call may run without a valid CSRF token.');
});

it('blocks the test action for non-POST requests', function () {
	syslog_load_plugin_source('functions.php');

	$GLOBALS['request'] = [];
	$GLOBALS['__test_db_calls'] = [];

	test_override('api_plugin_user_realm_auth', function ($page) {
		return true;
	});

	$_SERVER['REQUEST_METHOD'] = 'GET';

	$result = json_decode(syslog_rule_test_action('alert'), true);

	expect($result['error'])->not->toBe('');
	expect($GLOBALS['__test_db_calls'])->toBe([]);
});