<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Coverage for the #259 purge-syslog-hosts CSRF guard.
 *
 * setup.php and functions.php are pure function definitions, so the guard and
 * the utilities fragment can be driven for real against stubbed Cacti helpers
 * in a throwaway plugin directory, in a dedicated child process (its own
 * fake __()/csrf_check()/etc. would otherwise collide with this suite's
 * bootstrap stubs). syslog_config_safe() keys off a config.php beside
 * setup.php, which a checkout does not carry, hence the copy.
 *
 * What stays a source scan: the Location headers, because header() is a
 * no-op under the CLI SAPI; the absence of the old GET entry point; and
 * syslog.php, which is a page rather than a library and cannot be included
 * here.
 */

it('requires POST and a valid CSRF token for the purge-syslog-hosts utility', function () {
	$root = dirname(__DIR__, 2);

	$sandbox = sys_get_temp_dir() . '/' . uniqid('syslog259_', true);

	if (!mkdir($sandbox, 0700)) {
		throw new RuntimeException('Unable to create the sandbox plugin directory');
	}

	register_shutdown_function(function () use ($sandbox) {
		foreach (glob($sandbox . '/*') as $file) {
			unlink($file);
		}

		rmdir($sandbox);
	});

	foreach (['setup.php', 'functions.php'] as $file) {
		if (!copy($root . '/' . $file, $sandbox . '/' . $file)) {
			throw new RuntimeException("Unable to stage $file");
		}
	}

	file_put_contents($sandbox . '/config.php', "<?php\n");

	// The hostile title proves the encoder in place rather than in isolation: a
	// translation carrying </script> must not close the block it sits in.
	$payload = '</script><img src=x onerror=alert(1)>\'"&';

	$harness = <<<'HARNESS'
		<?php

		define('MESSAGE_LEVEL_INFO', 1);
		define('MESSAGE_LEVEL_ERROR', 3);

		$scenario = $argv[1];
		$payload  = getenv('ISSUE259_PAYLOAD');

		function __($text, ...$args) {
			global $payload;

			return $text === 'Confirm Purge' ? $payload : $text;
		}

		function __esc($text, ...$args) {
			return htmlspecialchars(__($text), ENT_QUOTES, 'UTF-8');
		}

		function cacti_log($message, $output = false, $facility = '') {
			print "LOG:$message\n";
		}

		function raise_message($id, $message = '', $level = MESSAGE_LEVEL_INFO) {
			print "MSG:$id|$message|$level\n";
		}

		function html_header($items, $span = 1) {
		}

		function syslog_db_execute($sql) {
			print "DBEXEC\n";

			return true;
		}

		function syslog_db_affected_rows() {
			return 1;
		}

		if ($scenario === 'action_valid_token') {
			function csrf_check($fatal = true) {
				return true;
			}
		} elseif ($scenario === 'action_bad_token') {
			function csrf_check($fatal = true) {
				return false;
			}
		}

		require_once __DIR__ . '/functions.php';
		require_once __DIR__ . '/setup.php';

		switch ($scenario) {
			case 'render':
				syslog_utilities_list();

				break;
			case 'action_get':
				$_SERVER['REQUEST_METHOD'] = 'GET';
				syslog_utilities_action('purge_syslog_hosts');

				break;
			default:
				$_SERVER['REQUEST_METHOD'] = 'POST';
				syslog_utilities_action('purge_syslog_hosts');
		}

		print "REACHED_END\n";
		HARNESS;

	file_put_contents($sandbox . '/harness.php', $harness);

	/*
	 * The payload travels through the process environment rather than argv:
	 * shell/argv quoting rules for control characters differ across platforms
	 * (notably Windows vs. POSIX shells), which can silently corrupt a hostile
	 * string in transit. Using proc_open()'s $env parameter hands the value to
	 * the child process verbatim on every platform.
	 */
	$runHarness = function ($scenario) use ($sandbox, $payload) {
		$pipes   = [];
		$process = proc_open(
			[PHP_BINARY, $sandbox . '/harness.php', $scenario],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			['ISSUE259_PAYLOAD' => $payload] + getenv()
		);

		if (!is_resource($process)) {
			return false;
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		return $stdout . $stderr;
	};

	// The encoder, on its own.
	$this->loadPluginSource('functions.php');

	$encoded = syslog_json_safe($payload);

	if (json_decode($encoded) !== $payload) {
		throw new RuntimeException('syslog_json_safe() does not round-trip through json_decode()');
	}

	foreach (['<', '>', '&', '"', '\''] as $raw) {
		if (strpos(substr($encoded, 1, -1), $raw) !== false) {
			throw new RuntimeException("syslog_json_safe() left a raw $raw in the JS literal");
		}
	}

	// The utilities fragment, rendered.
	$render = $runHarness('render');

	if (strpos($render, 'REACHED_END') === false) {
		throw new RuntimeException("syslog_utilities_list() did not complete:\n$render");
	}

	if (stripos($render, '<html') !== false) {
		throw new RuntimeException('The utilities fragment must not open a document');
	}

	if (substr_count($render, '</script>') !== 1) {
		throw new RuntimeException('A translated string escaped its script block');
	}

	if (!preg_match('/title:\s*("[^"]*"),/', $render, $title)) {
		throw new RuntimeException('The dialog title is not a JSON literal');
	}

	if (json_decode($title[1]) !== $payload) {
		throw new RuntimeException('The dialog title does not decode back to the translated text');
	}

	if (strpos($render, "'utilities.php?header=false'") === false) {
		throw new RuntimeException('The purge post must target the headerless utilities page');
	}

	if (strpos($render, 'json.__csrf_magic = csrfMagicToken;') === false) {
		throw new RuntimeException('The purge post must carry the CSRF token');
	}

	// The guard, driven.
	$blocked = [
		'action_get'       => 'non-POST request',
		'action_no_csrf'   => 'CSRF validation unavailable',
		'action_bad_token' => 'CSRF token validation failed',
	];

	foreach ($blocked as $scenario => $reason) {
		$output = $runHarness($scenario);

		if (strpos($output, 'DBEXEC') !== false) {
			throw new RuntimeException("$scenario reached the purge deletes");
		}

		if (strpos($output, "LOG:WARNING: syslog purge blocked -- $reason") === false) {
			throw new RuntimeException("$scenario did not audit the block as '$reason':\n$output");
		}

		if (!preg_match('/^MSG:(\S+)\|([^|]*)\|(\d+)$/m', $output, $message)) {
			throw new RuntimeException("$scenario did not raise a user-visible message:\n$output");
		}

		if ($message[3] != MESSAGE_LEVEL_ERROR) {
			throw new RuntimeException("$scenario raised the block at level {$message[3]}, not error");
		}

		if ($message[2] !== 'Invalid request. Please try again.') {
			throw new RuntimeException("$scenario used the wrong user-facing text: {$message[2]}");
		}

		if (stripos($message[2], 'csrf') !== false) {
			throw new RuntimeException("$scenario leaked CSRF internals to the user");
		}
	}

	// The allowed path, driven with a valid token.
	$allowed = $runHarness('action_valid_token');

	if (substr_count($allowed, 'DBEXEC') !== 2) {
		throw new RuntimeException("A POST with a valid token must run both deletes:\n$allowed");
	}

	if (strpos($allowed, 'MSG:syslog_info|') === false) {
		throw new RuntimeException("A completed purge must report the record count:\n$allowed");
	}
});
