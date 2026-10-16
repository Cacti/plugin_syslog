<?php
// Run only against an explicitly supplied disposable MariaDB server.
$socket = getenv('SYSLOG_TEST_MYSQL_SOCKET');
$dsn = $socket ? 'mysql:unix_socket=' . $socket : getenv('SYSLOG_TEST_MYSQL_DSN');
if (!$dsn) {
	fwrite(STDERR, "Set SYSLOG_TEST_MYSQL_SOCKET or SYSLOG_TEST_MYSQL_DSN for a disposable test server.\n");
	exit(2);
}
$pdo = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$syslogdb_default = 'syslog_incoming_' . bin2hex(random_bytes(4));
$config = ['poller_id' => 1];
$options = [];
$affected = 0;
$checks = 0;
function check_incoming($condition, $message) {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}
function syslog_db_execute_prepared($sql, $params = [], ...$args) {
	if (!empty($GLOBALS['fail_sql']) && str_starts_with($sql, $GLOBALS['fail_sql'])) {
		return false;
	}
	if (str_starts_with($sql, 'DELETE FROM') && isset($GLOBALS['before_delete'])) {
		$callback = $GLOBALS['before_delete'];
		unset($GLOBALS['before_delete']);
		$callback();
	}
	$q = $GLOBALS['pdo']->prepare($sql);
	try {
		$success = $q->execute($params);
	} catch (PDOException $error) {
		if (($error->errorInfo[1] ?? 0) !== 1205) { throw $error; }
		return false;
	}
	$GLOBALS['affected'] = $q->rowCount();
	return $success;
}
function syslog_db_execute($sql, ...$args) {
	$success = syslog_db_execute_prepared($sql);
	return $sql === 'COMMIT' && !empty($GLOBALS['lose_commit_ack']) ? false : $success;
}
function syslog_db_fetch_cell_prepared($sql, $params = [], ...$args) {
	$q = $GLOBALS['pdo']->prepare($sql);
	$q->execute($params);
	return $q->fetchColumn();
}
function syslog_db_fetch_cell($sql) { return syslog_db_fetch_cell_prepared($sql); }
function syslog_db_fetch_assoc_prepared($sql, $params = [], ...$args) {
	$q = $GLOBALS['pdo']->prepare($sql);
	$q->execute($params);
	return $q->fetchAll(PDO::FETCH_ASSOC);
}
function syslog_db_fetch_row_prepared($sql, $params = [], ...$args) {
	$q = $GLOBALS['pdo']->prepare($sql);
	$q->execute($params);
	return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function cacti_sizeof($items) { return is_array($items) ? count($items) : 0; }
function __($text, ...$args) {
	array_pop($args);
	return $args ? vsprintf($text, $args) : $text;
}
function db_affected_rows(...$args) { return $GLOBALS['affected']; }
function read_config_option($name) { return $GLOBALS['options'][$name] ?? ''; }
function api_plugin_hook(...$args) {}
function cacti_log(...$args) {}
require dirname(__DIR__, 2) . '/includes/functions.php';
function incoming_ids() {
	return $GLOBALS['pdo']->query('SELECT seq FROM syslog_incoming ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN);
}
try {
	$pdo->exec("CREATE DATABASE `$syslogdb_default`");
	$pdo->exec("USE `$syslogdb_default`");
	$pdo->exec('CREATE TABLE syslog_incoming (
		seq BIGINT PRIMARY KEY, status TINYINT DEFAULT 0, logtime TIMESTAMP NOT NULL,
		host VARCHAR(64), program VARCHAR(40), message TEXT,
		priority_id INT, facility_id INT, threshold_breached TINYINT) ENGINE=InnoDB');
	$pdo->exec('CREATE TABLE syslog (
		seq BIGINT AUTO_INCREMENT, logtime TIMESTAMP NOT NULL,
		priority_id INT, facility_id INT, program_id INT, host_id INT,
		message TEXT, message_digest CHAR(32), threshold_breached TINYINT,
		replication_source_poller_id INT, replication_source_event_id BIGINT,
		PRIMARY KEY(seq, logtime), KEY replication_source(replication_source_poller_id, replication_source_event_id)
		) ENGINE=InnoDB PARTITION BY RANGE(UNIX_TIMESTAMP(logtime)) (PARTITION dMaxValue VALUES LESS THAN MAXVALUE)');
	$pdo->exec('CREATE TABLE syslog_hosts (host_id INT PRIMARY KEY, host VARCHAR(64))');
	$pdo->exec('CREATE TABLE syslog_programs (program_id INT PRIMARY KEY, program VARCHAR(40))');
	$pdo->exec("INSERT INTO syslog_hosts VALUES (1, 'router')");
	$pdo->exec("INSERT INTO syslog_programs VALUES (1, 'app')");
	$pdo->exec("INSERT INTO syslog_incoming (seq,status,logtime,host,program,message) VALUES
		(1,1,NOW() - INTERVAL 2 DAY,'router','app','crash-stranded'),
		(2,1,NOW(),NULL,'app','missing host'),
		(3,1,NOW(),'router',NULL,'missing program'),
		(4,1,NOW(),'unknown','app','failed host normalization'),
		(5,1,NOW(),'router','unknown','failed program normalization'),
		(6,1,NOW(),'router','app','valid')");
	$batch = syslog_preprocess_incoming_records();
	check_incoming($batch === ['max_seq' => 6, 'incoming' => 6], 'An entirely crash-stranded queue must be retried');
	$pdo->exec("INSERT INTO syslog_incoming (seq,logtime,host,program,message)
		VALUES (7,NOW() - INTERVAL 2 DAY,'router','app','late arrival after snapshot')");
	check_incoming(syslog_incoming_to_syslog(6)['success'], 'Archival succeeds with unmatched rows');
	check_incoming(incoming_ids() === [2,3,4,5,7], 'NULLs, missing references and late arrivals must survive');
	check_incoming((int)$pdo->query('SELECT COUNT(*) FROM syslog')->fetchColumn() === 2, 'Both valid messages are archived');
	$pdo->exec("INSERT INTO syslog_hosts VALUES (2, 'unknown')");
	$pdo->exec("INSERT INTO syslog_programs VALUES (2, 'unknown')");
	$batch = syslog_preprocess_incoming_records();
	check_incoming($batch['max_seq'] === 7, 'Retry includes retained rows and newly arrived rows');
	check_incoming(syslog_incoming_to_syslog(7,4,5)['success'], 'Sliced retry succeeds');
	check_incoming(incoming_ids() === [2,3,7], 'Only repaired rows in the worker slice are removed');
	check_incoming(syslog_incoming_to_syslog(7,7,7)['success'], 'Delayed event is archived');
	check_incoming(incoming_ids() === [2,3], 'Missing fields stay queued without age-based deletion');

	// A failed delete must roll back both the history insert and incoming changes.
	$pdo->exec("INSERT INTO syslog_incoming (seq,status,logtime,host,program,message)
		VALUES (8,1,NOW(),'router','app','atomic transfer')");
	$before = (int)$pdo->query('SELECT COUNT(*) FROM syslog')->fetchColumn();
	$fail_sql = 'DELETE FROM';
	check_incoming(!syslog_incoming_to_syslog(8,8,8)['success'], 'Failed deletion reports transfer failure');
	check_incoming((int)$pdo->query('SELECT COUNT(*) FROM syslog')->fetchColumn() === $before, 'History insert rolls back');
	check_incoming(in_array(8, incoming_ids(), true), 'Failed deletion preserves incoming');
	$fail_sql = 'COMMIT';
	check_incoming(!syslog_incoming_to_syslog(8,8,8)['success'], 'Failed commit reports transfer failure');
	check_incoming((int)$pdo->query('SELECT COUNT(*) FROM syslog')->fetchColumn() === $before, 'Failed commit rolls back history');
	$fail_sql = '';
	$lose_commit_ack = true;
	check_incoming(!syslog_incoming_to_syslog(8,8,8)['success'], 'Unconfirmed committed transfer reports failure');
	$lose_commit_ack = false;
	check_incoming(syslog_incoming_to_syslog(8,8,8)['moved'] === 0, 'Retry after lost commit acknowledgement adds no history');
	check_incoming((int)$pdo->query('SELECT COUNT(*) FROM syslog')->fetchColumn() === $before + 1, 'Exactly one copy survives');

	// A disconnected process loses its uncommitted insert and preserves incoming.
	$pdo->exec("INSERT INTO syslog_incoming (seq,status,logtime,host,program,message)
		VALUES (9,1,NOW(),'router','app','disconnect before delete')");
	$before_delete = function () { throw new RuntimeException('simulated process exit'); };
	try { syslog_incoming_to_syslog(9,9,9); } catch (RuntimeException $error) {
		check_incoming($error->getMessage() === 'simulated process exit', 'Process stops after archival before deletion');
	}
	$pdo = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$pdo->exec("USE `$syslogdb_default`");
	check_incoming(in_array(9, incoming_ids(), true), 'Disconnect rolls back incoming state');
	check_incoming(syslog_incoming_to_syslog(9,9,9)['moved'] === 1, 'Retry archives the disconnected transfer once');

	// An independent connection must not archive a slice while its owner holds it.
	$pdo->exec("INSERT INTO syslog_incoming (seq,status,logtime,host,program,message)
		VALUES (10,1,NOW(),'router','app','overlapping retry')");
	$before_delete = function () use ($dsn, $syslogdb_default) {
		$owner = $GLOBALS['pdo'];
		$GLOBALS['pdo'] = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$GLOBALS['pdo']->exec("USE `$syslogdb_default`");
		$GLOBALS['pdo']->exec('SET innodb_lock_wait_timeout = 1');
		try {
			check_incoming(!syslog_incoming_to_syslog(10,10,10)['success'], 'Overlapping retry cannot bypass incoming locks');
		} finally { $GLOBALS['pdo'] = $owner; }
	};
	check_incoming(syslog_incoming_to_syslog(10,10,10)['success'], 'Original slice commits after overlap is rejected');
	check_incoming(syslog_incoming_to_syslog(10,10,10)['moved'] === 0, 'Overlapping retry adds no duplicate after commit');

	// Aria history cannot roll back: a retry must recognize the persisted insert.
	$pdo->exec('ALTER TABLE syslog ENGINE=Aria');
	$pdo->exec("INSERT INTO syslog_incoming (seq,status,logtime,host,program,message)
		VALUES (11,1,NOW(),'router','app','nontransactional retry')");
	$fail_sql = 'DELETE FROM';
	check_incoming(!syslog_incoming_to_syslog(11,11,11)['success'], 'Aria transfer failure is reported');
	check_incoming(in_array(11, incoming_ids(), true), 'Aria failure preserves incoming');
	$fail_sql = '';
	check_incoming(syslog_incoming_to_syslog(11,11,11)['moved'] === 0, 'Aria retry skips its existing history identity');
	check_incoming(!in_array(11, incoming_ids(), true), 'Aria retry acknowledges existing history');
	check_incoming((int)$pdo->query('SELECT COUNT(*) FROM syslog WHERE replication_source_event_id = 11')->fetchColumn() === 1,
		'Aria retry leaves exactly one history row');
	$pdo->exec('ALTER TABLE syslog ENGINE=InnoDB');

	$pdo->exec("INSERT INTO syslog_incoming (seq,status,logtime,host,program,message) VALUES
		(12,1,'2026-10-16 12:00:00','router','app','identical event text'),
		(13,1,'2026-10-16 12:00:00','router','app','identical event text')");
	check_incoming(syslog_incoming_to_syslog(13,12,13)['moved'] === 2,
		'Distinct incoming sequences with identical content remain distinct events');

	// Remote archival may omit NULL references, but the raw outbox must preserve them.
	$pdo->exec('CREATE TABLE syslog_replication_output (
		source_poller_id INT, source_event_id BIGINT, facility_id INT, priority_id INT,
		program VARCHAR(40), logtime TIMESTAMP NOT NULL, host VARCHAR(64), message TEXT,
		threshold_breached TINYINT, disposition VARCHAR(16), PRIMARY KEY(source_poller_id,source_event_id)) ENGINE=InnoDB');
	$config['poller_id'] = 2;
	$options = ['syslog_remote_enabled' => 'on', 'syslog_remote_store_records' => 'on'];
	check_incoming(syslog_incoming_to_syslog(7)['success'], 'Remote transfer commits with raw outbox copies');
	check_incoming(incoming_ids() === [], 'Incoming is acknowledged after outbox preservation');
	check_incoming((int)$pdo->query('SELECT COUNT(*) FROM syslog_replication_output')->fetchColumn() === 2, 'Both malformed rows survive in the outbox');
	// Verify the metadata guard against a populated physical overflow partition.
	$pdo->exec('CREATE TABLE syslog_removed LIKE syslog');
	$pdo->exec('ALTER TABLE syslog_removed ENGINE=Aria');
	$reason = '';
	check_incoming(syslog_partition_create('syslog_removed', time(), $reason), 'Small overflow can be reorganized');
	$pdo->exec("INSERT INTO syslog_removed (logtime,message)
		SELECT '2030-01-01 00:00:00','overflow' FROM seq_1_to_100001");
	check_incoming(!syslog_partition_create('syslog_removed', time() + 86400, $reason), 'Oversized real overflow is deferred');
	check_incoming(str_contains($reason, 'intervention required'), 'Deferral returns the operator-facing reason');
	$pdo->exec("INSERT INTO syslog_removed (logtime,message) VALUES ('2030-01-01 00:00:00','still writable')");
	check_incoming((int)$pdo->query('SELECT COUNT(*) FROM syslog_removed PARTITION (dMaxValue)')->fetchColumn() === 100002,
		'Deferred overflow remains intact and writable');

	$options = ['syslog_retention' => '7'];
	$cutoff = (intdiv(time(), 86400) - 6) * 86400;
	foreach (['UNIX_TIMESTAMP', 'TO_DAYS'] as $expression) {
		foreach ([false, true] as $spans_cutoff) {
			$pdo->exec('DROP TABLE syslog_removed');
			$pdo->exec("SET time_zone = '+00:00'");
			$epochs = [$cutoff - 2 * 86400, $cutoff - ($spans_cutoff ? 86400 : 0), $cutoff + 86400, $cutoff + 10 * 86400];
			$boundaries = [];
			foreach ($epochs as $epoch) {
				$boundaries[] = $expression === 'TO_DAYS'
					? (int)syslog_db_fetch_cell_prepared('SELECT TO_DAYS(?)', [gmdate('Y-m-d', $epoch)]) : $epoch;
			}
			$type = $expression === 'TO_DAYS' ? 'DATETIME' : 'TIMESTAMP';
			$pdo->exec("CREATE TABLE syslog_removed (id INT, logtime $type NOT NULL, PRIMARY KEY(id,logtime))
				ENGINE=InnoDB PARTITION BY RANGE ($expression(logtime)) (
				PARTITION misleading_label VALUES LESS THAN ($boundaries[0]),
				PARTITION expires VALUES LESS THAN ($boundaries[1]),
				PARTITION spans VALUES LESS THAN ($boundaries[2]),
				PARTITION future VALUES LESS THAN ($boundaries[3]),
				PARTITION dMaxValue VALUES LESS THAN MAXVALUE)");
			foreach ([-3 * 86400, -129600, -3600, 0, 3 * 86400] as $index => $offset) {
				syslog_db_execute_prepared('INSERT INTO syslog_removed VALUES (?,?)',
					[$index + 1, gmdate('Y-m-d H:i:s', $cutoff + $offset)]);
			}
			$pdo->exec("SET time_zone = '-07:00'");
			check_incoming(syslog_partition_remove('syslog_removed') === 2, "$expression sparse history expires by age");
			$ids = $pdo->query('SELECT id FROM syslog_removed ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
			check_incoming($ids === ($spans_cutoff ? [3,4,5] : [4,5]),
				"$expression preserves cutoff events, future events, and mixed-age partitions");
		}
	}

	echo "PASS: $checks incoming preservation database checks\n";
} finally {
	$pdo->exec("DROP DATABASE IF EXISTS `$syslogdb_default`");
}
