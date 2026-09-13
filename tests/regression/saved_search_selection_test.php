<?php

// Exercise saved-search dispatch with Cacti's request-helper mutation semantics.
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$validation = substr($source, strpos($source, 'function syslog_request_validation('));
preg_match('/\$filter_submitted = isset\(\$_POST\[\'rfilter\'\]\);/', $validation, $capture);
if (!$capture) {
	throw new RuntimeException('Capture the original filter submission before validation');
}
$start = strpos($validation, '// ================= saved searches =================');
$end = strpos($validation, "\n\tif (get_request_var('search_mode')", $start);
$dispatch = substr($validation, $start, $end - $start);
$start = strpos($source, 'function saved_search_apply(');
$end = strpos($source, 'function saved_search_save(', $start);
eval(substr($source, $start, $end - $start));

function set_request_var($name, $value) { $_REQUEST[$name] = $_POST[$name] = $value; }
function get_filter_request_var($name, $filter) { return filter_var($_REQUEST[$name] ?? 0, $filter); }
function isset_request_var($name) { return isset($_REQUEST[$name]); }
function kill_session_var($name) { unset($_SESSION[$name]); }
function get_username($id) { return 'tester'; }
function syslog_db_fetch_row_prepared($sql, $params) {
	return ['id' => 7, 'search' => 'host = "router" AND message contains "error"', 'removal' => '2', 'grouping' => '1'];
}
function selection_assert($condition, $message) {
	if (!$condition) throw new RuntimeException($message);
}

foreach ([false, true] as $manual) {
	$_SESSION = ['sess_user_id' => 1, 'sess_sl_syslog_saved' => 7];
	$_POST = $_REQUEST = ['saved' => 7];
	if ($manual) $_POST['rfilter'] = $_REQUEST['rfilter'] = 'manual';
	$current_tab = 'syslog';
	eval($capture[0]);
	// validate_store_request_vars restores rfilter through set_request_var.
	set_request_var('rfilter', $manual ? 'manual' : 'previous query');
	eval($dispatch);
	if ($manual) {
		selection_assert(!isset($_SESSION['sess_sl_syslog_saved']), 'Manual edits detach the saved search');
		selection_assert($_REQUEST['rfilter'] === 'manual', 'Manual expression is retained');
	} else {
		selection_assert($_REQUEST['rfilter'] === 'host = "router" AND message contains "error"', 'Selected expression is restored');
		selection_assert($_REQUEST['removal'] === '2' && $_REQUEST['grouping'] === '1', 'Saved display filters are restored');
		selection_assert($_SESSION['sess_sl_syslog_saved'] === 7, 'Active selection keeps Edit and Delete enabled');
		selection_assert(!$filter_submitted, 'Helper writes must not turn selection into a manual submission');
	}
}
echo "saved_search_selection_test passed\n";
