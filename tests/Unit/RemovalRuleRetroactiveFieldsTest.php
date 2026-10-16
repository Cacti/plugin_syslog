<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Retroactive removal rules compile against the normalized syslog table:
 * host/program names translate to their id columns through the reference
 * tables, and the QueryBuilder field map exposes the id columns.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

it('exposes id-column fields for the normalized syslog table', function () {
	syslog_load_plugin_source('includes/functions.php');

	$fields = syslog_get_removal_rule_fields('syslog');

	expect(array_keys($fields))->toBe(['message', 'host_id', 'program_id', 'facility_id', 'priority_id'])
		->and($fields['host_id']['column'])->toBe('`host_id`')
		->and($fields['host_id']['type'])->toBe('integer')
		->and($fields['message']['operators'])->toContain('contains');
});

it('maps host and program names to their normalized id tables', function () {
	syslog_load_plugin_source('includes/functions.php');

	$json = json_encode([
		'version'    => 1,
		'conditions' => [
			['join' => 'AND', 'negative' => false, 'field' => 'host', 'operator' => '=', 'value' => 'router01'],
			['join' => 'OR', 'negative' => false, 'field' => 'program', 'operator' => 'contains', 'value' => 'sshd']
		]
	]);

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		if (str_contains($sql, 'syslog_hosts')) {
			return [['id' => 12]];
		}

		if (str_contains($sql, 'syslog_programs')) {
			return [['id' => 9]];
		}

		return [];
	});

	$document = syslog_removal_filter_normalize($json, 'syslog');

	$hosts = array_values(array_filter($document['conditions'], fn ($c) => ($c['field'] ?? '') === 'host_id'));
	$programs = array_values(array_filter($document['conditions'], fn ($c) => ($c['field'] ?? '') === 'program_id'));

	expect($hosts)->toHaveCount(1)
		->and($hosts[0]['operator'])->toBe('=')
		->and($hosts[0]['value'])->toBe('12')
		->and($programs)->toHaveCount(1)
		->and($programs[0]['operator'])->toBe('=')
		->and($programs[0]['value'])->toBe('9');
});

it('compiles a retroactive filter rule against the syslog table', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params = []) {
		if (str_contains($sql, 'syslog_hosts')) {
			return [['id' => 12]];
		}

		return [];
	});

	$remove = ['type' => 'filter', 'max_seq' => 55, 'message' => json_encode([
		'version'    => 1,
		'conditions' => [
			['join' => 'AND', 'negative' => false, 'field' => 'host', 'operator' => '=', 'value' => 'router01']
		]
	])];

	$retro = syslog_get_removal_rule_sql($remove, 'syslog');

	expect($retro)->not->toBe([])
		->and($retro['sql'])->toContain('`host_id` = ?')
		->and($retro['params'])->toContain('12');
});

it('marks an unresolvable host as an unmatchable id', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslogdb';

	test_override('syslog_db_fetch_assoc_prepared', function () {
		return [];
	});

	$remove = ['type' => 'filter', 'max_seq' => 55, 'message' => json_encode([
		'version'    => 1,
		'conditions' => [
			['join' => 'AND', 'negative' => false, 'field' => 'host', 'operator' => '=', 'value' => 'no-such-host']
		]
	])];

	$retro = syslog_get_removal_rule_sql($remove, 'syslog');

	expect($retro)->not->toBe([])
		->and($retro['sql'])->toContain('`host_id` = ?')
		->and(end($retro['params']))->toBe('0');
});
