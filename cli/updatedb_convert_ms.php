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

foreach ($parms as $arg) {
	switch ($arg) {
		case '--confirm':
			$confirm = true;

			break;
		case '--help':
		case '-H':
		case '-h':
			updatedb_convert_ms_display_help();
			exit(0);
		default:
			fwrite(STDERR, "ERROR: Unknown argument: $arg" . PHP_EOL);
			updatedb_convert_ms_display_help();
			exit(1);
	}
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

exit(updatedb_convert_ms_run($confirm) ? 0 : 1);

/**
 * Convert the Syslog message pipeline tables to millisecond (TIMESTAMP(3)) logtime.
 *
 * This is a destructive, MariaDB only operation.  The message tables are
 * truncated before their logtime column is widened, because the historical
 * second-granularity rows cannot be migrated in place and the switch is
 * intended for a maintenance window.  The replication outbox is widened in
 * place instead, so undelivered rows are preserved, and its new rows then match
 * the millisecond incoming timestamps on archival.  MySQL is rejected outright
 * because it cannot RANGE partition-prune a fractional logtime column.
 *
 * The conversion is all-or-nothing: every TRUNCATE/ALTER is checked and the
 * stored precision setting is only recorded once every target column is
 * TIMESTAMP(3), so a partial failure can simply be re-run.
 *
 * @param bool $confirm Whether --confirm bypassed the prompt.
 *
 * @return bool Whether the conversion completed successfully.
 */
function updatedb_convert_ms_run(bool $confirm): bool {
	global $syslogdb_default;

	if (!syslog_db_is_mariadb()) {
		fwrite(STDERR, 'ERROR: Millisecond (TIMESTAMP(3)) timestamps require MariaDB.  This server is MySQL, which cannot partition-prune a fractional logtime column.  No changes were made.' . PHP_EOL);

		return false;
	}

	// Tables whose historical rows are discarded before the column is widened.
	$truncate_tables = ['syslog', 'syslog_incoming', 'syslog_removed'];

	// The outbox is widened in place so undelivered replication rows survive.
	$alter_only_tables = ['syslog_replication_output'];

	$all_tables = array_merge($truncate_tables, $alter_only_tables);

	// Report which targets still need conversion.  Checking every target (not
	// just syslog) lets a partial previous run be retried.
	$pending = [];

	foreach ($all_tables as $table) {
		if (syslog_db_table_exists($table, false) && syslog_detect_logtime_precision($table) !== 'ms') {
			$pending[] = $table;
		}
	}

	if (!$pending) {
		print 'The Syslog tables already use millisecond (TIMESTAMP(3)) timestamps.  Nothing to do.' . PHP_EOL;

		return true;
	}

	print '================================ WARNING ================================' . PHP_EOL;
	print 'This will convert the Syslog message pipeline to millisecond timestamps.' . PHP_EOL;
	print 'It is DESTRUCTIVE: these tables will be TRUNCATED (all stored messages' . PHP_EOL;
	print 'deleted) and their schema changed to TIMESTAMP(3):' . PHP_EOL;
	print '    ' . implode(', ', $truncate_tables) . PHP_EOL;
	print 'The replication outbox (' . implode(', ', $alter_only_tables) . ') is widened in' . PHP_EOL;
	print 'place, preserving any undelivered rows.  Alert, removal and report rules' . PHP_EOL;
	print 'are NOT affected.  Take a backup first.' . PHP_EOL;
	print '========================================================================' . PHP_EOL;

	if (!$confirm) {
		print 'Type "yes" to proceed, anything else to abort: ';

		$answer = trim((string) fgets(STDIN));

		if (strtolower($answer) !== 'yes') {
			print 'Aborted; no changes were made.' . PHP_EOL;

			return false;
		}
	}

	$column = syslog_logtime_sql_type('ms');

	// Preserve the AUTO_INCREMENT high-water marks before truncating so new
	// rows never reuse sequence values that remote replication identities
	// (source_event_id, receipts) still reference.
	$auto_increment = [];

	foreach ($truncate_tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			continue;
		}

		$next = syslog_db_fetch_cell_prepared(
			'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
			[$syslogdb_default, $table],
			'',
			false
		);

		$auto_increment[$table] = (int) $next;
	}

	$ok = true;

	foreach ($truncate_tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			continue;
		}

		$ok = syslog_db_execute("TRUNCATE TABLE `$syslogdb_default`.`$table`") && $ok;
	}

	foreach ($all_tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			continue;
		}

		$ok = syslog_db_execute("ALTER TABLE `$syslogdb_default`.`$table` MODIFY COLUMN logtime $column") && $ok;
	}

	foreach ($auto_increment as $table => $next) {
		if ($next > 1) {
			$ok = syslog_db_execute("ALTER TABLE `$syslogdb_default`.`$table` AUTO_INCREMENT = $next") && $ok;
		}
	}

	// Record success only when every target column is actually TIMESTAMP(3).
	foreach ($all_tables as $table) {
		if (syslog_db_table_exists($table, false) && syslog_detect_logtime_precision($table) !== 'ms') {
			$ok = false;
		}
	}

	if (!$ok) {
		fwrite(STDERR, 'ERROR: Conversion did not fully complete; the logtime precision setting was NOT changed.  Re-run this utility after resolving the database error (check the Cacti log).' . PHP_EOL);

		return false;
	}

	set_config_option('syslog_install_logtime_precision', 'ms');

	cacti_log('SYSLOG: Converted message pipeline tables to millisecond (TIMESTAMP(3)) logtime via cli/updatedb_convert_ms.php', false, 'SYSLOG');

	print 'Conversion complete; Syslog now stores millisecond timestamps.' . PHP_EOL;
	print 'Ensure your log forwarders emit sub-second timestamps (see README.md).' . PHP_EOL;

	return true;
}

/**
 * Display the millisecond conversion utility usage and options.
 *
 * @return void
 */
function updatedb_convert_ms_display_help(): void {
	print 'Usage: php updatedb_convert_ms.php [--confirm]' . PHP_EOL;
	print '  --confirm  Skip the interactive confirmation prompt.' . PHP_EOL;
	print '  --help     Display this help.' . PHP_EOL;
	print PHP_EOL;
	print 'Converts the Syslog message pipeline to millisecond TIMESTAMP(3) logtime.' . PHP_EOL;
	print 'MariaDB only.  TRUNCATES syslog, syslog_incoming and syslog_removed' . PHP_EOL;
	print '(deleting stored messages) and widens syslog_replication_output in place;' . PHP_EOL;
	print 'rules and settings are preserved.' . PHP_EOL;
}

