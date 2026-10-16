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

// Use only an explicitly supplied disposable server, as in the recovery fixture.
$dsn = getenv('SYSLOG_TEST_MYSQL_DSN');
if (!$dsn) {
	fwrite(STDERR, "Set SYSLOG_TEST_MYSQL_DSN for a disposable MariaDB server.\n");
	exit(2);
}
$root = dirname(__DIR__, 2);
$cacti = getenv('SYSLOG_TEST_CACTI_PATH') ?: dirname($root, 2);
require $cacti . '/include/global_constants.php';
require $cacti . '/lib/database.php';
function cacti_log(...$args) {}
function cacti_debug_backtrace(...$args) {}
function clean_up_lines($value) { return $value; }
function cacti_count($value) { return is_countable($value) ? count($value) : 0; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function __($text, ...$args) { return $text; }
function raise_message(...$args) {}
function get_username($id) { return 'alice'; }
function get_filter_request_var($name, ...$args) { return $GLOBALS['request'][$name] ?? ''; }
function get_nfilter_request_var($name) { return $GLOBALS['request'][$name] ?? ''; }
require $root . '/includes/database.php';
require $root . '/includes/functions.php';
require $root . '/includes/processing.php';
require $root . '/lib/syslog_dashboard.php';

$password = getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsn, 'root', $password, $options);
$prefix = 'syslog_quality_' . bin2hex(random_bytes(4));
$databases = [$prefix . '_cacti', $prefix . '_source', $prefix . '_remote', $prefix . '_collector'];
$config = ['poller_id' => 1];
$checks = 0;
function check_quality($condition, $message) {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}
try {
	foreach ($databases as $db) { $admin->exec("CREATE DATABASE `$db`"); }
	$local_db_cnn_id = new PDO($dsn . ';dbname=' . $databases[0], 'root', $password, $options);
	$syslog_cnn = new PDO($dsn . ';dbname=' . $databases[1], 'root', $password, $options);
	$remote = new PDO($dsn . ';dbname=' . $databases[2], 'root', $password, $options);
	$syslogdb_default = $databases[1];
	$database_default = $databases[0];
	$database_hostname = 'localhost';
	$database_port = 3306;
	$database_sessions = ["localhost:3306:$database_default" => $local_db_cnn_id];

	foreach (['syslog_alert', 'syslog_remove', 'syslog_reports'] as $table) {
		$syslog_cnn->exec("CREATE TABLE `$table` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128)) ENGINE=InnoDB");
		$id = syslog_sql_save(['id' => 0, 'name' => 'dedicated'], $table);
		check_quality($id > 0 && $syslog_cnn->query("SELECT name FROM `$table`")->fetchColumn() === 'dedicated', "$table saves in the dedicated database");
		check_quality(!$local_db_cnn_id->query("SHOW TABLES LIKE '$table'")->fetchColumn(), "$table save must not create Cacti shadow data");
	}
	$rows = [['id' => 2, 'name' => 'current']];
	check_quality(syslog_replace_data('syslog_alert', $rows, $remote, $syslog_cnn), 'Dedicated source schema creates a missing remote table');
	$remote->exec("INSERT INTO syslog_alert VALUES (9, 'stale')");
	check_quality(syslog_replace_data('syslog_alert', $rows, $remote, $syslog_cnn), 'A new complete snapshot replaces old rows');
	check_quality($remote->query('SELECT id FROM syslog_alert')->fetchAll(PDO::FETCH_COLUMN) === [2], 'Deleted rules disappear');
	$bad = [['id' => 3, 'name' => 'first'], ['id' => 3, 'name' => 'duplicate']];
	check_quality(!syslog_replace_data('syslog_alert', $bad, $remote, $syslog_cnn), 'Failed batch must not publish');
	check_quality($remote->query('SELECT id FROM syslog_alert')->fetchAll(PDO::FETCH_COLUMN) === [2], 'Failed snapshot preserves the live rules');
	$syslog_cnn->exec("ALTER TABLE syslog_alert ADD COLUMN body TEXT NOT NULL DEFAULT ''");
	$rows = [['id' => 2, 'name' => 'current', 'body' => 'new schema']];
	check_quality(syslog_replace_data('syslog_alert', $rows, $remote, $syslog_cnn), 'Source schema upgrades publish with the new snapshot');
	check_quality($remote->query('SELECT body FROM syslog_alert')->fetchColumn() === 'new schema', 'Remote schema and rows advance together');
	$main_source = $syslog_cnn;
	$main_cacti = $local_db_cnn_id;
	$local_db_cnn_id = $remote;
	$collector = new PDO($dsn . ';dbname=' . $databases[3], 'root', $password, $options);
	$syslog_cnn = $collector;
	check_quality(syslog_replace_data('syslog_alert', $rows), 'Remote dedicated storage imports from the local Cacti snapshot');
	check_quality($syslog_cnn->query('SELECT body FROM syslog_alert')->fetchColumn() === 'new schema', 'Remote dedicated storage has current data');
	$syslog_cnn = $main_source;
	$local_db_cnn_id = $main_cacti;
	$empty = [];
	check_quality(syslog_replace_data('syslog_alert', $empty, $remote, $syslog_cnn), 'An empty authoritative snapshot publishes');
	check_quality((int) $remote->query('SELECT COUNT(*) FROM syslog_alert')->fetchColumn() === 0, 'Last deleted rule disappears');
	check_quality(syslog_replace_data('syslog_alert', $empty, $collector, $remote), 'Empty Cacti snapshot clears dedicated collector rules');
	check_quality((int) $collector->query('SELECT COUNT(*) FROM syslog_alert')->fetchColumn() === 0, 'Last deleted rule disappears in dedicated collector storage');
	check_quality(!$remote->query("SHOW TABLES LIKE '%sync%'")->fetchColumn(), 'Snapshot staging tables are cleaned up');

	// Execute the actual counting query strings, rather than a rewritten example.
	foreach (['syslog', 'syslog_removed'] as $table) {
		$syslog_cnn->exec("CREATE TABLE `$table` (seq INT AUTO_INCREMENT PRIMARY KEY, host_id INT, message TEXT, message_digest CHAR(32) NOT NULL DEFAULT '', program_id INT, facility_id INT, priority_id INT) ENGINE=InnoDB");
		$syslog_cnn->exec("INSERT INTO `$table` (host_id,message,program_id,facility_id,priority_id) VALUES (1,'one',1,1,1),(2,'two',1,1,1)");
	}
	$source = file_get_contents($root . '/syslog.php');
	preg_match_all('/\$total_rows = syslog_db_fetch_cell\("SELECT SUM\(totals\).*?"\);/s', $source, $matches);
	$digest_count_key = "COUNT(DISTINCT CONCAT(host_id, '|', message, '|', program_id, '|', facility_id, '|', priority_id))";
	$sql_where = '';
	check_quality(count($matches[0]) === 2, 'Both grouped and ungrouped totals are tested');
	foreach ($matches[0] as $query) {
		eval($query);
		check_quality((int) $total_rows === 4, 'Equal table totals must add instead of deduplicating');
	}
	check_quality(syslog_backfill_message_digest(1) === 2, 'Each archived table backfills one sequence range');
	check_quality(syslog_backfill_message_digest(1) === 0, 'Backfill completes without counting the full history');
	$syslog_cnn->exec("INSERT INTO syslog (host_id,message,message_digest,program_id,facility_id,priority_id) VALUES (3,'new',MD5('new'),1,1,1)");
	check_quality(syslog_backfill_message_digest(1) === 0, 'New archival rows do not restart a completed migration');
	check_quality((int) $syslog_cnn->query("SELECT COUNT(*) FROM syslog WHERE message_digest = ''")->fetchColumn() === 0, 'Backfilled rows have digests');

	$syslog_cnn->exec("CREATE TABLE syslog_dashboards (id INT AUTO_INCREMENT PRIMARY KEY, name TEXT, user VARCHAR(32) DEFAULT 'alice', is_global VARCHAR(2) DEFAULT '', date INT DEFAULT 0, updated INT DEFAULT 0) ENGINE=InnoDB");
	$syslog_cnn->exec('CREATE TABLE syslog_dashboard_panels (id INT AUTO_INCREMENT PRIMARY KEY, dashboard_id INT, position INT, title TEXT, expression TEXT, source TEXT, removal INT, kind TEXT, chart TEXT, field TEXT, `interval` TEXT, timespan TEXT, top_n INT, width INT, height INT, date INT) ENGINE=InnoDB');
	$syslog_cnn->exec('CREATE TABLE syslog_dashboards_perm (dashboard_id INT) ENGINE=InnoDB');
	$syslog_cnn->exec("INSERT INTO syslog_dashboards (id,name) VALUES (5,'Operations')");
	$syslog_cnn->exec('INSERT INTO syslog_dashboard_panels (id,dashboard_id,position) VALUES (7,5,1),(8,5,2)');
	$syslog_cnn->exec('INSERT INTO syslog_dashboards_perm VALUES (5)');
	$syslog_cnn->exec("CREATE TRIGGER refuse_order BEFORE UPDATE ON syslog_dashboard_panels FOR EACH ROW BEGIN IF NEW.id = 7 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture refusal'; END IF; END");
	check_quality(!syslog_dashboard_panel_move(['id' => 7], 5, 1), 'Failed second ordering write must roll back');
	check_quality($syslog_cnn->query('SELECT id FROM syslog_dashboard_panels ORDER BY position')->fetchAll(PDO::FETCH_COLUMN) === [7, 8], 'Failed reorder preserves original positions');
	$syslog_cnn->exec('DROP TRIGGER refuse_order');
	check_quality(syslog_dashboard_panel_move(['id' => 7], 5, 1), 'Panel order can be changed after the fault clears');
	$_SESSION['sess_user_id'] = 1;
	$request = ['id' => 5, 'name' => 'Copy'];
	$syslog_cnn->exec("CREATE TRIGGER refuse_copy BEFORE INSERT ON syslog_dashboard_panels FOR EACH ROW BEGIN IF NEW.dashboard_id <> 5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture refusal'; END IF; END");
	$result = json_decode(syslog_dashboard_copy(), true);
	check_quality(isset($result['error']), 'Failed copied panel must produce an error');
	check_quality((int) $syslog_cnn->query('SELECT COUNT(*) FROM syslog_dashboards')->fetchColumn() === 1, 'Failed panel copy rolls back its new dashboard');
	$syslog_cnn->exec('DROP TRIGGER refuse_copy');
	$result = json_decode(syslog_dashboard_copy(), true);
	check_quality(!empty($result['id']), 'Dashboard copy can succeed after the fault clears');
	check_quality((int) $syslog_cnn->query('SELECT COUNT(*) FROM syslog_dashboard_panels WHERE dashboard_id = ' . (int) $result['id'])->fetchColumn() === 2, 'Successful copy includes both panels');
	check_quality(syslog_dashboard_delete((int) $result['id']), 'Remove only the copied dashboard before testing source deletion');
	$syslog_cnn->exec("CREATE TRIGGER refuse_dashboard_delete BEFORE DELETE ON syslog_dashboards FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture refusal'");
	check_quality(!syslog_dashboard_delete(5), 'Failed final delete must roll back the whole dashboard removal');
	check_quality((int) $syslog_cnn->query('SELECT COUNT(*) FROM syslog_dashboard_panels')->fetchColumn() === 2, 'Rollback preserves all panels');
	check_quality((int) $syslog_cnn->query('SELECT COUNT(*) FROM syslog_dashboards_perm')->fetchColumn() === 1, 'Rollback preserves shares');
	$syslog_cnn->exec('DROP TRIGGER refuse_dashboard_delete');
	check_quality(syslog_dashboard_delete(5), 'Dashboard removal succeeds after the fault clears');
	check_quality((int) $syslog_cnn->query('SELECT COUNT(*) FROM syslog_dashboard_panels')->fetchColumn() === 0, 'Successful removal deletes panels');
	print "Quality database regression checks passed: $checks\n";
} finally {
	foreach ($databases as $db) { $admin->exec("DROP DATABASE IF EXISTS `$db`"); }
}
