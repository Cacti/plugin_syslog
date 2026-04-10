<?php

/*
 * Regression test for issue #269 -- branch-logic invariants.
 *
 * These assertions verify the structural properties that make whitespace-only
 * input fall through to the file-upload branch instead of the textbox branch,
 * and that a non-empty payload is assigned to $xml_data without further
 * modification.  Pure source inspection: the functions themselves cannot be
 * called in isolation because they depend on the Cacti runtime.
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

	/*
	 * 1. The request variable must be captured into a local first.
	 *    Whitespace-only input falls through only because trim() is applied
	 *    to the local; if the assignment were missing the condition would
	 *    be wrong.
	 */
	if (!preg_match('/\$import_text\s*=\s*get_nfilter_request_var\s*\(\s*\'import_text\'\s*\)/', $content)) {
		fwrite(STDERR, "$func: \$import_text assignment via get_nfilter_request_var missing in $target\n");
		exit(1);
	}

	/*
	 * 2. The branch condition must trim the local variable, not the raw
	 *    request call.  This is what makes whitespace-only values fall
	 *    through to the file-upload branch.
	 */
	if (!preg_match('/trim\s*\(\s*\$import_text\s*\)\s*!=\s*\'\'/', $content)) {
		fwrite(STDERR, "$func: trim(\$import_text) != '' condition missing in $target\n");
		exit(1);
	}

	/*
	 * 3. Inside the textbox branch, $xml_data must be assigned the
	 *    untrimmed local.  A non-empty payload is preserved as-is.
	 */
	if (!preg_match('/\$xml_data\s*=\s*\$import_text\s*;/', $content)) {
		fwrite(STDERR, "$func: \$xml_data = \$import_text assignment missing in $target\n");
		exit(1);
	}

	/*
	 * 4. The file-upload branch must still exist (elseif on $_FILES).
	 *    Ensures the fallback path was not accidentally removed.
	 */
	if (!preg_match('/elseif\s*\(\s*\(\s*\$_FILES\s*\[/', $content)) {
		fwrite(STDERR, "$func: \$_FILES elseif branch missing in $target\n");
		exit(1);
	}
}

print "issue269_import_text_branch_logic_test passed\n";
