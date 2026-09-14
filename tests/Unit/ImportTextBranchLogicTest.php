<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #269: import parsing lives entirely in
 * syslog_get_import_xml_payload(). Each route must delegate to that helper
 * instead of maintaining a divergent text/file branch.
 */

it('routes every rule importer through the shared import payload helper', function () {
	$root    = dirname(__DIR__, 2);
	$targets = [
		'alert_import'   => $root . '/syslog_alerts.php',
		'removal_import' => $root . '/syslog_removal.php',
		'report_import'  => $root . '/syslog_reports.php',
	];

	foreach ($targets as $func => $target) {
		$content = file_get_contents($target);

		if ($content === false) {
			throw new RuntimeException("Failed to load $target");
		}

		if (substr_count($content, 'syslog_get_import_xml_payload(') !== 1) {
			throw new RuntimeException("$func: import route must call the shared payload helper exactly once in $target");
		}

		if (str_contains($content, "get_nfilter_request_var('import_text')") ||
			str_contains($content, "\$_FILES['import_file']")) {
			throw new RuntimeException("$func: route duplicates shared import payload parsing in $target");
		}
	}

	expect(true)->toBeTrue();
});
