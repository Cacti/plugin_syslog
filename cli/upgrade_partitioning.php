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
$partition = null;
$source_zone = null;

foreach ($parms as $arg) {
	if (strpos($arg, '--source-time-zone=') === 0) {
		$source_zone = substr($arg, 19);

		if ($source_zone === '') {
			fwrite(STDERR, 'ERROR: --source-time-zone needs a time zone name such as America/New_York.' . PHP_EOL);
			exit(1);
		}

		continue;
	}

	if (strpos($arg, '--partition=') === 0) {
		$levels = ['hourly' => 'hour', 'hour' => 'hour', 'daily' => 'day', 'day' => 'day'];
		$partition = $levels[strtolower(substr($arg, 12))] ?? null;

		if ($partition === null) {
			fwrite(STDERR, 'ERROR: --partition must be hourly or daily.' . PHP_EOL);
			exit(1);
		}

		continue;
	}

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
		case '--version':
		case '-V':
		case '-v':
			syslog_upart_display_version();
			exit(0);
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

// Never repair the schema here: an interrupted rebuild leaves the message
// tables renamed, and repair would recreate them empty.
define('SYSLOG_DEFER_SCHEMA_REPAIR', true);

if (!syslog_connect()) {
	fwrite(STDERR, 'ERROR: Unable to connect to the Syslog database.' . PHP_EOL);
	exit(1);
}

exit(syslog_upart_run($confirm, $convert_ms, $preserve_data, $partition, $source_zone) ? 0 : 1);

/**
 * Rebuild partitions using the configured level, engine, retention and horizon.
 * DDL is not transactional; failed copies retain originals for a safe retry.
 *
 * @param string|null $partition   'hour' or 'day' from --partition, saved to
 *                                 Settings once confirmed; null uses Settings.
 * @param string|null $source_zone The zone legacy DATETIME logtime values were
 *                                 written in (--source-time-zone); null refuses them.
 */
function syslog_upart_run(bool $confirm, bool $convert_ms, bool $preserve_data = true, ?string $partition = null,
	?string $source_zone = null): bool {
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
	$granularity = $partition ?? syslog_partition_requested_granularity();
	$ahead = syslog_partition_ahead_days_for($granularity);
	$limit = syslog_partition_server_limit();

	if (!isset($engines[strtolower($engine)]) || (strtolower($engine) === 'aria' && !$mariadb)
		|| !ctype_digit((string) $days) || (int) $days > 8184) {
		fwrite(STDERR, 'ERROR: Unsupported storage engine or retention setting; no changes were made.' . PHP_EOL);

		return false;
	}

	$level_error = syslog_partition_settings_error($granularity, $days, syslog_partition_ahead_days(), $limit);

	if ($level_error !== '') {
		fwrite(STDERR, "ERROR: $level_error No changes were made." . PHP_EOL);

		return false;
	}

	// Writers would interleave with copied rows and break verification, so
	// processing must be disabled, and the processing lock that
	// syslog_process.php also takes is held for the duration. Unlike Cacti's
	// process registry, the lock never takes over or terminates a live run.
	if ((string) read_config_option('syslog_enabled', true) !== '') {
		fwrite(STDERR, 'ERROR: Syslog processing is enabled. Uncheck Settings > Syslog > Syslog Enabled, stop remote collectors and any other Syslog database writers, then retry. No changes were made.' . PHP_EOL);

		return false;
	}

	$lock = syslog_processing_lock_name();

	if ((int) syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
		fwrite(STDERR, 'ERROR: A Syslog processing run or another rebuild is still active; wait for it to finish, then retry. No changes were made.' . PHP_EOL);

		return false;
	}

	try {
		// Workers launched before the lock was taken may still be writing;
		// later ones see the lock is no longer their master's and exit.
		$workers = syslog_upart_live_workers();

		if ($workers > 0) {
			fwrite(STDERR, "ERROR: $workers Syslog transfer worker(s) are still running; wait for them to finish, then retry. No changes were made." . PHP_EOL);

			return false;
		}

		return syslog_upart_run_locked($confirm, $convert_ms, $preserve_data, $engines[strtolower($engine)], (int) $days, $ahead, $granularity, $limit, $partition !== null, $source_zone);
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock]);
	}
}

/**
 * Transfer workers registered in Cacti's process table whose process is
 * still alive. Rows left by a crashed worker are ignored.
 *
 * @return int The number of live workers.
 */
function syslog_upart_live_workers(): int {
	$live = 0;

	foreach ((array) db_fetch_assoc("SELECT pid FROM processes WHERE tasktype = 'syslog' AND taskname = 'child'", false) as $row) {
		$pid = (int) ($row['pid'] ?? 0);

		// EPERM (1) means the process exists under another user.
		if ($pid > 0 && (!function_exists('posix_kill') || posix_kill($pid, 0) || posix_get_last_error() === 1)) {
			$live++;
		}
	}

	return $live;
}

/**
 * Validate --source-time-zone before anything changes. The zone must be an
 * IANA name known to PHP (and so to Cacti) and loaded in the Syslog database
 * server's time zone tables. PHP and the database must agree on its UTC offset
 * at every transition spanning the stored history, so neither side's zone data
 * is stale. Every legacy DATETIME value must exist in the zone (not fall in a
 * daylight-saving gap) and fit the TIMESTAMP range.
 *
 * @param string   $zone   The zone the legacy DATETIME values were written in.
 * @param string[] $tables Tables whose logtime column is DATETIME.
 *
 * @return string An error message, or '' when the zone may be used.
 */
function syslog_upart_source_zone_error(string $zone, array $tables): string {
	global $syslogdb_default, $config;

	if (!in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
		return "--source-time-zone=$zone is not a time zone known to PHP and Cacti; use an IANA name such as America/New_York.";
	}

	$known = syslog_db_fetch_cell_prepared("SELECT CONVERT_TZ('2000-01-01 00:00:00', '+00:00', ?) IS NOT NULL", [$zone]);

	if ((string) $known !== '1') {
		return "The Syslog database server does not know time zone $zone. Load its time zone tables, as the Cacti installer requires (for example: mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb mysql), then retry.";
	}

	$tz = new DateTimeZone($zone);
	$now = time();
	$low = $now;
	$high = $now;

	foreach ($tables as $table) {
		$range = syslog_db_fetch_row("SELECT MIN(logtime) AS first_time, MAX(logtime) AS last_time
			FROM `$syslogdb_default`.`$table` WHERE logtime > '1970-01-01 00:00:00'");

		if (!is_array($range) || !array_key_exists('first_time', $range)) {
			return "Cannot read the logtime range of $table.";
		}

		if ($range['first_time'] !== null) {
			$low = min($low, (new DateTime((string) $range['first_time'], $tz))->getTimestamp() - 86400);
			$high = max($high, (new DateTime((string) $range['last_time'], $tz))->getTimestamp() + 86400);
		}
	}

	// CONVERT_TZ leaves values outside the TIMESTAMP range unconverted.
	$low = max(1, $low);
	$high = min(2147483647, $high);
	$transitions = $tz->getTransitions($low, $high) ?: [];
	$probes = [$now];

	foreach ($transitions as $transition) {
		$probes[] = $transition['ts'] - 1;
		$probes[] = $transition['ts'];
	}

	foreach (array_unique($probes) as $probe) {
		if ($probe < 1 || $probe > 2147483647) {
			continue;
		}

		$utc = gmdate('Y-m-d H:i:s', $probe);
		$expected = (new DateTime('@' . $probe))->setTimezone($tz)->format('Y-m-d H:i:s');
		$actual = syslog_db_fetch_cell_prepared("SELECT CONVERT_TZ(?, '+00:00', ?)", [$utc, $zone]);

		if ((string) $actual !== $expected) {
			return "PHP and the Syslog database disagree on $zone at $utc UTC (PHP $expected, database " . var_export($actual, true)
				. '). Update the database time zone tables or PHP time zone data so they match, then retry.';
		}
	}

	$previous = syslog_db_fetch_cell('SELECT @@SESSION.time_zone');

	if (!is_string($previous) || !syslog_db_execute_prepared('SET SESSION time_zone = ?', [$zone])) {
		return "Cannot set the session time zone to $zone.";
	}

	$ambiguous = 0;

	try {
		foreach ($tables as $table) {
			// Values in a daylight-saving gap or outside the TIMESTAMP range do not round-trip.
			$invalid = syslog_db_fetch_row("SELECT COUNT(*) AS invalid_rows, MIN(logtime) AS sample
				FROM `$syslogdb_default`.`$table` WHERE logtime <> '0000-00-00 00:00:00'
				AND NOT (FROM_UNIXTIME(UNIX_TIMESTAMP(logtime)) <=> logtime)");

			if (!is_array($invalid) || !isset($invalid['invalid_rows'])) {
				return "Cannot check the logtime values of $table.";
			}

			if ((int) $invalid['invalid_rows'] > 0) {
				return "$table has {$invalid['invalid_rows']} logtime values, such as {$invalid['sample']}, that do not exist in $zone "
					. "(a daylight-saving gap) or fall outside the TIMESTAMP range. $zone is probably not the zone they were written in; otherwise correct or delete those rows, then retry.";
			}

			// When clocks fall back, the repeated wall-clock hour is ambiguous.
			foreach ($transitions as $index => $transition) {
				$offset = $index > 0 ? $transitions[$index - 1]['offset'] : $transition['offset'];

				if ($transition['offset'] >= $offset) {
					continue;
				}

				$start = (new DateTime('@' . $transition['ts']))->setTimezone($tz);
				$end = (clone $start)->modify('+' . ($offset - $transition['offset']) . ' seconds');
				$count = syslog_db_fetch_cell_prepared("SELECT COUNT(*) FROM `$syslogdb_default`.`$table`
					WHERE logtime >= ? AND logtime < ?", [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);

				if (!is_numeric($count)) {
					return "Cannot check the logtime values of $table.";
				}

				$ambiguous += (int) $count;
			}
		}
	} finally {
		if (!syslog_db_execute_prepared('SET SESSION time_zone = ?', [$previous])) {
			cacti_log('SYSLOG ERROR: Could not restore the session time zone after checking --source-time-zone', false, 'SYSLOG');
		}
	}

	$cacti_zones = [date_default_timezone_get()];

	if (db_column_exists('poller', 'timezone')) {
		$cacti_zones[] = (string) db_fetch_cell_prepared('SELECT timezone FROM poller WHERE id = ?', [$config['poller_id'] ?? 1]);
	}

	$cacti_zones = array_values(array_unique(array_filter($cacti_zones, static fn($name) => $name !== '')));

	print "Source time zone $zone: known to PHP and the Syslog database, which agree on its offsets; every DATETIME value in "
		. implode(', ', $tables) . ' exists in it.' . PHP_EOL;

	if ($ambiguous > 0) {
		print "NOTE: $ambiguous rows fall in repeated daylight-saving hours; each converts to one of its two possible instants." . PHP_EOL;
	}

	if (!in_array($zone, $cacti_zones, true)) {
		print 'WARNING: Cacti uses time zone ' . implode(' and ', $cacti_zones) . ", not $zone. Confirm the legacy values were written in $zone." . PHP_EOL;
	}

	return '';
}

/**
 * Rebuild while holding the Syslog processing lock.
 */
function syslog_upart_run_locked(bool $confirm, bool $convert_ms, bool $preserve_data, string $engine, int $days, int $ahead,
	string $granularity = 'day', int $limit = 8192, bool $save_level = false, ?string $source_zone = null): bool {
	global $syslogdb_default;

	if (syslog_db_table_exists('syslog_incoming_rebuild', false)) {
		fwrite(STDERR, 'ERROR: syslog_incoming_rebuild was left by an earlier development build. Restore it as described in the README, then retry. No changes were made.' . PHP_EOL);

		return false;
	}

	$tables = [];

	foreach (['syslog', 'syslog_removed'] as $table) {
		$backup_exists = syslog_db_table_exists($table . '_rebuild', false);

		if ($backup_exists && !$preserve_data) {
			fwrite(STDERR, "ERROR: {$table}_rebuild exists from an unfinished rebuild. Retry with --preserve-data and the original --convert-ms option, if used. No data was discarded." . PHP_EOL);

			return false;
		}

		if ($backup_exists || syslog_db_table_exists($table, false)) {
			$tables[] = $table;
		}
	}

	// The incoming queue and replication outbox keep their sequence identities
	// (archival and delivery deduplicate on them), so they are widened in place.
	$widen = [];

	foreach (['syslog_incoming', 'syslog_replication_output'] as $table) {
		if ($convert_ms && syslog_db_table_exists($table, false) && syslog_detect_logtime_precision($table) !== 'ms') {
			$widen[] = $table;
		}
	}

	if (!$tables && !$widen) {
		print 'No tables require rebuilding.' . PHP_EOL;

		return true;
	}

	// Legacy DATETIME history is wall-clock time; it is preserved only when
	// --source-time-zone names the zone it was written in.
	$checked = $widen;

	foreach ($tables as $table) {
		if ($preserve_data) {
			$checked[] = $table;
			$checked[] = $table . '_rebuild';
		}
	}

	// A discard with nothing to widen checks no tables; IN () is invalid SQL.
	$legacy = !$checked ? [] : syslog_db_fetch_assoc_prepared('SELECT TABLE_NAME FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = \'logtime\' AND DATA_TYPE = \'datetime\'
		AND TABLE_NAME IN (' . implode(', ', array_fill(0, count($checked), '?')) . ')',
		array_merge([$syslogdb_default], $checked));

	if (!is_array($legacy)) {
		fwrite(STDERR, 'ERROR: Cannot read the logtime column types; no tables were changed.' . PHP_EOL);

		return false;
	}

	$legacy = array_column($legacy, 'TABLE_NAME');

	if ($legacy && $source_zone === null) {
		fwrite(STDERR, 'ERROR: Legacy DATETIME logtime columns found: ' . implode(', ', $legacy)
			. '. Preserve them with --source-time-zone=<zone>, naming the zone their values were written in, or rebuild stored and removed history with --discard-data. No changes were made.' . PHP_EOL);

		return false;
	}

	if (!$legacy && $source_zone !== null) {
		print 'No legacy DATETIME logtime columns were found; --source-time-zone is ignored.' . PHP_EOL;
		$source_zone = null;
	}

	// A resumed copy must read the retained DATETIME original in the same zone.
	$zone_setting = 'syslog_upart_source_time_zone';
	$resumed = array_values(array_filter($legacy, static fn($table) => substr($table, -8) === '_rebuild'));
	$previous_zone = $resumed ? (string) read_config_option($zone_setting, true) : '';

	if ($previous_zone !== '' && $previous_zone !== $source_zone) {
		fwrite(STDERR, 'ERROR: ' . implode(', ', $resumed) . " was being copied from time zone $previous_zone. Retry with --source-time-zone=$previous_zone. No changes were made." . PHP_EOL);

		return false;
	}

	if ($legacy) {
		$zone_error = syslog_upart_source_zone_error($source_zone, $legacy);

		if ($zone_error !== '') {
			fwrite(STDERR, "ERROR: $zone_error No changes were made." . PHP_EOL);

			return false;
		}
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
	print 'Rebuilding ' . ($granularity === 'hour' ? 'hourly' : 'daily') . " partitions: engine=$engine, retention=$days days, ahead=$ahead days." . PHP_EOL;
	print 'Tables: ' . implode(', ', $tables) . ($widen ? '; widened in place: ' . implode(', ', $widen) : '') . PHP_EOL;
	print $preserve_data
		? 'Messages are preserved in verified 50,000-row copies and receive new sequence numbers; originals are dropped only after verification.' . PHP_EOL
		: 'DESTRUCTIVE: stored messages in the listed rebuild tables will be DELETED.' . PHP_EOL;
	print $convert_ms ? 'Converting the complete message pipeline to TIMESTAMP(3).' . PHP_EOL
		: 'Existing timestamp precision is preserved.' . PHP_EOL;

	if ($source_zone !== null) {
		print 'Legacy DATETIME values in ' . implode(', ', $legacy) . " are read as $source_zone wall-clock time." . PHP_EOL;
	}

	print 'Take a database backup. Syslog processing is disabled and its lock' . PHP_EOL;
	print 'is held, but this utility does NOT pause remote collectors, replication or' . PHP_EOL;
	print 'external database writers; stop them and buffer new logs upstream.' . PHP_EOL;
	print 'Keep them stopped until completion or recovery.' . PHP_EOL;
	print 'Live log tables are temporarily absent or incomplete during rebuilding.' . PHP_EOL;
	print '========================================================================' . PHP_EOL;

	if (!$confirm) {
		print 'Type "yes" to proceed, anything else to abort: ';

		if (strtolower(trim((string) fgets(STDIN))) !== 'yes') {
			print 'Aborted; no tables were changed.' . PHP_EOL;

			return false;
		}
	}

	// A retry must rebuild to the same level, so --partition is saved first.
	if ($save_level) {
		set_config_option('syslog_partition_granularity', $granularity);
	}

	if ($source_zone !== null) {
		set_config_option($zone_setting, $source_zone);
	}

	$zone = syslog_db_fetch_cell('SELECT @@SESSION.time_zone');
	$mode = syslog_db_fetch_cell('SELECT @@SESSION.sql_mode');
	$lock_wait = syslog_db_fetch_cell('SELECT @@SESSION.lock_wait_timeout');

	if (!is_string($zone) || !is_string($mode) || !ctype_digit((string) $lock_wait)) {
		fwrite(STDERR, 'ERROR: Cannot read the database session settings; no tables were changed.' . PHP_EOL);

		return false;
	}

	$lock_wait = (int) $lock_wait;
	$copy_mode = implode(',', array_diff(explode(',', $mode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE', '']));

	try {
		if (!syslog_db_execute("SET SESSION time_zone = '+00:00'")
			|| !syslog_db_execute_prepared('SET SESSION sql_mode = ?', [$copy_mode])
			|| !syslog_db_execute('SET SESSION lock_wait_timeout = 30')) {
			fwrite(STDERR, 'ERROR: Cannot configure the copy session; no tables were changed.' . PHP_EOL);

			return false;
		}

		foreach ($tables as $table) {
			if (!syslog_upart_rebuild($table, $convert_ms, $preserve_data, $engine, $days, $ahead, $granularity, $limit, 50000, $source_zone)) {
				return false;
			}
		}

		$column = syslog_logtime_sql_type('ms');

		if ($source_zone !== null && !syslog_db_execute_prepared('SET SESSION time_zone = ?', ['+00:00'])) {
			fwrite(STDERR, 'ERROR: Cannot reset the copy session time zone; the precision setting was not changed.' . PHP_EOL);

			return false;
		}

		foreach ($widen as $table) {
			if (!syslog_upart_widen($table, $column, in_array($table, $legacy, true) ? $source_zone : null)) {
				fwrite(STDERR, "ERROR: Cannot widen $table; the precision setting was not changed." . PHP_EOL);

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

	if ($source_zone !== null) {
		set_config_option($zone_setting, '');
	}

	cacti_log('SYSLOG: Completed cli/upgrade_partitioning.php' . ($convert_ms ? ' with --convert-ms' : '')
		. ($source_zone !== null ? " from DATETIME in $source_zone" : ''), false, 'SYSLOG');
	print 'Rebuild complete. Re-enable Syslog processing.' . ($convert_ms ? ' Syslog now stores millisecond timestamps; configure forwarders accordingly.' : '') . PHP_EOL;

	return true;
}

/**
 * Widen an incoming queue or outbox logtime column in place, so the sequence
 * identities that archival and replication rely on are kept. A legacy
 * DATETIME column is converted in its source zone, and its wall-clock values
 * are verified unchanged when read back in that zone.
 *
 * @param string      $table       The table to widen.
 * @param string      $column      The new logtime column definition.
 * @param string|null $source_zone The zone of a legacy DATETIME column, or null.
 */
function syslog_upart_widen(string $table, string $column, ?string $source_zone): bool {
	global $syslogdb_default;

	$target = "`$syslogdb_default`.`$table`";

	if ($source_zone === null) {
		return syslog_db_execute("ALTER TABLE $target MODIFY COLUMN logtime $column");
	}

	$digest = "SELECT COUNT(*) AS row_count, SUM(CRC32(DATE_FORMAT(logtime, '%Y-%m-%d %H:%i:%s'))) AS digest FROM $target";

	if (!syslog_db_execute_prepared('SET SESSION time_zone = ?', [$source_zone])) {
		return false;
	}

	try {
		$before = syslog_db_fetch_row($digest);

		if (!is_array($before) || !isset($before['row_count'])
			|| !syslog_db_execute("ALTER TABLE $target MODIFY COLUMN logtime $column")) {
			return false;
		}

		$after = syslog_db_fetch_row($digest);

		return is_array($after) && (string) ($after['row_count'] ?? '') === (string) $before['row_count']
			&& (string) ($after['digest'] ?? '') === (string) $before['digest'];
	} finally {
		syslog_db_execute("SET SESSION time_zone = '+00:00'");
	}
}

/**
 * Rebuild one message table without dropping its original until data agrees.
 *
 * Rows are copied without seq, in original order, so the replacement numbers
 * them from its own AUTO_INCREMENT, which starts above the original high-water
 * mark: a sequence value is never reused. Each batch, 50,000 rows by default,
 * is checksummed against its source range before a checkpoint records it. A
 * retry discards replacement rows above the last checkpoint and resumes after it.
 * At 10M rows on a 2 GiB buffer pool, batches of 10,000 to 65,536 rows ran
 * within measurement noise of each other; 50,000 was fastest.
 *
 * A legacy DATETIME original is copied with the session in $source_zone, so its
 * wall-clock values become the matching UTC instants, and each batch checksum
 * compares the same wall-clock text on both sides. TIMESTAMP originals are
 * copied in UTC, which keeps daylight-saving repeated hours distinct.
 */
function syslog_upart_rebuild(string $table, bool $convert_ms, bool $preserve_data, string $engine, int $days, int $ahead,
	string $granularity = 'day', int $limit = 8192, int $batch = 50000, ?string $source_zone = null): bool {
	global $syslogdb_default;

	$batch = max(1, $batch);

	$backup = $table . '_rebuild';
	$resume = syslog_db_table_exists($backup, false);
	$source = $resume ? $backup : $table;
	$original = "`$syslogdb_default`.`$source`";
	$target = "`$syslogdb_default`.`$table`";
	$saved = "`$syslogdb_default`.`$backup`";
	$progress = "`$syslogdb_default`.`syslog_rebuild_progress`";

	$fail = static function (string $reason) use ($table, $backup): bool {
		fwrite(STDERR, "ERROR: $table: $reason. Originals are retained in $table or $backup; keep writers stopped and retry with --preserve-data and the same --convert-ms and --source-time-zone options, if used. The precision setting was not changed." . PHP_EOL);

		return false;
	};

	$columns = syslog_db_fetch_assoc_prepared('SELECT COLUMN_NAME, EXTRA, DATA_TYPE
		FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
		ORDER BY ORDINAL_POSITION', [$syslogdb_default, $source]);
	$keys = syslog_db_fetch_assoc_prepared("SELECT COLUMN_NAME FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'
		ORDER BY SEQ_IN_INDEX", [$syslogdb_default, $source]);
	$triggers = syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TRIGGERS
		WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE IN (?, ?)', [$syslogdb_default, $table, $backup]);
	$foreign_keys = syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
		WHERE REFERENCED_TABLE_NAME IS NOT NULL AND
		((TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?))
		OR (REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IN (?, ?)))',
		[$syslogdb_default, $table, $backup, $syslogdb_default, $table, $backup]);

	if (!is_array($columns) || !$columns || !is_array($keys) || !in_array(array_column($keys, 'COLUMN_NAME'), [['seq'], ['seq', 'logtime']], true)
		|| !is_numeric($triggers) || !is_numeric($foreign_keys)) {
		return $fail('Cannot read the table metadata or the primary key is unsupported');
	}

	if ((int) $triggers !== 0 || (int) $foreign_keys !== 0) {
		return $fail('Custom triggers or foreign keys require a manual migration');
	}

	$logtime = array_values(array_filter($columns, static fn($column) => $column['COLUMN_NAME'] === 'logtime'));
	$type = $logtime ? $logtime[0]['DATA_TYPE'] : '';

	if ($type === 'datetime' && $preserve_data && $source_zone === null) {
		return $fail('Legacy DATETIME logtime needs --source-time-zone to be preserved; otherwise rebuild with --discard-data');
	}

	if (!in_array($type, ['timestamp', 'datetime'], true)) {
		return $fail('Unsupported original logtime type');
	}

	// TIMESTAMP is stored in UTC; a UTC session copies it without conversion.
	$copy_zone = $type === 'datetime' && $source_zone !== null ? $source_zone : '+00:00';

	if (!syslog_db_execute_prepared('SET SESSION time_zone = ?', [$copy_zone])) {
		return $fail('Cannot configure the timestamp copy time zone');
	}

	$precision = $convert_ms ? 'ms' : syslog_detect_logtime_precision($source);

	if (!in_array($precision, ['sec', 'ms'], true)) {
		return $fail('Cannot determine the original timestamp precision');
	}

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
		$range['last_time'] === null ? null : (int) $range['last_time'], null, $granularity, $limit);

	if ($partition_sql === null) {
		return $fail("The requested history and future horizon exceed the server's $limit-partition limit; reduce retention, choose daily partitioning or archive outlying timestamps first");
	}

	// information_schema may cache AUTO_INCREMENT, so MAX(seq) is the floor.
	$next = syslog_db_fetch_cell_prepared("SELECT GREATEST(CAST(COALESCE(MAX(seq), 0) AS DECIMAL(21,0)) + 1,
		CAST(COALESCE((SELECT AUTO_INCREMENT FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?), 1) AS DECIMAL(21,0))) FROM $original", [$syslogdb_default, $source]);

	if (!is_numeric($next) || !ctype_digit((string) $next)) {
		return $fail('Cannot read the sequence high-water mark');
	}

	if (!$resume) {
		$rows = $preserve_data ? syslog_db_fetch_cell("SELECT COUNT(*) FROM $original") : 0;
		$room = is_numeric($rows) ? syslog_db_fetch_cell_prepared('SELECT CAST(? AS DECIMAL(21,0)) + CAST(? AS DECIMAL(21,0)) <= 18446744073709551615',
			[$next, $rows]) : false;

		if ((string) $room !== '1') {
			return $fail('Too few sequence values remain above the high-water mark to renumber every row');
		}

		if (!syslog_db_execute("RENAME TABLE $target TO $saved")) {
			return $fail('Cannot rename the original table');
		}
	}

	$state = $preserve_data && syslog_db_table_exists('syslog_rebuild_progress', false)
		? syslog_db_fetch_row_prepared("SELECT source_seq, source_logtime, target_seq, copied FROM $progress WHERE table_name = ?", [$table])
		: [];

	if (!is_array($state)) {
		return $fail('Cannot read the copy checkpoint');
	}

	// A replacement without a checkpoint was interrupted during setup.
	if (syslog_db_table_exists($table, false) && !$state) {
		$count = syslog_db_fetch_cell("SELECT COUNT(*) FROM $target");

		if (!is_numeric($count) || (int) $count !== 0) {
			return $fail('The replacement has rows but no copy checkpoint; inspect it, drop it, and retry');
		}

		if (!syslog_db_execute("DROP TABLE $target")) {
			return $fail('Cannot drop the incomplete replacement');
		}
	}

	if (!syslog_db_table_exists($table, false)) {
		if (!syslog_db_execute("CREATE TABLE $target LIKE $saved")) {
			return $fail('Cannot create the replacement table');
		}

		$parts = syslog_db_fetch_assoc_prepared('SELECT PARTITION_NAME FROM information_schema.PARTITIONS
			WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$syslogdb_default, $table]);

		if (!is_array($parts) || !$parts) {
			return $fail('Cannot read the replacement partition metadata');
		}

		$column = syslog_logtime_sql_type($precision);
		$row_format = $engine === 'Aria' ? 'PAGE' : 'DYNAMIC';
		$steps = [];

		if ($parts[0]['PARTITION_NAME'] !== null) {
			$steps[] = "ALTER TABLE $target REMOVE PARTITIONING";
		}

		$steps[] = "ALTER TABLE $target MODIFY COLUMN logtime $column";
		$steps[] = "ALTER TABLE $target ENGINE=$engine ROW_FORMAT=$row_format";

		if (array_column($keys, 'COLUMN_NAME') === ['seq']) {
			$steps[] = "ALTER TABLE $target DROP PRIMARY KEY, ADD PRIMARY KEY (seq, logtime)";
		}

		$steps[] = "ALTER TABLE $target $partition_sql";

		// Before any row arrives, so copied rows never reuse an original seq.
		$steps[] = "ALTER TABLE $target AUTO_INCREMENT = $next";

		foreach ($steps as $sql) {
			if (!syslog_db_execute($sql)) {
				return $fail('Cannot configure the replacement schema (check the Cacti log)');
			}
		}

		if ($preserve_data) {
			$state = ['source_seq' => null, 'source_logtime' => null, 'target_seq' => '0', 'copied' => '0'];

			if (!syslog_db_execute("CREATE TABLE IF NOT EXISTS $progress (
					table_name varchar(64) NOT NULL PRIMARY KEY,
					source_seq bigint unsigned NULL,
					source_logtime varchar(32) NULL,
					target_seq bigint unsigned NOT NULL,
					copied bigint unsigned NOT NULL) ENGINE=InnoDB")
				|| !syslog_db_execute_prepared("REPLACE INTO $progress VALUES (?, NULL, NULL, 0, 0)", [$table])) {
				return $fail('Cannot record the copy checkpoint');
			}
		}
	} elseif (syslog_detect_logtime_precision($table) !== $precision) {
		return $fail('The partially copied replacement does not match the requested conversion; retry with the original options');
	} elseif (!syslog_upart_layout_ok($table, $engine, $granularity)) {
		return $fail('The partially copied replacement has a different engine or partition layout');
	}

	$names = [];
	$hash = [];

	foreach ($columns as $column) {
		if ($column['COLUMN_NAME'] === 'seq' || preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i', $column['EXTRA']) === 1) {
			continue;
		}

		$name = '`' . str_replace('`', '``', $column['COLUMN_NAME']) . '`';
		$value = $column['COLUMN_NAME'] === 'logtime' ? "DATE_FORMAT($name, '%Y-%m-%d %H:%i:%s.%f')" : "CAST($name AS BINARY)";
		$names[] = $name;
		$hash[] = "IFNULL(CONCAT(OCTET_LENGTH($value), ':', $value), '-')";
	}

	$names_sql = implode(', ', $names);
	$digest = 'MD5(CONCAT(' . implode(', ', $hash) . '))';
	$checksum = 'COUNT(*) AS row_count, SUM(CAST(CONV(LEFT(digest, 15), 16, 10) AS UNSIGNED)) AS digest_sum,
		BIT_XOR(CAST(CONV(RIGHT(digest, 16), 16, 10) AS UNSIGNED)) AS digest_xor';
	$composite = count($keys) === 2;
	$order = $composite ? 'source.seq, source.logtime' : 'source.seq';
	$reverse = $composite ? 'source.seq DESC, source.logtime DESC' : 'source.seq DESC';
	$cursor = $state && $state['source_seq'] !== null ? ['seq' => $state['source_seq'], 'logtime' => $state['source_logtime']] : null;
	$high = $state ? (string) $state['target_seq'] : '0';
	$copied = $state ? (int) $state['copied'] : 0;

	if ($preserve_data && !syslog_db_execute_prepared("DELETE FROM $target WHERE seq > ?", [$high])) {
		return $fail('Cannot discard unverified rows copied after the last checkpoint');
	}

	$batches = 0;

	while ($preserve_data) {
		$where = '1 = 1';
		$params = [];

		if ($cursor !== null) {
			$where = $composite ? '(source.seq > ? OR (source.seq = ? AND source.logtime > ?))' : 'source.seq > ?';
			$params = $composite ? [$cursor['seq'], $cursor['seq'], $cursor['logtime']] : [$cursor['seq']];
		}

		$last = syslog_db_fetch_row_prepared("SELECT source.seq, source.logtime FROM $saved AS source
			WHERE $where ORDER BY $order LIMIT 1 OFFSET " . ($batch - 1), $params);
		$final = $last === [];

		if ($final) {
			$last = syslog_db_fetch_row_prepared("SELECT source.seq, source.logtime FROM $saved AS source
				WHERE $where ORDER BY $reverse LIMIT 1", $params);
		}

		if (!is_array($last)) {
			return $fail('Cannot read the next copy batch');
		}

		if (!$last) {
			break;
		}

		$where .= $composite ? ' AND (source.seq < ? OR (source.seq = ? AND source.logtime <= ?))' : ' AND source.seq <= ?';
		$params = array_merge($params, $composite ? [$last['seq'], $last['seq'], $last['logtime']] : [$last['seq']]);

		if (!syslog_db_execute_prepared("INSERT INTO $target ($names_sql)
			SELECT $names_sql FROM $saved AS source WHERE $where ORDER BY $order", $params)) {
			return $fail('Cannot copy the next batch');
		}

		$expected = syslog_db_fetch_row_prepared("SELECT $checksum FROM (SELECT $digest AS digest
			FROM $saved AS source WHERE $where) AS batch", $params);
		$actual = syslog_db_fetch_row_prepared("SELECT $checksum, MAX(seq) AS high FROM (SELECT seq, $digest AS digest
			FROM $target WHERE seq > ?) AS batch", [$high]);

		if (!is_array($expected) || !is_array($actual) || !isset($expected['row_count'], $actual['row_count'])
			|| (int) $expected['row_count'] === 0
			|| (string) $expected['row_count'] !== (string) $actual['row_count']
			|| (string) $expected['digest_sum'] !== (string) $actual['digest_sum']
			|| (string) $expected['digest_xor'] !== (string) $actual['digest_xor']) {
			return $fail('A copied batch does not match its original rows');
		}

		$high = (string) $actual['high'];
		$copied += (int) $expected['row_count'];
		$cursor = $last;

		if (!syslog_db_execute_prepared("REPLACE INTO $progress VALUES (?, ?, ?, ?, ?)",
			[$table, $cursor['seq'], $cursor['logtime'], $high, $copied])) {
			return $fail('Cannot record the copy checkpoint');
		}

		if ($final || ++$batches % 100 === 0) {
			print "$table: copied and verified $copied rows" . PHP_EOL;
		}

		if ($final) {
			break;
		}
	}

	$old_count = $preserve_data ? syslog_db_fetch_cell("SELECT COUNT(*) FROM $saved") : '0';
	$new_count = syslog_db_fetch_cell("SELECT COUNT(*) FROM $target");

	if (!is_numeric($old_count) || !is_numeric($new_count) || (string) $old_count !== (string) $new_count
		|| ($preserve_data && (string) $copied !== (string) $new_count)
		|| syslog_detect_logtime_precision($table) !== $precision) {
		return $fail('Copied row counts or timestamp precision do not match');
	}

	if (!syslog_db_execute("DROP TABLE $saved")) {
		return $fail('Cannot drop the verified original');
	}

	if ($preserve_data) {
		syslog_db_execute_prepared("DELETE FROM $progress WHERE table_name = ?", [$table]);

		if ((string) syslog_db_fetch_cell("SELECT COUNT(*) FROM $progress") === '0') {
			syslog_db_execute("DROP TABLE IF EXISTS $progress");
		}
	}

	print "$table: verified $new_count rows; removed $backup" . PHP_EOL;

	return true;
}

/**
 * Check that a partially copied replacement still has the requested engine and
 * contiguous UTC hourly or daily partitions ending in dMaxValue.
 */
function syslog_upart_layout_ok(string $table, string $engine, string $granularity = 'day'): bool {
	global $syslogdb_default;

	$actual_engine = syslog_db_fetch_cell_prepared('SELECT ENGINE FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$syslogdb_default, $table]);
	$parts = syslog_db_fetch_assoc_prepared('SELECT PARTITION_NAME, PARTITION_DESCRIPTION, PARTITION_EXPRESSION
		FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
		ORDER BY PARTITION_ORDINAL_POSITION', [$syslogdb_default, $table]);
	$period = $granularity === 'hour' ? 3600 : 86400;
	$previous = null;

	if ($actual_engine !== $engine || !is_array($parts) || count($parts) < 2) {
		return false;
	}

	foreach ($parts as $index => $part) {
		$expression = strtolower(str_replace(['`', ' '], '', (string) $part['PARTITION_EXPRESSION']));
		$boundary = (string) $part['PARTITION_DESCRIPTION'];

		if ($expression !== 'unix_timestamp(logtime)') {
			return false;
		}

		if ($index === count($parts) - 1) {
			return $part['PARTITION_NAME'] === 'dMaxValue' && $boundary === 'MAXVALUE';
		}

		$start = (int) $boundary - $period;
		$name = $granularity === 'hour' ? 'h' . gmdate('YmdH', $start) : 'd' . gmdate('Ymd', $start);

		if (!ctype_digit($boundary) || (int) $boundary % $period !== 0 || $part['PARTITION_NAME'] !== $name
			|| ($previous !== null && (int) $boundary !== $previous + $period)) {
			return false;
		}

		$previous = (int) $boundary;
	}

	return false;
}

/**
 * Plan UTC hourly or daily partitions without deleting out-of-retention rows:
 * the first RANGE partition also accepts older timestamps. Retention counts
 * today as one UTC day. Indefinite retention starts at the oldest valid
 * timestamp; future data gets concrete partitions.
 *
 * @return string|null The PARTITION BY clause, or null above the partition limit.
 */
function syslog_upart_partition_sql(int $days, int $ahead, ?int $first, ?int $last, ?int $now = null,
	string $granularity = 'day', int $limit = 8192): ?string {
	$period = $granularity === 'hour' ? 3600 : 86400;
	$now = $now ?? time();
	$today = intdiv($now, 86400) * 86400;
	$current = intdiv($now, $period) * $period;
	$start = $days > 0 ? $today - ($days - 1) * 86400 : min($current, intdiv($first ?? $now, $period) * $period);
	$end = max($current + $ahead * 86400, intdiv($last ?? $now, $period) * $period);

	if (($end - $start) / $period + 2 > $limit) {
		return null;
	}

	$parts = [];

	for ($time = $start; $time <= $end; $time += $period) {
		$name = $granularity === 'hour' ? 'h' . gmdate('YmdH', $time) : 'd' . gmdate('Ymd', $time);
		$parts[] = "PARTITION $name VALUES LESS THAN (" . ($time + $period) . ')';
	}

	$parts[] = 'PARTITION dMaxValue VALUES LESS THAN MAXVALUE';

	return 'PARTITION BY RANGE (UNIX_TIMESTAMP(logtime)) (' . implode(', ', $parts) . ')';
}

function syslog_upart_display_version(): void {
	$version = plugin_syslog_version();
	print 'Syslog Partition Upgrade Utility, Version ' . trim($version['version']) . ', ' . COPYRIGHT_YEARS . PHP_EOL;
}

function syslog_upart_display_help(): void {
	syslog_upart_display_version();

	print PHP_EOL . 'usage: upgrade_partitioning.php [--partition=hourly|daily] [--convert-ms] [--preserve-data | --discard-data]' . PHP_EOL;
	print '    [--source-time-zone=<zone>] [--confirm]' . PHP_EOL . PHP_EOL;
	print 'Rebuilds syslog and syslog_removed with UTC hourly or daily partitions matching' . PHP_EOL;
	print 'the Partitioning Level, storage engine, message retention and future-partition' . PHP_EOL;
	print 'horizon. A Partitioning Level changed in Settings takes effect only when this' . PHP_EOL;
	print 'utility rebuilds the tables.' . PHP_EOL . PHP_EOL;
	print 'Existing precision is preserved unless --convert-ms is selected.' . PHP_EOL . PHP_EOL;
	print 'With preservation, rows are copied in verified batches and receive new sequence' . PHP_EOL;
	print 'numbers above the original high-water mark; originals are dropped only after' . PHP_EOL;
	print 'every batch and the row counts are verified.' . PHP_EOL . PHP_EOL;
	print 'Legacy DATETIME logtime columns are preserved only with --source-time-zone,' . PHP_EOL;
	print 'naming the zone their values were written in; otherwise use --discard-data.' . PHP_EOL . PHP_EOL;
	print 'Options:' . PHP_EOL;
	print '    --partition     - Save and apply this Partitioning Level instead of the one in Settings.' . PHP_EOL;
	print '    --convert-ms    - Also convert pipeline timestamps to TIMESTAMP(3); MariaDB only.' . PHP_EOL;
	print '    --preserve-data - Preserve existing messages (the default), copying 50,000 rows at a time.' . PHP_EOL;
	print '    --discard-data  - Delete messages in rebuilt tables; never discard the outbox.' . PHP_EOL;
	print '    --source-time-zone - IANA zone (for example America/New_York) of legacy DATETIME values.' . PHP_EOL;
	print '                      It must be known to PHP and the database, which must agree on its offsets.' . PHP_EOL;
	print '    --confirm       - Skip confirmation, not the size and maintenance warnings.' . PHP_EOL;
	print '    --version       - Display version information.' . PHP_EOL;
	print '    --help          - Display this help.' . PHP_EOL . PHP_EOL;
	print 'Maintenance:' . PHP_EOL;
	print 'Take a database backup, uncheck Syslog Enabled and stop ALL database writers first.' . PHP_EOL . PHP_EOL;
	print 'The current database size is reported before changes. Have at least that' . PHP_EOL;
	print 'much ADDITIONAL free disk space on the database server, plus temporary' . PHP_EOL;
	print 'file and binary-log headroom. Large transfers may take several hours.' . PHP_EOL . PHP_EOL;
	print 'On failure keep writers stopped and retry with --preserve-data and the' . PHP_EOL;
	print 'same --partition, --convert-ms and --source-time-zone options, if used; originals remain in *_rebuild tables.' . PHP_EOL . PHP_EOL;
}
