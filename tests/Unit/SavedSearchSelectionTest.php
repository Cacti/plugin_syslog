<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Exercise saved-search dispatch with Cacti's request-helper mutation
 * semantics: applying a saved search restores its expression, while a
 * manual edit on top of an active selection must detach it instead of
 * silently reapplying the saved query.
 */

it('applies a saved search on selection but detaches it on manual edits', function () {
	$source     = plugin_test_read_source('syslog.php');
	$validation = substr($source, strpos($source, 'function syslog_request_validation('));

	preg_match('/\$filter_submitted = isset\(\$_POST\[\'rfilter\'\]\);/', $validation, $capture);

	if (!$capture) {
		throw new RuntimeException('Capture the original filter submission before validation');
	}

	$start = strpos($validation, '// ================= saved searches =================');
	$end   = strpos($validation, "\n\tif (get_request_var('search_mode')", $start);
	$dispatch = substr($validation, $start, $end - $start);

	$start = strpos($source, 'function saved_search_apply(');
	$end   = strpos($source, 'function saved_search_save(', $start);

	eval(substr($source, $start, $end - $start));

	test_override('set_request_var', function ($name, $value) {
		$_REQUEST[$name] = $_POST[$name] = $value;
	});

	test_override('get_filter_request_var', function ($name, $filter) {
		return filter_var($_REQUEST[$name] ?? 0, $filter);
	});

	test_override('isset_request_var', function ($name) {
		return isset($_REQUEST[$name]);
	});

	test_override('kill_session_var', function ($name) {
		unset($_SESSION[$name]);
	});

	test_override('get_username', function ($id) {
		return 'tester';
	});

	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) {
		return ['id' => 7, 'search' => 'host = "router" AND message contains "error"', 'removal' => '2', 'grouping' => '1'];
	});

	foreach ([false, true] as $manual) {
		$_SESSION = ['sess_user_id' => 1, 'sess_sl_syslog_saved' => 7];
		$_POST = $_REQUEST = ['saved' => 7];

		if ($manual) {
			$_POST['rfilter'] = $_REQUEST['rfilter'] = 'manual';
		}

		$current_tab = 'syslog';

		eval($capture[0]);

		// validate_store_request_vars restores rfilter through set_request_var.
		set_request_var('rfilter', $manual ? 'manual' : 'previous query');

		eval($dispatch);

		if ($manual) {
			expect(isset($_SESSION['sess_sl_syslog_saved']))->toBeFalse('Manual edits detach the saved search');
			expect($_REQUEST['rfilter'])->toBe('manual', 'Manual expression is retained');
		} else {
			expect($_REQUEST['rfilter'])->toBe('host = "router" AND message contains "error"', 'Selected expression is restored');
			expect($_REQUEST['removal'] === '2' && $_REQUEST['grouping'] === '1')->toBeTrue('Saved display filters are restored');
			expect($_SESSION['sess_sl_syslog_saved'])->toBe(7, 'Active selection keeps Edit and Delete enabled');
			expect($filter_submitted)->toBeFalse('Helper writes must not turn selection into a manual submission');
		}
	}
});
