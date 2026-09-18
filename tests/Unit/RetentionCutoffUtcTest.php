<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #317 (R0-a): retention cutoffs must be
 * derived from UTC (gmdate()) so they agree with the integer UTC epoch
 * partition boundaries and cannot drift with the server timezone or DST
 * transitions.  Local-time date() cutoffs must not reappear in the
 * retention paths; other date() call sites (scheduling, CSV filenames,
 * display) are intentionally out of scope.
 */

function syslog_extract_function(string $file, string $function): string {
	$content = file_get_contents($file);

	if ($content === false) {
		throw new RuntimeException("Unable to read $file");
	}

	if (preg_match('/^function ' . $function . '\([^)]*\)\s*\{.*?^\}/ms', $content, $matches) !== 1) {
		throw new RuntimeException("Unable to isolate $function in $file");
	}

	return $matches[0];
}

it('computes the traditional retention cutoff in UTC', function () {
	$root  = dirname(__DIR__, 2);
	$function = syslog_extract_function($root . '/functions.php', 'syslog_traditional_manage');

	expect(str_contains($function, "gmdate('Y-m-d'"))->toBeTrue();

	/*
	 * Negative check uses a lookbehind that is not 'm' so the "date("
	 * inside "gmdate(" cannot satisfy it.
	 */
	expect(preg_match("/(?<![gm])date\('Y-m-d'/", $function))->toBe(0);
});

it('computes the reference table retention cutoff in UTC', function () {
	$root  = dirname(__DIR__, 2);
	$function = syslog_extract_function($root . '/functions.php', 'syslog_postprocess_tables');

	expect(str_contains($function, "gmdate('Y-m-d H:i:s'"))->toBeTrue();

	expect(preg_match("/(?<![gm])date\('Y-m-d H:i:s', time\(\)/", $function))->toBe(0);
});

it('keeps the daily optimize window in local time on purpose', function () {
	$root  = dirname(__DIR__, 2);
	$function = syslog_extract_function($root . '/functions.php', 'syslog_postprocess_tables');

	// The optimize gate is a scheduling concern tied to the server's local
	// calendar; converting it to UTC would move the window.  It must remain
	// local-time so behavior does not silently change.
	expect(str_contains($function, "date('G') == 0"))->toBeTrue();
});