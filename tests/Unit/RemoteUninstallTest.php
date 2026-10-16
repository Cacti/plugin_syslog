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

it('drops remote rule snapshots only for a full uninstall on the main poller', function ($method, $poller, $expected) {
	$setup = plugin_test_read_source('setup.php');
	preg_match('/^function plugin_syslog_uninstall\(\): void \{.*?^\}/ms', $setup, $match);
	$body = str_replace('__DIR__', var_export(dirname(__DIR__, 2), true), $match[0]);
	$script = '<?php ' . '$config = ["poller_id" => ' . $poller . ']; $syslogdb_default = "syslogdb"; $drops = [];' .
		'function syslog_determine_config() {} function syslog_connect() {} ' .
		'function isset_request_var($name) { return $name === "uninstall_method"; } ' .
		'function get_nfilter_request_var($name) { return ' . var_export($method, true) . '; } ' .
		'function syslog_db_execute($sql) { return true; } ' .
		'function api_plugin_drop_remote_table($table) { $GLOBALS["drops"][] = $table; } ' .
		$body . ' plugin_syslog_uninstall(); echo json_encode($drops);';
	$file = tempnam(sys_get_temp_dir(), 'syslog-uninstall-');
	try {
		file_put_contents($file, $script);
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $output, $status);
		expect($status)->toBe(0)
			->and(json_decode(implode("\n", $output), true))->toBe($expected);
	} finally {
		unlink($file);
	}
})->with([
	['all', 1, ['syslog_alert', 'syslog_device_rule', 'syslog_remove', 'syslog_reports']],
	['data', 1, []],
	['all', 2, []],
]);
