<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the deduplicated selected-items bulk action
 * dispatch helper: every rule-management page must route through the one
 * shared implementation instead of its own copy.
 */

it('routes bulk selected-item actions through the shared dispatch helper', function () {
	$root      = dirname(__DIR__, 2);
	$functions = file_get_contents($root . '/functions.php');

	if ($functions === false) {
		throw new RuntimeException('Failed to load functions.php');
	}

	if (!preg_match('/function\s+syslog_apply_selected_items_action\s*\(/', $functions)) {
		throw new RuntimeException('Shared selected-items action helper is missing.');
	}

	$targets = [
		$root . '/syslog_alerts.php',
		$root . '/syslog_reports.php',
		$root . '/syslog_removal.php',
	];

	foreach ($targets as $target) {
		$content = file_get_contents($target);

		if ($content === false) {
			throw new RuntimeException("Failed to load $target");
		}

		if (!preg_match('/syslog_apply_selected_items_action\s*\(/', $content)) {
			throw new RuntimeException("Shared selected-items action helper is not used in $target");
		}
	}
});
