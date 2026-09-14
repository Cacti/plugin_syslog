<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issues #256/#262: CSV formula-injection defusing
 * and the XML import size limit.
 */

it('defuses CSV formula injection and enforces the import size limit', function () {
	$functions = plugin_test_read_source('functions.php');

	foreach ([
		'SYSLOG_IMPORT_MAX_BYTES',
		"\$import_text = (string) get_nfilter_request_var('import_text')",
		'strlen($import_text) > SYSLOG_IMPORT_MAX_BYTES',
		'$size === false || $size <= 0 || $size > SYSLOG_IMPORT_MAX_BYTES',
		'function syslog_csv_safe(mixed $value): mixed',
	] as $needle) {
		if (!str_contains($functions, $needle)) {
			throw new RuntimeException("Missing import/export hardening: $needle");
		}
	}

	// Both Syslog CSV export paths (system logs and alert logs) must call the
	// hardening helper on every text cell. Check each branch of
	// syslog_export() individually so one branch losing several calls can't
	// hide behind matches accumulated elsewhere in the file.
	$exportStart = strpos($functions, 'function syslog_export(');
	$exportEnd   = strpos($functions, 'function syslog_debug(', $exportStart);

	if ($exportStart === false || $exportEnd === false) {
		throw new RuntimeException('Unable to isolate syslog_export()');
	}

	$exportBody  = substr($functions, $exportStart, $exportEnd - $exportStart);

	// Anchored to line start/end at one tab of indentation: syslog_csv_safe()
	// calls nested inside the per-message loops sit at deeper indentation
	// and would otherwise satisfy a plain (unanchored) substring search for
	// "} else {" from their own closing brace.
	if (!preg_match('/^\t\} else \{$/m', $exportBody, $branchMatch, PREG_OFFSET_CAPTURE)) {
		throw new RuntimeException('Unable to split syslog_export() into its system/alert log branches');
	}

	$branchSplit     = $branchMatch[0][1];
	$systemLogBranch = substr($exportBody, 0, $branchSplit);
	$alertLogBranch  = substr($exportBody, $branchSplit);

	if (substr_count($systemLogBranch, 'syslog_csv_safe(') < 5) {
		throw new RuntimeException('System log CSV export branch must harden every text cell');
	}

	if (substr_count($alertLogBranch, 'syslog_csv_safe(') < 6) {
		throw new RuntimeException('Alert log CSV export branch must harden every text cell');
	}

	if (substr_count($functions, 'fputcsv($fp, $line, \',\', \'"\', \'\')') !== 4) {
		throw new RuntimeException('Every CSV write must disable the proprietary backslash escape');
	}

	if (str_contains($functions, 'trim($hosts[$message[\'host_id\']], \' =+-@\')') ||
		str_contains($functions, 'trim($message[$syslog_incoming_config[\'textField\']], \' =+-@\')')) {
		throw new RuntimeException('CSV hardening must not mutate exported message data');
	}

	if (!preg_match('/function\s+syslog_csv_safe\s*\([^)]*\)\s*:\s*mixed\s*\{.*?\n\}/s', $functions, $match)) {
		throw new RuntimeException('Unable to extract syslog_csv_safe()');
	}

	eval(str_replace('function syslog_csv_safe', 'function issue256_262_csv_cell', $match[0]));

	foreach ([
		['=SUM(A1)', "'=SUM(A1)"],
		["\tevil", "'\tevil"],
		["\revil", "'\revil"],
		[' =SUM(A1)', "' =SUM(A1)"],
		[" \t=SUM(A1)", "' \t=SUM(A1)"],
		['   ', '   '],
		["'=SUM(A1)", "'=SUM(A1)"],
		['router-01', 'router-01'],
	] as [$input, $expected]) {
		expect(issue256_262_csv_cell($input))->toBe($expected, 'CSV formula hardening failed for ' . var_export($input, true));
	}

	$input = [
		'router-01',
		'attack\\",=cmd|\'/c calc\'!A0,"x',
		'tail',
	];
	$safe = array_map('issue256_262_csv_cell', $input);
	$csv  = fopen('php://temp', 'w+');

	if ($csv === false) {
		throw new RuntimeException('Unable to open CSV regression stream');
	}

	fputcsv($csv, $safe, ',', '"', '');
	rewind($csv);
	$row = stream_get_contents($csv);
	fclose($csv);

	if ($row === false) {
		throw new RuntimeException('Unable to read CSV regression stream');
	}

	$parsed = str_getcsv(rtrim($row, "\r\n"), ',', '"', '');

	if ($parsed !== $safe || count($parsed) !== count($input)) {
		throw new RuntimeException('CSV round trip created attacker-controlled extra cells');
	}

	foreach ($parsed as $cell) {
		if (preg_match('/^[=+\-@\t\r]/', ltrim($cell, ' ')) === 1) {
			throw new RuntimeException('CSV round trip produced an unsafe formula-leading cell');
		}
	}

	// Exercise the real oversized-import rejection in a clean child process,
	// since this plugin's request-var/logging stubs differ from bootstrap's.
	$root = dirname(__DIR__, 2);
	$code = sprintf(<<<'PHP'
		define('MESSAGE_LEVEL_ERROR', 1);

		$payload = str_repeat('x', (5 * 1024 * 1024) + 1);

		function get_nfilter_request_var(string $name): string {
			global $payload;

			return $payload;
		}

		// Real cacti_log()/raise_message() write to a log file/session, not
		// the response body; fwrite(STDERR, ...) mirrors that (and, unlike
		// print/echo, cannot itself cause "headers already sent" below).
		function cacti_log(string $message, bool $output, string $facility): void {
			fwrite(STDERR, "LOG:$message\n");
		}

		function __(string $text, string $domain = ''): string {
			return $text;
		}

		function raise_message(string $id, string $text = '', int $level = 0): void {
			fwrite(STDERR, "MSG:$text\n");
		}

		require %s;
		syslog_get_import_xml_payload('/blocked');
		print 'UNREACHABLE';
		PHP,
		var_export($root . '/functions.php', true)
	);

	$pipes   = [];
	$process = proc_open([PHP_BINARY, '-r', $code], [
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	], $pipes);

	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start oversized import regression process');
	}

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);

	// The rejection must raise its message and redirect without ever writing
	// to the response body first, or the redirect silently fails with
	// "headers already sent" and the user is left on a blank/broken page.
	if (str_contains($stderr, 'headers already sent')) {
		throw new RuntimeException("Oversized text import emitted output before redirecting: $stderr");
	}

	if ($status !== 0 || !str_contains($stderr, 'MSG:Text import payload exceeds the maximum size') ||
		str_contains($stdout, 'UNREACHABLE')) {
		throw new RuntimeException("Oversized text import did not fail closed: $stderr");
	}
});
