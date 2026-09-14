<?php

/*
 * Regression test for issue #269 -- branch-logic invariants.
 *
 * Import parsing now lives in syslog_get_import_xml_payload().  Each route must
 * delegate to that helper instead of maintaining a divergent text/file branch.
 */

$root    = dirname(__DIR__, 2);
$targets = [
	'alert_import'   => $root . '/syslog_alerts.php',
	'removal_import' => $root . '/syslog_removal.php',
	'report_import'  => $root . '/syslog_reports.php',
];

foreach ($targets as $func => $target) {
	$content = file_get_contents($target);

	if ($content === false) {
		fwrite(STDERR, "Failed to load $target\n");
		exit(1);
	}

	if (substr_count($content, 'syslog_get_import_xml_payload(') !== 1) {
		fwrite(STDERR, "$func: import route must call the shared payload helper exactly once in $target\n");
		exit(1);
	}

	if (str_contains($content, "get_nfilter_request_var('import_text')") ||
		str_contains($content, "\$_FILES['import_file']")) {
		fwrite(STDERR, "$func: route duplicates shared import payload parsing in $target\n");
		exit(1);
	}
}

print "issue269_import_text_branch_logic_test passed\n";
