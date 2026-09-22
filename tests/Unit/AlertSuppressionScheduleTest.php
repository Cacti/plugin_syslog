<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 */

it('recognizes same-day and overnight maintenance windows', function () {
	syslog_load_plugin_source('functions.php');

	$mondayLate = strtotime('2024-01-01 23:00:00');
	$tuesdayEarly = strtotime('2024-01-02 01:00:00');
	$tuesdayLate = strtotime('2024-01-02 03:00:00');

	expect(syslog_alert_schedule_is_active('Mon 22:00-02:00', $mondayLate))->toBeTrue();
	expect(syslog_alert_schedule_is_active('Mon 22:00-02:00', $tuesdayEarly))->toBeTrue();
	expect(syslog_alert_schedule_is_active('Mon 22:00-02:00', $tuesdayLate))->toBeFalse();
});

it('accepts wildcard and weekday ranges in maintenance windows', function () {
	syslog_load_plugin_source('functions.php');

	$wednesday = strtotime('2024-01-03 12:30:00');

	expect(syslog_alert_schedule_is_active('* 12:00-13:00', $wednesday))->toBeTrue();
	expect(syslog_alert_schedule_is_active('Mon-Fri 12:00-13:00', $wednesday))->toBeTrue();
	expect(syslog_alert_schedule_is_active('Sat-Sun 12:00-13:00', $wednesday))->toBeFalse();
});
