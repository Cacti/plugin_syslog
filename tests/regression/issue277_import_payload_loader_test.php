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

$root = dirname(__DIR__, 2);

$functions = file_get_contents($root . '/functions.php');

if ($functions === false) {
	fwrite(STDERR, "Failed to load functions.php\n");
	exit(1);
}

if (!str_contains($functions, 'function syslog_get_import_xml_payload(')) {
	fwrite(STDERR, "Shared import payload loader helper is missing.\n");
	exit(1);
}

if (!str_contains($functions, '$import_text = (string) get_nfilter_request_var(\'import_text\')') ||
		!str_contains($functions, 'trim($import_text) !== \'\'')) {
	fwrite(STDERR, "Shared import payload loader is missing trimmed text handling.\n");
	exit(1);
}

$targets = [
	$root . '/syslog_alerts.php',
	$root . '/syslog_reports.php',
	$root . '/syslog_removal.php'
];

foreach ($targets as $target) {
	$content = file_get_contents($target);

	if ($content === false) {
		fwrite(STDERR, "Failed to load $target\n");
		exit(1);
	}

	if (strpos($content, 'syslog_get_import_xml_payload(') === false) {
		fwrite(STDERR, "Shared import payload loader is not used in $target\n");
		exit(1);
	}

	if (strpos($content, "\$_FILES['import_file']['tmp_name']") !== false) {
		fwrite(STDERR, "Legacy per-file upload payload loading remains in $target\n");
		exit(1);
	}
}

print "issue277_import_payload_loader_test passed\n";
