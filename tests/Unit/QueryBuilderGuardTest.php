<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * QueryBuilder availability guards: a partial deploy without
 * lib/QueryBuilder.php must degrade the filter branches to an unusable rule
 * with a logged reason, never a fatal in the unattended poller.  The
 * degradation body lives in syslog_query_builder_unavailable() so it can be
 * exercised directly; a subprocess with lib/QueryBuilder.php removed proves
 * every guard routes through it.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

it('reports the unavailability reason and an empty fragment from the shared guard body', function () {
	syslog_load_plugin_source('includes/functions.php');

	unset($GLOBALS['syslog_rule_filter_error']);

	expect(syslog_query_builder_unavailable())->toBe([])
		->and($GLOBALS['syslog_rule_filter_error'])->toBe('The structured query builder is unavailable.');
});

it('fails each filter compile closed when lib/QueryBuilder.php is missing', function () {
	$shim = sys_get_temp_dir() . '/syslog-guard-' . bin2hex(random_bytes(6));

	mkdir($shim . '/includes', 0700, true);
	mkdir($shim . '/lib', 0700, true);

	copy(dirname(__DIR__, 2) . '/includes/functions.php', $shim . '/includes/functions.php');

	file_put_contents($shim . '/run.php', '<?php
	$shim = ' . var_export($shim, true) . ';

	// Collect this degraded-run coverage into a fragment the
	// patch-coverage gate merges, so the guard bodies count for the real
	// includes/functions.php (identical line numbers).
	$fragment = ' . var_export(dirname(__DIR__, 2) . '/coverage/clover-guard.xml', true) . ';

	if (function_exists("xdebug_start_code_coverage")) {
		xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

		register_shutdown_function(function () use ($fragment) {
			$coverage = xdebug_get_code_coverage();

			$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage generated=\"" . time() . "\">\n <project timestamp=\"" . time() . "\" name=\"Guard Fragment\">\n";

			foreach ($coverage as $file => $lines) {
				$xml .= "  <file name=\"" . htmlspecialchars($file) . "\">\n";

				foreach ($lines as $number => $data) {
					$count = is_array($data) ? (int) ($data[0] ?? 0) : (int) $data;

					$xml .= "   <line num=\"" . (int) $number . "\" type=\"stmt\" count=\"" . $count . "\"/>\n";
				}

				$xml .= "  </file>\n";
			}

			$xml .= " </project>\n</coverage>\n";

			file_put_contents($fragment, $xml);
		});
	}

	$GLOBALS["config"] = ["base_path" => $shim];
	$GLOBALS["syslogdb_default"] = "syslogdb";
	$GLOBALS["syslog_incoming_config"] = [
		"textField" => "message", "hostField" => "host", "programField" => "program",
		"facilityField" => "facility_id", "priorityField" => "priority_id",
	];

	require $shim . "/includes/functions.php";

	$filter = json_encode(["version" => 1, "conditions" => [["join" => "AND", "negative" => false, "field" => "message", "operator" => "contains", "value" => "crash"]]]);
	$alert = ["type" => "filter", "message" => $filter];
	$remove = ["type" => "filter", "message" => $filter, "max_seq" => 55];

	echo json_encode([
		"alert" => syslog_get_alert_sql($alert, 55, false, true),
		"alert_full" => syslog_get_alert_sql($alert, 55, false, false),
		"alert_full_error" => $GLOBALS["syslog_rule_filter_error"] ?? null,
		"alert_error" => $GLOBALS["syslog_rule_filter_error"] ?? null,
		"removal" => syslog_get_removal_rule_sql($remove, "syslog_incoming"),
		"removal_error" => $GLOBALS["syslog_rule_filter_error"] ?? null,
		"normalize" => syslog_removal_filter_normalize($filter, "syslog"),
	]);
	');

	try {
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($shim . '/run.php'), $output, $status);

		expect($status)->toBe(0);

		$report = json_decode(implode("\n", $output), true);
		$document = json_decode('{"version":1,"conditions":[{"join":"AND","negative":false,"field":"message","operator":"contains","value":"crash"}]}', true);

		expect($report['alert'])->toBe([])
			->and($report['alert_error'])->toBe('The structured query builder is unavailable.')
			->and($report['alert_full'])->toBe([])
			->and($report['alert_full_error'])->toBe('The structured query builder is unavailable.')
			->and($report['removal'])->toBe([])
			->and($report['removal_error'])->toBe('The structured query builder is unavailable.')
			->and($report['normalize'])->toBe($document, 'The normalize guard must pass the raw document through.');
	} finally {
		foreach (['includes/functions.php', 'run.php'] as $relative) {
			$file = $shim . '/' . $relative;

			if (is_file($file)) {
				unlink($file);
			}
		}

		rmdir($shim . '/includes');
		rmdir($shim . '/lib');
		rmdir($shim);
	}
});
