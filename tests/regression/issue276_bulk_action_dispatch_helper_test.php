<?php

$root = dirname(__DIR__, 2);

$functions = file_get_contents($root . '/functions.php');

if ($functions === false) {
	fwrite(STDERR, "Failed to load functions.php\n");
	exit(1);
}

if (!preg_match('/function\s+syslog_apply_selected_items_action\s*\(/', $functions)) {
	fwrite(STDERR, "Shared selected-items action helper is missing.\n");
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

	if (!preg_match('/syslog_apply_selected_items_action\s*\(/', $content)) {
		fwrite(STDERR, "Shared selected-items action helper is not used in $target\n");
		exit(1);
	}
}

print "issue276_bulk_action_dispatch_helper_test passed\n";
