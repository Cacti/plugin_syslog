<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Report rules: the Filter Builder and SQL Expression report queries, and
 * the read-only "Test rule" preview that runs them against stored messages.
 */

function report_preview_capture(array &$calls, array $overrides = []): void {
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params = []) use (&$calls, $overrides) {
		$calls[] = ['fn' => 'fetch_cell_prepared', 'sql' => $sql, 'params' => $params];

		return array_key_exists('count', $overrides) ? $overrides['count'] : '0';
	});

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = []) use (&$calls, $overrides) {
		$calls[] = ['fn' => 'fetch_assoc_prepared', 'sql' => $sql, 'params' => $params];

		return array_key_exists('sample', $overrides) ? $overrides['sample'] : [];
	});
}

function report_filter_rule(string $value): array {
	return [
		'type'    => 'filter',
		'message' => json_encode(['version' => 1, 'conditions' => [
			['join' => 'AND', 'negative' => false, 'field' => 'message', 'operator' => 'contains', 'value' => $value]
		]])
	];
}

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default']         = 'syslogdb';
	$GLOBALS['syslog_rule_filter_error'] = '';
});

afterEach(function () {
	unset($GLOBALS['forcer']);
	syslog_test_reset_globals();
});

it('reads legacy report match types from the stored table', function (string $type, string $where) {
	$report = ['type' => $type, 'message' => 'core-sw1'];

	expect(syslog_get_report_sql($report))->toContain('FROM `syslogdb`.`syslog` AS sl')
		->and(syslog_get_report_sql($report))->toContain($where);
})->with([
	'begins with' => ['messageb', "message LIKE 'core-sw1%'"],
	'ends with'   => ['messagee', "message LIKE '%core-sw1'"],
	'host'        => ['host', "sh.host = 'core-sw1'"],
	'facility'    => ['facility', "sf.facility = 'core-sw1'"],
	'program'     => ['program', "sp.program = 'core-sw1'"]
]);

it('processes Filter Builder reports with their bound values ahead of the time window', function () {
	$GLOBALS['config']['base_path'] = dirname(__DIR__) . '/Fixtures/threshold_notifications';
	$GLOBALS['forcer']              = true;

	test_override('read_config_option', static fn () => '');
	test_override('syslog_db_execute_prepared', static fn () => true);
	test_override('syslog_db_fetch_assoc', static fn () => [array_merge(report_filter_rule('FLOWVIEW'), [
		'id' => 7, 'name' => 'Flow', 'timepart' => 0, 'lastsent' => 0, 'timespan' => 3600, 'email' => 'ops@example.com', 'body' => ''
	])]);

	$queries = [];
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = []) use (&$queries) {
		$queries[] = ['sql' => $sql, 'params' => $params];

		return [];
	});

	expect(syslog_process_reports())->toBe(['total_reports' => 1, 'sent_reports' => 0])
		->and($queries)->toHaveCount(1)
		->and($queries[0]['sql'])->toContain('AND logtime BETWEEN ? AND ?')
		->and($queries[0]['params'])->toHaveCount(3)
		->and($queries[0]['params'][0])->toBe('%FLOWVIEW%');
});

it('compiles a Filter Builder report to bound SQL over the stored table', function () {
	$report = report_filter_rule('FLOWVIEW');
	$params = ['stale'];

	$sql = syslog_get_report_sql($report, $params);

	expect($sql)->toContain('FROM `syslogdb`.`syslog` AS sl')
		->and($sql)->toContain('LEFT JOIN `syslogdb`.`syslog_hosts` AS sh')
		->and($sql)->toContain('LEFT JOIN `syslogdb`.`syslog_programs` AS sp')
		->and($sql)->toContain('WHERE (')
		->and($sql)->not->toContain('FLOWVIEW')
		->and($params)->toBe(['%FLOWVIEW%']);
});

it('returns no report SQL when the filter does not compile', function () {
	$report = ['type' => 'filter', 'message' => 'not-json'];
	$params = ['stale'];

	expect(syslog_get_report_sql($report, $params))->toBe('')
		->and($params)->toBe([])
		->and($GLOBALS['syslog_rule_filter_error'])->not->toBe('');
});

it('resolves host and program names for SQL Expression reports without joins', function () {
	$report = ['type' => 'sql', 'message' => 'host_id = 1'];

	$sql = syslog_get_report_sql($report);

	expect($sql)->toContain('FROM `syslogdb`.`syslog` AS syslog')
		->and($sql)->toContain('AS host')
		->and($sql)->toContain('AS program')
		->and($sql)->not->toContain('JOIN')
		->and($sql)->toContain('WHERE (host_id = 1)');
});

it('reads report SQL from an alternate source when one is given', function () {
	$report = ['type' => 'messagec', 'message' => 'x'];

	expect(syslog_get_report_sql($report, $params, '(SELECT * FROM recent)'))->toContain('FROM (SELECT * FROM recent) AS sl');
});

it('previews a report against the most recent stored messages', function () {
	$calls = [];
	report_preview_capture($calls, [
		'count'  => '2',
		'sample' => [
			['seq' => '9', 'logtime' => '2026-11-18 10:03:00', 'host' => 'edge-rtr2', 'program' => 'flowview', 'message' => 'FLOWVIEW: refresh failed', 'host_id' => '2']
		]
	]);

	$preview = syslog_rule_preview(report_filter_rule('FLOWVIEW'), 'report', 10);

	expect($preview['error'])->toBe('')
		->and($preview['count'])->toBe(2)
		->and($preview['rows'])->toBe([[
			'seq'     => '9',
			'logtime' => '2026-11-18 10:03:00',
			'host'    => 'edge-rtr2',
			'program' => 'flowview',
			'message' => 'FLOWVIEW: refresh failed'
		]])
		->and($preview['notice'])->toContain('most recent ' . SYSLOG_RULE_PREVIEW_HISTORY_ROWS . ' stored messages')
		->and($calls)->toHaveCount(2);

	foreach ($calls as $call) {
		expect($call['sql'])->toStartWith('SELECT')
			->and($call['sql'])->toContain('ORDER BY seq DESC LIMIT ' . SYSLOG_RULE_PREVIEW_HISTORY_ROWS)
			->and($call['params'])->toBe(['%FLOWVIEW%']);
	}

	expect($calls[1]['sql'])->toEndWith('ORDER BY logtime DESC LIMIT 10');
});

it('reports report-preview failures without guessing at results', function (array $rule, array $overrides, string $error, int $count, int $queries) {
	$calls = [];
	report_preview_capture($calls, $overrides);

	$preview = syslog_rule_preview($rule, 'report', 10);

	expect($preview['error'])->toBe($error)
		->and($preview['count'])->toBe($count)
		->and($preview['rows'])->toBe([])
		->and($calls)->toHaveCount($queries);
})->with([
	'invalid filter'  => [['type' => 'filter', 'message' => 'not-json'], [], 'The filter is not valid JSON.', 0, 0],
	'unknown type'    => [['type' => 'bogus', 'message' => 'x'], [], 'The rule did not compile to a query.', 0, 0],
	'count failure'   => [['type' => 'messagec', 'message' => 'x'], ['count' => false], 'The preview query could not be evaluated.', 0, 1],
	'sample failure'  => [['type' => 'messagec', 'message' => 'x'], ['count' => '3', 'sample' => false], 'The preview sample could not be read.', 3, 2]
]);
