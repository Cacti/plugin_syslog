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
eval(substr($source, strpos($source, 'function syslog_setup_table_new(')));
$queries = [];
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

for ($run = 0; $run < 2; $run++) {
	syslog_setup_table_new(['upgrade_type' => 'truncate', 'engine' => 'InnoDB', 'days' => 0], true);
}
foreach ($queries as $sql) {
	check(!preg_match('/\b(DROP|TRUNCATE|DELETE)\b/i', $sql), 'Repair issued destructive SQL');
}
check(!isset($options_saved['syslog_rows']), 'Repair reset an existing preference');
check(substr_count(implode("\n", $queries), 'INSERT IGNORE INTO') === 4, 'Lookup seeds must tolerate repeat repairs');

// Run the actual CLI in an isolated Cacti layout with bootstrap/schema fixtures.
$fixture = sys_get_temp_dir() . '/syslog-cli-' . bin2hex(random_bytes(6));
mkdir($fixture . '/include', 0700, true);
mkdir($fixture . '/plugins/syslog/cli', 0700, true);
mkdir($fixture . '/plugins/syslog/includes', 0700, true);
copy($root . '/cli/upgrade_database.php', $fixture . '/plugins/syslog/cli/upgrade_database.php');
file_put_contents($fixture . '/include/cli_check.php', '<?php $config = ["poller_id" => getenv("SYSLOG_TEST_REMOTE") ? 2 : 1];');
file_put_contents($fixture . '/plugins/syslog/setup.php', <<<'STUB'
<?php
function syslog_determine_config() { define('SYSLOG_CONFIG', __FILE__); }
function syslog_connect($repair) { if (!$repair) { throw new Exception('Unsafe connect'); } return true; }
function read_config_option($name) { return $name === 'syslog_install_days' ? '0' : ''; }
function syslog_setup_table_new($options, $repair) {
	if (!$repair || $options['upgrade_type'] !== 'upgrade' || $options['days'] !== '0') {
		throw new Exception('Unsafe repair options');
	}
}
function syslog_check_upgrade($force) {
	if (!$force) { throw new Exception('Migrations were not forced'); }
	if (getenv('SYSLOG_TEST_SQL_ERROR')) { $GLOBALS['database_last_error'] = 'fixture error'; }
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
	$process = proc_open(array_merge([PHP_BINARY, $fixture . '/plugins/syslog/cli/upgrade_database.php'], $arguments),
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return [proc_close($process), $output];
}
try {
	check(run_cli($fixture)[0] === 0, 'CLI repair failed');
	check(run_cli($fixture, ['--upgrade'])[0] === 0, 'CLI upgrade failed');
	check(run_cli($fixture, ['--repair', '--upgrade'])[0] === 0, 'Combined switches failed');
	check(run_cli($fixture, [])[0] === 1, 'CLI ran without an explicit action');
	check(run_cli($fixture, ['--help'])[0] === 0, 'CLI help failed');
	check(run_cli($fixture, ['--truncate'])[0] === 1, 'CLI accepted destructive option');
	putenv('SYSLOG_TEST_MISSING=syslog_dashboards');
	[$status, $output] = run_cli($fixture);
	check($status === 1 && strpos($output, 'syslog_dashboards') !== false, 'CLI did not report missing table');
	putenv('SYSLOG_TEST_MISSING');
	putenv('SYSLOG_TEST_SQL_ERROR=1');
	check(run_cli($fixture)[0] === 1, 'CLI did not report migration SQL error');
	putenv('SYSLOG_TEST_SQL_ERROR');
	putenv('SYSLOG_TEST_REMOTE=1');
	check(run_cli($fixture)[0] === 0, 'Remote CLI required main-only tables');
} finally {
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
