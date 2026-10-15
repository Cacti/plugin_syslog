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
function check_recovery($condition, $message) {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}
function syslog_db_execute($sql, ...$args) { return $GLOBALS['pdo']->exec($sql) !== false; }
function syslog_db_execute_prepared($sql, $params = [], ...$args) { $q = $GLOBALS['pdo']->prepare($sql); return $q->execute($params); }
function syslog_db_fetch_assoc_prepared($sql, $params = [], ...$args) { $q = $GLOBALS['pdo']->prepare($sql); $q->execute($params); return $q->fetchAll(); }
function syslog_db_fetch_row_prepared($sql, $params = [], ...$args) { return syslog_db_fetch_assoc_prepared($sql, $params)[0] ?? []; }
function syslog_db_fetch_cell_prepared($sql, $params = [], ...$args) { $q = $GLOBALS['pdo']->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
function syslog_db_fetch_cell($sql) { return syslog_db_fetch_cell_prepared($sql); }
function db_execute_prepared($sql, $params = [], ...$args) { return syslog_db_execute_prepared($sql, $params); }
function cacti_log(...$args) {}
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
		$pdo->exec('CREATE TABLE syslog_alert (id INT PRIMARY KEY, enabled CHAR(2), method INT, date BIGINT)');
		$pdo->exec("INSERT INTO syslog_alert VALUES (1,'on',1,100),(2,'on',1,100)");
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
	print "Threshold recovery MariaDB checks passed: $checks\n";
} finally {
	foreach ($databases as $database) {
		$pdo->exec("DROP DATABASE IF EXISTS `$database`");
	}
}
