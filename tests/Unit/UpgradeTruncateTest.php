<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The upgrade "Truncate" choice discards stored messages only. Alert, removal
 * and report rules are configuration and must survive it.
 */

it('keeps rule tables when the truncate upgrade type is chosen', function () {
	$schema = dirname(__DIR__, 2) . '/includes/schema.php';
	$script = tempnam(sys_get_temp_dir(), 'syslog-truncate-');

	file_put_contents($script, '<?php
		$config = ["poller_id" => 1];
		$syslogdb_default = "syslogdb";
		$syslog_cnn = new stdClass();
		$settings = ["syslog" => []];
		$statements = [];
		function syslog_connect() { return true; }
		function syslog_db_execute($sql, $log = true) { $GLOBALS["statements"][] = $sql; return true; }
		function syslog_db_fetch_row($sql, $log = true) { return []; }
		function syslog_db_fetch_cell_prepared($sql, $params = [], $col = "", $log = true) { return 1; }
		function syslog_db_table_exists($table, $log = true) { return true; }
		function syslog_install_storage_engine($engine, $options = []) { return "InnoDB"; }
		function syslog_dashboard_seed_default() {}
		function syslog_validate_storage_engine($engine) { return "InnoDB"; }
		function read_config_option($name) { return ""; }
		function set_config_option($name, $value, $force = false) {}
		function db_update_table(...$args) { return true; }
		function db_fetch_cell_prepared(...$args) { return 1; }
		function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
		function cacti_log(...$args) {}
		function __($text, ...$args) { return $text; }
		require ' . var_export($schema, true) . ';
		syslog_setup_table_new(["upgrade_type" => "truncate", "engine" => "InnoDB", "days" => 30], false);
		$dropped = [];
		foreach ($statements as $sql) {
			if (preg_match("/^DROP TABLE IF EXISTS `syslogdb`\.`(\w+)`/", $sql, $m)) {
				$dropped[] = $m[1];
			}
		}
		echo json_encode($dropped);
	');

	try {
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);

		$dropped = json_decode(implode("\n", $output), true);

		expect($status)->toBe(0)
			->and($dropped)->toBeArray()
			->and($dropped)->toContain('syslog')
			->and($dropped)->not->toContain('syslog_alert')
			->and($dropped)->not->toContain('syslog_remove')
			->and($dropped)->not->toContain('syslog_reports');
	} finally {
		unlink($script);
	}
});
