<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('adds the message digest column to archived tables on upgrade', function () {
	$schema = plugin_test_read_source('includes/schema.php');

	expect($schema)->toContain("message_digest char(32) NOT NULL default ''")
		->toContain('function syslog_ensure_message_digest_columns(): void')
		->toContain("['syslog', 'syslog_removed'] as \$table")
		->toContain('syslog_ensure_message_digest_columns();');
});

it('keys grouped viewer queries and counts by the digest, with a hash fallback', function () {
	$syslog = plugin_test_read_source('syslog.php');

	expect($syslog)->toContain('syslog_message_digest_ready()')
		->toContain('IF(syslog.message_digest = \'\', MD5(syslog.message), syslog.message_digest)')
		->toContain("IF(message_digest = '', MD5(message), message_digest)");

	// the plain message group key survives only as the fallback branch of the
	// ternary, never inside a query string
	expect(substr_count($syslog, 'GROUP BY syslog.host_id, syslog.message'))->toBe(1);
	expect($syslog)->toContain("\n\t\t\t\t: 'GROUP BY syslog.host_id, syslog.message");
});

it('writes digests at every archived message entry point', function () {
	$functions = plugin_test_read_source('includes/functions.php');
	$process   = plugin_test_read_source('syslog_process.php');

	expect($functions)->toContain('message, message_digest, threshold_breached')
		->toContain(', message_digest')
		->toContain('MD5(message)')->toContain('MD5(si.message)')
		->toContain("db_column_exists(\$table, 'message_digest', false, \$central)")
		->toContain('function syslog_backfill_message_digest')
		->toContain('function syslog_message_digest_ready');

	expect($process)->toContain('syslog_ensure_message_digest_columns();')
		->toContain('syslog_backfill_message_digest() < 0');
});

it('keeps expanded groups within the active date range and search filters', function ($type, $message) {
	if (!extension_loaded('pdo_sqlite')) {
		$this->markTestSkipped('PDO SQLite is required to exercise the grouped detail query.');
	}

	syslog_load_plugin_source('includes/functions.php');
	test_override('db_qstr', static fn($value) => "'" . str_replace("'", "''", $value) . "'");

	$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$db->exec('CREATE TABLE syslog_programs (program_id INTEGER, program TEXT)');
	$db->exec("INSERT INTO syslog_programs VALUES (7, 'kernel')");
	$db->exec('CREATE TABLE syslog (seq INTEGER, logtime TEXT, program_id INTEGER, host_id INTEGER, message TEXT, PRIMARY KEY (seq, logtime))');
	$db->exec('CREATE TABLE syslog_removed (seq INTEGER, logtime TEXT, program_id INTEGER, host_id INTEGER, message TEXT, PRIMARY KEY (seq, logtime))');
	$insert_main = $db->prepare("INSERT INTO syslog VALUES (?, ?, 7, ?, 'stored link down')");
	$insert_removed = $db->prepare("INSERT INTO syslog_removed VALUES (?, ?, 7, ?, 'removed link down')");

	foreach ([
		[101, '2026-01-01 12:00:00', 1],
		[101, '2026-01-02 12:00:00', 1],
		[101, '2026-01-03 12:00:00', 1],
		[102, '2026-01-02 13:00:00', 2],
		[103, '2026-01-02 14:00:00', 1]
	] as $row) {
		$insert_main->execute($row);
		$insert_removed->execute($row);
	}

	$fetch = static function ($sql, $params = []) use ($db) {
		$query = $db->prepare($sql);
		$query->execute($params);

		return $query->fetchAll(PDO::FETCH_ASSOC);
	};
	test_override('syslog_db_fetch_assoc_prepared', $fetch);

	$GLOBALS['syslogdb_default'] = 'main';
	$sm = ['mtype' => $type, 'seq_list' => '101,102'];
	$sql_where = 'WHERE ' . syslog_logical_search_sql(syslog_parse_logical_search(
		'logtime >= "2026-01-02 00:00:00" AND logtime <= "2026-01-02 23:59:59" AND host_id = "1"'
	), 'message');

	$detail_messages = syslog_group_detail_messages($sm, $sql_where, '0');

	expect($detail_messages)->toHaveCount(1)
		->and($detail_messages[0]['seq'])->toBe(101)
		->and($detail_messages[0]['logtime'])->toBe('2026-01-02 12:00:00')
		->and($detail_messages[0]['message'])->toBe($message)
		->and($detail_messages[0]['program'])->toBe('kernel');

	$detail_messages = syslog_group_detail_messages($sm, '', '0');

	expect($detail_messages)->toHaveCount(4)
		->and(array_column($detail_messages, 'seq'))->not->toContain(103);
})->with([
	'stored messages' => ['main', 'stored link down'],
	'removed messages' => ['remove', 'removed link down']
]);
