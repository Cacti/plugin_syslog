<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/**
 * Exercise the syslog_get_alert_sql() condition-only branch: it is the fast
 * path the consolidated alert scan uses, returning a bare WHERE fragment
 * plus params so syslog_process_alerts() can gate every rule in one query.
 */
afterEach(function () {
	syslog_test_reset_globals();
});

it('compiles filter rules in condition-only mode through QueryBuilder', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];

	$alert = [
		'type'    => 'filter',
		'message' => json_encode([
			'version'    => 1,
			'conditions' => [
				['join' => 'AND', 'negative' => false, 'field' => 'message', 'operator' => 'contains', 'value' => 'crash'],
			],
		]),
	];

	$out = syslog_get_alert_sql($alert, 55, false, true);

	// filter conditions bind their value through placeholders, never inline
	expect($out)->not->toBe([])
		->and($out['sql'])->toContain('message')
		->and($out['params'])->toBe(['%crash%'])
		->and(substr_count($out['sql'], '?'))->toBe(1);
});

it('returns an empty fragment for invalid filter JSON in condition-only mode', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];

	$alert = ['type' => 'filter', 'message' => '{not json'];

	$out = syslog_get_alert_sql($alert, 10, false, true);

	expect($out)->toBe([])
		->and($GLOBALS['syslog_rule_filter_error'] ?? '')->not->toBe('');
});

it('returns a prepared fragment for facility/message/host/program alerts in condition-only mode', function ($type, $message, $expected_sql, $expected_params) {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
		'facilityField' => 'facility_id', 'priorityField' => 'priority_id',
	];

	$alert = ['type' => $type, 'message' => $message];
	$out   = syslog_get_alert_sql($alert, 99, false, true);

	expect($out['sql'])->toBe($expected_sql)
		->and($out['params'])->toBe($expected_params);
})->with([
	['facility', 3, '`facility_id` = ?', [3]],
	['messageb', 'error', '`message` LIKE ?', ['error%']],
	['messagec', 'error', '`message` LIKE ?', ['%error%']],
	['messagee', 'error', '`message` LIKE ?', ['%error']],
	['host', 'router1', '`host` = ?', ['router1']],
	['program', 'sshd', '`program` = ?', ['sshd']],
	['sql', '1=1', '(1=1)', []],
]);

it('returns an empty fragment for unknown alert types in condition-only mode', function () {
	syslog_load_plugin_source('includes/functions.php');
	$alert = ['type' => 'notatype', 'message' => 'm'];

	expect(syslog_get_alert_sql($alert, 1, false, true))->toBe([]);
});
