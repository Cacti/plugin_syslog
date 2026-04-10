<?php

$root    = dirname(__DIR__, 2);
$targets = [
	$root . '/syslog_alerts.php',
	$root . '/syslog_reports.php',
	$root . '/syslog_removal.php'
];

$legacy = "trim(get_nfilter_request_var('import_text') != '')";

foreach ($targets as $target) {
	$content = file_get_contents($target);

	if ($content === false) {
		fwrite(STDERR, "Failed to load $target\n");
		exit(1);
	}

	if (strpos($content, $legacy) !== false) {
		fwrite(STDERR, "Legacy import_text trim/comparison bug remains in $target\n");
		exit(1);
	}

	$fixedPattern = '/trim\s*\(\s*\$import_text\s*\)\s*!=\s*\'\'/';

	if (!preg_match($fixedPattern, $content)) {
		fwrite(STDERR, "Fixed import_text trim/comparison check missing in $target\n");
		exit(1);
	}

	/* After the local $import_text assignment, there must be no second
	   get_nfilter_request_var('import_text') call.  A duplicate call
	   would bypass the cached local variable. */
	$needle    = "\$import_text = get_nfilter_request_var('import_text')";
	$assignPos = strpos($content, $needle);

	if ($assignPos !== false) {
		$afterAssign = substr($content, $assignPos + strlen($needle));

		if (preg_match('/get_nfilter_request_var\s*\(\s*\'import_text\'\s*\)/', $afterAssign)) {
			fwrite(STDERR, "Redundant get_nfilter_request_var('import_text') call after local assignment in $target\n");
			exit(1);
		}
	}
}

print "issue269_import_text_trim_check_test passed\n";
