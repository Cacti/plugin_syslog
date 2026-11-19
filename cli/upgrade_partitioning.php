<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

include(__DIR__ . '/../../../include/cli_check.php');
require_once(dirname(__DIR__) . '/setup.php');
require_once(dirname(__DIR__) . '/includes/schema.php');

// process calling arguments
$parms = $_SERVER['argv'];
array_shift($parms);

$confirm = false;
$preserve_data = true;
$convert_ms = false;

foreach ($parms as $arg) {
	switch ($arg) {
		case '--confirm':
			$confirm = true;

			break;
		case '--preserve-data':
			$preserve_data = true;

			break;
		case '--discard-data':
			$preserve_data = false;

			break;
		case '--convert-ms':
			$convert_ms = true;

			break;
		case '--help':
		case '-H':
		case '-h':
			syslog_upart_display_help();
			exit(0);
		default:
			fwrite(STDERR, "ERROR: Unknown argument: $arg" . PHP_EOL);
			syslog_upart_display_help();
			exit(1);
	}
}

if (in_array('--preserve-data', $parms, true) && in_array('--discard-data', $parms, true)) {
	fwrite(STDERR, 'ERROR: --preserve-data and --discard-data cannot be combined.' . PHP_EOL);
	exit(1);
}

syslog_determine_config();
if (!defined('SYSLOG_CONFIG')) {
	fwrite(STDERR, 'ERROR: Syslog database configuration is missing.' . PHP_EOL);
	exit(1);
}

if (!syslog_connect()) {
	fwrite(STDERR, 'ERROR: Unable to connect to the Syslog database.' . PHP_EOL);
	exit(1);
}

exit(syslog_upart_run($confirm, $convert_ms, $preserve_data, defined('SYSLOG_LEGACY_MS_CONVERSION')) ? 0 : 1);

/**
 * Rebuild daily partitions using the configured engine, retention and horizon.
 * DDL is not transactional; failed copies retain originals for a safe retry.
 */
function syslog_upart_run(bool $confirm, bool $convert_ms, bool $preserve_data = true, bool $conversion_only = false): bool {
	global $syslogdb_default;

	$mariadb = syslog_db_is_mariadb();

	if (preg_match('/^[a-zA-Z0-9_]+$/', $syslogdb_default) !== 1) {
		fwrite(STDERR, 'ERROR: Invalid Syslog database name; no changes were made.' . PHP_EOL);

		return false;
	}

	if ($convert_ms && !$mariadb) {
		fwrite(STDERR, 'ERROR: --convert-ms requires MariaDB; MySQL cannot partition-prune fractional timestamps. No changes were made.' . PHP_EOL);

		return false;
	}

	$engine = read_config_option('syslog_install_engine') ?: 'InnoDB';
	$engines = ['innodb' => 'InnoDB', 'aria' => 'Aria'];
	$days = read_config_option('syslog_retention');
	$ahead = syslog_partition_ahead_days();

	if (!isset($engines[strtolower($engine)]) || (strtolower($engine) === 'aria' && !$mariadb)
		|| !ctype_digit((string) $days) || (int) $days > 8184) {
		fwrite(STDERR, 'ERROR: Unsupported storage engine or retention setting; no changes were made.' . PHP_EOL);

		return false;
	}

	$engine = $engines[strtolower($engine)];
	$lock = hash('sha256', $syslogdb_default . '.syslog_upgrade_partitioning');

	if ((int) syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
		fwrite(STDERR, 'ERROR: Cannot acquire the rebuild lock; another rebuild may be running.' . PHP_EOL);

		return false;
	}

	try {
		$tables = [];

		foreach (['syslog', 'syslog_removed', 'syslog_incoming'] as $table) {
			$backup_exists = syslog_db_table_exists($table . '_rebuild', false);

			if ($backup_exists && (!$preserve_data || ($table === 'syslog_incoming' && !$convert_ms))) {
				fwrite(STDERR, "ERROR: {$table}_rebuild exists from an unfinished rebuild. Retry with --preserve-data and the original --convert-ms option, if used. No data was discarded." . PHP_EOL);

				return false;
			}

			if ($backup_exists || (syslog_db_table_exists($table, false)
				&& (!$conversion_only || syslog_detect_logtime_precision($table) !== 'ms')
				&& ($table !== 'syslog_incoming' || ($convert_ms && syslog_detect_logtime_precision($table) !== 'ms')))) {
				$tables[] = $table;
			}
		}

		$outbox = $convert_ms && syslog_db_table_exists('syslog_replication_output', false)
			&& syslog_detect_logtime_precision('syslog_replication_output') !== 'ms';

		if (!$tables && !$outbox) {
			print 'No tables require rebuilding.' . PHP_EOL;

			return true;
		}

		$bytes = syslog_db_fetch_cell_prepared('SELECT SUM(DATA_LENGTH + INDEX_LENGTH)
			FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$syslogdb_default]);

		if (!is_numeric($bytes)) {
			fwrite(STDERR, 'ERROR: Cannot determine the current database size; no tables were changed.' . PHP_EOL);

			return false;
		}

		$size = number_format((float) $bytes / 1073741824, 2) . ' GiB (' . number_format((float) $bytes, 0) . ' bytes)';
		print '================================ WARNING ================================' . PHP_EOL;
		print "Database $syslogdb_default size (estimated data + indexes): $size" . PHP_EOL;
		print "Have AT LEAST another $size free on the DATABASE SERVER during transfer." . PHP_EOL;
		print 'Allow additional headroom for temporary files, indexes and binary logs.' . PHP_EOL;
		print 'Depending on the size of the Syslog tables and storage speed, this can' . PHP_EOL;
		print 'take SEVERAL HOURS. Database metadata is an estimate, not a disk-space check.' . PHP_EOL;
		print "Rebuilding daily partitions: engine=$engine, retention=$days days, ahead=$ahead days." . PHP_EOL;
		print 'Tables: ' . implode(', ', $tables) . ($outbox ? '; replication outbox widened in place' : '') . PHP_EOL;
		print $preserve_data
			? 'Messages are preserved in 10,000-row copies; originals are dropped only after verification.' . PHP_EOL
			: 'DESTRUCTIVE: stored messages in the listed rebuild tables will be DELETED.' . PHP_EOL;
		print $convert_ms ? 'Converting the complete message pipeline to TIMESTAMP(3).' . PHP_EOL
			: 'Existing timestamp precision is preserved.' . PHP_EOL;
		print 'Take a database backup. STOP all processing, partition maintenance,' . PHP_EOL;
		print 'replication and external database writers; buffer new logs upstream.' . PHP_EOL;
		print 'This utility does NOT pause them. Keep them stopped until completion or recovery.' . PHP_EOL;
		print 'Live log tables are temporarily absent or incomplete during rebuilding.' . PHP_EOL;
		print '========================================================================' . PHP_EOL;

		if (!$confirm) {
			print 'Type "yes" to proceed, anything else to abort: ';

			if (strtolower(trim((string) fgets(STDIN))) !== 'yes') {
				print 'Aborted; no tables were changed.' . PHP_EOL;

				return false;
			}
		}

		$zone = syslog_db_fetch_cell('SELECT @@SESSION.time_zone');
		$mode = syslog_db_fetch_cell('SELECT @@SESSION.sql_mode');
		$lock_wait = syslog_db_fetch_cell('SELECT @@SESSION.lock_wait_timeout');

		if (!is_string($zone) || !is_string($mode) || !ctype_digit((string) $lock_wait)) {
			fwrite(STDERR, 'ERROR: Cannot read the database session settings; no tables were changed.' . PHP_EOL);

			return false;
		}

		$lock_wait = (int) $lock_wait;
		$copy_mode = implode(',', array_unique(array_merge(
			array_diff(explode(',', $mode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE', '']),
			['NO_AUTO_VALUE_ON_ZERO']
		)));

		try {
			if (!syslog_db_execute("SET SESSION time_zone = '+00:00'")
				|| !syslog_db_execute_prepared('SET SESSION sql_mode = ?', [$copy_mode])
				|| !syslog_db_execute('SET SESSION lock_wait_timeout = 30')) {
				fwrite(STDERR, 'ERROR: Cannot configure the copy session; no tables were changed.' . PHP_EOL);

				return false;
			}

			foreach ($tables as $table) {
				if (!syslog_upart_rebuild($table, $convert_ms, $preserve_data, $engine, (int) $days, $ahead, $zone)) {
					return false;
				}
			}

			if ($outbox) {
				$column = syslog_logtime_sql_type('ms');

				if (!syslog_db_execute_prepared('SET SESSION time_zone = ?', [$zone])
					|| !syslog_db_execute("ALTER TABLE `$syslogdb_default`.`syslog_replication_output` MODIFY COLUMN logtime $column")) {
					fwrite(STDERR, 'ERROR: Cannot widen the replication outbox; the precision setting was not changed.' . PHP_EOL);

					return false;
				}
			}

			if ($convert_ms) {
				foreach (['syslog', 'syslog_removed', 'syslog_incoming', 'syslog_replication_output'] as $table) {
					if (syslog_db_table_exists($table, false) && syslog_detect_logtime_precision($table) !== 'ms') {
						fwrite(STDERR, "ERROR: $table did not convert; the precision setting was not changed." . PHP_EOL);

						return false;
					}
				}

				set_config_option('syslog_install_logtime_precision', 'ms');
			}
		} finally {
			$restored_zone = syslog_db_execute_prepared('SET SESSION time_zone = ?', [$zone]);
			$restored_mode = syslog_db_execute_prepared('SET SESSION sql_mode = ?', [$mode]);
			$restored_wait = syslog_db_execute("SET SESSION lock_wait_timeout = $lock_wait");

			if (!$restored_zone || !$restored_mode || !$restored_wait) {
				cacti_log('SYSLOG ERROR: Could not restore the rebuild session settings', false, 'SYSLOG');
			}
		}

		cacti_log('SYSLOG: Completed cli/upgrade_partitioning.php' . ($convert_ms ? ' with --convert-ms' : ''), false, 'SYSLOG');
		print 'Rebuild complete.' . ($convert_ms ? ' Syslog now stores millisecond timestamps; configure forwarders accordingly.' : '') . PHP_EOL;

		return true;
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
	}
}

/**
 * Rebuild one message table without dropping its original until data agrees.
 * A retained original is authoritative on retry; existing copied rows are
 * checked, not overwritten, so a failed copy never discards either version.
 */
function syslog_upart_rebuild(string $table, bool $convert_ms, bool $preserve_data, string $engine, int $days, int $ahead, ?string $local_zone = null): bool {
	global $syslogdb_default;

	$backup = $table . '_rebuild';
	$source = syslog_db_table_exists($backup, false) ? $backup : $table;
	$original = "`$syslogdb_default`.`$source`";
	$target = "`$syslogdb_default`.`$table`";
	$saved = "`$syslogdb_default`.`$backup`";

	$fail = static function (string $reason) use ($table, $backup): bool {
		fwrite(STDERR, "ERROR: $table: $reason. Originals are retained in $table or $backup; keep writers stopped and retry with --preserve-data and the same --convert-ms option, if used. The precision setting was not changed." . PHP_EOL);

		return false;
	};

	$columns = syslog_db_fetch_assoc_prepared('SELECT COLUMN_NAME, EXTRA, DATA_TYPE
		FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
		ORDER BY ORDINAL_POSITION', [$syslogdb_default, $source]);
	$keys = syslog_db_fetch_assoc_prepared("SELECT COLUMN_NAME FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'
		ORDER BY SEQ_IN_INDEX", [$syslogdb_default, $source]);
	$next = syslog_db_fetch_cell_prepared('SELECT AUTO_INCREMENT
		FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$syslogdb_default, $source]);
	$triggers = syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TRIGGERS
		WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE IN (?, ?)', [$syslogdb_default, $table, $backup]);
	$foreign_keys = syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
		WHERE REFERENCED_TABLE_NAME IS NOT NULL AND
		((TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?))
		OR (REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IN (?, ?)))',
		[$syslogdb_default, $table, $backup, $syslogdb_default, $table, $backup]);

	if (!is_array($columns) || !$columns || !is_array($keys) || !in_array(array_column($keys, 'COLUMN_NAME'), [['seq'], ['seq', 'logtime']], true)
		|| !ctype_digit((string) $next) || !is_numeric($triggers) || !is_numeric($foreign_keys)) {
		return $fail('Cannot read the table metadata or the primary key is unsupported');
	}

	if ((int) $triggers !== 0 || (int) $foreign_keys !== 0) {
		return $fail('Custom triggers or foreign keys require a manual migration');
	}

	$logtime = array_values(array_filter($columns, static fn($column) => $column['COLUMN_NAME'] === 'logtime'));

	if (!$logtime || !in_array($logtime[0]['DATA_TYPE'], ['timestamp', 'datetime'], true)) {
		return $fail('Unsupported original logtime type');
	}

	// Legacy DATETIME holds wall-clock time; TIMESTAMP must use UTC to avoid
	// duplicate cursor values during a daylight-saving fall-back hour.
	$zone = $logtime[0]['DATA_TYPE'] === 'timestamp' ? '+00:00'
		: ($local_zone ?? syslog_db_fetch_cell('SELECT @@SESSION.time_zone'));

	if (!is_string($zone) || !syslog_db_execute_prepared('SET SESSION time_zone = ?', [$zone])) {
		return $fail('Cannot configure the timestamp copy time zone');
	}

	$precision = $convert_ms ? 'ms' : syslog_detect_logtime_precision($source);
	$partitioned = $table !== 'syslog_incoming';
	$partition_sql = null;

	if (!in_array($precision, ['sec', 'ms'], true)) {
		return $fail('Cannot determine the original timestamp precision');
	}

	if ($partitioned) {
		$unique_keys = syslog_db_fetch_assoc_prepared("SELECT INDEX_NAME,
			GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
			FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 GROUP BY INDEX_NAME",
			[$syslogdb_default, $source]);
		$range = syslog_db_fetch_row("SELECT UNIX_TIMESTAMP(MIN(logtime)) AS first_time,
			UNIX_TIMESTAMP(MAX(logtime)) AS last_time FROM $original WHERE logtime > '1970-01-01 00:00:00'");

		if (!is_array($unique_keys) || !is_array($range) || !array_key_exists('first_time', $range) || !array_key_exists('last_time', $range)) {
			return $fail('Cannot read the unique indexes or timestamp range');
		}

		foreach ($unique_keys as $key) {
			if ($key['INDEX_NAME'] !== 'PRIMARY' && !in_array('logtime', explode(',', $key['columns_list']), true)) {
				return $fail('A custom unique index excludes logtime; partitioning requires a manual migration');
			}
		}

		$partition_sql = syslog_upart_partition_sql($days, $ahead,
			$range['first_time'] === null ? null : (int) $range['first_time'],
			$range['last_time'] === null ? null : (int) $range['last_time']);

		if ($partition_sql === null) {
			return $fail('The requested history and future horizon exceed the 8192-partition limit; reduce retention or archive outlying timestamps first');
		}
	}

	if ($source === $table) {
		if (!syslog_db_execute("RENAME TABLE $target TO $saved")) {
			return $fail('Cannot rename the original table');
		}
	}

	if (!syslog_db_table_exists($table, false)
		&& !syslog_db_execute("CREATE TABLE $target LIKE $saved")) {
		return $fail('Cannot create the replacement table');
	}

	$count = syslog_db_fetch_cell("SELECT COUNT(*) FROM $target");
	$parts = syslog_db_fetch_assoc_prepared('SELECT PARTITION_NAME, PARTITION_DESCRIPTION, PARTITION_EXPRESSION
		FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
		ORDER BY PARTITION_ORDINAL_POSITION', [$syslogdb_default, $table]);

	if (!is_numeric($count) || !is_array($parts) || !$parts) {
		return $fail('Cannot read the replacement row count or partition metadata');
	}

	if ((int) $count === 0) {
		$column = syslog_logtime_sql_type($precision);
		$steps = [];

		if ($partitioned && $parts[0]['PARTITION_NAME'] !== null) {
			$steps[] = "ALTER TABLE $target REMOVE PARTITIONING";
		}

		$steps[] = "ALTER TABLE $target MODIFY COLUMN logtime $column";

		if ($partitioned) {
			$row_format = $engine === 'Aria' ? 'PAGE' : 'DYNAMIC';
			$steps[] = "ALTER TABLE $target ENGINE=$engine ROW_FORMAT=$row_format";

			if (array_column($keys, 'COLUMN_NAME') === ['seq']) {
				$steps[] = "ALTER TABLE $target DROP PRIMARY KEY, ADD PRIMARY KEY (seq, logtime)";
			}

			$steps[] = "ALTER TABLE $target $partition_sql";
		}

		foreach ($steps as $sql) {
			if (!syslog_db_execute($sql)) {
				return $fail('Cannot configure the replacement schema (check the Cacti log)');
			}
		}
	} elseif (!$preserve_data || syslog_detect_logtime_precision($table) !== $precision) {
		return $fail('The nonempty replacement does not match the requested conversion; retry with the original options');
	} elseif ($partitioned) {
		$actual_engine = syslog_db_fetch_cell_prepared('SELECT ENGINE FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$syslogdb_default, $table]);
		$previous = null;

		if ($actual_engine !== $engine || count($parts) < 2) {
			return $fail('The partially copied replacement has a different engine or partition layout');
		}

		foreach ($parts as $index => $part) {
			$expression = strtolower(str_replace(['`', ' '], '', (string) $part['PARTITION_EXPRESSION']));
			$boundary = (string) $part['PARTITION_DESCRIPTION'];

			if ($expression !== 'unix_timestamp(logtime)') {
				return $fail('The partially copied replacement does not use UTC epoch partitioning');
			}

			if ($index === count($parts) - 1) {
				if ($part['PARTITION_NAME'] !== 'dMaxValue' || $boundary !== 'MAXVALUE') {
					return $fail('The partially copied replacement has no final dMaxValue partition');
				}
			} elseif (!ctype_digit($boundary) || (int) $boundary % 86400 !== 0
				|| $part['PARTITION_NAME'] !== 'd' . gmdate('Ymd', (int) $boundary - 86400)
				|| ($previous !== null && (int) $boundary !== $previous + 86400)) {
				return $fail('The partially copied replacement does not have contiguous UTC daily partitions');
			}

			$previous = (int) $boundary;
		}
	}

	$names = [];
	$values = [];
	$equal = [];

	foreach ($columns as $column) {
		$name = '`' . str_replace('`', '``', $column['COLUMN_NAME']) . '`';
		$binary = $column['COLUMN_NAME'] === 'logtime' ? '' : 'BINARY ';
		$equal[] = "({$binary}copied.$name <=> {$binary}source.$name)";

		if (preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i', $column['EXTRA']) !== 1) {
			$names[] = $name;
			$values[] = "source.$name";
		}
	}

	$join = 'copied.seq = source.seq AND copied.logtime = source.logtime';
	$composite = count($keys) === 2;
	$order = $composite ? 'source.seq, source.logtime' : 'source.seq';
	$cursor = null;
	$copied = 0;

	while ($preserve_data) {
		$where = '1 = 1';
		$params = [];

		if ($cursor !== null) {
			$where = $composite ? '(source.seq > ? OR (source.seq = ? AND source.logtime > ?))' : 'source.seq > ?';
			$params = $composite ? [$cursor['seq'], $cursor['seq'], $cursor['logtime']] : [$cursor['seq']];
		}

		$rows = syslog_db_fetch_assoc_prepared("SELECT source.seq, source.logtime FROM $saved AS source
			WHERE $where ORDER BY $order LIMIT 10000", $params);

		if (!is_array($rows)) {
			return $fail('Cannot read the next copy batch');
		}

		if (!$rows) {
			break;
		}

		$last = $rows[count($rows) - 1];
		$upper = $composite ? '(source.seq < ? OR (source.seq = ? AND source.logtime <= ?))' : 'source.seq <= ?';
		$params = array_merge($params, $composite ? [$last['seq'], $last['seq'], $last['logtime']] : [$last['seq']]);
		$names_sql = implode(', ', $names);
		$values_sql = implode(', ', $values);

		if (!syslog_db_execute_prepared("INSERT INTO $target ($names_sql)
			SELECT $values_sql FROM $saved AS source
			LEFT JOIN $target AS copied ON $join
			WHERE $where AND $upper
				AND copied.seq IS NULL ORDER BY $order", $params)) {
			return $fail('Cannot copy the next batch');
		}

		$cursor = $last;
		$copied += count($rows);
		print "$table: checked $copied source rows" . PHP_EOL;

		if (count($rows) < 10000) {
			break;
		}
	}

	$old_count = $preserve_data ? syslog_db_fetch_cell("SELECT COUNT(*) FROM $saved") : 0;
	$new_count = syslog_db_fetch_cell("SELECT COUNT(*) FROM $target");
	$equal_sql = implode(' AND ', $equal);
	$different = $preserve_data ? syslog_db_fetch_cell("SELECT EXISTS (SELECT 1 FROM $saved AS source
		LEFT JOIN $target AS copied ON $join
		WHERE copied.seq IS NULL OR NOT ($equal_sql))") : 0;

	if (!is_numeric($old_count) || !is_numeric($new_count) || (string) $old_count !== (string) $new_count
		|| !is_numeric($different) || (int) $different !== 0
		|| syslog_detect_logtime_precision($table) !== $precision) {
		return $fail('Copied rows, values or timestamp precision do not match');
	}

	if (!syslog_db_execute("ALTER TABLE $target AUTO_INCREMENT = $next")
		|| !syslog_db_execute("DROP TABLE $saved")) {
		return $fail('Cannot restore the sequence high-water mark or drop the verified original');
	}

	print "$table: verified $new_count rows; removed $backup" . PHP_EOL;

	return true;
}

/**
 * Plan UTC daily partitions without deleting out-of-retention rows: the first
 * RANGE partition also accepts older timestamps. Indefinite retention starts
 * at the oldest valid timestamp; future data gets concrete daily partitions.
 */
function syslog_upart_partition_sql(int $days, int $ahead, ?int $first, ?int $last, ?int $now = null): ?string {
	$today = intdiv($now ?? time(), 86400) * 86400;
	$start = $days > 0 ? $today - ($days - 1) * 86400 : min($today, intdiv($first ?? $today, 86400) * 86400);
	$end = max($today + $ahead * 86400, intdiv($last ?? $today, 86400) * 86400);

	if (($end - $start) / 86400 + 2 > 8192) {
		return null;
	}

	$parts = [];

	for ($day = $start; $day <= $end; $day += 86400) {
		$parts[] = 'PARTITION d' . gmdate('Ymd', $day) . ' VALUES LESS THAN (' . ($day + 86400) . ')';
	}

	$parts[] = 'PARTITION dMaxValue VALUES LESS THAN MAXVALUE';

	return 'PARTITION BY RANGE (UNIX_TIMESTAMP(logtime)) (' . implode(', ', $parts) . ')';
}

function syslog_upart_display_help(): void {
	$legacy = defined('SYSLOG_LEGACY_MS_CONVERSION');
	$name = $legacy ? 'updatedb_convert_ms.php' : 'upgrade_partitioning.php';
	print "Usage: php $name " . ($legacy ? '' : '[--convert-ms] ') . '[--preserve-data|--discard-data] [--confirm]' . PHP_EOL;

	if ($legacy) {
		print 'WARNING: Legacy default DELETES messages. Select --preserve-data to keep them.' . PHP_EOL;
		print 'Implies --convert-ms; skips already-converted tables. MariaDB only.' . PHP_EOL;
	} else {
		print '  --convert-ms     Also convert all message pipeline timestamps to TIMESTAMP(3); MariaDB only.' . PHP_EOL;
	}

	print '  --preserve-data  Preserve existing messages' . ($legacy ? '' : ' (the default)') . ', copying 10,000 rows at a time.' . PHP_EOL;
	print '  --discard-data   Delete messages in rebuilt tables; the replication outbox is never discarded.' . PHP_EOL;
	print '  --confirm        Skip the confirmation prompt, not the size and maintenance warnings.' . PHP_EOL;
	print '  --help           Display this help.' . PHP_EOL;
	print PHP_EOL;
	print 'Rebuilds syslog and syslog_removed with UTC daily partitions matching the' . PHP_EOL;
	print 'configured storage engine, message retention and future-partition horizon.' . PHP_EOL;
	print 'Existing precision is preserved unless --convert-ms is selected.' . PHP_EOL;
	print 'With preservation, originals are dropped only after row counts and values are verified.' . PHP_EOL;
	print 'On failure keep writers stopped and retry with --preserve-data and the' . PHP_EOL;
	print 'same --convert-ms option, if used; originals remain in *_rebuild tables.' . PHP_EOL;
	print 'Take a database backup and stop ALL processing and database writers first.' . PHP_EOL;
	print 'The current database size is reported before changes. Have at least that' . PHP_EOL;
	print 'much ADDITIONAL free disk space on the database server, plus temporary' . PHP_EOL;
	print 'file and binary-log headroom. Large transfers may take several hours.' . PHP_EOL;
}
