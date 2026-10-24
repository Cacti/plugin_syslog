<?php
/*
 * Shared setup for the database regression scripts: connects to the disposable
 * MariaDB server named in the environment, defines the plugin's database
 * helpers on top of PDO with bound values, and loads the plugin code under
 * test.
 *
 * The file is inert unless it is started from the command line with the test
 * environment set, and answers 404 when requested over the web.
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);

	exit;
}

$socket = getenv('SYSLOG_TEST_MYSQL_SOCKET');
$dsn    = $socket ? 'mysql:unix_socket=' . $socket : getenv('SYSLOG_TEST_MYSQL_DSN');

if (!$dsn) {
	fwrite(STDERR, "Set SYSLOG_TEST_MYSQL_SOCKET or SYSLOG_TEST_MYSQL_DSN for a disposable test server.\n");

	exit(2);
}

$pdo = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$syslogdb_default = 'syslog_review_check';
$config           = ['poller_id' => 1, 'base_path' => sys_get_temp_dir()];
$syslog_cnn       = new stdClass();
$options          = [];
$affected         = 0;
$checks           = 0;
$failures         = [];
error_reporting(E_ALL & ~E_DEPRECATED);
$settings         = ['syslog' => []];
$severities       = [3 => 'Error'];
$syslog_levels    = [3 => 'err'];
$syslog_facilities = [1 => 'user'];
$syslog_incoming_config = [
	'textField' => 'message', 'hostField' => 'host', 'programField' => 'program',
	'facilityField' => 'facility_id', 'priorityField' => 'priority_id', 'id' => 'seq',
];

function check($condition, $message) {
	$GLOBALS['checks']++;

	if (!$condition) {
		$GLOBALS['failures'][] = $message;
	}
}

function run_sql($sql, $params = []) {
	$statement = $GLOBALS['pdo']->prepare($sql);

	try {
		$ok = $statement->execute($params);
	} catch (PDOException $error) {
		$GLOBALS['last_error'] = $error->getMessage();

		return false;
	}

	$GLOBALS['affected'] = $statement->rowCount();

	return $ok;
}

function syslog_db_execute($sql, ...$args) {
	return run_sql($sql);
}

function syslog_db_execute_prepared($sql, $params = [], ...$args) {
	return run_sql($sql, $params);
}

function syslog_db_fetch_cell_prepared($sql, $params = [], ...$args) {
	$statement = $GLOBALS['pdo']->prepare($sql);

	try {
		$statement->execute($params);
	} catch (PDOException $error) {
		return false;
	}

	return $statement->fetchColumn();
}

function syslog_db_fetch_cell($sql, ...$args) {
	return syslog_db_fetch_cell_prepared($sql);
}

function syslog_db_fetch_assoc_prepared($sql, $params = [], ...$args) {
	$statement = $GLOBALS['pdo']->prepare($sql);

	try {
		$statement->execute($params);
	} catch (PDOException $error) {
		return false;
	}

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function syslog_db_fetch_assoc($sql, ...$args) {
	return syslog_db_fetch_assoc_prepared($sql);
}

function syslog_db_fetch_row_prepared($sql, $params = [], ...$args) {
	$rows = syslog_db_fetch_assoc_prepared($sql, $params);

	return $rows ? $rows[0] : [];
}

function syslog_db_fetch_row($sql, ...$args) {
	return syslog_db_fetch_row_prepared($sql);
}

function syslog_db_table_exists($table, ...$args) {
	return (bool) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
}

function syslog_db_column_exists($table, $column, ...$args) {
	return (bool) syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
}

function quoted_identifier($name) {
	if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $name)) {
		throw new InvalidArgumentException('Unexpected identifier in a test statement');
	}

	return '`' . $name . '`';
}

function syslog_sql_save($row, $table, $key = 'id', $autoinc = true) {
	if (!preg_match('/^(?:`?(\w+)`?\.)?`?(\w+)`?$/', $table, $parts)) {
		throw new InvalidArgumentException('Unexpected table name in a test statement');
	}

	$target = ($parts[1] !== '' ? quoted_identifier($parts[1]) . '.' : '') . quoted_identifier($parts[2]);
	$id     = (int) ($row[$key] ?? 0);

	unset($row[$key]);

	$row = array_filter($row, static fn($value) => $value !== '');

	if ($id > 0) {
		$assignments = implode(', ', array_map(static fn($column) => quoted_identifier($column) . ' = ?', array_keys($row)));

		return run_sql('UPDATE ' . $target . ' SET ' . $assignments . ' WHERE ' . quoted_identifier($key) . ' = ?', array_merge(array_values($row), [$id])) ? $id : 0;
	}

	$columns = implode(', ', array_map('quoted_identifier', array_keys($row)));
	$marks   = implode(', ', array_fill(0, count($row), '?'));

	run_sql('INSERT INTO ' . $target . ' (' . $columns . ') VALUES (' . $marks . ')', array_values($row));

	return (int) $GLOBALS['pdo']->lastInsertId();
}

function db_affected_rows(...$args) {
	return $GLOBALS['affected'];
}

function syslog_db_fetch_insert_id() {
	return (int) $GLOBALS['pdo']->lastInsertId();
}

function cacti_sizeof($items) {
	return is_array($items) ? count($items) : 0;
}

function __($text, ...$args) {
	array_pop($args);

	return $args ? vsprintf($text, $args) : $text;
}

function __esc($text, ...$args) {
	return htmlspecialchars(__($text, ...$args));
}

function read_config_option($name) {
	return $GLOBALS['options'][$name] ?? '';
}

function set_config_option($name, $value, $force = false) {
	$GLOBALS['options'][$name] = $value;
}

function api_plugin_hook(...$args) {
}

function api_plugin_hook_function($hook, $data = null) {
	return $data;
}

function cacti_log(...$args) {
}

function syslog_connect() {
	return true;
}

function syslog_dashboard_seed_default() {
}

function db_update_table(...$args) {
	return true;
}

function syslog_db_add_column(...$args) {
	return true;
}

function title_trim($text, $length) {
	return $text;
}

function html_escape($text) {
	return htmlspecialchars((string) $text);
}

function db_qstr($value) {
	return $GLOBALS['pdo']->quote((string) $value);
}

function generate_hash() {
	return bin2hex(random_bytes(16));
}

function cacti_escapeshellarg($value) {
	return escapeshellarg((string) $value);
}

function clean_up_name($value) {
	return $value;
}

require dirname(__DIR__, 3) . '/includes/functions.php';
// settings.php pulls in the full plugin bootstrap, which a bare database run does not have.
$schema_dir  = sys_get_temp_dir() . '/syslog-review-' . bin2hex(random_bytes(6));
$schema_file = $schema_dir . '/schema.php';

mkdir($schema_dir, 0700);
file_put_contents($schema_file, str_replace("require_once(__DIR__ . '/settings.php');", '', file_get_contents(dirname(__DIR__, 3) . '/includes/schema.php')));
chmod($schema_file, 0600);
register_shutdown_function(static function () use ($schema_file, $schema_dir) {
	unlink($schema_file);
	rmdir($schema_dir);
});

require $schema_file;
require dirname(__DIR__, 3) . '/lib/syslog_dashboard.php';

function table_count($table) {
	$queries = [
		'syslog'          => 'SELECT COUNT(*) FROM syslog',
		'syslog_alert'    => 'SELECT COUNT(*) FROM syslog_alert',
		'syslog_remove'   => 'SELECT COUNT(*) FROM syslog_remove',
		'syslog_reports'  => 'SELECT COUNT(*) FROM syslog_reports',
		'syslog_removed'  => 'SELECT COUNT(*) FROM syslog_removed',
		'syslog_incoming' => 'SELECT COUNT(*) FROM syslog_incoming',
	];

	return (int) $GLOBALS['pdo']->query($queries[$table])->fetchColumn();
}

function owner_column_type($table) {
	$statement = $GLOBALS['pdo']->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
	$statement->execute([$table, 'user']);

	return $statement->fetchColumn();
}

function set_sql_mode($mode) {
	$statement = $GLOBALS['pdo']->prepare('SET SESSION sql_mode = ?');
	$statement->execute([$mode]);
}

function db_fetch_row_prepared($sql, $params = [], ...$args) {
	return syslog_db_fetch_row_prepared($sql, $params);
}

function isset_request_var($name) {
	return isset($GLOBALS['request'][$name]);
}

function get_nfilter_request_var($name) {
	return $GLOBALS['request'][$name] ?? '';
}

function raise_message($id, $text = '', $level = 0) {
	$GLOBALS['messages'][] = [$id, $text, $level];
}

/**
 * Load one function from a plugin page without running the page: the source is
 * written to a private temporary file, required, and removed at shutdown.
 */
function extract_page_function($file, $name) {
	$alias = 'page_' . preg_replace('/\W/', '_', $file) . '_' . $name;

	if (function_exists($alias)) {
		return $alias;
	}

	$source = file_get_contents(dirname(__DIR__, 3) . '/' . $file);

	if (!preg_match('/^function ' . preg_quote($name, '/') . '\\(.*?^\\}/ms', $source, $match)) {
		throw new RuntimeException("Unable to load $file::$name");
	}

	$directory = sys_get_temp_dir() . '/syslog-page-' . bin2hex(random_bytes(6));
	$path      = $directory . '/function.php';

	mkdir($directory, 0700);
	file_put_contents($path, "<?php\n" . preg_replace('/^function ' . preg_quote($name, '/') . '\\(/', 'function ' . $alias . '(', $match[0]) . "\n");
	chmod($path, 0600);
	register_shutdown_function(static function () use ($path, $directory) {
		unlink($path);
		rmdir($directory);
	});

	require $path;

	return $alias;
}

function reports_load_format_file($file, &$output, &$report_tag, &$theme) {
	$output     = '<REPORT>';
	$report_tag = true;

	return true;
}

function db_fetch_assoc_prepared($sql, $params = [], ...$args) {
	return str_contains($sql, 'plugin_realms') ? [['id' => 1]] : [];
}

function is_realm_allowed($realm) {
	return true;
}

function get_username($user_id) {
	return 'admin';
}

const MESSAGE_LEVEL_INFO  = 1;
const MESSAGE_LEVEL_ERROR = 3;

function fresh_database() {
	$GLOBALS['pdo']->exec('DROP DATABASE IF EXISTS syslog_review_check');
	$GLOBALS['pdo']->exec('CREATE DATABASE syslog_review_check');
	$GLOBALS['pdo']->exec('USE syslog_review_check');
	$GLOBALS['pdo']->exec("SET SESSION sql_mode = ''");
}

function drop_database() {
	$GLOBALS['pdo']->exec('DROP DATABASE IF EXISTS syslog_review_check');
}

function finish_checks() {
	if ($GLOBALS['failures']) {
		fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $GLOBALS['failures']) . "\n");

		exit(1);
	}

	echo 'OK: ' . $GLOBALS['checks'] . " checks passed\n";

	exit(0);
}
