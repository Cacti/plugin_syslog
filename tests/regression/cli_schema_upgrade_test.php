<?php
// Run: php tests/regression/cli_schema_upgrade_test.php
$root = dirname(__DIR__, 2);
function check($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

// Exercise the real table builder with SQL captured instead of sent to a database.
$source = file_get_contents($root . '/includes/schema.php');
$start = strpos($source, 'function syslog_setup_table_new(');
$end = strpos($source, '/** Complete an interrupted install', $start);
eval(substr($source, $start, $end - $start));
eval(str_replace("require_once(__DIR__ . '/settings.php');", '', substr($source, strpos($source, 'function syslog_ensure_table_structures('))));
$queries = [];
$existing_tables = [];
$config = ['poller_id' => 1];
$options_saved = [];
$settings = ['syslog' => ['syslog_rows' => ['default' => 750]]];
$syslogdb_default = 'syslog';
function syslog_connect() { return true; }
function cacti_sizeof($value) { return count($value); }
function read_config_option($name) { return ''; }
function set_config_option($name, $value, $force = false) {
	global $options_saved;
	$options_saved[$name] = $value;
}
function syslog_install_storage_engine($engine, $options) { return $engine; }
function syslog_db_fetch_row($sql) { return []; }
function syslog_db_execute($sql) {
	global $queries;
	$queries[] = $sql;
	if (preg_match('/CREATE TABLE IF NOT EXISTS `syslog`\.`([^`]+)`/', $sql, $match)) {
		$GLOBALS['existing_tables'][] = $match[1];
	}
	return true;
}
function syslog_create_partitioned_syslog_table($engine, $days, $ahead) {
	check($days === 0, 'Repair must preserve indefinite retention');
}
function syslog_create_replication_output_table() {}
function syslog_create_replication_receipts_table() {}
function syslog_create_replication_collectors_table() {}
function syslog_create_replication_recovery_table() {}
function syslog_ensure_share_tables() {}
function syslog_ensure_saved_search_tables() {}
function syslog_ensure_dashboard_tables() {}
function syslog_ensure_status_table() {}
function syslog_create_device_rule_table() {}
function db_update_table($table, $definition, $create, $upgrade, $connection) { return true; }
function syslog_db_table_exists($table, $log = true) {
	if (($GLOBALS['config']['poller_id'] ?? 1) > 1 && in_array($table, ['syslog_replication_receipts', 'syslog_replication_collectors'], true)) {
		throw new RuntimeException('Remote schema repair required a main-only table');
	}
	return !empty($GLOBALS['all_tables_exist']) || in_array($table, $GLOBALS['existing_tables'], true);
}

for ($run = 0; $run < 2; $run++) {
	syslog_setup_table_new(['upgrade_type' => 'truncate', 'engine' => 'InnoDB', 'days' => 0], true);
}
foreach ($queries as $sql) {
	check(!preg_match('/\b(DROP|TRUNCATE|DELETE)\b/i', $sql), 'Repair issued destructive SQL');
}
check(!isset($options_saved['syslog_rows']), 'Repair reset an existing preference');
check(substr_count(implode("\n", $queries), 'INSERT INTO') === 2, 'Lookup tables must only be seeded when missing');
$config['poller_id'] = 2;
$all_tables_exist = true;
check(syslog_ensure_table_structures(), 'Remote schema repair failed');

// The real configuration hook must not migrate during schema CLI bootstrap.
function syslog_config_safe() { throw new RuntimeException('CLI bootstrap ran the migration hook'); }
preg_match('/function syslog_config_insert\(\): void \{.*?^\}/ms', file_get_contents($root . '/includes/settings.php'), $hook);
eval($hook[0]);
syslog_config_insert();

// Run the actual CLI in an isolated Cacti layout with bootstrap/schema fixtures.
$fixture = sys_get_temp_dir() . '/syslog-cli-' . bin2hex(random_bytes(6));
mkdir($fixture . '/include', 0700, true);
mkdir($fixture . '/plugins/syslog/cli', 0700, true);
mkdir($fixture . '/plugins/syslog/includes', 0700, true);
copy($root . '/cli/syslog_schema.php', $fixture . '/plugins/syslog/cli/syslog_schema.php');
file_put_contents($fixture . '/include/cli_check.php', '<?php $config = ["poller_id" => getenv("SYSLOG_TEST_REMOTE") ? 2 : 1];');
file_put_contents($fixture . '/plugins/syslog/setup.php', <<<'STUB'
<?php
function cacti_sizeof($value) { return count($value); }
function syslog_determine_config() { define('SYSLOG_CONFIG', __FILE__); }
function syslog_connect() { return true; }
function read_config_option($name) { return $name === 'syslog_install_days' ? '0' : ''; }
function syslog_setup_table_new($options, $repair) {
	if (getenv('SYSLOG_TEST_AUDIT')) { throw new Exception('Audit attempted table repair'); }
	if (!$repair || $options['upgrade_type'] !== 'upgrade' || $options['days'] !== '0') {
		throw new Exception('Unsafe repair options');
	}
}
function syslog_check_upgrade($force) {
	if (getenv('SYSLOG_TEST_AUDIT')) { throw new Exception('Audit attempted migrations'); }
	if (!$force) { throw new Exception('Migrations were not forced'); }
	if (getenv('SYSLOG_TEST_SQL_ERROR')) { $GLOBALS['database_last_error'] = 'fixture error'; }
	return !getenv('SYSLOG_TEST_UPGRADE_FAILURE');
}
function syslog_db_table_exists($table, $cache) {
	if (getenv('SYSLOG_TEST_REMOTE') && in_array($table, ['syslog_replication_receipts', 'syslog_replication_collectors'], true)) {
		throw new Exception('Main-only table required remotely');
	}
	return $table !== getenv('SYSLOG_TEST_MISSING');
}
STUB
);
file_put_contents($fixture . '/plugins/syslog/includes/schema.php', '<?php');
file_put_contents($fixture . '/plugins/syslog/includes/settings.php', '<?php');
function run_cli($fixture, $arguments = ['--repair']) {
	$process = proc_open(array_merge([PHP_BINARY, $fixture . '/plugins/syslog/cli/syslog_schema.php'], $arguments),
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return [proc_close($process), $output];
}
try {
	putenv('SYSLOG_TEST_AUDIT=1');
	[$status, $output] = run_cli($fixture, ['--audit']);
	check($status === 0 && strpos($output, 'audit passed') !== false, 'Read-only audit failed');
	putenv('SYSLOG_TEST_MISSING=syslog_dashboards');
	[$status, $output] = run_cli($fixture, ['--audit']);
	check($status === 1 && strpos($output, 'syslog_dashboards') !== false, 'Audit did not report missing table');
	putenv('SYSLOG_TEST_MISSING');
	check(run_cli($fixture, ['--audit', '--repair'])[0] === 1, 'Audit accepted repair');
	check(run_cli($fixture, ['--audit', '--upgrade'])[0] === 1, 'Audit accepted upgrade');
	putenv('SYSLOG_TEST_REMOTE=1');
	check(run_cli($fixture, ['--audit'])[0] === 0, 'Remote audit required main-only tables');
	putenv('SYSLOG_TEST_REMOTE');
	putenv('SYSLOG_TEST_AUDIT');
	check(run_cli($fixture)[0] === 0, 'CLI repair failed');
	check(run_cli($fixture, ['--upgrade'])[0] === 0, 'CLI upgrade failed');
	check(run_cli($fixture, ['--repair', '--upgrade'])[0] === 0, 'Combined switches failed');
	check(run_cli($fixture, [])[0] === 1, 'CLI ran without an explicit action');
	foreach (['--help', '-h', '-H'] as $flag) {
		[$status, $output] = run_cli($fixture, [$flag]);
		check($status === 0 && strpos($output, 'Usage: php syslog_schema.php') !== false, 'CLI help failed: ' . $flag);
	}
	check(run_cli($fixture, ['--truncate'])[0] === 1, 'CLI accepted destructive option');
	putenv('SYSLOG_TEST_MISSING=syslog_dashboards');
	[$status, $output] = run_cli($fixture);
	check($status === 1 && strpos($output, 'syslog_dashboards') !== false, 'CLI did not report missing table');
	putenv('SYSLOG_TEST_MISSING');
	putenv('SYSLOG_TEST_SQL_ERROR=1');
	check(run_cli($fixture)[0] === 1, 'CLI did not report migration SQL error');
	putenv('SYSLOG_TEST_SQL_ERROR');
	putenv('SYSLOG_TEST_UPGRADE_FAILURE=1');
	check(run_cli($fixture)[0] === 1, 'CLI ignored a failed upgrade result');
	putenv('SYSLOG_TEST_UPGRADE_FAILURE');
	putenv('SYSLOG_TEST_REMOTE=1');
	check(run_cli($fixture)[0] === 0, 'Remote CLI required main-only tables');
} finally {
	putenv('SYSLOG_TEST_UPGRADE_FAILURE');
	putenv('SYSLOG_TEST_AUDIT');
	putenv('SYSLOG_TEST_REMOTE');
	putenv('SYSLOG_TEST_SQL_ERROR');
	putenv('SYSLOG_TEST_MISSING');
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($files as $file) {
		$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
	}
	rmdir($fixture);
}
print "CLI schema upgrade regression checks passed.\n";
