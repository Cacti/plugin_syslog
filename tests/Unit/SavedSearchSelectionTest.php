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
	syslog_load_plugin_source('includes/functions.php');

	test_override('read_user_setting', fn ($name, $default) => $name === 'syslog_default_search_syslog' ? $GLOBALS['default_search'] : $default);
	test_override('set_user_setting', function ($name, $value) {
		$GLOBALS['default_search'] = $value;
	});

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

	// Saved search 9 has been deleted (or is no longer shared).
	test_override('syslog_db_fetch_row_prepared', function ($sql, $params) {
		return $params[0] === 9 ? false : ['id' => 7, 'search' => 'host = "router" AND message contains "error"', 'removal' => '2', 'grouping' => '1'];
	});

	$saved_query = 'host = "router" AND message contains "error"';

	// selected/manual: a saved search chosen from the list, then edited.
	// default/stale/later: the tab's default on first entry, a default that no
	// longer exists, and a later visit with a filter already in the session.
	foreach (['selected', 'manual', 'default', 'stale', 'later'] as $case) {
		$selection = $case === 'selected' || $case === 'manual';

		$_SESSION = $selection ? ['sess_user_id' => 1, 'sess_sl_syslog_saved' => 7] : ['sess_user_id' => 1];
		$_POST = $_REQUEST = $selection ? ['saved' => 7] : [];
		$GLOBALS['default_search'] = ['default' => 7, 'stale' => 9, 'later' => 7][$case] ?? 0;
		$first_entry = $case === 'default' || $case === 'stale';

		if ($case === 'manual') {
			$_POST['rfilter'] = $_REQUEST['rfilter'] = 'manual';
		}

		$current_tab = 'syslog';

		eval($capture[0]);

		// validate_store_request_vars restores rfilter through set_request_var.
		set_request_var('rfilter', $case === 'manual' ? 'manual' : 'previous query');

		eval($dispatch);

		if ($case === 'manual') {
			expect(isset($_SESSION['sess_sl_syslog_saved']))->toBeFalse('Manual edits detach the saved search');
			expect($_REQUEST['rfilter'])->toBe('manual', 'Manual expression is retained');
		} elseif ($case === 'selected') {
			expect($_REQUEST['rfilter'])->toBe($saved_query, 'Selected expression is restored');
			expect($_REQUEST['removal'] === '2' && $_REQUEST['grouping'] === '1')->toBeTrue('Saved display filters are restored');
			expect($_SESSION['sess_sl_syslog_saved'])->toBe(7, 'Active selection keeps Edit and Delete enabled');
			expect($filter_submitted)->toBeFalse('Helper writes must not turn selection into a manual submission');
		} elseif ($case === 'default') {
			expect($_REQUEST['rfilter'])->toBe($saved_query, 'Default search applies on first entry');
			expect($_SESSION['sess_sl_syslog_saved'])->toBe(7, 'Default search becomes the active selection');
		} elseif ($case === 'stale') {
			expect($_REQUEST['rfilter'])->toBe('previous query', 'A missing default leaves the filter alone');
			expect($GLOBALS['default_search'])->toBe(0, 'A missing default is dropped');
		} else {
			expect($_REQUEST['rfilter'])->toBe('previous query', 'An existing session filter wins over the default');
			expect(isset($_SESSION['sess_sl_syslog_saved']))->toBeFalse('Default is not reapplied');
		}
	}
});
it('sets a tab default and clears it only when it still points at the search', function () {
	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['settings'] = ['syslog_default_search_alerts' => 4];
	test_override('read_user_setting', fn ($name, $default) => $GLOBALS['settings'][$name] ?? $default);
	test_override('set_user_setting', function ($name, $value) {
		$GLOBALS['settings'][$name] = $value;
	});

	syslog_saved_search_set_default(7, true, 'syslog');
	syslog_saved_search_set_default(9, false, 'syslog');
	expect($GLOBALS['settings'])->toBe(['syslog_default_search_alerts' => 4, 'syslog_default_search_syslog' => 7]);

	syslog_saved_search_set_default(7, false, 'syslog');
	expect($GLOBALS['settings']['syslog_default_search_syslog'])->toBe(0);
});
