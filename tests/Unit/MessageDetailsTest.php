<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The message details flyout dispatch: syslog.php?action=message_details
 * reads one record by table and sequence id and returns it with the
 * recommendations that match its message.
 */

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('includes/recommendations.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';

	// No rule-editor realms, so rule links stay empty unless a test grants them.
	test_override('db_fetch_assoc_prepared', static fn () => []);
	test_override('is_realm_allowed', static fn () => false);
});

afterEach(function () {
	syslog_test_reset_globals();
});

function message_details_capture(array &$calls, $row, array $recommendations = []): void {
	test_override('syslog_db_fetch_row_prepared', function ($sql, $params = []) use (&$calls, $row) {
		$calls[] = ['sql' => $sql, 'params' => $params];

		return $row;
	});

	test_override('syslog_db_fetch_assoc_prepared', static fn () => $recommendations);
}

it('reads a stored message by id and logtime with its recommendations', function () {
	$calls = [];
	message_details_capture($calls, [
		'seq' => '15', 'logtime' => '2026-11-18 12:00:25', 'message' => '%LINK-3-UPDOWN: Interface Gi1/0/24, changed state to down',
		'threshold_breached' => '1', 'host' => 'core-sw1', 'program' => 'CACTI', 'facility' => 'local7', 'priority' => 'err'
	], [[
		'vendor' => 'Cisco', 'identifier' => '%LINK-3-UPDOWN', 'component' => 'LINK', 'severity' => '3', 'message' => '',
		'explanation' => 'The interface changed state.', 'recommended_action' => 'Check the cable.', 'reference' => ''
	]]);

	$details = syslog_message_details('main', '15', '2026-11-18 12:00:25');

	expect($calls[0]['sql'])->toContain('FROM `syslogdb`.`syslog` AS s')
		->and($calls[0]['sql'])->toContain('AND s.logtime = ?')
		->and($calls[0]['params'])->toBe([15, '2026-11-18 12:00:25'])
		->and($details['found'])->toBeTrue()
		->and($details['record'])->toBe([
			'id' => '15', 'source' => 'main', 'received' => '2026-11-18 12:00:25', 'device' => 'core-sw1', 'program' => 'CACTI',
			'facility' => 'local7', 'severity' => 'err', 'message' => '%LINK-3-UPDOWN: Interface Gi1/0/24, changed state to down',
			'breached' => true, 'alert' => '', 'count' => null
		])
		->and($details['rules'])->toBe([])
		->and($details['recommendations'][0]['identifier'])->toBe('%LINK-3-UPDOWN')
		->and($details['recommendations'][0]['severity_name'])->not->toBe('');
});

it('reads removed messages without a logtime and alert-log entries with their rule', function () {
	$calls = [];
	message_details_capture($calls, ['seq' => '7', 'logtime' => '2026-11-18 10:00:00', 'message' => 'm', 'host' => 'h']);

	syslog_message_details('remove', 7, 'not a time');

	expect($calls[0]['sql'])->toContain('FROM `syslogdb`.`syslog_removed` AS s')
		->and($calls[0]['sql'])->not->toContain('s.logtime = ?')
		->and($calls[0]['params'])->toBe([7]);

	$calls = [];
	message_details_capture($calls, ['seq' => '3', 'logtime' => '2026-11-18 10:00:00', 'message' => 'm', 'host' => 'h', 'alert' => 'Config changed', 'count' => '4']);

	$details = syslog_message_details('alert', '3');

	expect($calls[0]['sql'])->toContain('FROM `syslogdb`.`syslog_logs` AS l')
		->and($calls[0]['sql'])->toContain('LEFT JOIN `syslogdb`.`syslog_alert` AS sa')
		->and($details['record']['alert'])->toBe('Config changed')
		->and($details['record']['count'])->toBe(4)
		->and($details['record']['breached'])->toBeFalse();
});

it('rejects unknown tables, invalid ids and missing records', function ($source, $id, $row, $queries) {
	$calls = [];
	message_details_capture($calls, $row);

	expect(syslog_message_details($source, $id))->toBe(['found' => false])
		->and($calls)->toHaveCount($queries);
})->with([
	'unknown table'  => ['user_auth', '1', [], 0],
	'non-numeric id' => ['main', '1 OR 1=1', [], 0],
	'zero id'        => ['main', '0', [], 0],
	'missing record' => ['main', '99', [], 1],
	'failed query'   => ['alert', '99', false, 1]
]);

it('encodes the dispatch response with the recommendation labels', function () {
	$calls = [];
	message_details_capture($calls, []);

	$json = json_decode(syslog_message_details_json('main', '5'), true);

	expect($json['found'])->toBeFalse()
		->and($json['labels'])->toHaveKeys(['explanation', 'action', 'reference']);
});

it('names a dispatchable record on the row button only for known tables and ids', function ($id, $source, $expected) {
	$html = syslog_message_button('msg', 'router', 'kernel', 'kern', 'warning', '2026-11-18 10:00:00', $id, $source);

	preg_match('/data-message="([^"]*)"/', $html, $matches);
	$data = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

	expect(array_intersect_key($data, ['id' => 1, 'source' => 1]))->toBe($expected);
})->with([
	'stored'          => [15, 'main', ['id' => '15', 'source' => 'main']],
	'removed'         => ['8', 'remove', ['id' => '8', 'source' => 'remove']],
	'alert log'       => [3, 'alert', ['id' => '3', 'source' => 'alert']],
	'unknown table'   => [15, 'removed', []],
	'no id'           => [0, 'main', []],
	'non-numeric id'  => ['15&x=1', 'main', []]
]);
