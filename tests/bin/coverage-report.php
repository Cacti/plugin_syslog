<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Merge the dumps written by tests/bin/coverage-prepend.php and list, for the
 * lines a change adds, whether the real entry points executed them, plus the
 * branches that overlap those lines.
 *
 * Usage: php tests/bin/coverage-report.php <dump-directory> <base-ref> [--fail]
 *
 * Exits 1 with --fail when a changed executable line was not executed, 2 on
 * bad input.
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);

	exit;
}

if ($argc < 3 || !is_dir($argv[1])) {
	fwrite(STDERR, "usage: coverage-report.php <dump-directory> <base-ref> [--fail]\n");

	exit(2);
}

$diff = [];
exec('git --no-pager diff --no-ext-diff -U0 ' . escapeshellarg($argv[2]) . '...HEAD -- . ' . escapeshellarg(':!tests') . ' ' . escapeshellarg(':!*.md') . ' ' . escapeshellarg(':!locales') . ' ' . escapeshellarg(':!.github') . ' 2>&1', $diff);

$file    = '';
$changed = [];

foreach ($diff as $row) {
	if (preg_match('#^\+\+\+ b/(.+\.php)$#', $row, $match)) {
		$file = $match[1];
	} elseif ($file !== '' && preg_match('/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/', $row, $match)) {
		$count = isset($match[2]) ? (int) $match[2] : 1;

		for ($i = 0; $i < $count; $i++) {
			$changed[$file][] = (int) $match[1] + $i;
		}
	}
}

$lines    = [];
$branches = [];

foreach (array_merge(glob($argv[1] . '/*.json'), glob($argv[1] . '/*.jsonl')) as $dump) {
	foreach (file($dump, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $row) {
		$decoded = json_decode($row, true) ?: [];
		$entries = isset($decoded['file'], $decoded['data']) ? [$decoded['file'] => $decoded['data']] : $decoded;

		foreach ($entries as $file => $data) {
			if (!isset($changed[$file])) {
				continue;
			}

			foreach ($data['lines'] ?? [] as $number => $hit) {
				$lines[$file][$number] = max($lines[$file][$number] ?? -2, $hit);
			}

			foreach ($data['functions'] ?? [] as $function => $info) {
				foreach ($info['branches'] ?? [] as $id => $branch) {
					$key = $function . '#' . $id;

					if (!isset($branches[$file][$key])) {
						$branches[$file][$key] = $branch + ['function' => $function];
					}

					$branches[$file][$key]['hit'] = max($branches[$file][$key]['hit'], $branch['hit']);
				}
			}
		}
	}
}

$failed = false;

printf("%-28s %8s %9s %9s %12s\n", 'file', 'changed', 'executed', 'missed', 'branches');

foreach ($changed as $name => $numbers) {
	$executed = 0;
	$missed   = [];

	foreach ($numbers as $number) {
		$hit = $lines[$name][$number] ?? null;

		if ($hit === 1) {
			$executed++;
		} elseif ($hit === -1) {
			$missed[] = $number;
		}
	}

	$overlap  = 0;
	$taken    = 0;
	$unhit    = [];

	foreach ($branches[$name] ?? [] as $branch) {
		foreach ($numbers as $number) {
			if ($number >= $branch['line_start'] && $number <= $branch['line_end']) {
				$overlap++;
				$taken += $branch['hit'] ? 1 : 0;

				if (!$branch['hit']) {
					$unhit[$branch['line_start'] . '-' . $branch['line_end']] = true;
				}

				break;
			}
		}
	}

	printf("%-28s %8d %9d %9d %12s\n", $name, count($numbers), $executed, count($missed), $overlap ? $taken . '/' . $overlap : 'n/a');

	if ($missed) {
		print '  missed lines: ' . implode(', ', $missed) . "\n";
		$failed = true;
	}

	if ($unhit) {
		print '  branches not taken at lines: ' . implode(', ', array_keys($unhit)) . "\n";
	}
}

exit($failed && in_array('--fail', $argv, true) ? 1 : 0);
