<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The dashboard SQL builders compose statements from allowlisted table
 * names, fixed aliases (so logical-search predicates bind exactly like the
 * log viewer queries), and integer bucket/window literals. These tests
 * pin the shape of that composition and verify that ownership scoping on
 * every load cannot be bypassed by panel or dashboard id.
 */

it('builds timeseries SQL with fixed aliases and integer literals', function () {
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$sql = syslog_dashboard_timeseries_sql('syslog', '-1', '(LOCATE(\'error\', message) > 0) AND syslog.logtime BETWEEN FROM_UNIXTIME(1000) AND FROM_UNIXTIME(2000)', 3600, 1000);

	// Log tables are aliased syslog so DSL predicates bind; buckets are
	// integer literals; the predicate is fully substituted.
	expect($sql)->toContain('FROM `syslogdb`.`syslog` AS syslog');
	expect($sql)->toContain('FLOOR(UNIX_TIMESTAMP(syslog.logtime) / 3600) * 3600 AS bucket');
	expect($sql)->toContain('COUNT(*) AS records');
	expect($sql)->toContain("(LOCATE('error', message) > 0)");
	expect($sql)->not->toContain('%s');

	$sql = syslog_dashboard_timeseries_sql('alerts', '1', '1=1', 60, 0);

	// Alert logs sum grouped occurrences and keep the syslog alias.
	expect($sql)->toContain('SUM(syslog.count) AS records');
	expect($sql)->toContain('`syslogdb`.`syslog_logs` AS syslog');

	// All records = UNION over both system log tables, summed per bucket.
	$sql = syslog_dashboard_timeseries_sql('syslog', '1', '1=1', 60, 0);

	expect(substr_count($sql, 'UNION ALL'))->toBe(1);
	expect($sql)->toContain('SUM(records) AS records');
	expect($sql)->toContain('`syslogdb`.`syslog_removed` AS syslog');
	expect(substr_count($sql, 'WHERE 1=1'))->toBe(2);

	// The bucket select keeps its trailing comma only before the aggregate;
	// each union branch must not emit a double comma before the mtype tag.
	expect($sql)->toContain("COUNT(*) AS records, 'main' AS mtype");
	expect($sql)->toContain("COUNT(*) AS records, 'remove' AS mtype");
	expect($sql)->not->toContain(', ,');
});

it('resolves breakdown dimensions through lookup subqueries', function () {
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$sql = syslog_dashboard_breakdown_sql('syslog', 'host', '-1', '1=1', 0);

	// Host breakdown joins the host table and binds on host_id.
	expect($sql)->toContain('FROM `syslogdb`.`syslog_hosts` AS lookup');
	expect($sql)->toContain('lookup.host_id = syslog.host_id');
	expect($sql)->toContain('GROUP BY label ORDER BY records DESC');

	$sql = syslog_dashboard_breakdown_sql('syslog', 'facility', '-1', '1=1', 0);

	expect($sql)->toContain('`syslogdb`.`syslog_facilities`');

	// Alert logs store the host name directly; no lookup is needed.
	$sql = syslog_dashboard_breakdown_sql('alerts', 'host', '1', '1=1', 0);

	expect($sql)->toContain('syslog.host AS label');
	expect($sql)->toContain('SUM(syslog.count)');
	expect($sql)->not->toContain('syslog_hosts');
});

it('scopes panel and dashboard loads to the requesting user', function () {
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$_SESSION['sess_user_id'] = 42;

	$calls = [];

	test_override('get_username', function ($id) {
		return 'alice';
	});

	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) use (&$calls) {
		$calls[] = ['sql' => $sql, 'params' => $params];

		return false;
	});

	syslog_dashboard_load(7);

	// Loads bind the id and owner parameters.
	expect($calls[0]['sql'])->toContain('WHERE id = ?');
	expect($calls[0]['sql'])->toContain("AND `user` = ?");
	expect($calls[0]['params'])->toBe([7, 'alice']);

	$calls = [];

	syslog_dashboard_load_panel(13);

	// Panel loads join dashboard ownership and scope by owner.
	expect($calls[0]['sql'])->toContain('INNER JOIN `syslogdb`.`syslog_dashboards`');
	expect($calls[0]['sql'])->toContain("AND d.`user` = ?");
	expect($calls[0]['params'])->toBe([13, 'alice']);
});

it('repositions panels within one dashboard only', function () {
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$updates = [];

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		if (str_contains($sql, 'ORDER BY position')) {
			return [
				['id' => 3, 'position' => 1],
				['id' => 5, 'position' => 2],
				['id' => 9, 'position' => 3]
			];
		}

		return [];
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$updates) {
		if (str_contains($sql, 'SET position = ?')) {
			$updates[] = $params;
		}

		return true;
	});

	syslog_dashboard_panel_move(['id' => 5], 1, 1);

	// Moving 5 down swaps it with 9: order becomes 3, 9, 5 → positions 1..3.
	expect($updates)->toBe([[1, 3], [2, 9], [3, 5]], 'Positions are renumbered after the swap');

	// Moving the first panel up is a no-op.
	$updates = [];
	syslog_dashboard_panel_move(['id' => 3], 1, -1);
	expect($updates)->toBe([], 'Moving the first panel up changes nothing');
});

it('repositions panels by absolute drag position', function () {
	syslog_load_plugin_source('functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$GLOBALS['syslogdb_default'] = 'syslogdb';

	$updates = [];

	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) {
		if (str_contains($sql, 'ORDER BY position')) {
			return [
				['id' => 3, 'position' => 1],
				['id' => 5, 'position' => 2],
				['id' => 9, 'position' => 3]
			];
		}

		return [];
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$updates) {
		if (str_contains($sql, 'SET position = ?')) {
			$updates[] = $params;
		}

		return true;
	});

	// Dragging 3 to the last slot: order becomes 5, 9, 3 → positions 1..3.
	syslog_dashboard_panel_reposition(['id' => 3], 1, 3);
	expect($updates)->toBe([[1, 5], [2, 9], [3, 3]], 'Absolute position renumbers the rest');

	// Out-of-range positions clamp instead of corrupting the sequence.
	$updates = [];
	syslog_dashboard_panel_reposition(['id' => 9], 1, 99);
	expect($updates)->toBe([[1, 3], [2, 5], [3, 9]], 'Oversized position clamps to the last slot');
});