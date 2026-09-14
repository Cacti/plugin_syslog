<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Deleting the date row must not drop the default last-day limit: page
 * entry reapplies it whenever a logical search carries no date condition,
 * submitted or not.
 */

it('reapplies the default last-day limit whenever a search has no date condition', function () {
	syslog_load_plugin_source('functions.php');

	$source = plugin_test_read_source('syslog.php');
	$start  = strpos($source, "set_shift_span(\$shift_span, 'sess_sl_' . \$current_tab);");
	$end    = strpos($source, "\t\$GLOBALS['syslog_search_tree'] = null;", $start);

	if (!$start || !$end) {
		throw new RuntimeException('Default date block not found in syslog.php');
	}

	$block = substr($source, $start, $end - $start);

	if (!function_exists('set_shift_span')) {
		function set_shift_span($shift_span, $session_prefix) {
		}
	}

	test_override('get_request_var', function ($name) {
		global $request;

		return $request[$name] ?? '';
	});

	test_override('set_request_var', function ($name, $value) {
		global $request;

		$request[$name] = $value;
	});

	$run_block = function ($rfilter, $shift_span = false, $date1 = '', $date2 = '') use ($block) {
		global $request;

		$current_tab = 'syslog';
		$request = ['search_mode' => 'logical', 'rfilter' => $rfilter, 'date1' => $date1, 'date2' => $date2];
		$_SESSION = [];

		eval($block);

		return $request['rfilter'];
	};

	// A search whose date row was deleted keeps the default last-day limit.
	expect($run_block('message contains "error"'))->toBe('(message contains "error") AND logtime last "86400"', 'Default last-day limit reapplied after the date row is deleted');

	// An empty query is limited to the default range as well.
	expect($run_block(''))->toBe('logtime last "86400"', 'Empty query receives the default range');

	// Authored date conditions are kept intact.
	expect($run_block('message contains "error" AND logtime >= "2026-01-01 00:00:00"'))
		->toBe('message contains "error" AND logtime >= "2026-01-01 00:00:00"', 'Authored date condition untouched');

	// A shifted span uses the selected dates instead of the relative default.
	expect($run_block('message contains "error"', 'custom', '2026-02-01 00:00:00', '2026-02-02 00:00:00'))
		->toBe('(message contains "error") AND logtime >= "2026-02-01 00:00:00" AND logtime <= "2026-02-02 00:00:00"', 'Shifted span injects the selected dates');

	// Invalid input is left intact for the validation error below.
	expect($run_block('(unclosed'))->toBe('(unclosed', 'Invalid input not modified');

	// The obsolete once-per-session gate must not return.
	expect($source)->not->toContain('_query_dates', 'Session date gate removed');
});
