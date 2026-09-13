<?php

// Deleting the date row must not drop the default last-day limit: page entry
// reapplies it whenever a logical search carries no date condition, submitted
// or not.
require_once dirname(__DIR__, 2) . '/functions.php';

$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$start  = strpos($source, "set_shift_span(\$shift_span, 'sess_sl_' . \$current_tab);");
$end    = strpos($source, "\t\$GLOBALS['syslog_search_tree'] = null;", $start);
if (!$start || !$end) {
	throw new RuntimeException('Default date block not found in syslog.php');
}
$block = substr($source, $start, $end - $start);

function set_shift_span($shift_span, $session_prefix) {}
function get_request_var($name) { global $request; return $request[$name] ?? ''; }
function set_request_var($name, $value) { global $request; $request[$name] = $value; }

function date_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function run_block($rfilter, $shift_span = false, $date1 = '', $date2 = '') {
	global $request, $block;
	$current_tab = 'syslog';
	$request = ['search_mode' => 'logical', 'rfilter' => $rfilter, 'date1' => $date1, 'date2' => $date2];
	$_SESSION = [];
	eval($block);
	return $request['rfilter'];
}

// A search whose date row was deleted keeps the default last-day limit.
date_assert(
	run_block('message contains "error"') === '(message contains "error") AND logtime last "86400"',
	'Default last-day limit reapplied after the date row is deleted'
);

// An empty query is limited to the default range as well.
date_assert(run_block('') === 'logtime last "86400"', 'Empty query receives the default range');

// Authored date conditions are kept intact.
date_assert(
	run_block('message contains "error" AND logtime >= "2026-01-01 00:00:00"') === 'message contains "error" AND logtime >= "2026-01-01 00:00:00"',
	'Authored date condition untouched'
);

// A shifted span uses the selected dates instead of the relative default.
date_assert(
	run_block('message contains "error"', 'custom', '2026-02-01 00:00:00', '2026-02-02 00:00:00')
		=== '(message contains "error") AND logtime >= "2026-02-01 00:00:00" AND logtime <= "2026-02-02 00:00:00"',
	'Shifted span injects the selected dates'
);

// Invalid input is left intact for the validation error below.
date_assert(run_block('(unclosed') === '(unclosed', 'Invalid input not modified');

// The obsolete once-per-session gate must not return.
date_assert(strpos($source, '_query_dates') === false, 'Session date gate removed');

echo 'default_date_reapplied_test passed', PHP_EOL;