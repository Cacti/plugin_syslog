<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for syslog_csv_safe(). Extracts the function source from
 * functions.php and evals a renamed copy so this test does not collide
 * with the rest of functions.php (which defines many dependencies like
 * syslog_debug that conflict with test-time stubs).
 */

it('prefixes only values a spreadsheet would treat as a formula', function () {
	$functions = plugin_test_read_source('functions.php');

	if (!preg_match('/function\s+syslog_csv_safe\s*\([^)]*\)\s*(?::\s*mixed\s*)?\{.*?\n\}/s', $functions, $m)) {
		throw new RuntimeException('Could not extract syslog_csv_safe from functions.php');
	}

	$source = str_replace('function syslog_csv_safe', 'function issue315_csv_safe', $m[0]);

	eval($source);

	$cases = [
		// [input, expected output, label]
		['', '', 'empty string passes through'],
		[null, null, 'null passes through'],
		[42, 42, 'integer passes through'],
		['router1', 'router1', 'benign string unchanged'],
		['=SUM(A1)', "'=SUM(A1)", 'leading = prefixed'],
		['+1234567', "'+1234567", 'leading + prefixed'],
		['-2+3', "'-2+3", 'leading - prefixed'],
		['@user', "'@user", 'leading @ prefixed'],
		["\tevil", "'\tevil", 'leading tab prefixed'],
		["\rboot", "'\rboot", 'leading CR prefixed'],
		[' =SUM(A1)', "' =SUM(A1)", 'leading whitespace before = prefixed'],
		['  @user', "'  @user", 'multiple leading spaces before @ prefixed'],
		['router@home', 'router@home', '@ not at start unchanged'],
		['has =equals', 'has =equals', '= not at start unchanged'],
		["'=already", "'=already", 'already-escaped value is not double-prefixed'],
	];

	foreach ($cases as [$input, $expected, $label]) {
		$actual = issue315_csv_safe($input);

		expect($actual)->toBe($expected, sprintf(
			'case (%s): expected %s, got %s',
			$label,
			var_export($expected, true),
			var_export($actual, true)
		));
	}
});
