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
$syslogdb_default = 'syslog_rebuild_test';
$options = ['syslog_install_engine' => 'InnoDB', 'syslog_retention' => '2', 'syslog_partition_ahead_days' => '2'];
$copy_attempts = 0;
$fail_copy_at = 0;
$fail_prefix = '';
$corrupt_after_copy = '';
$batch_sizes = [];
$checks = 0;

function rebuild_check(bool $ok, string $message): void {
	$GLOBALS['checks']++;

	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function syslog_connect() { return true; }
function __($text, ...$args) { array_pop($args); return $args ? vsprintf($text, $args) : $text; }
function read_config_option($name, $force = false) { return $GLOBALS['options'][$name] ?? ''; }
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

		if ($GLOBALS['corrupt_after_copy'] !== '') {
			$GLOBALS['pdo']->exec($GLOBALS['corrupt_after_copy']);
			$GLOBALS['corrupt_after_copy'] = '';
		}
	}

	return $ok;
}
function syslog_db_execute($sql, ...$args) { return syslog_db_execute_prepared($sql); }
function syslog_db_fetch_assoc_prepared($sql, $params = [], ...$args) {
	$query = $GLOBALS['pdo']->prepare($sql);
	$query->execute($params);

	return $query->fetchAll();
}
function syslog_db_fetch_row_prepared($sql, $params = [], ...$args) {
	$rows = syslog_db_fetch_assoc_prepared($sql, $params);

	return $rows[0] ?? [];
}
function syslog_db_fetch_row($sql) { return syslog_db_fetch_row_prepared($sql); }
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
// No Cacti process table here, so no transfer workers are registered.
function db_fetch_assoc($sql, ...$args) { return []; }

require dirname(__DIR__, 2) . '/includes/schema.php';

// The partitioning-level helpers come from the real functions.php source.
$helpers = file_get_contents(dirname(__DIR__, 2) . '/includes/functions.php');
$helper_code = '';

foreach (['syslog_partition_periods', 'syslog_partition_requested_granularity', 'syslog_partition_ahead_days_for',
	'syslog_partition_server_limit', 'syslog_partition_projected_count', 'syslog_partition_settings_error',
	'syslog_processing_lock_name'] as $helper) {
	if (preg_match('/^function ' . $helper . '\(.*?^\}/ms', $helpers, $match) !== 1) {
		throw new RuntimeException("Cannot find $helper");
	}

	$helper_code .= $match[0] . "\n";
}

$source = file_get_contents(dirname(__DIR__, 2) . '/cli/upgrade_partitioning.php');
$offset = strpos($source, "\nfunction syslog_upart_run(");

if ($offset === false) {
	throw new RuntimeException('Cannot find rebuild functions');
}

$fixture = tempnam(sys_get_temp_dir(), 'syslog-rebuild-');

try {
	file_put_contents($fixture, "<?php\n" . $helper_code . substr($source, $offset));
	require $fixture;
} finally {
	unlink($fixture);
}

function is_free_processing_lock(): bool {
	return (int) syslog_db_fetch_cell_prepared('SELECT IS_FREE_LOCK(?)', [syslog_processing_lock_name()]) === 1;
}

function rebuild_table(string $table, bool $convert = true, string $engine = 'InnoDB'): bool {
	ob_start();

	try {
		// 10,000-row batches keep the multi-batch resume paths covered on a small table.
		return syslog_upart_rebuild($table, $convert, true, $engine, 2, 2, 'day', 8192, 10000);
	} finally {
		ob_end_clean();
	}
}

try {
	$pdo->exec('DROP DATABASE IF EXISTS `syslog_rebuild_test`');
	$pdo->exec('CREATE DATABASE `syslog_rebuild_test`');
	$pdo->exec('USE `syslog_rebuild_test`');
	$pdo->exec("SET SESSION time_zone = '+00:00'");
	$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
	$pdo->exec("CREATE TABLE syslog (
		seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
		message TEXT COLLATE utf8mb4_general_ci NOT NULL, nullable_value VARCHAR(40),
		replication_source_event_id BIGINT UNSIGNED,
		PRIMARY KEY(seq,logtime), KEY logtime(logtime)
		) ENGINE=InnoDB PARTITION BY RANGE(UNIX_TIMESTAMP(logtime))
		(PARTITION legacy VALUES LESS THAN MAXVALUE)");
	$pdo->exec("INSERT INTO syslog (seq,logtime,message,replication_source_event_id)
		SELECT seq,TIMESTAMPADD(SECOND,seq,UTC_DATE()),CONCAT('Event ',seq),seq FROM seq_1_to_10005");
	$pdo->exec("INSERT INTO syslog VALUES
		(0,'0000-00-00 00:00:00','zero',NULL,0),
		(10000,TIMESTAMPADD(SECOND,10001,UTC_DATE()),'repeated seq','',10000),
		(10006,UTC_DATE() - INTERVAL 400 DAY,'outside retention',NULL,10006),
		(20000,UTC_DATE(),UNHEX('410042'),NULL,20000)");
	$pdo->exec('ALTER TABLE syslog AUTO_INCREMENT=1000000');
	$total = syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog');
	$original_order = $pdo->query('SELECT message FROM syslog ORDER BY seq, logtime')->fetchAll(PDO::FETCH_COLUMN);
	$fail_copy_at = 2;
	rebuild_check(!rebuild_table('syslog', false), 'The injected second-batch failure must stop the rebuild');
	rebuild_check(syslog_db_table_exists('syslog_rebuild'), 'A failed copy must retain the original');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === 10000, 'The first verified copy is exactly 10000 rows');
	rebuild_check((int) syslog_db_fetch_cell('SELECT copied FROM syslog_rebuild_progress WHERE table_name=\'syslog\'') === 10000, 'The checkpoint records the verified batch');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_rebuild') === (int) $total, 'The original is intact');
	$fail_copy_at = 0;
	$pdo->exec("INSERT INTO syslog (logtime,message) VALUES (UTC_DATE(),'unverified partial batch')");
	$corrupt_after_copy = 'UPDATE syslog SET message=LOWER(message) ORDER BY seq DESC LIMIT 1';
	rebuild_check(!rebuild_table('syslog', false), 'Case-only differences must fail binary batch verification');
	rebuild_check(syslog_db_table_exists('syslog_rebuild'), 'A value mismatch must retain the original even with matching counts');
	rebuild_check((int) syslog_db_fetch_cell('SELECT copied FROM syslog_rebuild_progress WHERE table_name=\'syslog\'') === 10000, 'A mismatched batch is never checkpointed');
	rebuild_check(rebuild_table('syslog', false), 'A retry discards unverified rows and resumes after the checkpoint');
	rebuild_check(!syslog_db_table_exists('syslog_rebuild'), 'Verified originals are removed automatically');
	rebuild_check(!syslog_db_table_exists('syslog_rebuild_progress'), 'The checkpoint table is removed after the last rebuild');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === (int) $total, 'Every row survives retries exactly once');
	rebuild_check((int) syslog_db_fetch_cell("SELECT COUNT(*) FROM syslog WHERE message IN ('unverified partial batch')") === 0, 'Unverified replacement rows are discarded');
	rebuild_check($pdo->query('SELECT message FROM syslog ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN) === $original_order, 'Renumbered rows keep their original order, including repeated sequence values');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(DISTINCT seq) FROM syslog') === (int) $total, 'Repeated original sequence values receive distinct new values');
	rebuild_check((int) syslog_db_fetch_cell('SELECT MIN(seq) FROM syslog') >= 1000000, 'Copied rows never reuse a sequence value at or below the original high-water mark');
	rebuild_check(syslog_db_fetch_cell("SELECT HEX(message) FROM syslog WHERE replication_source_event_id=20000") === '410042', 'Binary message contents survive');
	rebuild_check(syslog_db_fetch_cell("SELECT logtime FROM syslog WHERE message='zero'") === '0000-00-00 00:00:00', 'Zero timestamps survive');
	rebuild_check((int) syslog_db_fetch_cell("SELECT replication_source_event_id FROM syslog WHERE message='Event 5'") === 5, 'Non-sequence columns are copied unchanged');

	$pdo->exec('CREATE TABLE syslog_removed (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logtime TIMESTAMP NOT NULL, message TEXT) ENGINE=InnoDB');
	$pdo->exec("INSERT INTO syslog_removed (logtime,message) SELECT UTC_DATE(),'near the limit' FROM seq_1_to_10");
	$pdo->exec('ALTER TABLE syslog_removed AUTO_INCREMENT=18446744073709551610');
	rebuild_check(!rebuild_table('syslog_removed', false), 'Too few remaining sequence values must stop the rebuild');
	rebuild_check(!syslog_db_table_exists('syslog_removed_rebuild') && (int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_removed') === 10, 'The sequence headroom check runs before any change');
	$pdo->exec('DROP TABLE syslog_removed');

	$pdo->exec('CREATE TABLE syslog_removed (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logtime TIMESTAMP NOT NULL, message TEXT) ENGINE=Aria');
	rebuild_check(rebuild_table('syslog_removed', false, 'Aria'), 'An empty legacy table can be repartitioned');
	rebuild_check(syslog_detect_logtime_precision('syslog_removed') === 'sec', 'A plain rebuild must preserve second precision');
	rebuild_check(syslog_db_fetch_cell("SELECT ENGINE FROM information_schema.TABLES
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='syslog_removed'") === 'Aria', 'Configured Aria engine is supported');
	$pdo->exec("INSERT INTO syslog_removed (logtime,message) VALUES (CONCAT(UTC_DATE(),' 12:00:00'),'removed')");
	$removed_epoch = syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_removed');
	$fail_prefix = "CREATE TABLE `$syslogdb_default`.`syslog_removed`";
	rebuild_check(!rebuild_table('syslog_removed', false, 'Aria'), 'A create failure after rename must be recoverable');
	rebuild_check(!syslog_db_table_exists('syslog_removed') && syslog_db_table_exists('syslog_removed_rebuild'), 'The renamed original survives a create failure');
	$fail_prefix = "ALTER TABLE `$syslogdb_default`.`syslog_removed` AUTO_INCREMENT";
	rebuild_check(!rebuild_table('syslog_removed', false, 'Aria'), 'A setup failure before the checkpoint must be recoverable');
	rebuild_check(syslog_db_table_exists('syslog_removed') && (int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_removed') === 0, 'An interrupted setup leaves an empty replacement');
	$fail_prefix = '';
	rebuild_check(rebuild_table('syslog_removed', false, 'Aria'), 'An empty replacement without a checkpoint is recreated on retry');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_removed') === 1, 'Aria data survives recovery');
	rebuild_check(syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_removed') === $removed_epoch, 'TIMESTAMP history keeps its UTC instant');

	$pdo->exec('ALTER TABLE syslog_removed REMOVE PARTITIONING');
	$pdo->exec('ALTER TABLE syslog_removed MODIFY logtime DATETIME NOT NULL');
	rebuild_check(!rebuild_table('syslog_removed', false, 'Aria'), 'Preserving a legacy DATETIME table is refused');
	rebuild_check(!syslog_db_table_exists('syslog_removed_rebuild'), 'The DATETIME refusal happens before any change');
	$options['syslog_enabled'] = '';
	ob_start();
	rebuild_check(!syslog_upart_run(true, false), 'The command refuses legacy DATETIME history before any change');
	ob_end_clean();
	rebuild_check(syslog_db_fetch_cell("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
		AND TABLE_NAME='syslog_removed' AND COLUMN_NAME='logtime'") === 'datetime' && !syslog_db_table_exists('syslog_removed_rebuild'), 'A refused command leaves DATETIME tables untouched');
	rebuild_check(is_free_processing_lock(), 'The processing lock is released after a refusal');
	ob_start();
	rebuild_check(syslog_upart_rebuild('syslog_removed', false, false, 'InnoDB', 2, 2), 'Explicit discard mode replaces a legacy DATETIME table');
	ob_end_clean();
	rebuild_check(syslog_detect_logtime_precision('syslog_removed') === 'sec' && syslog_db_fetch_cell("SELECT DATA_TYPE FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='syslog_removed' AND COLUMN_NAME='logtime'") === 'timestamp', 'A discarded DATETIME table is recreated as TIMESTAMP');

	set_config_option('syslog_enabled', 'on');
	ob_start();
	rebuild_check(!syslog_upart_run(true, false), 'The command refuses to run while Syslog processing is enabled');
	ob_end_clean();
	rebuild_check(is_free_processing_lock(), 'Enabled processing is refused before taking the processing lock');
	set_config_option('syslog_enabled', '');
	// A second connection stands in for a running syslog_process.php.
	$holder = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$holder->prepare('SELECT GET_LOCK(?, 0)')->execute([syslog_processing_lock_name()]);
	ob_start();
	rebuild_check(!syslog_upart_run(true, false), 'The command refuses to run while a processing run holds the processing lock');
	ob_end_clean();
	rebuild_check(!syslog_db_table_exists('syslog_rebuild') && !syslog_db_table_exists('syslog_removed_rebuild'), 'Refusals leave the message tables untouched');
	$holder = null;

	$pdo->exec('CREATE TABLE syslog_incoming (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logtime TIMESTAMP NOT NULL, status INT, message TEXT) ENGINE=InnoDB');
	$pdo->exec("INSERT INTO syslog_incoming VALUES(30,UTC_DATE(),1,'queued'),(40,UTC_DATE(),0,'new')");
	$pdo->exec("INSERT INTO syslog_incoming SELECT seq+100,UTC_DATE(),0,CONCAT('queued ',seq) FROM seq_1_to_10001");
	$incoming_count = syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_incoming');
	$incoming_seq = syslog_db_fetch_cell('SELECT SUM(seq) FROM syslog_incoming');
	$pdo->exec("SET SESSION time_zone = '+05:30'");
	$pdo->exec('CREATE TABLE syslog_replication_output (seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logtime TIMESTAMP NOT NULL, message TEXT) ENGINE=InnoDB');
	$pdo->exec("INSERT INTO syslog_replication_output VALUES(4,CONCAT(UTC_DATE(),' 12:00:00'),'undelivered')");
	$outbox_epoch = syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_replication_output');
	$stored_epochs = syslog_db_fetch_cell("SELECT SUM(UNIX_TIMESTAMP(logtime)) FROM syslog WHERE logtime > '1970-01-01'");
	$options['syslog_install_logtime_precision'] = 'sec';
	$fail_prefix = "ALTER TABLE `$syslogdb_default`.`syslog_replication_output`";
	ob_start();
	rebuild_check(!syslog_upart_run(true, true), 'Outbox conversion failure must fail the overall operation');
	$output = ob_get_clean();
	rebuild_check(str_contains($output, 'size (estimated data + indexes):') && str_contains($output, 'AT LEAST another')
		&& str_contains($output, 'SEVERAL HOURS'), 'Size, extra-space and duration warnings are mandatory with --confirm');
	rebuild_check($options['syslog_install_logtime_precision'] === 'sec', 'Partial conversion must not update the precision setting');
	rebuild_check(syslog_db_fetch_cell('SELECT @@SESSION.time_zone') === '+05:30', 'The original non-UTC session zone is restored after failure');
	rebuild_check(is_free_processing_lock(), 'The processing lock is released after failure');
	$fail_prefix = '';
	ob_start();
	rebuild_check(syslog_upart_run(true, true), 'The complete pipeline can finish after the outbox failure is fixed');
	ob_end_clean();
	rebuild_check($options['syslog_install_logtime_precision'] === 'ms', 'Only complete conversion records millisecond precision');
	rebuild_check(syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_incoming') === $incoming_count
		&& syslog_db_fetch_cell('SELECT SUM(seq) FROM syslog_incoming') === $incoming_seq, 'Queued incoming messages keep their sequence identities');
	rebuild_check((int) syslog_db_fetch_cell('SELECT status FROM syslog_incoming WHERE seq=30') === 1, 'Incoming processing state survives');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_replication_output') === 1, 'Undelivered replication messages survive');
	rebuild_check((string) syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP(logtime) FROM syslog_replication_output') === $outbox_epoch . '.000', 'The outbox keeps its UTC instant');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === (int) $total, 'Converted history keeps every row');
	rebuild_check(syslog_db_fetch_cell("SELECT SUM(UNIX_TIMESTAMP(logtime)) FROM syslog WHERE logtime > '1970-01-01'") === $stored_epochs . '.000', 'Converted history keeps every UTC instant');
	rebuild_check((int) syslog_db_fetch_cell("SELECT COUNT(*) FROM information_schema.PARTITIONS
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('syslog','syslog_removed')
		AND REPLACE(PARTITION_EXPRESSION,'`','')='unix_timestamp(logtime)'") > 2, 'Stored and removed tables use epoch partitions');
	ob_start();
	rebuild_check(syslog_upart_rebuild('syslog_removed', false, false, 'InnoDB', 2, 2), 'Explicit discard mode completes');
	ob_end_clean();
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_removed') === 0, 'Only explicit discard mode empties a rebuilt table');
	rebuild_check(!syslog_db_table_exists('syslog_removed_rebuild'), 'Explicit discard removes the original after verification');
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_replication_output') === 1, 'Discard mode does not delete the outbox');
	rebuild_check(max($batch_sizes) <= 50000, 'No stored INSERT SELECT may copy more than the 50000-row default batch');

	ob_start();
	rebuild_check(syslog_upart_rebuild('syslog', false, true, 'InnoDB', 2, 2, 'hour', 8192), 'A preserving rebuild converts daily partitions to hourly');
	ob_end_clean();
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === (int) $total, 'Every row survives the hourly rebuild');
	rebuild_check(syslog_upart_layout_ok('syslog', 'InnoDB', 'hour') && !syslog_upart_layout_ok('syslog', 'InnoDB', 'day'), 'The replacement has contiguous UTC hourly partitions');
	$now = (int) syslog_db_fetch_cell('SELECT UNIX_TIMESTAMP()');
	$future = syslog_db_fetch_assoc_prepared('SELECT PARTITION_NAME FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE()
		AND TABLE_NAME = \'syslog\' AND PARTITION_DESCRIPTION <> \'MAXVALUE\' AND CAST(PARTITION_DESCRIPTION AS UNSIGNED) > ?', [$now + 7200]);
	$future = array_column($future, 'PARTITION_NAME');
	rebuild_check(count($future) >= 46, 'Hourly partitions exist at least two days ahead');
	$plan = syslog_db_fetch_assoc_prepared('EXPLAIN PARTITIONS SELECT COUNT(*) FROM syslog WHERE logtime >= FROM_UNIXTIME(?) AND logtime < FROM_UNIXTIME(?)',
		[$now - 86400, $now + 1]);
	$scanned = explode(',', (string) ($plan[0]['partitions'] ?? ''));
	rebuild_check($scanned !== [''] && !array_intersect($scanned, array_merge($future, ['dMaxValue'])), 'The automatic upper bound prunes every empty future partition');
	ob_start();
	rebuild_check(syslog_upart_rebuild('syslog', false, true, 'InnoDB', 2, 2, 'day', 8192), 'A preserving rebuild converts hourly partitions back to daily');
	ob_end_clean();
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === (int) $total && syslog_upart_layout_ok('syslog', 'InnoDB', 'day'), 'Every row survives the daily rebuild');
	rebuild_check(syslog_upart_partition_sql(400, 2, null, null, null, 'hour', 8192) === null, 'Hourly plans above the server partition limit are refused');
	ob_start();
	rebuild_check(syslog_upart_run(true, false, false), 'The discard command runs when no table needs a column-type check');
	ob_end_clean();
	rebuild_check((int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog') === 0 && is_free_processing_lock(), 'The discard command empties the rebuilt tables and releases the lock');

	print "$checks partition rebuild database checks passed.\n";
} finally {
	$pdo->exec('DROP DATABASE `syslog_rebuild_test`');
}
