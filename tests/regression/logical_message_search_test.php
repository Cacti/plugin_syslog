<?php

require_once dirname(__DIR__, 2) . '/functions.php';

function search_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function db_qstr($value) {
	return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}
function get_request_var($name) { return $GLOBALS['request'][$name] ?? ''; }
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function isempty_request_var($name) { return get_request_var($name) === ''; }
function api_plugin_hook_function($name, $value) { return $value; }
function get_order_string() { return 'ORDER BY logtime DESC'; }
function syslog_db_fetch_assoc($sql) { $GLOBALS['captured_sql'] = $sql; return []; }
function filter_value($value, $filter, $href = '') { return 'legacy'; }

$term = function ($text) { return ['term', $text]; };
search_assert(syslog_parse_logical_search('  ') === null, 'Empty search');
search_assert(syslog_parse_logical_search('connection refused') === $term('connection refused'), 'Unquoted phrase');
search_assert(syslog_parse_logical_search('cANDy or warning') === $term('cANDy or warning'), 'Standalone uppercase operators only');
search_assert(syslog_parse_logical_search('error OR warning AND NOT timeout') === ['OR', $term('error'), ['AND', $term('warning'), ['NOT', $term('timeout')]]], 'Operator precedence');
search_assert(syslog_parse_logical_search('(error OR warning) AND NOT timeout') === ['AND', ['OR', $term('error'), $term('warning')], ['NOT', $term('timeout')]], 'Grouping');
search_assert(syslog_parse_logical_search('NOT NOT ((error))') === ['NOT', ['NOT', $term('error')]], 'Nested NOT and parentheses');
search_assert(syslog_parse_logical_search('"AND (OR)"') === $term('AND (OR)'), 'Quoted syntax');
search_assert(syslog_parse_logical_search('"a\\"b\\\\c"') === $term('a"b\\c'), 'Quote and backslash escapes');
foreach (['AND a', 'a OR', 'NOT', '(a', 'a)', '()', '""', '"open', 'a "b"', 'a NOT b', 'a AND OR b', str_repeat('(', 34) . 'a' . str_repeat(')', 34), str_repeat('x', 8193)] as $invalid) {
	try {
		syslog_parse_logical_search($invalid);
		throw new RuntimeException('Accepted invalid expression: ' . $invalid);
	} catch (InvalidArgumentException $expected) {
	}
}
foreach (["O'Reilly", '%_.*[x]', 'x\\y', "'; DROP TABLE syslog; --"] as $literal) {
	search_assert(syslog_logical_search_sql($term($literal), 'message') === '(LOCATE(' . db_qstr($literal) . ', message) > 0)', 'Literal SQL quoting');
}
search_assert(syslog_logical_positive_terms(syslog_parse_logical_search('error AND NOT timeout OR NOT NOT warning')) === ['error', 'warning'], 'Highlight positive terms');
$GLOBALS['request'] = ['search_mode' => 'logical'];
$GLOBALS['syslog_search_tree'] = syslog_parse_logical_search('"<script>" OR error AND NOT timeout');
$rendered = syslog_message_filter_value('<script>error</script> timeout', '');
search_assert($rendered === '<span class="filteredValue">&lt;script&gt;</span><span class="filteredValue">error</span>&lt;/script&gt; timeout', 'Safe literal highlighting');
$GLOBALS['request']['search_mode'] = 'regex';
search_assert(syslog_message_filter_value('error', 'error') === 'legacy', 'Regex rendering unchanged');

// Table predicates are allowlisted and values remain SQL literals.
$GLOBALS['syslogdb_default'] = 'syslog';
foreach (['host = "router-1"', 'host like "web-%"', 'program contains "sshd"', 'facility = "auth"', 'priority_id <= "4"', 'logtime >= "2026-01-01 00:00:00"'] as $expression) {
	$tree = syslog_parse_logical_search($expression);
	search_assert($tree[0] === 'predicate', 'Field predicate parsed');
	foreach (['message', 'logmsg'] as $column) {
		search_assert(str_contains(syslog_logical_search_sql($tree, $column), db_qstr($tree[3])), 'Field values quoted');
	}
}
foreach (['message regex "error|warning"', 'password = "x"', 'host_id like "1"', 'seq = "1 OR 1=1"', 'logtime > "yesterday"'] as $invalid) {
	try {
		syslog_parse_logical_search($invalid);
		throw new RuntimeException('Accepted invalid field predicate: ' . $invalid);
	} catch (InvalidArgumentException $expected) {}
}
search_assert(syslog_logical_positive_terms(syslog_parse_logical_search('host = "router" AND message contains "error"')) === ['error'], 'Only message values highlighted');
search_assert(str_contains(syslog_logical_search_sql(syslog_parse_logical_search('host = "router"'), 'logmsg'), 'syslog.host ='), 'Alerts use their stored hostname');

// Exercise the real query builder without bootstrapping Cacti or requiring a database.
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$start = strpos($source, 'function get_syslog_messages(');
$end = strpos($source, 'function syslog_filter(', $start);
eval(substr($source, $start, $end - $start));
$GLOBALS['syslogdb_default'] = 'syslog';
$GLOBALS['request'] = ['search_mode' => 'logical', 'rfilter' => '(error OR warning) AND NOT timeout AND (host like "web-%" OR program = "sshd") AND priority_id <= "4"', 'host' => '', 'date1' => '2026-01-01', 'date2' => '2026-01-02', 'eprogram' => '-1', 'efacility' => '-1', 'epriority' => '-1', 'page' => 1];
$GLOBALS['syslog_search_tree'] = syslog_parse_logical_search($GLOBALS['request']['rfilter']);
$GLOBALS['syslog_search_error'] = '';
foreach (['syslog', 'alerts'] as $tab) {
	$GLOBALS['current_tab'] = $tab;
	foreach (['-1', '1', '0'] as $removal) {
		foreach (['0', '1'] as $grouping) {
			$GLOBALS['request']['removal'] = $removal;
			$GLOBALS['request']['grouping'] = $grouping;
			$sql_where = '';
			get_syslog_messages($sql_where, 20, $tab);
			$predicate = syslog_logical_search_sql($GLOBALS['syslog_search_tree'], $tab === 'syslog' ? 'message' : 'logmsg');
			search_assert(str_contains($sql_where, ' AND ' . $predicate), 'Predicate in shared count filter');
			search_assert(str_contains($GLOBALS['captured_sql'], $sql_where), 'Predicate in results query');
			search_assert(str_contains($GLOBALS['captured_sql'], 'LIMIT 0,20'), 'Pagination retained');
			if ($tab === 'syslog' && $removal === '1') {
				search_assert(substr_count($GLOBALS['captured_sql'], $predicate) === 2, 'Both union branches filtered');
			}
		}
	}
	$GLOBALS['request']['export'] = true;
	get_syslog_messages($sql_where, 20, $tab);
	search_assert(str_contains($GLOBALS['captured_sql'], 'LIMIT 10000'), 'Export limit retained');
	unset($GLOBALS['request']['export']);
}
$GLOBALS['syslog_search_error'] = 'Invalid logical search';
get_syslog_messages($sql_where, 20, 'syslog');
search_assert(str_contains($sql_where, 'AND (1 = 0)'), 'Invalid expression fails closed');
ob_start();
syslog_export('syslog');
$error = ob_get_clean();
search_assert(http_response_code() === 400 && $error === 'Invalid logical search', 'Invalid export rejected');
$GLOBALS['syslog_search_error'] = '';
$GLOBALS['request']['search_mode'] = 'regex';
$GLOBALS['request']['rfilter'] = 'error|warning';
get_syslog_messages($sql_where, 20, 'syslog');
search_assert(str_contains($sql_where, "message RLIKE 'error|warning'"), 'Regex query retained');
print "logical_message_search_test passed\n";
