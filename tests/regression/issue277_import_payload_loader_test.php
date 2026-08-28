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

if (substr_count($functions, 'function syslog_get_import_xml_payload(') !== 1 ||
	preg_match('/^function syslog_get_import_xml_payload\([^)]*\)\s*\{.*?^\}/ms', $functions, $matches) !== 1) {
	fwrite(STDERR, "Unable to isolate one shared import payload loader helper.\n");
	exit(1);
}

$helperBody = $matches[0];

if (strpos($helperBody, '$import_text = (string) get_nfilter_request_var(\'import_text\')') === false ||
	strpos($helperBody, "trim(\$import_text) !== ''") === false) {
	fwrite(STDERR, "Shared import payload loader is missing trimmed text handling.\n");
	exit(1);
}

if (strpos($helperBody, 'return $import_text;') === false) {
	fwrite(STDERR, "Shared import payload loader must return non-empty textbox input without trimming it.\n");
	exit(1);
}

$uploadGuards = [
	"\$_FILES['import_file']['tmp_name']",
	"\$_FILES['import_file']['error'] !== UPLOAD_ERR_OK",
	'is_uploaded_file($tmp_name)',
	'syslog_read_import_file($tmp_name)',
];

foreach ($uploadGuards as $guard) {
	if (strpos($helperBody, $guard) === false) {
		fwrite(STDERR, "Shared import payload loader is missing upload guard: $guard\n");
		exit(1);
	}
}

$validationPosition = strpos($helperBody, 'is_uploaded_file($tmp_name)');
$readPosition       = strpos($helperBody, 'syslog_read_import_file($tmp_name)');

if ($validationPosition === false || $readPosition === false || $validationPosition > $readPosition) {
	fwrite(STDERR, "Shared import payload loader must validate an upload before opening it.\n");
	exit(1);
}

require_once $root . '/functions.php';

$emptyFixture   = tempnam(sys_get_temp_dir(), 'syslog-empty-import-');
$payloadFixture = tempnam(sys_get_temp_dir(), 'syslog-import-');

if ($emptyFixture === false || $payloadFixture === false ||
	file_put_contents($payloadFixture, '<xml>fixture</xml>') === false) {
	fwrite(STDERR, "Unable to create import payload fixtures.\n");
	exit(1);
}

try {
	if (syslog_read_import_file($emptyFixture) !== false) {
		fwrite(STDERR, "A zero-byte import must fail without calling fread() with a zero length.\n");
		exit(1);
	}

	if (syslog_read_import_file($payloadFixture) !== '<xml>fixture</xml>') {
		fwrite(STDERR, "A non-empty import payload must round trip without data loss.\n");
		exit(1);
	}
} finally {
	unlink($emptyFixture);
	unlink($payloadFixture);
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
