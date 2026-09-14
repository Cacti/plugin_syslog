<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for syslog_strip_auto_dates(): saved searches must
 * keep their expression dynamic by stripping only the exact date clause the
 * page entry logic appended, never a user-authored condition that merely
 * looks similar.
 */

it('strips only the auto-appended date clause from a saved search expression', function () {
	$this->loadPluginSource('functions.php');

	test_override('syslog_db_fetch_assoc', function ($sql) {
		return [];
	});

	$d1 = '2026-09-12 00:00:00';
	$d2 = '2026-09-13 00:00:00';

	// Empty authored search: only the appended dates are removed.
	expect(syslog_strip_auto_dates('logtime >= "' . $d1 . '" AND logtime <= "' . $d2 . '"', $d1, $d2))->toBe('', 'Bare date clause removed');

	// Authored search keeps its wrapper after the date suffix is removed.
	expect(syslog_strip_auto_dates('(error AND host_id = "3") AND logtime >= "' . $d1 . '" AND logtime <= "' . $d2 . '"', $d1, $d2))
		->toBe('(error AND host_id = "3")');

	// An expression without the appended clause is untouched.
	expect(syslog_strip_auto_dates('error OR warning', $d1, $d2))->toBe('error OR warning', 'Unrelated expression untouched');

	// A user-authored date condition with different dates is untouched.
	$user_dates = 'logtime >= "2026-01-01 00:00:00" AND logtime <= "2026-01-02 00:00:00"';
	expect(syslog_strip_auto_dates($user_dates, $d1, $d2))->toBe($user_dates, 'Different dates preserved');

	// Quotes in dates cannot smuggle a suffix match.
	expect(syslog_strip_auto_dates('logtime >= "' . $d1 . '" AND logtime <= "' . $d2 . '"', $d1 . 'x', $d2))
		->not->toBe('', 'Suffix must match both dates');
});
