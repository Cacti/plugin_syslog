<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the logical search DSL: the parser must reject
 * malformed/oversized input, every literal value must reach SQL only
 * through db_qstr()/prepared placeholders (never raw interpolation), and
 * the real query builder (get_syslog_messages()) must apply the resulting
 * predicate consistently across tabs, union branches, and export.
 */

it('parses the logical search grammar and rejects malformed or oversized input', function () {
	syslog_load_plugin_source('functions.php');

	test_override('db_qstr', function ($value) {
		return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
	});

	$term = function ($text) {
		return ['term', $text];
	};

	expect(syslog_parse_logical_search('  '))->toBeNull('Empty search');
	expect(syslog_parse_logical_search('connection refused'))->toBe($term('connection refused'), 'Unquoted phrase');
	expect(syslog_parse_logical_search('cANDy or warning'))->toBe($term('cANDy or warning'), 'Standalone uppercase operators only');
	expect(syslog_parse_logical_search('error OR warning AND NOT timeout'))->toBe(['OR', $term('error'), ['AND', $term('warning'), ['NOT', $term('timeout')]]], 'Operator precedence');
	expect(syslog_parse_logical_search('(error OR warning) AND NOT timeout'))->toBe(['AND', ['OR', $term('error'), $term('warning')], ['NOT', $term('timeout')]], 'Grouping');
	expect(syslog_parse_logical_search('NOT NOT ((error))'))->toBe(['NOT', ['NOT', $term('error')]], 'Nested NOT and parentheses');
	expect(syslog_parse_logical_search('"AND (OR)"'))->toBe($term('AND (OR)'), 'Quoted syntax');
	expect(syslog_parse_logical_search('"a\\"b\\\\c"'))->toBe($term('a"b\\c'), 'Quote and backslash escapes');

	foreach (['AND a', 'a OR', 'NOT', '(a', 'a)', '()', '""', '"open', 'a "b"', 'a NOT b', 'a AND OR b', str_repeat('(', 34) . 'a' . str_repeat(')', 34), str_repeat('x', 8193)] as $invalid) {
		try {
			syslog_parse_logical_search($invalid);

			throw new RuntimeException('Accepted invalid expression: ' . $invalid);
		} catch (InvalidArgumentException $expected) {
		}
	}

	foreach (["O'Reilly", '%_.*[x]', 'x\\y', "'; DROP TABLE syslog; --"] as $literal) {
		expect(syslog_logical_search_sql($term($literal), 'message'))->toBe('(LOCATE(' . db_qstr($literal) . ', message) > 0)', 'Literal SQL quoting');
	}

	expect(syslog_logical_positive_terms(syslog_parse_logical_search('error AND NOT timeout OR NOT NOT warning')))->toBe(['error', 'warning'], 'Highlight positive terms');

	test_override('get_request_var', function ($name) {
		return $GLOBALS['request'][$name] ?? '';
	});

	$GLOBALS['request']               = ['search_mode' => 'logical'];
	$GLOBALS['syslog_search_tree']    = syslog_parse_logical_search('"<script>" OR error AND NOT timeout');
	$rendered = syslog_message_filter_value('<script>error</script> timeout', '');

	expect($rendered)->toBe('<span class="filteredValue">&lt;script&gt;</span><span class="filteredValue">error</span>&lt;/script&gt; timeout', 'Safe literal highlighting');

	test_override('filter_value', function ($value, $filter, $href = '') {
		return 'legacy';
	});

	$GLOBALS['request']['search_mode'] = 'regex';

	expect(syslog_message_filter_value('error', 'error'))->toBe('legacy', 'Regex rendering unchanged');

	// Table predicates are allowlisted and values remain SQL literals.
	$GLOBALS['syslogdb_default'] = 'syslog';

	foreach (['host = "router-1"', 'host like "web-%"', 'program contains "sshd"', 'facility = "auth"', 'priority_id <= "4"', 'logtime >= "2026-01-01 00:00:00"'] as $expression) {
		$tree = syslog_parse_logical_search($expression);

		expect($tree[0])->toBe('predicate', 'Field predicate parsed');

		foreach (['message', 'logmsg'] as $column) {
			expect(str_contains(syslog_logical_search_sql($tree, $column), db_qstr($tree[3])))->toBeTrue('Field values quoted');
		}
	}

	foreach (['message regex "error|warning"', 'password = "x"', 'host_id like "1"', 'seq = "1 OR 1=1"', 'logtime > "yesterday"'] as $invalid) {
		try {
			syslog_parse_logical_search($invalid);

			throw new RuntimeException('Accepted invalid field predicate: ' . $invalid);
		} catch (InvalidArgumentException $expected) {
		}
	}

	expect(syslog_logical_positive_terms(syslog_parse_logical_search('host = "router" AND message contains "error"')))->toBe(['error'], 'Only message values highlighted');
	expect(str_contains(syslog_logical_search_sql(syslog_parse_logical_search('host = "router"'), 'logmsg'), 'syslog.host ='))->toBeTrue('Alerts use their stored hostname');

	foreach (['3600', '21600', '86400', '604800', '1209600', '2592000'] as $seconds) {
		$tree = syslog_parse_logical_search('logtime last "' . $seconds . '"');

		expect(syslog_logical_search_sql($tree, 'message'))->toBe('(syslog.logtime BETWEEN DATE_SUB(NOW(), INTERVAL ' . $seconds . ' SECOND) AND NOW())', 'Rolling preset uses database time');
	}

	foreach (['3months' => '3 MONTH', '6months' => '6 MONTH'] as $preset => $interval) {
		$tree = syslog_parse_logical_search('logtime last "' . $preset . '"');

		expect(syslog_logical_search_sql($tree, 'message'))->toBe('(syslog.logtime BETWEEN DATE_SUB(NOW(), INTERVAL ' . $interval . ') AND NOW())', 'Month presets use calendar months');
	}

	foreach (['logtime last "0"', 'logtime last "3600); DROP TABLE syslog"', 'host last "3600"'] as $invalid) {
		try {
			syslog_parse_logical_search($invalid);

			throw new RuntimeException('Accepted invalid preset');
		} catch (InvalidArgumentException $expected) {
		}
	}
});

it('applies the logical search predicate consistently in the real query builder', function () {
	syslog_load_plugin_source('functions.php');

	test_override('db_qstr', function ($value) {
		return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
	});

	test_override('get_request_var', function ($name) {
		return $GLOBALS['request'][$name] ?? '';
	});

	test_override('isset_request_var', function ($name) {
		return isset($GLOBALS['request'][$name]);
	});

	test_override('isempty_request_var', function ($name) {
		return ($GLOBALS['request'][$name] ?? '') === '';
	});

	test_override('api_plugin_hook_function', function ($name, $value = null) {
		return $value;
	});

	test_override('get_order_string', function () {
		return 'ORDER BY logtime DESC';
	});

	test_override('syslog_db_fetch_assoc', function ($sql) {
		$GLOBALS['captured_sql'] = $sql;

		return [];
	});

	// Exercise the real query builder without bootstrapping Cacti or requiring a database.
	$source = plugin_test_read_source('syslog.php');
	$start  = strpos($source, 'function get_syslog_messages(');
	$end    = strpos($source, 'function syslog_filter(', $start);

	eval(substr($source, $start, $end - $start));

	// get_syslog_messages() declares `global $sql_where;` internally (Cacti
	// convention alongside the by-reference parameter of the same name), so
	// the caller's $sql_where must be the same global binding for the
	// by-reference parameter to actually round-trip the built predicate.
	global $sql_where;

	$GLOBALS['syslogdb_default']    = 'syslog';
	$GLOBALS['request']             = ['search_mode' => 'logical', 'rfilter' => '(error OR warning) AND NOT timeout AND (host like "web-%" OR program = "sshd") AND priority_id <= "4"', 'host' => '', 'date1' => '2026-01-01', 'date2' => '2026-01-02', 'eprogram' => '-1', 'efacility' => '-1', 'epriority' => '-1', 'page' => 1];
	$GLOBALS['syslog_search_tree']  = syslog_parse_logical_search($GLOBALS['request']['rfilter']);
	$GLOBALS['syslog_search_error'] = '';

	foreach (['syslog', 'alerts'] as $tab) {
		$GLOBALS['current_tab'] = $tab;

		foreach (['-1', '1', '0'] as $removal) {
			foreach (['0', '1'] as $grouping) {
				$GLOBALS['request']['removal']  = $removal;
				$GLOBALS['request']['grouping'] = $grouping;
				$sql_where = '';

				get_syslog_messages($sql_where, 20, $tab);

				$predicate = syslog_logical_search_sql($GLOBALS['syslog_search_tree'], $tab === 'syslog' ? 'message' : 'logmsg');

				expect(str_contains($sql_where, $predicate))->toBeTrue('Predicate in shared count filter');
				expect(str_contains($GLOBALS['captured_sql'], $sql_where))->toBeTrue('Predicate in results query');
				expect(str_contains($GLOBALS['captured_sql'], 'LIMIT 0,20'))->toBeTrue('Pagination retained');

				if ($tab === 'syslog' && $removal === '1') {
					expect(substr_count($GLOBALS['captured_sql'], $predicate))->toBe(2, 'Both union branches filtered');
				}
			}
		}

		$GLOBALS['request']['export'] = true;

		get_syslog_messages($sql_where, 20, $tab);

		expect(str_contains($GLOBALS['captured_sql'], 'LIMIT 10000'))->toBeTrue('Export limit retained');

		unset($GLOBALS['request']['export']);
	}

	// Builder dates are the only time restriction, including ranges outside the old span.
	$GLOBALS['syslog_search_tree'] = syslog_parse_logical_search('logtime >= "2020-01-01 00:00:00" AND logtime <= "2020-01-02 00:00:00"');

	get_syslog_messages($sql_where, 20, 'syslog');

	expect(str_contains($sql_where, "syslog.logtime >= '2020-01-01 00:00:00'"))->toBeTrue('From condition applied');
	expect(str_contains($sql_where, "syslog.logtime <= '2020-01-02 00:00:00'"))->toBeTrue('To condition applied');
	expect(str_contains($sql_where, 'BETWEEN'))->toBeFalse('No hidden legacy date restriction');

	$GLOBALS['syslog_search_tree'] = null;

	get_syslog_messages($sql_where, 20, 'syslog');

	expect(str_contains($sql_where, 'logtime'))->toBeFalse('Removing date conditions removes time restrictions');

	$GLOBALS['syslog_search_error'] = 'Invalid logical search';

	get_syslog_messages($sql_where, 20, 'syslog');

	expect(str_contains($sql_where, '(1 = 0)'))->toBeTrue('Invalid expression fails closed');

	// syslog_export() calls http_response_code()/header(): run it in its own
	// process so an earlier test's console output in this same PHPUnit/Pest
	// process (progress dots, etc.) can never trip "headers already sent".
	$root = dirname(__DIR__, 2);
	$code = sprintf(<<<'PHP'
		$GLOBALS['syslog_search_error'] = 'Invalid logical search';
		require %s;
		ob_start();
		syslog_export('syslog');
		$error = ob_get_clean();
		echo 'CODE:' . http_response_code() . "\n";
		echo 'BODY:' . $error;
		PHP,
		var_export($root . '/functions.php', true)
	);

	$process = proc_open([PHP_BINARY, '-r', $code], [
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	], $pipes);

	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start the export-rejection regression process');
	}

	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	expect(str_contains($stdout, 'CODE:400'))->toBeTrue('Invalid export must respond with HTTP 400');
	expect(str_contains($stdout, 'BODY:Invalid logical search'))->toBeTrue('Invalid export rejected');

	$GLOBALS['syslog_search_error']     = '';
	$GLOBALS['request']['search_mode']  = 'regex';
	$GLOBALS['request']['rfilter']      = 'error|warning';

	get_syslog_messages($sql_where, 20, 'syslog');

	expect(str_contains($sql_where, "message RLIKE 'error|warning'"))->toBeTrue('Regex query retained');
});
