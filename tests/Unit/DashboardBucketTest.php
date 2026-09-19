<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Bucket math drives every timeseries chart: explicit intervals must be
 * honored, auto intervals must scale with the window, both must coarsen
 * when a window would produce too many buckets, and the "last N" presets
 * (including month-based ones) must map to stable second counts.
 */

it('maps dashboard timespan presets to seconds', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	expect(syslog_dashboard_timespan_seconds('3600'))->toBe(3600, 'One hour preset');
	expect(syslog_dashboard_timespan_seconds('21600'))->toBe(21600, 'Six hour preset');
	expect(syslog_dashboard_timespan_seconds('86400'))->toBe(86400, 'One day preset');
	expect(syslog_dashboard_timespan_seconds('2592000'))->toBe(2592000, 'One month preset');
	expect(syslog_dashboard_timespan_seconds('3months'))->toBe((int) round(3 * 30.44 * 86400), 'Three month preset');
	expect(syslog_dashboard_timespan_seconds('6months'))->toBe((int) round(6 * 30.44 * 86400), 'Six month preset');
	expect(syslog_dashboard_timespan_seconds('dashboard'))->toBe(86400, 'Unknown values fall back to a day');
	expect(syslog_dashboard_timespan_seconds('-5'))->toBe(86400, 'Negative values fall back to a day');
});

it('resolves explicit and automatic bucket intervals', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	expect(syslog_dashboard_bucket_seconds(3600, 'minute'))->toBe(60, 'Explicit minute bucket');
	expect(syslog_dashboard_bucket_seconds(3600, '10min'))->toBe(600, 'Explicit ten minute bucket');
	expect(syslog_dashboard_bucket_seconds(86400, 'hour'))->toBe(3600, 'Explicit hour bucket');
	expect(syslog_dashboard_bucket_seconds(86400, 'day'))->toBe(86400, 'Explicit day bucket');

	// Auto picks a bucket from the window length.
	expect(syslog_dashboard_bucket_seconds(21600, 'auto'))->toBe(60, 'Auto: up to six hours buckets by minute');
	expect(syslog_dashboard_bucket_seconds(259200, 'auto'))->toBe(600, 'Auto: up to three days buckets by ten minutes');
	expect(syslog_dashboard_bucket_seconds(2592000, 'auto'))->toBe(3600, 'Auto: up to thirty days buckets by hour');
	expect(syslog_dashboard_bucket_seconds(15768000, 'auto'))->toBe(86400, 'Auto: months bucket by day');
});

it('coarsens buckets that would render too many points', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	// A month of minute buckets (43200) exceeds the cap, so it steps up the
	// ladder: ten minutes (4320, still over) then one hour = 720 buckets.
	expect(syslog_dashboard_bucket_seconds(2592000, 'minute'))->toBe(3600, 'One month of minute buckets coarsens to one hour');

	$bucket = syslog_dashboard_bucket_seconds(31536000, 'minute');

	expect(31536000 / $bucket)->toBeLessThanOrEqual(720, 'Year-long window is coarsened below the bucket cap');
	expect($bucket)->toBeLessThanOrEqual(86400, 'Bucket never exceeds one day');
});

it('formats bucket labels with the right precision', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	$day = strtotime('2026-09-18 00:00:00');

	expect(syslog_dashboard_bucket_label($day, 3600))->toBe('2026-09-18 00:00', 'Sub-day buckets show time');
	expect(syslog_dashboard_bucket_label($day, 86400))->toBe('2026-09-18', 'Day buckets show the date only');
});

it('sanitizes chart labels for tooltip contexts', function () {
	syslog_load_plugin_source('lib/syslog_dashboard.php');

	expect(syslog_dashboard_label_safe('router-01'))->toBe('router-01', 'Plain labels pass through');
	expect(syslog_dashboard_label_safe('<b>alert</b>'))->toBe('&lt;b&gt;alert&lt;/b&gt;', 'Markup is escaped to entities');

	// Control characters (including the newline in this literal) vanish.
	expect(syslog_dashboard_label_safe("bad\x01name\n"))->toBe('badname', 'Control characters are stripped');
	expect(syslog_dashboard_label_safe(123))->toBe('123', 'Non-string labels are cast safely');

	// billboard.js crashes on empty legend ids, so blank labels surface
	// as "Unknown" instead of an empty string.
	expect(syslog_dashboard_label_safe(''))->toBe('Unknown', 'Empty labels are replaced');
	expect(syslog_dashboard_label_safe('   '))->toBe('Unknown', 'Whitespace-only labels are replaced');
	expect(syslog_dashboard_label_safe("\x01\n"))->toBe('Unknown', 'Labels that strip to empty are replaced');
});