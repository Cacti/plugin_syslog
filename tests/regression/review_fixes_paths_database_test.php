<?php
/*
 * Path coverage for the review fixes against a real MariaDB: install and upgrade
 * choices on a database that holds data, the dashboard queries for every record
 * type and dimension, removal rules of every type and method, alert evaluation
 * with an invalid rule, the report row limit, owner column width, hostname
 * lookup budget and rule imports. Run it the same way as
 * review_fixes_database_test.php.
 */
require __DIR__ . '/support/database_shim.php';

function seed_reference_data($pdo) {
	$pdo->exec("INSERT INTO syslog_hosts (host_id, host) VALUES (1, 'router1'), (2, 'router2'), (3, 'switch3')");
	$pdo->exec("INSERT INTO syslog_programs (program_id, program) VALUES (1, 'app'), (2, 'daemon')");
}

function scalar($sql, $params = []) {
	$statement = $GLOBALS['pdo']->prepare($sql);
	$statement->execute($params);

	return $statement->fetchColumn();
}

function ids($sql, $params = []) {
	$statement = $GLOBALS['pdo']->prepare($sql);
	$statement->execute($params);

	return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function reset_rules_and_data($pdo) {
	foreach (['syslog_alert', 'syslog_remove', 'syslog_reports', 'syslog_device_rule', 'syslog', 'syslog_removed', 'syslog_incoming', 'syslog_logs', 'syslog_alert_counts', 'syslog_threshold_state', 'syslog_threshold_messages', 'syslog_hosts', 'syslog_programs'] as $table) {
		$pdo->exec(['syslog_alert' => 'DELETE FROM syslog_alert', 'syslog_remove' => 'DELETE FROM syslog_remove', 'syslog_reports' => 'DELETE FROM syslog_reports',
			'syslog_device_rule' => 'DELETE FROM syslog_device_rule', 'syslog' => 'DELETE FROM syslog', 'syslog_removed' => 'DELETE FROM syslog_removed',
			'syslog_incoming' => 'DELETE FROM syslog_incoming', 'syslog_logs' => 'DELETE FROM syslog_logs', 'syslog_alert_counts' => 'DELETE FROM syslog_alert_counts',
			'syslog_threshold_state' => 'DELETE FROM syslog_threshold_state', 'syslog_threshold_messages' => 'DELETE FROM syslog_threshold_messages',
			'syslog_hosts' => 'DELETE FROM syslog_hosts', 'syslog_programs' => 'DELETE FROM syslog_programs'][$table]);
	}
}

function insert_rule($pdo, $table, array $values) {
	$statements = [
		'syslog_alert'  => 'INSERT INTO syslog_alert (name, type, message, method, level, num, enabled, severity, user, email, command, recovery_command, notes) VALUES (?, ?, ?, ?, ?, ?, \'on\', 1, \'u\', \'\', ?, \'\', \'\')',
		'syslog_remove' => 'INSERT INTO syslog_remove (name, type, message, method, enabled, user) VALUES (?, ?, ?, ?, \'on\', \'u\')',
	];

	$pdo->prepare($statements[$table])->execute($values);
}

try {
	fresh_database();

	// ---- Install and upgrade choices on a database that holds data ----------------
	$install = ['upgrade_type' => 'upgrade', 'engine' => 'InnoDB', 'days' => 30, 'db_type' => 'part'];

	syslog_setup_table_new($install, false);
	check(table_count('syslog') === 0 && table_count('syslog_alert') === 0, 'A new install creates empty tables');
	check((int) scalar('SELECT COUNT(*) FROM syslog_facilities') === 24 && (int) scalar('SELECT COUNT(*) FROM syslog_priorities') === 9, 'A new install seeds the lookup tables');
	check(syslog_ensure_table_structures() === false, 'Schema repair reports the tables Cacti creates in a live install as missing');

	foreach ([
		'CREATE TABLE IF NOT EXISTS syslog_saved_searches (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_saved_searches_perm (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_dashboards (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_dashboards_perm (id INT)',
		'CREATE TABLE IF NOT EXISTS syslog_dashboard_panels (id INT)',
	] as $statement) {
		$pdo->exec($statement);
	}

	check(syslog_ensure_table_structures() === true, 'Schema repair succeeds once those tables exist');

	$seed = static function () use ($pdo) {
		reset_rules_and_data($pdo);
		seed_reference_data($pdo);
		insert_rule($pdo, 'syslog_alert', ['kept alert', 'messagec', 'x', 0, 0, 1, '']);
		insert_rule($pdo, 'syslog_remove', ['kept removal', 'messagec', 'x', 'del']);
		$pdo->exec("INSERT INTO syslog_reports (name, type, message, user) VALUES ('kept report', 'messagec', 'x', 'u')");
		$pdo->exec("INSERT INTO syslog_device_rule (host, user) VALUES ('router1', 'u')");
		$pdo->exec("INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 1, 1, 1, 3, 'stored one'), (NOW(), 2, 2, 1, 3, 'stored two')");
		$pdo->exec("INSERT INTO syslog_removed (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 1, 1, 1, 3, 'removed one')");
		$pdo->exec("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES (1, NOW(), 'router1', 'app', 'queued', 1, 3)");
	};

	$counts = static fn() => [
		'syslog' => table_count('syslog'), 'removed' => table_count('syslog_removed'), 'incoming' => table_count('syslog_incoming'),
		'alert' => table_count('syslog_alert'), 'remove' => table_count('syslog_remove'), 'reports' => table_count('syslog_reports'),
		'device' => (int) scalar('SELECT COUNT(*) FROM syslog_device_rule'), 'hosts' => (int) scalar('SELECT COUNT(*) FROM syslog_hosts'),
	];

	foreach (['inline', 'background', 'upgrade'] as $choice) {
		$seed();
		$before = $counts();
		syslog_setup_table_new(['upgrade_type' => $choice] + $install, true);
		check($counts() === $before, "The $choice upgrade keeps every row");
	}

	$seed();
	syslog_setup_table_new(['upgrade_type' => 'truncate'] + $install, false);
	check($counts() === ['syslog' => 0, 'removed' => 0, 'incoming' => 0, 'alert' => 1, 'remove' => 1, 'reports' => 1, 'device' => 1, 'hosts' => 0],
		'Truncate discards messages, queue and devices, and keeps all four kinds of rule');
	check((int) scalar('SELECT COUNT(*) FROM syslog_facilities') === 24, 'Truncate re-seeds the lookup tables');

	// ---- Dashboard queries for every record type and dimension --------------------
	reset_rules_and_data($pdo);
	seed_reference_data($pdo);
	$pdo->exec("INSERT INTO syslog_facilities (facility_id, facility) VALUES (99, 'spare') ON DUPLICATE KEY UPDATE facility = facility");

	$main    = [[1, 1, 1, 3], [1, 1, 1, 3], [2, 2, 1, 4], [3, 1, 16, 3], [3, 2, 16, 4]];
	$removed = [[1, 2, 1, 3], [2, 2, 16, 3]];

	foreach ($main as $index => [$host, $program, $facility, $priority]) {
		$pdo->prepare('INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (?, ?, ?, ?, ?, ?)')
			->execute([sprintf('2026-10-01 0%d:10:00', $index), $host, $program, $facility, $priority, 'm']);
	}

	foreach ($removed as $index => [$host, $program, $facility, $priority]) {
		$pdo->prepare('INSERT INTO syslog_removed (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (?, ?, ?, ?, ?, ?)')
			->execute([sprintf('2026-10-01 0%d:40:00', $index), $host, $program, $facility, $priority, 'm']);
	}

	$pdo->exec("INSERT INTO syslog_logs (alert_id, logseq, logtime, logmsg, host, facility_id, priority_id, program_id, count) VALUES
		(1, 1, '2026-10-01 01:00:00', 'a', 'router1', 1, 3, 1, 4), (1, 2, '2026-10-01 01:30:00', 'b', 'router1', 1, 3, 1, 2), (2, 3, '2026-10-01 02:00:00', 'c', 'router2', 16, 4, 2, 5)");

	$range  = "syslog.logtime BETWEEN '2026-09-01 00:00:00' AND '2026-12-01 00:00:00'";
	$values = [
		'host' => ['router1' => 0, 'router2' => 0, 'switch3' => 0], 'program' => ['app' => 0, 'daemon' => 0],
		'facility' => ['user' => 0, 'local0' => 0], 'priority' => ['err' => 0, 'warning' => 0],
	];
	$column = ['host' => 0, 'program' => 1, 'facility' => 2, 'priority' => 3];
	$tables = ['-1' => [$main], '2' => [$removed], '1' => [$main, $removed], '0' => [$main, $removed]];

	$pdo->exec("UPDATE syslog_facilities SET facility = 'user' WHERE facility_id = 1");
	$pdo->exec("UPDATE syslog_facilities SET facility = 'mail' WHERE facility_id = 2");
	$pdo->exec("UPDATE syslog_facilities SET facility = 'local0' WHERE facility_id = 16");
	$pdo->exec("UPDATE syslog_priorities SET priority = 'err' WHERE priority_id = 3");
	$pdo->exec("UPDATE syslog_priorities SET priority = 'warning' WHERE priority_id = 4");

	$names = [
		'host' => [1 => 'router1', 2 => 'router2', 3 => 'switch3'], 'program' => [1 => 'app', 2 => 'daemon'],
		'facility' => [1 => 'user', 2 => 'mail', 16 => 'local0'], 'priority' => [3 => 'err', 4 => 'warning'],
	];

	foreach (['', 'ONLY_FULL_GROUP_BY'] as $mode) {
		set_sql_mode($mode);

		foreach ($tables as $removal => $sources) {
			foreach ($names as $field => $labels) {
				$expected = [];

				foreach ($sources as $rows) {
					foreach ($rows as $row) {
						$label            = $labels[$row[$column[$field]]];
						$expected[$label] = ($expected[$label] ?? 0) + 1;
					}
				}

				$rows   = syslog_db_fetch_assoc(syslog_dashboard_breakdown_sql('syslog', $field, (string) $removal, $range, 0));
				$actual = $rows === false ? ['error'] : array_map('intval', array_column($rows, 'records', 'label'));
				ksort($expected);
				ksort($actual);
				check($actual == $expected, "Breakdown by $field for record type $removal in " . ($mode ?: 'default mode') . ' returns every label with its own total');
			}

			$total = array_sum(array_map('count', $sources));
			$rows  = syslog_db_fetch_assoc(syslog_dashboard_timeseries_sql('syslog', (string) $removal, $range, 3600, 0));
			check($rows !== false && array_sum(array_column($rows, 'records')) == $total && count($rows) === count(array_unique(array_column($rows, 'bucket'))),
				"Timeseries for record type $removal in " . ($mode ?: 'default mode') . ' sums to the record total with one row per bucket');
		}

		$rows = syslog_db_fetch_assoc(syslog_dashboard_breakdown_sql('alerts', 'host', '1', $range, 0));
		check($rows !== false && array_map('intval', array_column($rows, 'records', 'label')) == ['router1' => 6, 'router2' => 5], 'Alert breakdown by host sums the grouped counts (' . ($mode ?: 'default mode') . ')');

		foreach ([['program', ['app' => 6, 'daemon' => 5]], ['facility', ['user' => 6, 'local0' => 5]], ['priority', ['err' => 6, 'warning' => 5]]] as [$field, $expected]) {
			$rows = syslog_db_fetch_assoc(syslog_dashboard_breakdown_sql('alerts', $field, '1', $range, 0));
			check($rows !== false && array_map('intval', array_column($rows, 'records', 'label')) == $expected, "Alert breakdown by $field returns each label (" . ($mode ?: 'default mode') . ')');
		}

		$rows = syslog_db_fetch_assoc(syslog_dashboard_timeseries_sql('alerts', '1', $range, 3600, 0));
		check($rows !== false && array_sum(array_column($rows, 'records')) == 11, 'Alert timeseries sums the grouped counts (' . ($mode ?: 'default mode') . ')');
	}

	set_sql_mode('');

	// ---- Removal rules of every type and method ----------------------------------
	$facility_rule_value = ['syslog_incoming' => '1', 'syslog' => 'user'];
	$filter              = static fn($field, $value) => json_encode(['version' => 1, 'conditions' => [['join' => 'AND', 'negative' => false, 'field' => $field, 'operator' => '=', 'value' => $value]]]);

	foreach (['syslog_incoming', 'syslog'] as $table) {
		$rules = [
			'host'     => ['host', 'router1', 2],
			'program'  => ['program', 'daemon', 2],
			'facility' => ['facility', $facility_rule_value[$table], 3],
			'messageb' => ['messageb', 'alpha', 2],
			'messagec' => ['messagec', 'one', 2],
			'messagee' => ['messagee', 'two', 2],
			'sql'      => ['sql', $table === 'syslog' ? 'host_id = 1' : "host = 'router1'", 2],
			'filter'   => ['filter', $table === 'syslog' ? $filter('host_id', '1') : $filter('host', 'router1'), 2],
		];

		foreach ($rules as $label => [$type, $message, $expected]) {
			foreach (['del', 'trans'] as $method) {
				reset_rules_and_data($pdo);
				seed_reference_data($pdo);
				$rows = [
					['router1', 'app', 'alpha one', 1], ['router1', 'daemon', 'beta two', 2], ['router2', 'daemon', 'alpha three one', 1],
					['switch3', 'app', 'gamma two', 1], ['router2', 'app', 'delta', 2],
				];
				$hosts = ['router1' => 1, 'router2' => 2, 'switch3' => 3];
				$progs = ['app' => 1, 'daemon' => 2];

				foreach ($rows as [$host, $program, $text, $facility]) {
					$pdo->prepare('INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES (1, NOW(), ?, ?, ?, ?, 3)')->execute([$host, $program, $text, $facility]);
					$pdo->prepare('INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), ?, ?, ?, 3, ?)')->execute([$hosts[$host], $progs[$program], $facility, $text]);
				}

				insert_rule($pdo, 'syslog_remove', ["$label $method", $type, $message, $method]);
				$max = (int) scalar('SELECT MAX(seq) FROM syslog_incoming');

				$result = $table === 'syslog_incoming' ? syslog_remove_items('syslog_incoming', $max) : syslog_remove_items('syslog', 0, (int) scalar('SELECT id FROM syslog_remove'));

				$left = (int) scalar($table === 'syslog_incoming' ? 'SELECT COUNT(*) FROM syslog_incoming' : 'SELECT COUNT(*) FROM syslog');

				if ($method === 'del') {
					check($result['removed'] === $expected && $result['xferred'] === 0 && $left === 5 - $expected && table_count('syslog_removed') === 0,
						"A $label rule with delete on $table removes $expected rows and archives none, removed {$result['removed']}");
				} else {
					check($result['xferred'] === $expected && $left === 5 - $expected && table_count('syslog_removed') === $expected,
						"A $label rule with move on $table archives $expected rows and leaves the rest, moved {$result['xferred']}");
				}
			}
		}
	}

	$statement = $pdo->prepare("INSERT INTO syslog_remove (name, type, message, method, enabled, user) VALUES ('disabled', 'host', 'router1', 'del', '', 'u')");
	reset_rules_and_data($pdo);
	seed_reference_data($pdo);
	$pdo->exec("INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 1, 1, 1, 3, 'a')");
	$statement->execute();
	$only = (int) scalar('SELECT id FROM syslog_remove');
	check(syslog_remove_items('syslog', 0, $only) === ['removed' => 0, 'xferred' => 0] && table_count('syslog') === 1, 'Reprocessing a disabled rule changes nothing');
	check(syslog_remove_items('syslog', 0, $only + 100) === ['removed' => 0, 'xferred' => 0], 'Reprocessing an unknown rule changes nothing');

	// ---- Alert evaluation with an invalid rule among valid ones -------------------
	reset_rules_and_data($pdo);
	$options['syslog_html']       = 'on';
	$options['syslog_maxrecords'] = 3;

	foreach (range(1, 12) as $number) {
		$pdo->prepare('INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES (1, NOW(), ?, \'app\', ?, 1, 3)')
			->execute([$number <= 8 ? 'router1' : 'router2', "ROW-$number hit tok"]);
	}

	$pdo->exec("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES (1, NOW(), 'router3', 'app', 'link is down', 1, 3), (1, NOW(), 'router3', 'app', 'other', 1, 3)");

	insert_rule($pdo, 'syslog_alert', ['immediate', 'sql', "message LIKE '%ROW-1 %'", 0, 0, 1, '']);
	insert_rule($pdo, 'syslog_alert', ['threshold limited', 'sql', "message LIKE '%hit tok%'", 1, 0, 1, '']);
	insert_rule($pdo, 'syslog_alert', ['per host asterisk', 'sql', "message REGEXP 'link.*down'", 1, 1, 1, '']);
	insert_rule($pdo, 'syslog_alert', ['broken one', 'sql', "message LIKE 'x' AND (", 0, 0, 1, '']);
	insert_rule($pdo, 'syslog_alert', ['broken two', 'sql', 'host IN (', 1, 1, 1, '']);
	insert_rule($pdo, 'syslog_alert', ['unknown type', 'nonsense', 'x', 0, 0, 1, '']);
	$max = (int) scalar('SELECT MAX(seq) FROM syslog_incoming');

	$result = syslog_process_alerts($max);
	$fired  = ids('SELECT DISTINCT a.name FROM syslog_logs l INNER JOIN syslog_alert a ON a.id = l.alert_id ORDER BY a.name');

	check($result['syslog_alerts'] === 6 && $fired == ['immediate', 'per host asterisk', 'threshold limited'], 'Valid rules of every kind fire while two invalid rules are skipped, fired: ' . implode(', ', $fired) . ', loaded ' . $result['syslog_alerts']);

	$html = (string) scalar("SELECT l.html FROM syslog_logs l INNER JOIN syslog_alert a ON a.id = l.alert_id WHERE a.name = 'threshold limited'");
	check(substr_count($html, 'ROW-') === 3, 'The threshold alert lists 3 of 12 matches, listed ' . substr_count($html, 'ROW-'));

	foreach ([[0, 12], [20, 12]] as [$limit, $listed]) {
		$pdo->exec('DELETE FROM syslog_logs');
		$pdo->exec('DELETE FROM syslog_alert_counts');
		$pdo->exec('DELETE FROM syslog_threshold_state');
		$pdo->exec('DELETE FROM syslog_threshold_messages');
		$pdo->exec("UPDATE syslog_incoming SET status = 1");
		$options['syslog_maxrecords'] = $limit;
		syslog_process_alerts($max);
		$html = (string) scalar("SELECT l.html FROM syslog_logs l INNER JOIN syslog_alert a ON a.id = l.alert_id WHERE a.name = 'threshold limited'");
		check(substr_count($html, 'ROW-') === $listed, "With the limit set to $limit all $listed matches are listed, listed " . substr_count($html, 'ROW-'));
	}

	// ---- Owner columns at the boundary --------------------------------------------
	$fifty = str_repeat('u', 50);
	$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
	reset_rules_and_data($pdo);

	foreach (['syslog_alert' => 'INSERT INTO syslog_alert (name, type, message, method, user, suppression_schedule) VALUES (\'o\', \'messagec\', \'x\', 0, ?, \'\')',
		'syslog_remove' => 'INSERT INTO syslog_remove (name, type, message, method, user) VALUES (\'o\', \'messagec\', \'x\', \'del\', ?)',
		'syslog_reports' => 'INSERT INTO syslog_reports (name, type, message, user) VALUES (\'o\', \'messagec\', \'x\', ?)',
		'syslog_device_rule' => 'INSERT INTO syslog_device_rule (host, user) VALUES (\'h\', ?)'] as $table => $sql) {
		$pdo->prepare($sql)->execute([$fifty]);
		check(scalar('SELECT user FROM ' . ['syslog_alert' => 'syslog_alert', 'syslog_remove' => 'syslog_remove', 'syslog_reports' => 'syslog_reports', 'syslog_device_rule' => 'syslog_device_rule'][$table]) === $fifty, "$table stores a 50 character owner");

		try {
			$pdo->prepare($sql)->execute([$fifty . 'x']);
			check(false, "$table accepted a 51 character owner in strict mode");
		} catch (PDOException $error) {
			check(true, '');
		}
	}

	$pdo->exec("SET SESSION sql_mode = ''");

	// ---- The widening is retried until it fully succeeds ----------------------------
	$marker = static fn() => scalar("SELECT value FROM syslog_status WHERE name = 'schema_user_width'");

	$pdo->exec("DELETE FROM syslog_status WHERE name = 'schema_user_width'");
	$pdo->exec("CREATE TRIGGER block_marker BEFORE INSERT ON syslog_status FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'");
	check(syslog_ensure_table_structures() === false && $marker() === false, 'A marker that cannot be stored makes the check fail and leaves no marker');
	$pdo->exec('DROP TRIGGER block_marker');
	check(syslog_ensure_table_structures() === true && $marker() === '50', 'The next check widens again and stores the marker');
	check(syslog_ensure_table_structures() === true && (int) scalar('SELECT COUNT(*) FROM syslog_status WHERE name = \'schema_user_width\'') === 1, 'A later check does nothing more');

	$pdo->exec("DELETE FROM syslog_status WHERE name = 'schema_user_width'");
	$pdo->exec('RENAME TABLE syslog_device_rule TO syslog_device_rule_real');
	$pdo->exec('CREATE VIEW syslog_device_rule AS SELECT * FROM syslog_device_rule_real');
	check(syslog_ensure_table_structures() === false && $marker() === false, 'A table that cannot be altered makes the check fail and leaves no marker');
	$pdo->exec('DROP VIEW syslog_device_rule');
	$pdo->exec('RENAME TABLE syslog_device_rule_real TO syslog_device_rule');
	check(syslog_ensure_table_structures() === true && $marker() === '50', 'Once the table can be altered the widening completes');

	// ---- Hostname lookup budget ----------------------------------------------------
	reset_rules_and_data($pdo);
	$pdo->exec('CREATE TABLE IF NOT EXISTS host (id INT AUTO_INCREMENT PRIMARY KEY, hostname VARCHAR(100), description VARCHAR(100))');
	$pdo->exec('DELETE FROM host');
	$pdo->exec("INSERT INTO host (hostname, description) VALUES ('known-device.invalid', 'Known Device')");
	$options['syslog_resolve_hostname'] = 'on';
	$options['syslog_no_dns']           = '';

	foreach (range(1, 40) as $number) {
		$pdo->prepare("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES (1, NOW(), ?, 'app', 'm', 1, 3)")->execute(["unresolvable-$number.invalid"]);
	}

	$pdo->exec("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES (1, NOW(), 'known-device.invalid', 'app', 'm', 1, 3)");
	$max = (int) scalar('SELECT MAX(seq) FROM syslog_incoming');

	$pdo->exec("INSERT INTO syslog_incoming (status, logtime, host, program, message, facility_id, priority_id) VALUES (1, NOW(), 'localhost', 'app', 'm', 1, 3)");
	$max = (int) scalar('SELECT MAX(seq) FROM syslog_incoming');

	$resolved = syslog_resolve_incoming_hosts($max, 0, 0, 0);
	check($resolved === 1, 'With no budget only the Cacti device lookup resolves a name, resolved ' . $resolved);
	check((int) scalar("SELECT COUNT(*) FROM syslog_incoming WHERE host LIKE 'unresolved-%'") === 0, 'With no budget no name is called unresolved');
	check((int) scalar("SELECT COUNT(*) FROM syslog_incoming WHERE host = 'Known Device'") === 1, 'A name found in the Cacti device list is renamed to its description');
	check((int) scalar("SELECT COUNT(*) FROM syslog_incoming WHERE host LIKE 'unresolvable-%'") === 40, 'With no budget the sender names are left as received');

	$resolved = syslog_resolve_incoming_hosts($max, 0, 0, 60);
	check((int) scalar("SELECT COUNT(*) FROM syslog_incoming WHERE host = 'localhost'") === 1 && $resolved >= 1, 'A name that resolves through DNS keeps its name');
	$prefixed  = (int) scalar("SELECT COUNT(*) FROM syslog_incoming WHERE host LIKE 'unresolved-unresolvable-%'");
	$untouched = (int) scalar("SELECT COUNT(*) FROM syslog_incoming WHERE host LIKE 'unresolvable-%'");
	check($prefixed + $untouched === 40 && $prefixed > 0, "A generous budget looks names up and marks the failures: $prefixed marked, $untouched left");

	$options = array_merge($options, ['syslog_no_dns' => 'on']);
	$pdo->exec("UPDATE syslog_incoming SET host = CONCAT('late-', seq)");
	syslog_resolve_incoming_hosts($max, 0, 0, 60);
	check((int) scalar("SELECT COUNT(*) FROM syslog_incoming WHERE host LIKE 'unresolved-late-%'") === 42, 'With lookups disabled every unknown name is marked unresolved');

	// ---- Rule imports against the real tables -------------------------------------
	reset_rules_and_data($pdo);

	foreach ([
		'DROP TABLE IF EXISTS syslog_saved_searches',
		'CREATE TABLE syslog_saved_searches (id INT AUTO_INCREMENT PRIMARY KEY, hash VARCHAR(32) NOT NULL DEFAULT \'\', name VARCHAR(128) NOT NULL DEFAULT \'\', search TEXT, removal INT NOT NULL DEFAULT 1, `grouping` INT NOT NULL DEFAULT 0, user VARCHAR(50) NOT NULL DEFAULT \'\', is_global CHAR(2) NOT NULL DEFAULT \'\', date INT NOT NULL DEFAULT 0)',
	] as $statement) {
		$pdo->exec($statement);
	}

	$GLOBALS['db'] = $syslogdb_default;
	$_SESSION['sess_user_id'] = 1;
	$GLOBALS['syslog_allow_rule_edits_value'] = true;

	$import_alert  = extract_page_function('syslog_alerts.php', 'alert_import');
	$import_search = extract_page_function('syslog_saved_searches.php', 'syslog_saved_search_import');
	$run           = static function ($function, $table, array $templates, $raw = null) {
		$GLOBALS['messages']                = [];
		$GLOBALS['request']['import_text'] = $raw ?? json_encode(['version' => 1, 'table' => $table, 'templates' => $templates]);
		$function();
	};

	$alert = ['name' => 'imported', 'severity' => 1, 'method' => 0, 'suppression_schedule' => '', 'type' => 'messagec', 'message' => 'error'];

	$run($import_alert, 'syslog_alert', [$alert + ['hash' => str_repeat('a', 32)]]);
	check(table_count('syslog_alert') === 1, 'A valid alert file imports one rule');

	$run($import_alert, 'syslog_alert', [array_merge($alert, ['hash' => str_repeat('a', 32), 'id' => 999, 'message' => 'changed'])]);
	check(table_count('syslog_alert') === 1 && scalar('SELECT message FROM syslog_alert') === 'changed', 'A second import with the same hash updates that rule and ignores a hostile id');

	$run($import_alert, 'syslog_alert', [], '{"version":1,"table":"syslog_alert","templates":[');
	$run($import_alert, 'syslog_alert', [], 'not json at all');
	$run($import_alert, 'syslog_alert', [['name' => 'wrong table']], json_encode(['version' => 1, 'table' => 'syslog_remove', 'templates' => [['name' => 'x']]]));
	$run($import_alert, 'syslog_alert', [array_merge($alert, ['hash' => str_repeat('b', 32), 'type' => 'sql', 'message' => 'message LIKE ('])]);
	$run($import_alert, 'syslog_alert', [array_merge($alert, ['hash' => str_repeat('c', 32), 'type' => 'filter', 'message' => '{"version":1,"conditions":[{"field":"nope"}]}'])]);
	$run($import_alert, 'syslog_alert', [array_merge($alert, ['hash' => str_repeat('d', 32), 'type' => 'unknown'])]);
	$run($import_alert, 'syslog_alert', [array_diff_key($alert, ['type' => 1])]);
	check(table_count('syslog_alert') === 1, 'Malformed, wrong-table, invalid-query, invalid-filter, unknown-type and typeless alert files import nothing');

	$run($import_alert, 'syslog_alert', [array_diff_key($alert, ['hash' => 1]) + ['name' => 'no hash']]);
	check(table_count('syslog_alert') === 2, 'A file without a hash still imports a new rule');

	$search = ['name' => 'saved', 'search' => 'message contains "error"', 'removal' => 1, 'grouping' => 0];

	$run($import_search, 'syslog_saved_searches', [$search]);
	$run($import_search, 'syslog_saved_searches', [array_merge($search, ['name' => 'sql in text', 'search' => 'message contains "a\'); DROP TABLE syslog; --"'])]);
	check((int) scalar('SELECT COUNT(*) FROM syslog_saved_searches') === 2 && table_count('syslog') === 0, 'Valid searches import, and text that looks like SQL is stored as text');

	foreach ([
		['unterminated', ['search' => 'host = "unterminated']], ['bad record type', ['removal' => 7]], ['bad grouping', ['grouping' => 5]],
		['empty name', ['name' => '']], ['long name', ['name' => str_repeat('n', 129)]], ['empty search group', ['search' => '(']],
	] as [$label, $override]) {
		$run($import_search, 'syslog_saved_searches', [array_merge($search, ['name' => $label], $override)]);
	}

	check((int) scalar('SELECT COUNT(*) FROM syslog_saved_searches') === 2, 'Searches that do not parse or carry invalid values import nothing');

	$run($import_search, 'syslog_saved_searches', [], '{"version":1,"table":"syslog_saved_searches","templates":"x"}');
	$run($import_search, 'syslog_saved_searches', [], '[]');
	check((int) scalar('SELECT COUNT(*) FROM syslog_saved_searches') === 2, 'Empty and malformed search files import nothing');
} catch (Throwable $error) {
	$failures[] = $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ')';
} finally {
	drop_database();
}

finish_checks();
