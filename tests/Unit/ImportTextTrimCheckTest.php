<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #269: the shared import payload helper must
 * preserve the original trim/comparison semantics (checking the trimmed
 * text for emptiness while returning the untrimmed value), and the legacy
 * buggy comparison must not reappear anywhere it was removed from.
 */

it('preserves import text trim semantics in the shared payload helper', function () {
	$root    = dirname(__DIR__, 2);
	$helper  = file_get_contents($root . '/functions.php');
	$targets = [
		$root . '/syslog_alerts.php',
		$root . '/syslog_reports.php',
		$root . '/syslog_removal.php',
	];

	$legacy = "trim(get_nfilter_request_var('import_text') != '')";

	if ($helper === false) {
		throw new RuntimeException('Unable to read the shared import helper');
	}

	if (substr_count($helper, 'function syslog_get_import_xml_payload(') !== 1 ||
		preg_match('/^function syslog_get_import_xml_payload\([^)]*\)\s*\{.*?^\}/ms', $helper, $matches) !== 1) {
		throw new RuntimeException('Unable to isolate one shared import helper');
	}

	$helperBody = $matches[0];
	$usesLocal  = str_contains($helperBody, '$import_text = (string) get_nfilter_request_var(\'import_text\')') &&
		str_contains($helperBody, "trim(\$import_text) !== ''");
	$usesDirect = str_contains($helperBody, "trim(get_nfilter_request_var('import_text')) != ''");

	if (!$usesLocal && !$usesDirect) {
		throw new RuntimeException('Shared import helper does not preserve the issue #269 trim semantics');
	}

	if (str_contains($helperBody, $legacy)) {
		throw new RuntimeException('Legacy import_text trim/comparison bug remains in the shared import helper');
	}

	foreach ($targets as $target) {
		$content = file_get_contents($target);

		if ($content === false) {
			throw new RuntimeException("Failed to load $target");
		}

		if (strpos($content, $legacy) !== false) {
			throw new RuntimeException("Legacy import_text trim/comparison bug remains in $target");
		}

		if (substr_count($content, 'syslog_get_import_xml_payload(') !== 1) {
			throw new RuntimeException("Shared import payload helper call missing in $target");
		}
	}
});
