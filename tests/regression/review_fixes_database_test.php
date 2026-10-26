<?php
/*
 * Database regression checks for the review fixes. Run against an explicitly
 * supplied disposable MariaDB server:
 *
 *   SYSLOG_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306' \
 *   SYSLOG_TEST_MYSQL_PASSWORD=secret php tests/regression/review_fixes_database_test.php
 *
 * The file is inert unless it is started from the command line with the
 * test environment set.
 */
require __DIR__ . '/support/database_shim.php';

try {
	fresh_database();

	// ---- Upgrade truncate keeps the rule tables --------------------------------
	syslog_setup_table_new(['upgrade_type' => 'upgrade', 'engine' => 'InnoDB', 'days' => 30, 'db_type' => 'part'], false);

	$pdo->exec("INSERT INTO syslog_alert (name, type, message, method, num, user) VALUES ('rule', 'messagec', 'x', 0, 1, 'a-user-name-that-is-forty-characters-long')");
	$pdo->exec("INSERT INTO syslog_remove (name, type, message, method, user) VALUES ('rule', 'messagec', 'x', 'del', 'u')");
	$pdo->exec("INSERT INTO syslog_reports (name, type, message, user) VALUES ('rule', 'messagec', 'x', 'u')");
	$pdo->exec("INSERT INTO syslog_hosts (host) VALUES ('router-a')");
	$pdo->exec("INSERT INTO syslog_programs (program) VALUES ('app')");
	$pdo->exec("INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 1, 1, 1, 3, 'kept until truncate')");

	syslog_setup_table_new(['upgrade_type' => 'truncate', 'engine' => 'InnoDB', 'days' => 30, 'db_type' => 'part'], false);

	check(table_count('syslog') === 0, 'Truncate must discard stored messages');
	check(table_count('syslog_alert') === 1 && table_count('syslog_remove') === 1 && table_count('syslog_reports') === 1, 'Truncate must keep alert, removal and report rules');

	// ---- Owner columns hold a full Cacti username ------------------------------
	foreach (['syslog_alert', 'syslog_remove', 'syslog_reports', 'syslog_device_rule'] as $table) {
		$type = owner_column_type($table);
		check($type === 'varchar(50)', "$table.user must be varchar(50), found $type");
	}

	$pdo->exec('ALTER TABLE syslog_alert MODIFY COLUMN `user` varchar(32) NOT NULL DEFAULT \'\'');
	$pdo->exec('ALTER TABLE syslog_device_rule MODIFY COLUMN `user` varchar(32) NOT NULL DEFAULT \'\'');
	// Cacti's table reconciler creates these in a live install; this run has no Cacti.
	foreach ([
		'CREATE TABLE IF NOT EXISTS syslog_saved_searches (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_saved_searches_perm (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_dashboards (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_dashboards_perm (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_dashboard_panels (id INT)',
	] as $statement) {
		$pdo->exec($statement);
	}

	check(syslog_ensure_table_structures(), 'Schema repair must succeed on a 32 character owner column');

	foreach (['syslog_alert', 'syslog_device_rule'] as $table) {
		$type = owner_column_type($table);
		check($type === 'varchar(50)', "Repair must widen $table.user, found $type");
	}

	check(syslog_ensure_table_structures(), 'Repeating schema repair must succeed without a separate migration marker');
	check(table_count('syslog_alert') === 1, 'Repeated schema repair must retain the alert rule');

	// ---- All records dashboard queries -----------------------------------------
	$pdo->exec('DELETE FROM syslog_hosts');
	$pdo->exec("INSERT INTO syslog_hosts (host_id, host) VALUES (1, 'a'), (2, 'b')");
	$pdo->exec("INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES
		('2026-10-01 00:10:00', 1, 1, 1, 3, 'm'), ('2026-10-01 00:20:00', 1, 1, 1, 3, 'm'),
		('2026-10-01 05:00:00', 2, 1, 1, 3, 'm')");
	$pdo->exec("INSERT INTO syslog_removed (logtime, host_id, program_id, facility_id, priority_id, message) VALUES
		('2026-10-01 05:30:00', 2, 1, 1, 3, 'm')");

	$range = "syslog.logtime BETWEEN '2026-09-01 00:00:00' AND '2026-12-01 00:00:00'";

	foreach (['', 'ONLY_FULL_GROUP_BY'] as $mode) {
		set_sql_mode($mode);

		$rows   = syslog_db_fetch_assoc(syslog_dashboard_breakdown_sql('syslog', 'host', '1', $range, 0));
		$byhost = $rows === false ? [] : array_column($rows, 'records', 'label');
		check($byhost == ['a' => 2, 'b' => 2], 'Breakdown must report every host with its own total (' . ($mode ?: 'default mode') . ')');

		$rows    = syslog_db_fetch_assoc(syslog_dashboard_timeseries_sql('syslog', '1', $range, 3600, 0));
		$buckets = $rows === false ? [] : array_map('intval', array_column($rows, 'records'));
		sort($buckets);
		check($rows !== false && count($rows) === 2 && $buckets == [2, 2], 'Timeseries must report each hour bucket (' . ($mode ?: 'default mode') . ')');
	}

	$pdo->exec("SET SESSION sql_mode = ''");

	// ---- Archiving removal rules on host and program ---------------------------
	$pdo->exec('DELETE FROM syslog_hosts');
	$pdo->exec("INSERT INTO syslog_hosts (host_id, host) VALUES (1, 'router1'), (2, 'router2')");
	$pdo->exec('DELETE FROM syslog_programs');
	$pdo->exec("INSERT INTO syslog_programs (program_id, program) VALUES (1, 'app'), (2, 'daemon')");
	$pdo->exec('DELETE FROM syslog_removed');
	$pdo->exec('DELETE FROM syslog_remove');

	foreach ([['host', 'router1'], ['program', 'daemon']] as [$type, $value]) {
		$pdo->exec('DELETE FROM syslog_incoming');
		$pdo->exec('DELETE FROM syslog_removed');
		$pdo->exec('DELETE FROM syslog_remove');
		$insert = $pdo->prepare("INSERT INTO syslog_remove (name, type, message, method, enabled, user) VALUES ('archive', ?, ?, 'trans', 'on', 'u')");
		$insert->execute([$type, $value]);
		$pdo->exec("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES
			(1, NOW(), 'router1', 'app', 'one', 1, 3), (1, NOW(), 'router2', 'daemon', 'two', 1, 3), (1, NOW(), 'router2', 'app', 'three', 1, 3)");
		$max = (int) $pdo->query('SELECT MAX(seq) FROM syslog_incoming')->fetchColumn();

		$result = syslog_remove_items('syslog_incoming', $max);

		check($result['xferred'] === 1, "Archiving a $type rule must move the matching message, moved " . $result['xferred']);
		check(table_count('syslog_removed') === 1 && table_count('syslog_incoming') === 2, "Archiving a $type rule must leave the other messages queued");
	}

	// ---- Reprocess runs only the selected rule --------------------------------
	$pdo->exec('DELETE FROM syslog');
	$pdo->exec('DELETE FROM syslog_removed');
	$pdo->exec('DELETE FROM syslog_remove');
	$pdo->exec("INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES
		(NOW(), 1, 1, 1, 3, 'alpha noise'), (NOW(), 1, 1, 1, 3, 'beta noise')");
	$pdo->exec("INSERT INTO syslog_remove (name, type, message, method, enabled, user) VALUES
		('alpha', 'messagec', 'alpha', 'del', 'on', 'u'), ('beta', 'messagec', 'beta', 'del', 'on', 'u')");
	$alpha = (int) $pdo->query("SELECT id FROM syslog_remove WHERE name = 'alpha'")->fetchColumn();

	$result = syslog_remove_items('syslog', 0, $alpha);

	check($result['removed'] === 1, 'Reprocessing one rule must remove only its own messages');
	check(table_count('syslog') === 1, 'The other rule must not run during a reprocess');

	// ---- One bad alert rule does not silence the others ------------------------
	$pdo->exec('DELETE FROM syslog_alert');
	$pdo->exec('DELETE FROM syslog_incoming');
	$pdo->exec('DELETE FROM syslog_logs');
	$pdo->exec("INSERT INTO syslog_alert (name, type, message, method, num, enabled, severity, user, email, command, recovery_command, notes) VALUES
		('good', 'sql', 'message LIKE ''%good%''', 1, 1, 'on', 3, 'u', '', '', '', ''),
		('bad', 'sql', 'message LIKE ''%bad%'' AND (', 1, 1, 'on', 3, 'u', '', '', '', '')");
	$pdo->exec("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES
		(1, NOW(), 'router1', 'app', 'a good message', 1, 3)");
	$max = (int) $pdo->query('SELECT MAX(seq) FROM syslog_incoming')->fetchColumn();

	$combined = syslog_db_fetch_assoc_prepared("SELECT SUM(CASE WHEN (message LIKE '%good%') THEN 1 ELSE 0 END) AS m0, SUM(CASE WHEN (message LIKE '%bad%' AND () THEN 1 ELSE 0 END) AS m1 FROM syslog_incoming WHERE status = 1 AND seq <= ?", [$max]);
	check($combined === false, 'The fixture must make the combined count query fail');

	$result = syslog_process_alerts($max);

	check($result['syslog_alerts'] === 2, 'Both rules are loaded');
	check($result['syslog_alarms'] === 1, 'The valid rule must still fire when another rule cannot run, fired ' . $result['syslog_alarms']);

	// ---- Host-level SQL rules with an asterisk ---------------------------------
	$pdo->exec('DELETE FROM syslog_alert');
	$pdo->exec('DELETE FROM syslog_logs');
	$pdo->exec('DELETE FROM syslog_alert_counts');
	$pdo->exec('DELETE FROM syslog_threshold_state');
	$pdo->exec("INSERT INTO syslog_alert (name, type, message, method, num, level, enabled, severity, user, email, command, recovery_command, notes) VALUES
		('regexp', 'sql', 'message REGEXP ''link.*down''', 1, 1, 1, 'on', 3, 'u', '', '', '', '')");
	$pdo->exec('DELETE FROM syslog_incoming');
	$pdo->exec("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES
		(1, NOW(), 'router1', 'app', 'link is down', 1, 3)");
	$max = (int) $pdo->query('SELECT MAX(seq) FROM syslog_incoming')->fetchColumn();

	$result = syslog_process_alerts($max);

	check($result['syslog_alarms'] === 1, 'A host-level rule that uses an asterisk must fire');
} catch (Throwable $error) {
	$failures[] = $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')';
} finally {
	drop_database();
}

finish_checks();
