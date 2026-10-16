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

afterEach(function () {
	syslog_test_reset_globals();
});

it('renders stored rule names as text in every bulk confirmation', function ($file, $action, $name) {
	syslog_load_plugin_source('includes/functions.php');
	$handler = syslog_test_page_function($file, 'form_actions');
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslog_actions'] = array_fill(1, 5, 'Action');
	$GLOBALS['request'] = ['drp_action' => $action];
	$_POST = ['chk_7' => 'on'];
	test_override('db_fetch_assoc_prepared', static fn () => [['id' => 1]]);
	test_override('syslog_db_fetch_cell_prepared', static fn () => $name);
	ob_start();
	$handler();
	$html = ob_get_clean();
	expect($html)->toContain('<li>' . html_escape($name) . '</li>');
	$document = new DOMDocument();
	preg_match_all('/<li>.*?<\/li>/s', $html, $items);
	$document->loadHTML('<meta charset="UTF-8"><ul>' . implode('', $items[0]) . '</ul>');
	expect($document->getElementsByTagName('img')->length)->toBe(0)
		->and($document->getElementsByTagName('svg')->length)->toBe(0)
		->and($document->getElementsByTagName('li')->item(0)->textContent)->toBe($name);
})->with([
	['syslog_removal.php', '1'], ['syslog_removal.php', '2'], ['syslog_removal.php', '3'], ['syslog_removal.php', '4'], ['syslog_removal.php', '5'],
	['syslog_reports.php', '1'], ['syslog_reports.php', '2'], ['syslog_reports.php', '3'], ['syslog_reports.php', '4'],
])->with([
	'<img src=x onerror=alert(1)>',
	'<svg onload=alert(1)> & "quoted"',
	'Router Montréal & backup',
]);
