<?php

$root = dirname(__DIR__, 2);

function statistics_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$viewer = file_get_contents($root . '/syslog.php');
$setup = file_get_contents($root . '/setup.php');
$functions = file_get_contents($root . '/functions.php');
$process = file_get_contents($root . '/syslog_process.php');
$javascript = file_get_contents($root . '/js/functions.js');

statistics_assert(strpos($viewer, "['syslog', 'alerts', 'current']") !== false, 'Viewer must reject removed tab names');
statistics_assert(strpos($viewer, "['stats']") === false, 'Statistics tab registration remains');
statistics_assert(strpos($viewer, 'function syslog_statistics') === false, 'Statistics view remains');
statistics_assert(strpos($setup, 'CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_statistics`') === false, 'Statistics table setup remains');
statistics_assert(strpos($setup, "'syslog_statistics' => [") === false, 'Statistics setting remains');
statistics_assert(strpos($functions, 'function syslog_update_statistics') === false, 'Statistics aggregation remains');
statistics_assert(strpos($process, 'syslog_update_statistics(') === false, 'Statistics aggregation call remains');
statistics_assert(strpos($javascript, 'initSyslogStats') === false, 'Statistics JavaScript remains');

echo 'statistics_deprecation_test passed', PHP_EOL;
