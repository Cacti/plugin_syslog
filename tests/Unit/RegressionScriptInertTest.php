<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The database regression script lives under tests/, which ships with the
 * plugin. It must do nothing when requested over the web or started without
 * the test environment.
 */

it('does nothing without the test database settings', function () {
	$script = dirname(__DIR__) . '/regression/review_fixes_database_test.php';
	$env    = array_filter(getenv(), static fn($name) => !str_starts_with($name, 'SYSLOG_TEST_'), ARRAY_FILTER_USE_KEY);
	$run    = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($run))->toBe(2)
		->and($stdout)->toBe('')
		->and($stderr)->toContain('disposable test server');
});

it('answers 404 with an empty body when requested over the web', function () {
	$directory = dirname(__DIR__) . '/regression';
	$port      = random_int(20000, 60000);
	$env       = array_filter(getenv(), static fn($name) => !str_starts_with($name, 'SYSLOG_TEST_'), ARRAY_FILTER_USE_KEY);
	$server    = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $directory], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
	$status    = 0;
	$body      = null;

	try {
		for ($attempt = 0; $attempt < 50 && $body === null; $attempt++) {
			set_error_handler(static fn() => true);
			$socket = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
			restore_error_handler();

			if ($socket === false) {
				usleep(100000);

				continue;
			}

			fwrite($socket, "GET /review_fixes_database_test.php HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
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
});
