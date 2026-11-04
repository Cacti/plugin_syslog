<?php
/*
 * End-to-end checks against an installed Cacti with this plugin enabled: the
 * real processor (syslog_process.php) is run over queued messages and the
 * outcome is read back from the database. It covers alert commands, archiving
 * and deleting removal rules, an invalid alert rule next to valid ones, and the
 * dashboard queries over the processed messages.
 *
 *   SYSLOG_TEST_LIVE=1 php plugins/syslog/tests/regression/live_pipeline_test.php
 *
 * It only touches rows whose names start with "live-test", removes them when it
 * is done, and restores cacti.log to the size it had when the run started. The
 * file does nothing over the web or without SYSLOG_TEST_LIVE=1.
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);

	exit;
}

if (getenv('SYSLOG_TEST_LIVE') !== '1') {
	fwrite(STDERR, "Set SYSLOG_TEST_LIVE=1 to run these checks against an installed Cacti.\n");

	exit(2);
}

$cacti_root = dirname(__DIR__, 4);

require $cacti_root . '/include/cli_check.php';
include_once dirname(__DIR__, 2) . '/setup.php';
include_once dirname(__DIR__, 2) . '/includes/settings.php';

if (!syslog_connect()) {
	fwrite(STDERR, "The Syslog database is not available.\n");

	exit(2);
}

global $config, $severities, $syslog_levels, $syslog_facilities;

syslog_config_arrays();

$log_file = $cacti_root . '/log/cacti.log';
$log_size = is_file($log_file) ? filesize($log_file) : 0;
$checks   = 0;
$failures = [];
$work     = sys_get_temp_dir() . '/syslog-live-' . bin2hex(random_bytes(6));

mkdir($work, 0700);

function live_check($condition, $message) {
	$GLOBALS['checks']++;

	if (!$condition) {
		$GLOBALS['failures'][] = $message;
	}
}

function live_value($sql, array $params = []) {
	return syslog_db_fetch_cell_prepared($sql, $params);
}

function live_rule($table, array $values) {
	$statements = [
		'syslog_alert'  => 'INSERT INTO syslog_alert (name, type, message, method, level, num, enabled, severity, user, email, command, recovery_command, notes, suppression_schedule) VALUES (?, ?, ?, ?, ?, 1, \'on\', 1, \'live-test\', \'\', ?, \'\', \'\', \'\')',
		'syslog_remove' => 'INSERT INTO syslog_remove (name, type, message, method, enabled, user) VALUES (?, ?, ?, ?, \'on\', \'live-test\')',
	];

	syslog_db_execute_prepared($statements[$table], $values);
}

function live_message($host, $program, $message) {
	syslog_db_execute_prepared('INSERT INTO syslog_incoming (facility_id, priority_id, program, logtime, host, message) VALUES (1, 3, ?, NOW(), ?, ?)', [$program, $host, $message]);
}

function live_process($work) {
	$process = proc_open([PHP_BINARY, '-q', dirname(__DIR__, 2) . '/syslog_process.php'], [1 => ['file', $work . '/process.out', 'w'], 2 => ['file', $work . '/process.err', 'w']], $pipes);

	return is_resource($process) ? proc_close($process) : -1;
}

function live_cleanup() {
	syslog_db_execute("DELETE l FROM syslog_logs l INNER JOIN syslog_alert a ON a.id = l.alert_id WHERE a.user = 'live-test'");
	syslog_db_execute("DELETE FROM syslog_alert WHERE user = 'live-test'");
	syslog_db_execute("DELETE FROM syslog_remove WHERE user = 'live-test'");
	syslog_db_execute("DELETE FROM syslog_incoming WHERE host LIKE 'live-test%' OR host LIKE 'unresolved-live-test%'");
	syslog_db_execute("DELETE s FROM syslog s INNER JOIN syslog_hosts h ON h.host_id = s.host_id WHERE h.host LIKE 'live-test%'");
	syslog_db_execute("DELETE s FROM syslog_removed s INNER JOIN syslog_hosts h ON h.host_id = s.host_id WHERE h.host LIKE 'live-test%'");
	syslog_db_execute("DELETE FROM syslog_hosts WHERE host LIKE 'live-test%'");
	syslog_db_execute("DELETE FROM syslog_programs WHERE program LIKE 'live-test%'");
}

$saved_options = [];

foreach (['syslog_enabled' => 'on', 'syslog_resolve_hostname' => '', 'syslog_html' => '', 'syslog_alert_cooldown_minutes' => '0', 'syslog_alert_deduplication_minutes' => '0'] as $name => $value) {
	$saved_options[$name] = read_config_option($name, true);
	set_config_option($name, $value);
}

try {
	live_cleanup();

	// ---- Alert commands receive each value as one argument --------------------
	$hostile = [
		'plain'        => ['live-test-plain', 'plain message'],
		'spaces'       => ['live-test host with spaces', 'message  with   spaces'],
		'quotes'       => ["live-test-o'brien", 'say "hello" and \'bye\''],
		'metacharacters' => ['live-test-meta;rm', 'a; b | c & d > e < f $HOME `id` $(id) * ? [x] \\ !'],
		'host token'   => ['live-test-a<MESSAGE>b', 'plain text'],
		'message token' => ['live-test-token', 'before <HOSTNAME> and <MESSAGE> and <SEVERITY> after'],
		'both tokens'  => ['live-test-<MESSAGE>', 'x <HOSTNAME> y'],
		'newline'      => ['live-test-newline', "first line\nsecond line"],
		'unicode'      => ['live-test-ünï', 'ключ=значение'],
	];

	$index  = 0;
	$stored = [];

	foreach ($hostile as $label => [$host, $message]) {
		$index++;
		$tag    = 'LIVETAG' . $index . 'X';
		$script = $work . '/command-' . $index . '.php';
		$output = $work . '/arguments-' . $index . '.json';

		file_put_contents($script, '#!' . PHP_BINARY . "\n<?php file_put_contents(" . var_export($output, true) . ', json_encode(array_slice($argv, 1)));');
		chmod($script, 0700);

		live_rule('syslog_alert', ["live-test $label", 'messagec', $tag, 0, 1, $script . ' <ALERTID> <HOSTNAME> <PRIORITY> <FACILITY> <MESSAGE> <SEVERITY>']);
		live_message($host, 'live-test-app', $tag . ' ' . $message);
		$stored[$label] = [(string) live_value('SELECT host FROM syslog_incoming WHERE message LIKE ?', [$tag . ' %']), (string) live_value('SELECT message FROM syslog_incoming WHERE message LIKE ?', [$tag . ' %'])];
	}

	live_check(live_process($work) === 0, 'The processor exits cleanly after the alert command messages, ' . trim((string) @file_get_contents($work . '/process.err')));

	$index = 0;

	foreach ($hostile as $label => [$host, $message]) {
		$index++;
		$tag      = 'LIVETAG' . $index . 'X';
		$output   = $work . '/arguments-' . $index . '.json';
		$id       = (int) live_value("SELECT id FROM syslog_alert WHERE name = ?", ["live-test $label"]);
		$actual   = is_file($output) ? json_decode(file_get_contents($output), true) : null;
		[$stored_host, $stored_message] = $stored[$label];
		// Cacti's argument escaping removes line breaks, so a message arrives as one line.
		$expected = [(string) $id, str_replace(["\n", "\r"], '', $stored_host), $syslog_levels[3], $syslog_facilities[1], str_replace(["\n", "\r"], '', $stored_message), $severities[1]];

		live_check($stored_host === $host, "The $label host name is stored as received");

		live_check($actual === $expected, "The alert command for the $label case gets six exact arguments" . ($actual === null ? ' (the command did not run)' : ($actual === $expected ? '' : ', got ' . json_encode($actual))));
	}

	// ---- Removal rules through the processor ------------------------------------
	live_cleanup();
	live_rule('syslog_remove', ['live-test archive host', 'host', 'live-test-archived', 'trans']);
	live_rule('syslog_remove', ['live-test archive program', 'program', 'live-test-prog-archived', 'trans']);
	live_rule('syslog_remove', ['live-test delete phrase', 'messagec', 'LIVE-DELETE-ME', 'del']);
	live_rule('syslog_remove', ['live-test archive phrase', 'messageb', 'LIVE-ARCHIVE-ME', 'trans']);

	live_message('live-test-archived', 'live-test-app', 'goes to the removed table by host');
	live_message('live-test-kept', 'live-test-prog-archived', 'goes to the removed table by program');
	live_message('live-test-kept', 'live-test-app', 'LIVE-DELETE-ME vanishes');
	live_message('live-test-kept', 'live-test-app', 'LIVE-ARCHIVE-ME moves');
	live_message('live-test-kept', 'live-test-app', 'ordinary message stays');

	live_check(live_process($work) === 0, 'The processor exits cleanly after the removal rule messages');
	live_check((int) live_value("SELECT COUNT(*) FROM syslog_removed r INNER JOIN syslog_hosts h ON h.host_id = r.host_id WHERE h.host LIKE 'live-test%'") === 3, 'Host, program and begins-with rules with the move method archive three messages');
	live_check((int) live_value("SELECT COUNT(*) FROM syslog s INNER JOIN syslog_hosts h ON h.host_id = s.host_id WHERE h.host LIKE 'live-test%'") === 1, 'One ordinary message reaches the main table');
	live_check((int) live_value("SELECT COUNT(*) FROM syslog_incoming WHERE host LIKE 'live-test%'") === 0, 'The queue is drained');
	live_check((int) live_value("SELECT COUNT(*) FROM syslog WHERE message LIKE '%LIVE-DELETE-ME%'") === 0 && (int) live_value("SELECT COUNT(*) FROM syslog_removed WHERE message LIKE '%LIVE-DELETE-ME%'") === 0, 'A delete rule leaves no copy behind');

	// ---- An invalid alert rule does not silence the valid ones -------------------
	live_cleanup();
	live_rule('syslog_alert', ['live-test good immediate', 'messagec', 'LIVE-GOOD', 0, 0, '']);
	live_rule('syslog_alert', ['live-test good per host', 'sql', "message LIKE '%LIVE-GOOD%'", 1, 1, '']);
	live_rule('syslog_alert', ['live-test bad query', 'sql', "message LIKE '%x' AND (", 0, 0, '']);
	live_rule('syslog_alert', ['live-test bad per host', 'sql', 'host IN (', 1, 1, '']);

	live_message('live-test-alerted', 'live-test-app', 'LIVE-GOOD message');
	live_check(live_process($work) === 0, 'The processor exits cleanly with invalid alert rules present');

	$fired = syslog_db_fetch_assoc("SELECT DISTINCT a.name FROM syslog_logs l INNER JOIN syslog_alert a ON a.id = l.alert_id WHERE a.user = 'live-test' ORDER BY a.name");

	live_check(array_column($fired, 'name') === ['live-test good immediate', 'live-test good per host'], 'Both valid rules fire and neither invalid rule does, fired: ' . implode(', ', array_column($fired, 'name')));

	// ---- Dashboard queries over the processed messages --------------------------
	live_message('live-test-dash-a', 'live-test-app', 'dashboard one');
	live_message('live-test-dash-a', 'live-test-app', 'dashboard two');
	live_message('live-test-dash-b', 'live-test-app', 'dashboard three');
	live_check(live_process($work) === 0, 'The processor exits cleanly before the dashboard checks');

	require_once dirname(__DIR__, 2) . '/lib/syslog_dashboard.php';

	$range = "syslog.logtime BETWEEN NOW() - INTERVAL 1 HOUR AND NOW() + INTERVAL 1 HOUR AND syslog.message LIKE 'dashboard%'";

	foreach (['', 'ONLY_FULL_GROUP_BY'] as $mode) {
		syslog_db_execute_prepared('SET SESSION sql_mode = ?', [$mode]);

		foreach (['-1', '1', '2'] as $removal) {
			$rows = syslog_db_fetch_assoc(syslog_dashboard_breakdown_sql('syslog', 'host', $removal, $range, 0));
			$map  = $rows === false ? [] : array_column($rows, 'records', 'label');
			$want = $removal === '2' ? [] : ['live-test-dash-a' => 2, 'live-test-dash-b' => 1];

			live_check(array_map('intval', $map) == $want, "The host breakdown for record type $removal is right in " . ($mode ?: 'default mode'));

			$series = syslog_db_fetch_assoc(syslog_dashboard_timeseries_sql('syslog', $removal, $range, 3600, 0));

			live_check($series !== false && array_sum(array_column($series, 'records')) == ($removal === '2' ? 0 : 3), "The timeseries total for record type $removal is right in " . ($mode ?: 'default mode'));
		}
	}

	syslog_db_execute_prepared('SET SESSION sql_mode = ?', ['']);
} catch (Throwable $error) {
	$failures[] = $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')';
} finally {
	live_cleanup();

	foreach ($saved_options as $name => $value) {
		set_config_option($name, $value === false ? '' : $value);
	}

	$process_output = (string) @file_get_contents($work . '/process.out') . (string) @file_get_contents($work . '/process.err');

	foreach (glob($work . '/*') ?: [] as $file) {
		unlink($file);
	}

	rmdir($work);

	if (is_file($log_file) && filesize($log_file) > $log_size) {
		$handle = fopen($log_file, 'r+');
		ftruncate($handle, $log_size);
		fclose($handle);
	}
}

if ($failures) {
	fwrite(STDERR, 'Processor output: ' . trim(substr((string) $process_output, -1500)) . "\n");
	fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");

	exit(1);
}

echo "OK: $checks checks passed\n";
