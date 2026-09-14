<?php

require_once dirname(__DIR__, 2) . '/functions.php';

function strip_dates_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function syslog_db_fetch_assoc($sql) { return []; }

$d1 = '2026-09-12 00:00:00';
$d2 = '2026-09-13 00:00:00';

// Empty authored search: only the appended dates are removed.
strip_dates_assert(syslog_strip_auto_dates('logtime >= "' . $d1 . '" AND logtime <= "' . $d2 . '"', $d1, $d2) === '', 'Bare date clause removed');

// Authored search keeps its wrapper after the date suffix is removed.
strip_dates_assert(
	syslog_strip_auto_dates('(error AND host_id = "3") AND logtime >= "' . $d1 . '" AND logtime <= "' . $d2 . '"', $d1, $d2),
	'(error AND host_id = "3")'
);

// An expression without the appended clause is untouched.
strip_dates_assert(syslog_strip_auto_dates('error OR warning', $d1, $d2) === 'error OR warning', 'Unrelated expression untouched');

// A user-authored date condition with different dates is untouched.
$user_dates = 'logtime >= "2026-01-01 00:00:00" AND logtime <= "2026-01-02 00:00:00"';
strip_dates_assert(syslog_strip_auto_dates($user_dates, $d1, $d2) === $user_dates, 'Different dates preserved');

// Quotes in dates cannot smuggle a suffix match.
strip_dates_assert(
	syslog_strip_auto_dates('logtime >= "' . $d1 . '" AND logtime <= "' . $d2 . '"', $d1 . 'x', $d2) !== '',
	'Suffix must match both dates'
);

echo 'saved_search_strip_dates_test passed', PHP_EOL;