<?php

// Exercise the real alert logger without Cacti bootstrap or database writes.
function sql_save($data, ...$args) { $GLOBALS['saved_alert'] = $data; return 42; }
function cacti_sizeof($value) { return count($value); }
function api_plugin_hook_function($name, $value) { return $value; }
function cacti_log(...$args) {}
require dirname(__DIR__, 2) . '/includes/database.php';
require dirname(__DIR__, 2) . '/includes/functions.php';

$syslogdb_default = 'syslog';
$severities = [0 => 'Warning'];
$message = ['seq' => 123, 'logtime' => '2026-10-15 20:48:03', 'message' => '123', 'host' => 'cacti-12x-main', 'facility_id' => 1, 'priority_id' => 5];
foreach ([
	[5, ['cacti-12x-main'], 'cacti-12x-main'],
	[5, [3 => 'cacti-12x-main'], 'cacti-12x-main'],
	[5, ['cacti-12x-main', 'another-host'], 'N/A'],
	[5, [], 'N/A'],
	[1, [], 'cacti-12x-main']
] as [$count, $hosts, $expected]) {
	$id = syslog_log_alert(1, 'Threshold test', 0, $message, $count, '', $hosts);
	if ($id !== 42 || $GLOBALS['saved_alert']['host'] !== $expected || $GLOBALS['saved_alert']['count'] !== $count) {
		throw new RuntimeException('Threshold alert hostname or count was not preserved');
	}
}
print "Threshold alert hostname regression checks passed.\n";
