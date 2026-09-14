<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the query-builder dropdown/suggestion data:
 * choice lists must expose readable labels, suggestion values must be
 * normalized strings bound as SQL parameters (never interpolated), and
 * unknown or unavailable fields must not reach the database at all.
 */

it('builds search choices and suggestions safely, rejecting unknown fields', function () {
	syslog_load_plugin_source('functions.php');

	test_override('syslog_db_fetch_assoc', function ($sql) {
		return [['id' => 4, 'name' => 'warning']];
	});

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		$GLOBALS['lookup_sql']    = $sql;
		$GLOBALS['lookup_params'] = $params;

		return [['value' => 4, 'label' => 'warning']];
	});

	$GLOBALS['syslogdb_default'] = 'syslog';

	$choices = syslog_search_choices();

	expect($choices['priority_id'])->toBe([['4', 'warning (4)']], 'Dropdown stores ID with readable label');
	expect($choices['facility'])->toBe([['warning', 'warning']], 'Named dropdown stores database name');
	expect(isset($choices['program_id'], $choices['facility_id']))->toBeTrue('ID dropdowns populated');

	$results = syslog_search_suggestions('host', "a%_!'", 'syslog', '-1');

	expect($results)->toBe([['value' => '4', 'label' => 'warning']], 'Suggestion values normalized to strings');
	expect($GLOBALS['lookup_params'])->toBe(["%a!%!_!!'%", "%a!%!_!!'%"], 'Literal search safely parameterized');
	expect(str_contains($GLOBALS['lookup_sql'], 'syslog_hosts') && str_contains($GLOBALS['lookup_sql'], 'LIMIT 30'))->toBeTrue('Host suggestions bounded');

	syslog_search_suggestions('message', 'error', 'syslog', '1');

	expect(str_contains($GLOBALS['lookup_sql'], 'syslog_removed') && str_contains($GLOBALS['lookup_sql'], 'UNION ALL'))->toBeTrue('Both record sources suggested');
	expect(substr_count($GLOBALS['lookup_sql'], 'LIMIT 1000'))->toBe(2, 'Message sample bounded per source');

	syslog_search_suggestions('message', 'error', 'alerts', '-1');

	expect(str_contains($GLOBALS['lookup_sql'], 'logmsg') && str_contains($GLOBALS['lookup_sql'], 'syslog_logs'))->toBeTrue('Alerts use alert message column');

	$last_sql = $GLOBALS['lookup_sql'];

	expect(syslog_search_suggestions('password', '', 'syslog', '-1'))->toBe([], 'Unknown fields rejected');
	expect(syslog_search_suggestions('host_id', '', 'alerts', '-1'))->toBe([], 'Unavailable alert host ID rejected');
	expect($GLOBALS['lookup_sql'])->toBe($last_sql, 'Invalid fields do not execute SQL');
});
