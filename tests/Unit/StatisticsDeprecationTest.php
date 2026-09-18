<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the Statistics tab removal: the feature, its
 * schema, its setting, its aggregation, and its client-side JS must all be
 * gone, not just hidden behind a flag.
 */

it('fully removes the deprecated Statistics tab and its data collection', function () {
	$root = dirname(__DIR__, 2);

	$viewer     = file_get_contents($root . '/syslog.php');
	$setup      = file_get_contents($root . '/setup.php');
	$functions  = file_get_contents($root . '/functions.php');
	$process    = file_get_contents($root . '/syslog_process.php');
	$javascript = file_get_contents($root . '/js/functions.js');

	expect(strpos($viewer, "['syslog', 'alerts', 'current', 'status', 'dashboard']"))->not->toBeFalse('Viewer must reject removed tab names while allowing Syslog Status and Dashboard');
	expect(strpos($viewer, "['stats']"))->toBeFalse('Statistics tab registration remains');
	expect(strpos($viewer, 'function syslog_statistics'))->toBeFalse('Statistics view remains');
	expect(strpos($setup, 'CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_statistics`'))->toBeFalse('Statistics table setup remains');
	expect(strpos($setup, "'syslog_statistics' => ["))->toBeFalse('Statistics setting remains');
	expect(strpos($functions, 'function syslog_update_statistics'))->toBeFalse('Statistics aggregation remains');
	expect(strpos($process, 'syslog_update_statistics('))->toBeFalse('Statistics aggregation call remains');
	expect(strpos($javascript, 'initSyslogStats'))->toBeFalse('Statistics JavaScript remains');
});
