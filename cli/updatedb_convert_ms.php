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

/* tables whose logtime column carries the sub-second message timestamp */
$convert_tables = ['syslog', 'syslog_incoming', 'syslog_removed'];

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

exit(updatedb_convert_ms_run($convert_tables, $confirm) ? 0 : 1);

/**
 * Convert the Syslog message tables to millisecond (TIMESTAMP(3)) logtime.
 *
 * This is a destructive, MariaDB only operation: the message tables are
 * truncated before their logtime column is widened, because the historical
 * second-granularity rows cannot be migrated in place and the switch is
 * intended for a maintenance window.  MySQL is rejected outright because it
 * cannot RANGE partition-prune a fractional logtime column.
 *
 * @param array<int, string> $tables  The message tables to convert.
 * @param bool               $confirm Whether --confirm bypassed the prompt.
 *
 * @return bool Whether the conversion completed successfully.
 */
function updatedb_convert_ms_run(array $tables, bool $confirm): bool {
	global $syslogdb_default;

	if (!syslog_db_is_mariadb()) {
		fwrite(STDERR, 'ERROR: Millisecond (TIMESTAMP(3)) timestamps require MariaDB.  This server is MySQL, which cannot partition-prune a fractional logtime column.  No changes were made.' . PHP_EOL);

		return false;
	}

	if (syslog_detect_logtime_precision('syslog') === 'ms') {
		print 'The Syslog tables already use millisecond (TIMESTAMP(3)) timestamps.  Nothing to do.' . PHP_EOL;

		return true;
	}

	print '================================ WARNING ================================' . PHP_EOL;
	print 'This will convert the Syslog message tables to millisecond timestamps.' . PHP_EOL;
	print 'It is DESTRUCTIVE: the following tables will be TRUNCATED (all stored' . PHP_EOL;
	print 'messages deleted) and their schema changed to TIMESTAMP(3):' . PHP_EOL;
	print '    ' . implode(', ', $tables) . PHP_EOL;
	print 'Alert, removal and report rules are NOT affected.  Take a backup first.' . PHP_EOL;
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

	foreach ($tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			continue;
		}

		syslog_db_execute("TRUNCATE TABLE `$syslogdb_default`.`$table`");
	}

	foreach ($tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			continue;
		}

		syslog_db_execute("ALTER TABLE `$syslogdb_default`.`$table` MODIFY COLUMN logtime $column");
	}

	set_config_option('syslog_install_logtime_precision', 'ms');

	if (syslog_detect_logtime_precision('syslog') !== 'ms') {
		fwrite(STDERR, 'ERROR: Conversion did not complete; check the Cacti log.' . PHP_EOL);

		return false;
	}

	cacti_log('SYSLOG: Converted message tables to millisecond (TIMESTAMP(3)) logtime via cli/updatedb_convert_ms.php', false, 'SYSLOG');

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
	print 'Converts the Syslog message tables (syslog, syslog_incoming, syslog_removed)' . PHP_EOL;
	print 'to millisecond TIMESTAMP(3) logtime.  MariaDB only.  This TRUNCATES those' . PHP_EOL;
	print 'tables (deleting stored messages); rules and settings are preserved.' . PHP_EOL;
}
