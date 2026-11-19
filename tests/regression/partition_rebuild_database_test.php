<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$socket = getenv('SYSLOG_TEST_MYSQL_SOCKET');
$dsn = $socket ? 'mysql:unix_socket=' . $socket : getenv('SYSLOG_TEST_MYSQL_DSN');

if (!$dsn) {
	fwrite(STDERR, "Set SYSLOG_TEST_MYSQL_SOCKET or SYSLOG_TEST_MYSQL_DSN for a disposable MariaDB server.\n");
	exit(2);
}

$pdo = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::ATTR_EMULATE_PREPARES => false
]);
$syslogdb_default = 'syslog_rebuild_' . bin2hex(random_bytes(4));
$options = ['syslog_install_engine' => 'InnoDB', 'syslog_retention' => '2', 'syslog_partition_ahead_days' => '2'];
$copy_attempts = 0;
$fail_copy_at = 0;
$fail_prefix = '';
$batch_sizes = [];
$checks = 0;

function rebuild_check(bool $ok, string $message): void {
	$GLOBALS['checks']++;

	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function syslog_connect() { return true; }
function read_config_option($name) { return $GLOBALS['options'][$name] ?? ''; }
function set_config_option($name, $value) { $GLOBALS['options'][$name] = $value; }
function cacti_log(...$args) {}
function syslog_db_execute_prepared($sql, $params = [], ...$args) {
	if ($GLOBALS['fail_prefix'] !== '' && str_starts_with($sql, $GLOBALS['fail_prefix'])) {
		return false;
	}

	$copy = str_starts_with($sql, 'INSERT INTO');

	if ($copy && ++$GLOBALS['copy_attempts'] === $GLOBALS['fail_copy_at']) {
		return false;
	}

	$query = $GLOBALS['pdo']->prepare($sql);
	$ok = $query->execute($params);

	if ($copy) {
		$GLOBALS['batch_sizes'][] = $query->rowCount();
	}

	return $ok;
}
function syslog_db_execute($sql, ...$args) { return syslog_db_execute_prepared($sql); }
function syslog_db_fetch_assoc_prepared($sql, $params = [], ...$args) {
	$query = $GLOBALS['pdo']->prepare($sql);
	$query->execute($params);

	return $query->fetchAll();
}
function syslog_db_fetch_row($sql) {
	$rows = syslog_db_fetch_assoc_prepared($sql);

	return $rows[0] ?? [];
}
function syslog_db_fetch_cell_prepared($sql, $params = [], ...$args) {
	$query = $GLOBALS['pdo']->prepare($sql);
	$query->execute($params);

	return $query->fetchColumn();
}
function syslog_db_fetch_cell($sql) { return syslog_db_fetch_cell_prepared($sql); }
function syslog_db_table_exists($table, ...$args) {
	return (bool) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$GLOBALS['syslogdb_default'], $table]);
}
function syslog_partition_ahead_days() { return (int) read_config_option('syslog_partition_ahead_days'); }

require dirname(__DIR__, 2) . '/includes/schema.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/cli/upgrade_partitioning.php');
$offset = strpos($source, "\nfunction syslog_upart_run(");

if ($offset === false) {
	throw new RuntimeException('Cannot find rebuild functions');
}

$fixture = tempnam(sys_get_temp_dir(), 'syslog-rebuild-');

try {
	file_put_contents($fixture, "<?php\n" . substr($source, $offset));
	require $fixture;
} finally {
	unlink($fixture);
}

function rebuild_table(string $table, bool $convert = true, string $engine = 'InnoDB'): bool {
	ob_start();

	try {
		return syslog_upart_rebuild($table, $convert, true, $engine, 2, 2);
	} finally {
		ob_end_clean();
	}
}

try {
	$pdo->exec("CREATE DATABASE `$syslogdb_default`");
	$pdo->exec("USE `$syslogdb_default`");
	$pdo->exec("SET SESSION time_zone = '+00:00'");
	$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
	$pdo->exec("CREATE TABLE syslog (
		seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, logtime DATETIME NOT NULL,
		message TEXT COLLATE utf8mb4_general_ci NOT NULL, nullable_value VARCHAR(40),
		replication_source_event_id BIGINT UNSIGNED,
		PRIMARY KEY(seq,logtime), KEY logtime(logtime)
		) ENGINE=InnoDB PARTITION BY RANGE(TO_DAYS(logtime))
		(PARTITION legacy VALUES LESS THAN MAXVALUE)");
	$pdo->exec("INSERT INTO syslog (seq,logtime,message,replication_source_event_id)
		SELECT seq,TIMESTAMPADD(SECOND,seq,UTC_DATE()),CONCAT('Event ',seq),seq FROM seq_1_to_10005");
	$pdo->exec("INSERT INTO syslog VALUES
		(0,'0000-00-00 00:00:00','zero',NULL,0),
		(10000,TIMESTAMPADD(SECOND,10001,UTC_DATE()),'repeated seq','',10000),
		(10006,UTC_DATE() - INTERVAL 400 DAY,'outside retention',NULL,10006),
		(18446744073709551600,UTC_DATE(),UNHEX('410042'),NULL,18446744073709551600)");
	$pdo->exec('ALTER TABLE syslog AUTO_INCREMENT=18446744073709551610');
	$total = syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog');
	$fail_copy_at = 2;
	rebuild_check(!rebuild_table('syslog'), 'The injected second-batch failure must stop the rebuild');
	rebuild_check(syslog_db_table_exists('syslog_rebuild'), 'A failed copy must retain the original');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === 10000, 'The first copy is exactly 10000 rows');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_rebuild') === (int) $total, 'The original is intact');
	$fail_copy_at = 0;
	$pdo->exec("UPDATE syslog SET message='event 1' WHERE seq=1");
	rebuild_check(!rebuild_table('syslog'), 'Case-only differences must fail binary value verification');
	rebuild_check(syslog_db_table_exists('syslog_rebuild'), 'A value mismatch must retain the original even with matching counts');
	$pdo->exec('UPDATE syslog AS copied JOIN syslog_rebuild AS source
		ON copied.seq=source.seq AND copied.logtime=source.logtime SET copied.message=source.message');
	rebuild_check(rebuild_table('syslog'), 'A repaired copy resumes successfully');
	rebuild_check(!syslog_db_table_exists('syslog_rebuild'), 'Verified originals are removed automatically');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === (int) $total, 'Every row survives retries');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog WHERE seq=10000') === 2, 'The composite cursor preserves repeated sequence numbers across batches');
	rebuild_check(syslog_db_fetch_cell('SELECT HEX(message) FROM syslog WHERE seq=18446744073709551600') === '410042', 'Binary message contents survive');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog WHERE seq=0') === 1, 'Zero sequence values must not be regenerated');
	rebuild_check((string) syslog_db_fetch_cell("SELECT AUTO_INCREMENT FROM information_schema.TABLES
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='syslog'") === '18446744073709551610', 'Unsigned high-water marks must not overflow PHP integers');

	$pdo->exec('CREATE TABLE syslog_removed (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logtime TIMESTAMP NOT NULL, message TEXT) ENGINE=Aria');
	rebuild_check(rebuild_table('syslog_removed', false, 'Aria'), 'An empty legacy table can be repartitioned');
	rebuild_check(syslog_detect_logtime_precision('syslog_removed') === 'sec', 'A plain rebuild must preserve second precision');
	rebuild_check(syslog_db_fetch_cell("SELECT ENGINE FROM information_schema.TABLES
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='syslog_removed'") === 'Aria', 'Configured Aria engine is supported');
	$pdo->exec('ALTER TABLE syslog_removed REMOVE PARTITIONING');
	$pdo->exec('ALTER TABLE syslog_removed MODIFY logtime DATETIME NOT NULL');
	$pdo->exec("SET SESSION time_zone = '+05:30'");
	$pdo->exec("INSERT INTO syslog_removed VALUES(8,CONCAT(UTC_DATE(),' 12:00:00'),'removed')");
	$removed_epoch = syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_removed');
	$fail_prefix = "CREATE TABLE `$syslogdb_default`.`syslog_removed`";
	rebuild_check(!rebuild_table('syslog_removed', false, 'Aria'), 'A create failure after rename must be recoverable');
	rebuild_check(!syslog_db_table_exists('syslog_removed') && syslog_db_table_exists('syslog_removed_rebuild'), 'The renamed original survives a create failure');
	$fail_prefix = '';
	rebuild_check(rebuild_table('syslog_removed', false, 'Aria'), 'A missing replacement can be recreated on retry');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_removed') === 1, 'Aria data survives recovery');
	rebuild_check(syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_removed') === $removed_epoch, 'Legacy DATETIME history preserves its original non-UTC interpretation');

	$pdo->exec('CREATE TABLE syslog_incoming (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logtime TIMESTAMP NOT NULL, status INT, message TEXT) ENGINE=InnoDB');
	$pdo->exec("INSERT INTO syslog_incoming VALUES(30,UTC_DATE(),1,'queued'),(40,UTC_DATE(),0,'new')");
	$pdo->exec("INSERT INTO syslog_incoming SELECT seq+100,UTC_DATE(),0,CONCAT('queued ',seq) FROM seq_1_to_10001");
	$incoming_count = syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_incoming');
	$pdo->exec("SET SESSION time_zone = '+05:30'");
	$pdo->exec('CREATE TABLE syslog_replication_output (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logtime DATETIME NOT NULL, message TEXT) ENGINE=InnoDB');
	$pdo->exec("INSERT INTO syslog_replication_output VALUES(4,CONCAT(UTC_DATE(),' 12:00:00'),'undelivered')");
	$outbox_epoch = syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_replication_output');
	$options['syslog_install_logtime_precision'] = 'sec';
	$fail_prefix = "ALTER TABLE `$syslogdb_default`.`syslog_replication_output`";
	ob_start();
	rebuild_check(!syslog_upart_run(true, true), 'Outbox conversion failure must fail the overall operation');
	$output = ob_get_clean();
	rebuild_check(str_contains($output, 'size (estimated data + indexes):') && str_contains($output, 'AT LEAST another')
		&& str_contains($output, 'SEVERAL HOURS'), 'Size, extra-space and duration warnings are mandatory with --confirm');
	rebuild_check($options['syslog_install_logtime_precision'] === 'sec', 'Partial conversion must not update the precision setting');
	rebuild_check(syslog_db_fetch_cell('SELECT @@SESSION.time_zone') === '+05:30', 'The original non-UTC session zone is restored after failure');
	rebuild_check((int) syslog_db_fetch_cell("SELECT IS_FREE_LOCK('" . hash('sha256', $syslogdb_default . '.syslog_upgrade_partitioning') . "')") === 1, 'The named lock is released after failure');
	$fail_prefix = '';
	ob_start();
	rebuild_check(syslog_upart_run(true, true), 'The complete pipeline can finish after the outbox failure is fixed');
	ob_end_clean();
	rebuild_check($options['syslog_install_logtime_precision'] === 'ms', 'Only complete conversion records millisecond precision');
	rebuild_check(syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_incoming') === $incoming_count, 'All queued incoming messages survive multiple sequence-only batches');
	rebuild_check((int) syslog_db_fetch_cell('SELECT status FROM syslog_incoming WHERE seq=30') === 1, 'Incoming processing state survives');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_replication_output') === 1, 'Undelivered replication messages survive');
	rebuild_check((string) syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_replication_output') === $outbox_epoch . '.000', 'A legacy DATETIME outbox uses its original session time zone');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === (int) $total, 'Previously converted history survives subsequent pipeline retries');
	rebuild_check((int) syslog_db_fetch_cell("SELECT COUNT(*) FROM information_schema.PARTITIONS
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('syslog','syslog_removed')
		AND REPLACE(PARTITION_EXPRESSION,'`','')='unix_timestamp(logtime)'") > 2, 'Stored and removed tables use epoch partitions');
	ob_start();
	rebuild_check(syslog_upart_rebuild('syslog_removed', false, false, 'InnoDB', 2, 2), 'Explicit discard mode completes');
	ob_end_clean();
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_removed') === 0, 'Only explicit discard mode empties a rebuilt table');
	rebuild_check(!syslog_db_table_exists('syslog_removed_rebuild'), 'Explicit discard removes the original after verification');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_replication_output') === 1, 'Discard mode does not delete the outbox');
	rebuild_check(max($batch_sizes) <= 10000, 'No stored or incoming INSERT SELECT may copy more than 10000 rows');

	print "$checks partition rebuild database checks passed.\n";
} finally {
	$pdo->exec("DROP DATABASE `$syslogdb_default`");
}
