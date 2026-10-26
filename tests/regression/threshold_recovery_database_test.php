<?php
// Run only against an explicitly supplied disposable MariaDB socket.
$socket = getenv('SYSLOG_TEST_MYSQL_SOCKET');
$dsn = $socket ? 'mysql:unix_socket=' . $socket : getenv('SYSLOG_TEST_MYSQL_DSN');
if (!$dsn) {
	fwrite(STDERR, "Set SYSLOG_TEST_MYSQL_SOCKET or SYSLOG_TEST_MYSQL_DSN for a disposable test server.\n");
	exit(2);
}
$pdo = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$syslogdb_default = 'syslog_recovery_' . bin2hex(random_bytes(4));
$central_db = $syslogdb_default . '_central';
$databases = [$syslogdb_default, $central_db];
$config = ['poller_id' => 1];
$checks = 0;
$command_log = tempnam(sys_get_temp_dir(), 'syslog-clear-');
function check_recovery($condition, $message) {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}
function syslog_db_execute($sql, ...$args) { return $GLOBALS['pdo']->exec($sql) !== false; }
function syslog_db_execute_prepared($sql, $params = [], ...$args) { if (!empty($GLOBALS['fail_recovery_sql']) && str_starts_with($sql, $GLOBALS['fail_recovery_sql'])) { return false; } $q = $GLOBALS['pdo']->prepare($sql); return $q->execute($params); }
function syslog_db_fetch_assoc_prepared($sql, $params = [], ...$args) { $q = $GLOBALS['pdo']->prepare($sql); $q->execute($params); return $q->fetchAll(); }
function syslog_db_fetch_row_prepared($sql, $params = [], ...$args) { return syslog_db_fetch_assoc_prepared($sql, $params)[0] ?? []; }
function syslog_db_fetch_cell_prepared($sql, $params = [], ...$args) { $q = $GLOBALS['pdo']->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
function syslog_db_fetch_cell($sql) { return syslog_db_fetch_cell_prepared($sql); }
function db_execute_prepared($sql, $params = [], ...$args) { return syslog_db_execute_prepared($sql, $params); }
function read_config_option($name) { return $GLOBALS['am_options'][$name] ?? ''; }
function api_plugin_installed($name) { return true; }
function api_plugin_is_enabled($name) { return true; }
function db_fetch_cell_prepared(...$args) { return 1; }
function api_plugin_hook_function($name, $envelope) {
	$GLOBALS['am_events'][] = $envelope['event'];
	alarmmanager_validate_event($envelope['event']);
	$envelope['status'] = $GLOBALS['am_status'];
	$envelope['event_row_id'] = 42;
	return $envelope;
}
$am_validator = getenv('SYSLOG_TEST_ALARMMANAGER_VALIDATOR') ?: dirname(__DIR__, 3) . '/plugin_alarmmanager/includes/events.php';
if (is_file($am_validator)) { require $am_validator; }
function cacti_log(...$args) {}
function cacti_sizeof($items) { return is_array($items) ? count($items) : 0; }
function cacti_escapeshellarg($value) { return escapeshellarg((string) $value); }
function clean_up_name($value) { return $value; }
require dirname(__DIR__, 2) . '/includes/functions.php';
require dirname(__DIR__, 2) . '/includes/schema.php';
require dirname(__DIR__, 2) . '/lib/syslog_thresholds.php';
function displayed_breach($event) {
	$sql = syslog_message_breach_sql();
	return (int) syslog_db_fetch_cell_prepared("SELECT $sql FROM syslog AS syslog WHERE replication_source_event_id = ?", [$event]);
}
function add_test_messages($sequences) {
	foreach ($sequences as $seq) {
		syslog_db_execute_prepared('INSERT INTO syslog_incoming (seq,status) VALUES (?,1)', [$seq]);
		syslog_db_execute_prepared('INSERT INTO syslog (replication_source_poller_id,replication_source_event_id) VALUES (1,?)', [$seq]);
	}
	return array_map(static fn($seq) => ['seq' => $seq], $sequences);
}
try {
	foreach ($databases as $database) {
		$pdo->exec("CREATE DATABASE `$database`");
		$pdo->exec("USE `$database`");
		$local_db = $syslogdb_default;
		$syslogdb_default = $database;
		syslog_create_alert_counts_table();
		$syslogdb_default = $local_db;
		$pdo->exec('CREATE TABLE syslog_alert (id INT PRIMARY KEY, enabled CHAR(2), method INT, date BIGINT, recovery_command VARCHAR(255), alarm_destination VARCHAR(16) NOT NULL DEFAULT \'syslog\')');
		$pdo->exec("INSERT INTO syslog_alert (id,enabled,method,date) VALUES (1,'on',1,100),(2,'on',1,100)");
		$pdo->exec('CREATE TABLE syslog_incoming (seq BIGINT PRIMARY KEY, status INT, threshold_breached TINYINT DEFAULT NULL)');
		$pdo->exec('CREATE TABLE syslog (seq BIGINT AUTO_INCREMENT PRIMARY KEY, replication_source_poller_id INT, replication_source_event_id BIGINT, threshold_breached TINYINT DEFAULT NULL)');
	}
	$pdo->exec("USE `$syslogdb_default`");
	check_recovery(syslog_thresholds_enabled(), 'Threshold tab is available with an enabled threshold rule');
	check_recovery(syslog_breached_threshold_count() === 0, 'Healthy threshold rules keep the tab with zero breaches');
	$base = time();
	$pdo->exec('SET timestamp = ' . $base);
	$rule = ['id' => 1, 'method' => 1, 'level' => 0, 'num' => 5, 'threshold_window' => 10, 'threshold_unit' => 'minute', 'threshold_recovery_minutes' => 5, 'date' => 100, 'type' => 'sql', 'message' => '123'];
	check_recovery(syslog_threshold_evaluate($rule, 3, '', $base) === 3 && empty($rule['_threshold_state']), 'Three messages must not breach');
	check_recovery(syslog_threshold_evaluate($rule, 2, '', $base + 60) === 5 && $rule['_threshold_state']['new_breach'], 'The fifth message opens the breach');
	check_recovery(syslog_breached_threshold_count() === 1, 'Active breach is counted without requiring a notification');
	$first_key = $rule['_threshold_state']['breach_key'];
	syslog_mark_threshold_messages($rule, 5, add_test_messages([4, 5]));
	check_recovery(displayed_breach(4) === 1, 'Breach-associated messages display Yes');
	check_recovery((int) $pdo->query('SELECT MAX(' . syslog_message_breach_sql() . ') FROM syslog AS syslog')->fetchColumn() === 1, 'Grouped messages show an active breach');
	check_recovery(syslog_threshold_evaluate($rule, 1, '', $base + 300) === 6 && !$rule['_threshold_state']['new_breach'], 'Another match extends the existing episode without a new alert');
	$state = syslog_db_fetch_row_prepared('SELECT * FROM syslog_threshold_state WHERE alert_id = 1');
	check_recovery((int) $state['breached_at'] === $base + 60, 'Breach start remains fixed when the quiet timer extends');
	check_recovery((int) $state['expires_at'] === $base + 600, 'Recovery timer restarts on the latest match');
	syslog_threshold_recover($base + 360);
	check_recovery(displayed_breach(4) === 1, 'The original timer must not clear an extended breach');
	$pdo->exec('SET timestamp = ' . ($base + 600));
	check_recovery(syslog_breached_threshold_count() === 0, 'Expired episodes leave the active list before cleanup');
	check_recovery(displayed_breach(4) === 0, 'The view clears an expired breach even before the next poller');
	check_recovery((int) $pdo->query('SELECT MAX(' . syslog_message_breach_sql() . ') FROM syslog AS syslog')->fetchColumn() === 0, 'Grouped messages also clear after recovery');
	syslog_threshold_recover($base + 600);
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_alert_counts')->fetchColumn() === 0, 'Quiet recovery resets counts to zero');
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_state')->fetchColumn() === 0, 'Quiet recovery runs with no new messages');
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog')->fetchColumn() === 2, 'Recovery preserves historical messages');
	check_recovery(syslog_threshold_evaluate($rule, 1, '', $base + 601) === 1 && empty($rule['_threshold_state']), 'A new episode starts from zero');
	check_recovery(syslog_threshold_evaluate($rule, 4, '', $base + 660) === 5 && $rule['_threshold_state']['new_breach'], 'A subsequent fifth match rearms the alarm');
	check_recovery($rule['_threshold_state']['breach_key'] !== $first_key, 'A rearmed breach gets a new identity');
	syslog_mark_threshold_messages($rule, 5, add_test_messages([7]));
	check_recovery(displayed_breach(4) === 0 && displayed_breach(7) === 1, 'Old messages do not light up for a later breach');
	// A low count during an active breach must still extend the quiet timer and mark messages.
	check_recovery(syslog_threshold_evaluate($rule, 1, '', $base + 940) === 6 && !$rule['_threshold_state']['new_breach'], 'Matches continue extending the episode');
	$rule['threshold_window'] = 0;
	$rule['id'] = 2;
	check_recovery(syslog_threshold_evaluate($rule, 5, '', $base + 660) === 5, 'Per-cycle thresholds also support recovery');
	check_recovery(syslog_threshold_evaluate($rule, 1, '', $base + 940) === 1 && !$rule['_threshold_state']['new_breach'], 'Active breaches survive a below-threshold batch');
	syslog_mark_threshold_messages($rule, 1, [['seq' => 7]]);
	$states = syslog_threshold_replication_states([['source_poller_id' => 1, 'source_event_id' => 7, 'threshold_breached' => 1]]);
	check_recovery(count($states['1:7']) === 2, 'A message can belong to two active rules');
	$event = ['source_poller_id' => 1, 'source_event_id' => 7, 'threshold_states' => $states['1:7']];
	check_recovery(syslog_threshold_accept_central($event, $pdo, $central_db), 'Active state replicates to the central collector');
	check_recovery(syslog_threshold_accept_central($event, $pdo, $central_db), 'State replication is idempotent');
	$pdo->exec("INSERT INTO `$central_db`.syslog (replication_source_poller_id,replication_source_event_id) VALUES (1,7)");
	$local_db = $syslogdb_default;
	$syslogdb_default = $central_db;
	$pdo->exec("USE `$central_db`");
	check_recovery((int) $pdo->query('SELECT breached_at FROM syslog_threshold_state WHERE alert_id = 1')->fetchColumn() === $base + 660, 'Collector replication preserves the breach start time');
	check_recovery(displayed_breach(7) === 1, 'Central messages display an active remote breach');
	$origin = array_values(array_filter($states['1:7'], static fn($state) => (int) $state['alert_id'] === 1))[0];
	$old = $origin;
	$old['last_match'] -= 20;
	$old['expires_at'] -= 20;
	syslog_threshold_accept_central(['source_poller_id' => 1, 'source_event_id' => 7, 'threshold_states' => [$old]], $pdo, $central_db);
	check_recovery((int) $pdo->query('SELECT expires_at FROM syslog_threshold_state WHERE alert_id = 1')->fetchColumn() === (int) $origin['expires_at'], 'An older snapshot cannot shorten an active deadline');
	$next = $origin;
	$next['breach_key'] = str_repeat('b', 32);
	$next['last_match'] += 10;
	$next['expires_at'] -= 100;
	syslog_threshold_accept_central(['source_poller_id' => 1, 'source_event_id' => 8, 'threshold_states' => [$next]], $pdo, $central_db);
	check_recovery($pdo->query('SELECT breach_key FROM syslog_threshold_state WHERE alert_id = 1')->fetchColumn() === $next['breach_key'], 'A newer episode replaces old state even with a shorter configured quiet period');
	syslog_threshold_accept_central(['source_poller_id' => 1, 'source_event_id' => 9, 'threshold_states' => [$old]], $pdo, $central_db);
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_messages WHERE source_event_id = 9')->fetchColumn() === 0, 'Late delivery does not recreate associations for an ended episode');
	check_recovery(displayed_breach(7) === 1, 'A second active rule keeps the message breached after the first is replaced');

	$pdo->exec('SET timestamp = ' . ($base + 1240));
	check_recovery(displayed_breach(7) === 0, 'Central messages clear at quiet expiry without a recovery event');
	$syslogdb_default = $local_db;
	$pdo->exec("USE `$local_db`");

	syslog_threshold_recover($base + 2000);
	$rule = ['id' => 1, 'method' => 1, 'level' => 1, 'num' => 2, 'threshold_window' => 10, 'threshold_unit' => 'minute', 'threshold_recovery_minutes' => 5, 'date' => 100];
	check_recovery(syslog_threshold_evaluate($rule, 2, 'host-a', $base + 2000) === 2 && $rule['_threshold_state']['new_breach'], 'Host A opens its own breach');
	$key_a = $rule['_threshold_state']['breach_key'];
	check_recovery(syslog_threshold_evaluate($rule, 2, 'host-b', $base + 2010) === 2 && $rule['_threshold_state']['new_breach'], 'Host B opens an independent breach');
	check_recovery($key_a !== $rule['_threshold_state']['breach_key'], 'Device scopes have separate episode identities');
	syslog_threshold_evaluate($rule, 1, 'host-a', $base + 2290);
	syslog_threshold_recover($base + 2310);
	check_recovery((int) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM syslog_threshold_state WHERE scope_key = ?', ['host-a']) === 1, 'Host A remains breached after its latest match');
	check_recovery((int) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM syslog_threshold_state WHERE scope_key = ?', ['host-b']) === 0, 'Host B recovers independently');
	check_recovery(syslog_threshold_evaluate($rule, 1, 'host-b', $base + 2311) === 1 && empty($rule['_threshold_state']), 'Host B starts counting from zero');
	$rule['threshold_recovery_minutes'] = 1;
	check_recovery(syslog_threshold_evaluate($rule, 1, 'host-a', $base + 2400) === 1 && empty($rule['_threshold_state']), 'Changing recovery settings invalidates the previous episode');
	check_recovery((int) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM syslog_threshold_state WHERE scope_key = ?', ['host-a']) === 0, 'A changed rule cannot leave its old breach active');
	foreach (['minute' => 60, 'hour' => 3600, 'day' => 86400] as $unit => $seconds) {
		$rule['threshold_recovery_unit'] = $unit;
		syslog_threshold_evaluate($rule, 2, 'unit-test', $base + 2500);
		$expiry = (int) syslog_db_fetch_cell_prepared('SELECT expires_at FROM syslog_threshold_state WHERE scope_key = ?', ['unit-test']);
		check_recovery($expiry === $base + 2500 + $seconds, "Recovery unit $unit sets the correct quiet deadline");
	}
	$plan = $pdo->query('EXPLAIN SELECT ' . syslog_message_breach_sql() . ' FROM syslog AS syslog LIMIT 30')->fetchAll();
	$lookup = array_values(array_filter($plan, static fn($row) => $row['table'] === 'tm'));
	check_recovery($lookup && in_array($lookup[0]['key'], ['PRIMARY', 'episode'], true), 'Message associations use the identity index');
	$pdo->exec('UPDATE syslog_alert SET date = 101');
	check_recovery(syslog_breached_threshold_count() === 0, 'Editing a rule removes its previous episodes from the active list');
	$pdo->exec("UPDATE syslog_alert SET enabled = ''");
	check_recovery(!syslog_thresholds_enabled() && syslog_breached_threshold_count() === 0, 'Disabled rules hide the tab and their episodes');
	$pdo->exec("UPDATE syslog_alert SET enabled = 'on', method = 0");
	check_recovery(!syslog_thresholds_enabled(), 'Individual reporting does not show the threshold tab');

	// Exercise real recovery scripts using a local recording fixture only.
	$config['poller_id'] = 2;
	$severities = [1 => 'Warning'];
	$syslog_levels = [3 => 'Error'];
	$syslog_facilities = [1 => 'User'];
	foreach (['syslog_threshold_state', 'syslog_threshold_messages', 'syslog_alert_counts'] as $table) {
		$pdo->exec("TRUNCATE TABLE `$table`");
	}
	$command = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/threshold_command.php') . ' ' . escapeshellarg($command_log) . ' --fail-clear-once <HOSTNAME>';
	$pdo->prepare("UPDATE syslog_alert SET enabled = 'on', method = 1, date = 100, recovery_command = ?")->execute([$command]);
	$pdo->prepare("UPDATE `$central_db`.syslog_alert SET recovery_command = ?")->execute([$command]);
	$rule = ['id' => 1, 'name' => 'Device errors', 'method' => 1, 'level' => 1, 'num' => 2, 'date' => 100,
		'severity' => 1, 'message' => 'error', 'command' => $command, 'recovery_command' => $command, 'threshold_recovery_minutes' => 1];
	$start = $base + 10000;
	$keys = [];
	foreach (['host-a', 'host-b'] as $i => $host) {
		check_recovery(syslog_threshold_evaluate($rule, 2, $host, $start + $i * 10) === 2, "Open $host with a recovery command");
		$keys[$host] = $rule['_threshold_state']['breach_key'];
		$matches = [['seq' => 50 + $i, 'host' => $host, 'message' => 'device error', 'priority_id' => 3, 'facility_id' => 1]];
		check_recovery(syslog_mark_threshold_messages($rule, 2, $matches), "Save $host recovery context");
		check_recovery(syslog_execute_alert_command($rule, $matches[0], $host), "Run $host breach command");
	}
	$states = syslog_threshold_replication_states([['source_poller_id' => 2, 'source_event_id' => 50, 'threshold_breached' => 1]]);
	check_recovery(!array_key_exists('recovery_context', $states['2:50'][0]), 'Script context stays on its owning collector');
	check_recovery(syslog_threshold_accept_central(['source_poller_id' => 2, 'source_event_id' => 50, 'threshold_states' => $states['2:50']], $pdo, $central_db), 'Replicate the visible episode without its command');
	$pdo->exec("USE `$central_db`");
	$syslogdb_default = $central_db;
	$config['poller_id'] = 1;
	syslog_threshold_recover($start + 60);
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_recovery_queue')->fetchColumn() === 0, 'Central expiry never queues a remote clear');
	$pdo->exec("USE `$local_db`");
	$syslogdb_default = $local_db;
	$config['poller_id'] = 2;
	syslog_threshold_recover($start + 60);
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_recovery_queue')->fetchColumn() === 1, 'Queue one quiet recovery');
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_state')->fetchColumn() === 1, 'The other device remains breached');
	syslog_threshold_deliver_recoveries($start + 60);
	$pending = syslog_db_fetch_row_prepared('SELECT * FROM syslog_threshold_recovery_queue');
	check_recovery((int) $pending['attempts'] === 1 && (int) $pending['available_at'] === $start + 120, 'Failed clear is retained with a one-minute retry');
	syslog_threshold_deliver_recoveries($start + 90);
	check_recovery(count(file($command_log)) === 3, 'No retry before its due time');
	syslog_threshold_deliver_recoveries($start + 120);
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_recovery_queue')->fetchColumn() === 0, 'Successful retry removes the queued clear');
	syslog_threshold_deliver_recoveries($start + 120);
	check_recovery(count(file($command_log)) === 4, 'Delivered clear is not sent again');
	syslog_threshold_recover($start + 70);
	syslog_threshold_deliver_recoveries($start + 120);
	$records = array_map(static fn($line) => json_decode($line, true), file($command_log, FILE_IGNORE_NEW_LINES));
	check_recovery(array_column($records, 'event') === ['breach', 'breach', 'clear', 'clear', 'clear'], 'Each device receives its own breach and clear');
	check_recovery($records[0]['breach_id'] === $records[2]['breach_id'] && $records[2]['breach_id'] === $records[3]['breach_id'], 'Retry and clear retain the original breach identity');
	check_recovery($records[1]['breach_id'] === $records[4]['breach_id'] && $records[0]['breach_id'] !== $records[1]['breach_id'], 'Device identities cannot clear each other');
	check_recovery($records[4]['hostname'] === 'host-b' && $records[4]['poller_id'] === '2', 'Clear script receives the origin and device');

	foreach (["date = 101", "enabled = ''", 'method = 0'] as $change) {
		$pdo->exec("UPDATE syslog_alert SET enabled = 'on', method = 1, date = 100");
		syslog_threshold_evaluate($rule, 2, 'cancelled', $start + 200);
		syslog_mark_threshold_messages($rule, 2, [['seq' => 60, 'host' => 'cancelled', 'message' => 'error', 'priority_id' => 3, 'facility_id' => 1]]);
		$pdo->exec("UPDATE syslog_alert SET $change");
		syslog_threshold_recover($start + 260);
		check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_recovery_queue')->fetchColumn() === 0, "Rule change $change does not send a healthy event");
	}
	$pdo->exec("UPDATE syslog_alert SET enabled = 'on', method = 1, date = 100");
	syslog_threshold_evaluate($rule, 2, 'transaction', $start + 300);
	syslog_mark_threshold_messages($rule, 2, [['seq' => 61, 'host' => 'transaction', 'message' => 'error', 'priority_id' => 3, 'facility_id' => 1]]);
	$fail_recovery_sql = 'DELETE m FROM';
	syslog_threshold_recover($start + 360);
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_recovery_queue')->fetchColumn() === 0, 'Cleanup failure rolls back the queued clear');
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_state')->fetchColumn() === 1, 'Cleanup failure retains the episode for retry');
	check_recovery(syslog_threshold_evaluate($rule, 2, 'transaction', $start + 361) === 0, 'New matches cannot discard an unqueued expired clear');
	$fail_recovery_sql = '';
	syslog_threshold_recover($start + 360);
	$lock = 'syslog-clear:' . sha1($syslogdb_default . ':2');
	$other = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '');
	$other->prepare('SELECT GET_LOCK(?, 0)')->execute([$lock]);
	syslog_threshold_deliver_recoveries($start + 360);
	check_recovery(count(file($command_log)) === 5, 'Another delivery owner prevents concurrent script execution');
	$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
	$pdo->exec('UPDATE syslog_alert SET date = 101');
	syslog_threshold_deliver_recoveries($start + 360);
	check_recovery((int) $pdo->query('SELECT COUNT(*) FROM syslog_threshold_recovery_queue')->fetchColumn() === 0 && count(file($command_log)) === 5, 'Editing a rule cancels a pending clear before execution');
	if (is_file($am_validator)) {
		// Real queue SQL and recovery commands; only the Alarm Manager ingestion boundary is stubbed.
		$am_options = ['syslog_alarmmanager_enabled' => 'on', 'base_url' => 'https://fixture.invalid/'];
		$am_status = 'accepted';
		$am_events = [];
		$command = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/threshold_command.php') . ' ' . escapeshellarg($command_log) . ' <HOSTNAME>';
		$pdo->prepare("UPDATE syslog_alert SET enabled = 'on', method = 1, date = 100, recovery_command = ?")->execute([$command]);
		$pdo->exec("UPDATE syslog_alert SET alarm_destination = 'alarmmanager'");
		$rule['alarm_destination'] = 'alarmmanager';
		$rule['date'] = 100;
		$rule['recovery_command'] = $command;
		$rule['command'] = $command;
		$when = $start + 5000;
		$matches = [['seq' => 900, 'logtime' => date('Y-m-d H:i:s', $when), 'host' => 'host-am', 'message' => 'fixture', 'priority_id' => 3, 'facility_id' => 1]];
		check_recovery(syslog_threshold_evaluate($rule, 2, 'host-am', $when) === 2, 'Open Alarm Manager threshold episode');
		check_recovery(syslog_mark_threshold_messages($rule, 2, $matches), 'Retain immutable recovery context');
		check_recovery(syslog_alarmmanager_forward($rule, $matches, 2), 'Accepted raise records recovery ownership');
		$raised = end($am_events);
		syslog_threshold_recover($when + 60);
		$am_status = 'failed';
		$commands_before = count(file($command_log));
		syslog_threshold_deliver_recoveries($when + 60);
		check_recovery(count(file($command_log)) === $commands_before, 'Alarm Manager recovery does not run configured commands');
		$clear = end($am_events);
		check_recovery($clear['transition'] === 'recovered' && $clear['episode_id'] === $raised['episode_id'], 'Queued recovery correlates to raise');
		check_recovery((int) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM syslog_threshold_recovery_queue WHERE breach_key = ?', [$rule['_threshold_state']['breach_key']]) === 1, 'Failed clear remains queued');
		$am_status = 'already_accepted';
		syslog_threshold_deliver_recoveries($when + 120);
		check_recovery(end($am_events) === $clear, 'Queued recovery retry retains identical event and time');
		check_recovery(count(file($command_log)) === $commands_before, 'Forwarding retry does not run hidden recovery command');
		check_recovery((int) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM syslog_threshold_recovery_queue WHERE breach_key = ?', [$rule['_threshold_state']['breach_key']]) === 0, 'Durable duplicate completes queued recovery');
	} else {
		print "SKIP: Alarm Manager recovery cases require its event validator.\n";
	}
	print "Threshold recovery MariaDB checks passed: $checks\n";
} finally {
	unlink($command_log);
	foreach ($databases as $database) {
		$pdo->exec("DROP DATABASE IF EXISTS `$database`");
	}
}
