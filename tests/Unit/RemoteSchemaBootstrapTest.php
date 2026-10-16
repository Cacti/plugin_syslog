<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

it('initializes remote schema before returning a usable connection without recursive repair', function ($poller, $enabled, $repair_ok, $expected, $calls) {
	$source = plugin_test_read_source('setup.php');
	preg_match('/^function syslog_connect\(\): bool \{.*?^\}/ms', $source, $match);
	$dir = sys_get_temp_dir() . '/syslog-connect-' . bin2hex(random_bytes(6));
	mkdir($dir . '/includes', 0700, true);
	foreach (['functions.php', 'database.php'] as $name) {
		file_put_contents($dir . '/includes/' . $name, '<?php');
	}
	file_put_contents($dir . '/includes/schema.php', '<?php function syslog_ensure_table_structures() { $GLOBALS["repairs"]++; if (!syslog_connect()) { throw new RuntimeException("Recursive connection failed"); } return ' . var_export($repair_ok, true) . '; }');
	file_put_contents($dir . '/config.php', '<?php $use_cacti_db = true;');
	$body = str_replace('__DIR__', var_export($dir, true), $match[0]);
	$script = '<?php define("SYSLOG_CONFIG", ' . var_export($dir . '/config.php', true) . '); ' .
		'$config = ["poller_id" => ' . $poller . ', "syslog_remote_db" => true]; $local_db_cnn_id = (object) []; $repairs = 0; ' .
		'function syslog_determine_config() {} function read_config_option($name) { return ' . var_export($enabled, true) . '; } ' .
		$body . ' echo json_encode([syslog_connect(), syslog_connect(), $repairs, $syslog_cnn === $local_db_cnn_id]);';
	file_put_contents($dir . '/run.php', $script);
	try {
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/run.php'), $output, $status);
		expect($status)->toBe(0)
			->and(json_decode(implode("\n", $output), true))->toBe([$expected, $expected, $calls, true]);
	} finally {
		foreach (glob($dir . '/includes/*') as $file) { unlink($file); }
		foreach (glob($dir . '/*') as $file) { if (is_file($file)) { unlink($file); } }
		rmdir($dir . '/includes');
		rmdir($dir);
	}
})->with([
	[3, 'on', true, true, 1],
	[3, 'on', false, false, 2],
	[1, 'on', true, true, 0],
	[3, '', true, true, 0],
]);
