<?php

$functions = file_get_contents(__DIR__ . '/../../functions.php');

if ($functions === false) {
	fwrite(STDERR, "Unable to read Syslog sources\n");
	exit(1);
}

foreach ([
	'SYSLOG_IMPORT_MAX_BYTES',
	'$import_text = (string) get_nfilter_request_var(\'import_text\')',
	'strlen($import_text) > SYSLOG_IMPORT_MAX_BYTES',
	'$size <= 0 || $size > SYSLOG_IMPORT_MAX_BYTES',
	'function syslog_csv_cell(mixed $value): string',
	"array_map('syslog_csv_cell'",
] as $needle) {
	if (!str_contains($functions, $needle)) {
		fwrite(STDERR, "Missing import/export hardening: $needle\n");
		exit(1);
	}
}

if (substr_count($functions, "array_map('syslog_csv_cell'") !== 2) {
	fwrite(STDERR, "Both Syslog CSV export paths must harden every cell\n");
	exit(1);
}

if (substr_count($functions, 'fputcsv($fp, $line, \',\', \'"\', \'\')') !== 4) {
	fwrite(STDERR, "Every CSV write must disable the proprietary backslash escape\n");
	exit(1);
}

if (str_contains($functions, 'trim($hosts[$message[\'host_id\']], \' =+-@\')') ||
	str_contains($functions, 'trim($message[$syslog_incoming_config[\'textField\']], \' =+-@\')')) {
	fwrite(STDERR, "CSV hardening must not mutate exported message data\n");
	exit(1);
}

if (!preg_match('/function\s+syslog_csv_cell\s*\([^)]*\)\s*:\s*string\s*\{.*?\n\}/s', $functions, $match)) {
	fwrite(STDERR, "Unable to extract syslog_csv_cell()\n");
	exit(1);
}

eval(str_replace('function syslog_csv_cell', 'function issue256_262_csv_cell', $match[0]));

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
	if (issue256_262_csv_cell($input) !== $expected) {
		fwrite(STDERR, 'CSV formula hardening failed for ' . var_export($input, true) . "\n");
		exit(1);
	}
}

$input = [
	'router-01',
	'attack\\",=cmd|\'/c calc\'!A0,"x',
	'tail',
];
$safe  = array_map('issue256_262_csv_cell', $input);
$csv   = fopen('php://temp', 'w+');

if ($csv === false) {
	fwrite(STDERR, "Unable to open CSV regression stream\n");
	exit(1);
}

fputcsv($csv, $safe, ',', '"', '');
rewind($csv);
$row = stream_get_contents($csv);
fclose($csv);

if ($row === false) {
	fwrite(STDERR, "Unable to read CSV regression stream\n");
	exit(1);
}

$parsed = str_getcsv(rtrim($row, "\r\n"), ',', '"', '');

if ($parsed !== $safe || count($parsed) !== count($input)) {
	fwrite(STDERR, "CSV round trip created attacker-controlled extra cells\n");
	exit(1);
}

foreach ($parsed as $cell) {
	if (preg_match('/^[=+\-@\t\r]/', ltrim($cell, ' ')) === 1) {
		fwrite(STDERR, "CSV round trip produced an unsafe formula-leading cell\n");
		exit(1);
	}
}

$root = dirname(__DIR__, 2);
$code = sprintf(<<<'PHP'
$payload = str_repeat('x', (5 * 1024 * 1024) + 1);

function get_nfilter_request_var(string $name): string {
	global $payload;

	return $payload;
}

function cacti_log(string $message, bool $output, string $facility): void {
	print $message;
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
	fwrite(STDERR, "Unable to start oversized import regression process\n");
	exit(1);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);

if ($status !== 0 || !str_contains($stdout, 'Text import payload exceeds the maximum size') ||
	str_contains($stdout, 'UNREACHABLE')) {
	fwrite(STDERR, "Oversized text import did not fail closed: $stderr\n");
	exit(1);
}

print "issue256_262_csv_import_hardening_test passed\n";
