<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

afterEach(function () {
	syslog_test_reset_globals();
});

it('previews bounded stored history when an alert filter has no incoming matches', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog_preview_test';
	$GLOBALS['syslog_incoming_config'] = [
		'textField'     => 'raw_message',
		'timeField'     => 'raw_time',
		'hostField'     => 'host',
		'programField'  => 'program',
		'facilityField' => 'facility_id',
		'priorityField' => 'priority_id'
	];

	$count_calls = 0;
	$sample_sql  = '';

	test_override('syslog_db_table_exists', static fn($table) => $table === 'syslog');
	test_override('syslog_db_fetch_cell_prepared', function () use (&$count_calls) {
		return ++$count_calls === 1 ? 0 : '1';
	});
	test_override('syslog_db_fetch_assoc_prepared', function ($sql) use (&$sample_sql) {
		$sample_sql = $sql;

		return [[
			'seq'     => 12,
			'logtime' => '2026-10-26 12:00:00',
			'host'    => 'router01',
			'program' => 'sshd',
			'message' => 'Disconnected from a test session'
		]];
	});

	$rule = [
		'type'    => 'filter',
		'message' => json_encode([
			'version'    => 1,
			'conditions' => [[
				'join'     => 'AND',
				'negative' => false,
				'field'    => 'message',
				'operator' => 'begins',
				'value'    => 'Disconnect'
			]]
		], JSON_THROW_ON_ERROR)
	];

	$result = syslog_rule_preview($rule, 'alert');

	expect($result['error'])->toBe('')
		->and($result['count'])->toBe(1)
		->and($result['history_limit'])->toBe(SYSLOG_RULE_PREVIEW_HISTORY_ROWS)
		->and($result['rows'][0])->toBe([
			'seq'     => '12',
			'logtime' => '2026-10-26 12:00:00',
			'host'    => 'router01',
			'program' => 'sshd',
			'message' => 'Disconnected from a test session'
		])
		->and($sample_sql)->toContain('ORDER BY seq DESC LIMIT 10000')
		->and($count_calls)->toBe(2);
});

it('returns a compile error if host normalization exceeds the condition limit', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog_preview_test';
	$GLOBALS['syslog_incoming_config'] = [
		'textField'     => 'message',
		'hostField'     => 'host',
		'programField'  => 'program',
		'facilityField' => 'facility_id',
		'priorityField' => 'priority_id'
	];

	test_override('syslog_db_table_exists', static fn($table) => $table === 'syslog');
	test_override('syslog_db_fetch_cell_prepared', static fn() => 0);
	test_override('syslog_db_fetch_assoc_prepared', static function () {
		return array_map(static fn($id) => ['id' => $id], range(1, 129));
	});

	$rule = [
		'type'    => 'filter',
		'message' => json_encode([
			'version'    => 1,
			'conditions' => [[
				'join'     => 'AND',
				'negative' => false,
				'field'    => 'host',
				'operator' => 'contains',
				'value'    => 'router'
			]]
		], JSON_THROW_ON_ERROR)
	];

	$result = syslog_rule_preview($rule, 'alert');

	expect($result['error'])->toBe('The historical preview filter could not be compiled.')
		->and($result['count'])->toBe(0)
		->and($result['rows'])->toBe([]);
});

it('reports failure when the historical preview count cannot be evaluated', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['syslogdb_default'] = 'syslog_preview_test';
	$GLOBALS['syslog_incoming_config'] = [
		'textField'     => 'message',
		'hostField'     => 'host',
		'programField'  => 'program',
		'facilityField' => 'facility_id',
		'priorityField' => 'priority_id'
	];

	$count_calls = 0;

	test_override('syslog_db_table_exists', static fn($table) => $table === 'syslog');
	test_override('syslog_db_fetch_cell_prepared', function () use (&$count_calls) {
		return ++$count_calls === 1 ? 0 : false;
	});

	$rule = [
		'type'    => 'filter',
		'message' => json_encode([
			'version'    => 1,
			'conditions' => [[
				'join'     => 'AND',
				'negative' => false,
				'field'    => 'message',
				'operator' => 'begins',
				'value'    => 'Disconnect'
			]]
		], JSON_THROW_ON_ERROR)
	];

	$result = syslog_rule_preview($rule, 'alert');

	expect($result['error'])->toBe('The historical preview query could not be evaluated.')
		->and($result['count'])->toBe(0)
		->and($result['rows'])->toBe([])
		->and($count_calls)->toBe(2);
});
