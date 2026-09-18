<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Coverage for the syslog_max_workers settings entry: the admin facing
 * control must exist in the General Settings group, default to single
 * process operation, and offer the documented worker choices.
 */

function syslog_worker_settings_extract_array(string $file, string $key): string {
	$content = file_get_contents($file);

	if ($content === false) {
		throw new RuntimeException("Unable to read $file");
	}

	if (preg_match("/'" . $key . "' => \[.*?\n\t\t\],/s", $content, $matches) !== 1) {
		throw new RuntimeException("Unable to isolate settings array '$key' in $file");
	}

	return $matches[0];
}

it('defines the syslog_max_workers setting with single process default', function () {
	$root    = dirname(__DIR__, 2);
	$element = syslog_worker_settings_extract_array($root . '/setup.php', 'syslog_max_workers');

	expect(str_contains($element, "'method'        => 'drop_array'"))->toBeTrue('Rendered as a dropdown');
	expect(str_contains($element, "'default'       => '1'"))->toBeTrue('Defaults to a single worker process');
	expect(str_contains($element, "'friendly_name' => __('Maximum Syslog Processing Processes', 'syslog')"))->toBeTrue('Localized friendly name present');
});

it('offers the documented worker choices', function () {
	$root    = dirname(__DIR__, 2);
	$element = syslog_worker_settings_extract_array($root . '/setup.php', 'syslog_max_workers');

	foreach ([1, 2, 4, 6, 8, 16] as $choice) {
		expect(preg_match('/\t\t\t\t' . $choice . '\s+=> __\(\'%d Worker Process/', $element))->toBe(1, "Choice $choice present");
	}

	expect(str_contains($element, 'pcntl'))->toBeTrue('Documents the platform fallback');
});

it('documents the parallel behavior in the description', function () {
	$root    = dirname(__DIR__, 2);
	$element = syslog_worker_settings_extract_array($root . '/setup.php', 'syslog_max_workers');

	expect(str_contains($element, 'parallel worker processes'))->toBeTrue();
	expect(str_contains($element, 'disjoint range'))->toBeTrue();
	expect(str_contains($element, 'Windows'))->toBeTrue();
});