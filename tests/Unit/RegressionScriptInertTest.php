<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The scripts under tests/ ship with the plugin. Each of them must do nothing
 * when requested over the web or when started without the test environment.
 */

function inert_environment(): array {
	return array_filter(getenv(), static fn($name) => !str_starts_with($name, 'SYSLOG_TEST_') && !str_starts_with($name, 'SYSLOG_COVERAGE_'), ARRAY_FILTER_USE_KEY);
}

function inert_run_cli(string $script, array $arguments = []): array {
	$run = proc_open(array_merge([PHP_BINARY, $script], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, inert_environment());

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($run), $stdout, $stderr];
}

it('does nothing without the test environment', function ($script, $status, $message) {
	[$code, $stdout, $stderr] = inert_run_cli(dirname(__DIR__) . '/' . $script);

	expect($code)->toBe($status)
		->and($stdout)->toBe('')
		->and($stderr)->toContain($message);
})->with([
	'database checks'       => ['regression/review_fixes_database_test.php', 2, 'disposable test server'],
	'database path checks'  => ['regression/review_fixes_paths_database_test.php', 2, 'disposable test server'],
	'live pipeline checks'  => ['regression/live_pipeline_test.php', 2, 'SYSLOG_TEST_LIVE=1'],
	'coverage report'       => ['bin/coverage-report.php', 2, 'usage'],
]);

it('stays silent as a prepend file without coverage settings', function () {
	[$code, $stdout, $stderr] = inert_run_cli(dirname(__DIR__) . '/bin/coverage-prepend.php');

	// Started directly it answers like a web request would: nothing, with a 404 status.
	expect($stdout)->toBe('')->and($stderr)->toBe('');
});

it('answers 404 with an empty body when requested over the web', function ($directory, $file) {
	$port   = random_int(20000, 60000);
	$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/' . $directory], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, inert_environment());
	$status = 0;
	$body   = null;

	try {
		for ($attempt = 0; $attempt < 50 && $body === null; $attempt++) {
			set_error_handler(static fn() => true);
			$socket = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
			restore_error_handler();

			if ($socket === false) {
				usleep(100000);

				continue;
			}

			fwrite($socket, "GET /$file HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
			$response = stream_get_contents($socket);
			fclose($socket);

			[$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
			$status        = (int) substr($head, 9, 3);
		}
	} finally {
		proc_terminate($server);
		proc_close($server);
	}

	expect($status)->toBe(404)
		->and($body)->toBe('');
})->with([
	'database checks'      => ['regression', 'review_fixes_database_test.php'],
	'database path checks' => ['regression', 'review_fixes_paths_database_test.php'],
	'live pipeline checks' => ['regression', 'live_pipeline_test.php'],
	'shared setup'         => ['regression/support', 'database_shim.php'],
	'coverage report'      => ['bin', 'coverage-report.php'],
	'coverage prepend'     => ['bin', 'coverage-prepend.php'],
]);
