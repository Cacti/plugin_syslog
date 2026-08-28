<?php

$root    = dirname(__DIR__, 2);
$helper  = file_get_contents($root . '/functions.php');
$targets = [
	$root . '/syslog_alerts.php',
	$root . '/syslog_reports.php',
	$root . '/syslog_removal.php'
];

$legacy = "trim(get_nfilter_request_var('import_text') != '')";

if ($helper === false) {
	fwrite(STDERR, "Unable to read the shared import helper\n");
	exit(1);
}

if (substr_count($helper, 'function syslog_get_import_xml_payload(') !== 1 ||
	preg_match('/^function syslog_get_import_xml_payload\([^)]*\)\s*\{.*?^\}/ms', $helper, $matches) !== 1) {
	fwrite(STDERR, "Unable to isolate one shared import helper\n");
	exit(1);
}

$helperBody = $matches[0];
$usesLocal  = str_contains($helperBody, '$import_text = (string) get_nfilter_request_var(\'import_text\')') &&
	str_contains($helperBody, "trim(\$import_text) !== ''");
$usesDirect = str_contains($helperBody, "trim(get_nfilter_request_var('import_text')) != ''");

if (!$usesLocal && !$usesDirect) {
	fwrite(STDERR, "Shared import helper does not preserve the issue #269 trim semantics\n");
	exit(1);
}

if (str_contains($helperBody, $legacy)) {
	fwrite(STDERR, "Legacy import_text trim/comparison bug remains in the shared import helper\n");
	exit(1);
}

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

	if (substr_count($content, 'syslog_get_import_xml_payload(') !== 1) {
		fwrite(STDERR, "Shared import payload helper call missing in $target\n");
		exit(1);
	}
}

print "issue269_import_text_trim_check_test passed\n";
