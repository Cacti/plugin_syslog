<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Test bootstrap.
 *
 * Syslog's sources expect to be included by Cacti, which has already
 * defined the db_*, request-variable, and logging helpers as plain global
 * functions. Nothing here talks to a database or a network: each Cacti (and
 * syslog_db_*) function is declared as a stub that records the call in
 * $GLOBALS['__test_db_calls'] and hands back a safe default.
 *
 * The CI workflow checks out a pinned Cacti release next to this plugin so
 * Pest runs against Cacti's own Composer-managed vendor tree (Pest/PHPUnit)
 * instead of a vendor tree local to this plugin. The version check below
 * makes sure that checkout actually matches what tests/.cacti-version
 * expects before any plugin source is loaded.
 *
 * Guarding every declaration with function_exists() keeps this file usable
 * if a future integration suite loads real Cacti first.
 *
 * Individual tests frequently need different fake behavior for the same
 * function (e.g. what get_request_var() returns, or what a fake
 * syslog_db_fetch_assoc() hands back). Because every test file loads into
 * one Pest process, a second global `function get_request_var()` in a test
 * file would be a fatal redeclaration. Tests install a closure through
 * test_override() instead; syslog_test_reset_globals() (called before every
 * test, see Pest.php) clears the registry so one test's fake never leaks
 * into the next.
 */

$cacti_root = dirname(__DIR__, 3);
$autoload   = $cacti_root . '/include/vendor/autoload.php';
$version    = $cacti_root . '/include/cacti_version';
$expected   = __DIR__ . '/.cacti-version';

if (!is_readable($autoload)) {
	throw new RuntimeException("Cacti Composer autoloader is not readable: $autoload");
}

if (!is_readable($version)) {
	throw new RuntimeException("Cacti version file is not readable: $version");
}

if (!is_readable($expected)) {
	throw new RuntimeException("Expected Cacti version file is not readable: $expected");
}

$cacti_version    = trim((string) file_get_contents($version));
$expected_version = trim((string) file_get_contents($expected));

if ($cacti_version === '') {
	throw new RuntimeException("Cacti version file is empty: $version");
}

if ($expected_version === '') {
	throw new RuntimeException("Expected Cacti version file is empty: $expected");
}

// The CI workflow tracks a moving branch (1.2.x or develop) rather than a pinned release, so any actual version is accepted.
if (!in_array($expected_version, ['1.2.x', 'develop'], true) && $cacti_version !== $expected_version) {
	throw new RuntimeException("Expected Cacti $expected_version, found $cacti_version in $version");
}

require_once $autoload;

/*
 * base_path has to point at the Cacti root two levels above this plugin:
 * syslog's source files build include paths from it at runtime.
 */
$GLOBALS['config'] = [
	'base_path'       => $cacti_root,
	'url_path'        => '/cacti/',
	'cacti_version'   => $cacti_version,
	'cacti_server_os' => 'unix',
];

/*
 * Per-test override registry. See the file-level comment above.
 */
function test_override($name, callable $callback) {
	$GLOBALS['__test_overrides'][$name] = $callback;
}

function test_call_override($name, array $args, $default) {
	if (isset($GLOBALS['__test_overrides'][$name])) {
		return call_user_func_array($GLOBALS['__test_overrides'][$name], $args);
	}

	return $default;
}

function syslog_test_reset_globals() {
	$GLOBALS['__test_overrides'] = [];
	$GLOBALS['__test_db_calls']  = [];
	$GLOBALS['request']          = [];
	$_SESSION = [];
	$_POST    = [];
	$_REQUEST = [];
	$_GET     = [];
	$_FILES   = [];
}

syslog_test_reset_globals();

if (!defined('MESSAGE_LEVEL_INFO')) {
	define('MESSAGE_LEVEL_INFO', 1);
}

if (!defined('MESSAGE_LEVEL_WARN')) {
	define('MESSAGE_LEVEL_WARN', 2);
}

if (!defined('MESSAGE_LEVEL_ERROR')) {
	define('MESSAGE_LEVEL_ERROR', 3);
}

if (!defined('POLLER_VERBOSITY_LOW')) {
	define('POLLER_VERBOSITY_LOW', 2);
}

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 3);
}

if (!defined('POLLER_VERBOSITY_DEBUG')) {
	define('POLLER_VERBOSITY_DEBUG', 5);
}

if (!defined('POLLER_VERBOSITY_NONE')) {
	define('POLLER_VERBOSITY_NONE', 6);
}

// Cacti-specific request-filter constants; kept out of PHP's native FILTER_* range.
if (!defined('FILTER_VALIDATE_IS_REGEX')) {
	define('FILTER_VALIDATE_IS_REGEX', 100001);
}

if (!defined('FILTER_VALIDATE_IS_NUMERIC_LIST')) {
	define('FILTER_VALIDATE_IS_NUMERIC_LIST', 100002);
}

if (!defined('CACTI_PATH_BASE')) {
	define('CACTI_PATH_BASE', $GLOBALS['config']['base_path']);
}

if (!function_exists('db_execute')) {
	function db_execute($sql, $log = true, $connection = false) {
		$GLOBALS['__test_db_calls'][] = ['fn' => 'db_execute', 'sql' => $sql, 'params' => []];

		return test_call_override('db_execute', [$sql, $log, $connection], true);
	}
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true, $connection = false) {
		$GLOBALS['__test_db_calls'][] = ['fn' => 'db_execute_prepared', 'sql' => $sql, 'params' => $params];

		return test_call_override('db_execute_prepared', [$sql, $params, $log, $connection], true);
	}
}

if (!function_exists('db_affected_rows')) {
	function db_affected_rows($connection = false) {
		return test_call_override('db_affected_rows', [$connection], 0);
	}
}

if (!function_exists('db_fetch_assoc')) {
	function db_fetch_assoc($sql, $log = true, $connection = false) {
		return test_call_override('db_fetch_assoc', [$sql, $log, $connection], []);
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = [], $log = true, $connection = false) {
		return test_call_override('db_fetch_assoc_prepared', [$sql, $params, $log, $connection], []);
	}
}

if (!function_exists('db_fetch_row')) {
	function db_fetch_row($sql, $log = true, $connection = false) {
		return test_call_override('db_fetch_row', [$sql, $log, $connection], []);
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = [], $log = true, $connection = false) {
		return test_call_override('db_fetch_row_prepared', [$sql, $params, $log, $connection], []);
	}
}

if (!function_exists('db_fetch_cell')) {
	function db_fetch_cell($sql, $col_name = '', $log = true, $connection = false) {
		return test_call_override('db_fetch_cell', [$sql, $col_name, $log, $connection], '');
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $connection = false) {
		return test_call_override('db_fetch_cell_prepared', [$sql, $params, $col_name, $log, $connection], '');
	}
}

if (!function_exists('db_index_exists')) {
	function db_index_exists($table, $index, $connection = false) {
		return test_call_override('db_index_exists', [$table, $index, $connection], false);
	}
}

if (!function_exists('db_column_exists')) {
	function db_column_exists($table, $column, $connection = false) {
		return test_call_override('db_column_exists', [$table, $column, $connection], false);
	}
}

if (!function_exists('db_table_exists')) {
	function db_table_exists($table, $connection = false) {
		return test_call_override('db_table_exists', [$table, $connection], false);
	}
}

if (!function_exists('syslog_db_table_exists')) {
	function syslog_db_table_exists($table, $log = true) {
		return test_call_override('syslog_db_table_exists', [$table, $log], db_table_exists($table));
	}
}

if (!function_exists('db_qstr')) {
	function db_qstr($value) {
		return test_call_override('db_qstr', [$value], "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'");
	}
}

/*
 * syslog_db_* generic stubs. database.php's real implementations simply
 * delegate to the equivalent db_*() call against $syslog_cnn, so the
 * defaults below mirror that delegation; a test that needs different
 * behavior installs a test_override() instead of redeclaring the function.
 */
if (!function_exists('syslog_db_execute')) {
	function syslog_db_execute($sql, $log = true) {
		return test_call_override('syslog_db_execute', [$sql, $log], db_execute($sql, $log));
	}
}

if (!function_exists('syslog_db_execute_prepared')) {
	function syslog_db_execute_prepared($sql, $params = [], $log = true) {
		return test_call_override('syslog_db_execute_prepared', [$sql, $params, $log], db_execute_prepared($sql, $params, $log));
	}
}

if (!function_exists('syslog_db_affected_rows')) {
	function syslog_db_affected_rows() {
		return test_call_override('syslog_db_affected_rows', [], db_affected_rows());
	}
}

if (!function_exists('syslog_db_fetch_assoc')) {
	function syslog_db_fetch_assoc($sql, $log = true) {
		return test_call_override('syslog_db_fetch_assoc', [$sql, $log], db_fetch_assoc($sql, $log));
	}
}

if (!function_exists('syslog_db_fetch_assoc_prepared')) {
	function syslog_db_fetch_assoc_prepared($sql, $params = [], $log = true) {
		return test_call_override('syslog_db_fetch_assoc_prepared', [$sql, $params, $log], db_fetch_assoc_prepared($sql, $params, $log));
	}
}

if (!function_exists('syslog_db_fetch_row')) {
	function syslog_db_fetch_row($sql, $log = true) {
		return test_call_override('syslog_db_fetch_row', [$sql, $log], db_fetch_row($sql, $log));
	}
}

if (!function_exists('syslog_db_fetch_row_prepared')) {
	function syslog_db_fetch_row_prepared($sql, $params = [], $log = true) {
		return test_call_override('syslog_db_fetch_row_prepared', [$sql, $params, $log], db_fetch_row_prepared($sql, $params, $log));
	}
}

if (!function_exists('syslog_db_fetch_cell')) {
	function syslog_db_fetch_cell($sql, $col_name = '', $log = true) {
		return test_call_override('syslog_db_fetch_cell', [$sql, $col_name, $log], db_fetch_cell($sql, $col_name, $log));
	}
}

if (!function_exists('syslog_db_fetch_cell_prepared')) {
	function syslog_db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true) {
		return test_call_override('syslog_db_fetch_cell_prepared', [$sql, $params, $col_name, $log], db_fetch_cell_prepared($sql, $params, $col_name, $log));
	}
}

if (!function_exists('syslog_db_fetch_insert_id')) {
	function syslog_db_fetch_insert_id() {
		return test_call_override('syslog_db_fetch_insert_id', [], 0);
	}
}

if (!function_exists('api_plugin_db_add_column')) {
	function api_plugin_db_add_column($plugin, $table, $data) {
		return test_call_override('api_plugin_db_add_column', [$plugin, $table, $data], true);
	}
}

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) {
		return test_call_override('api_plugin_db_table_create', [$plugin, $table, $data], true);
	}
}

if (!function_exists('api_plugin_register_hook')) {
	function api_plugin_register_hook($plugin, $hook, $function, $file, $auth = false) {
		return test_call_override('api_plugin_register_hook', [$plugin, $hook, $function, $file, $auth], true);
	}
}

if (!function_exists('api_plugin_register_realm')) {
	function api_plugin_register_realm($plugin, $files, $description, $login) {
		return test_call_override('api_plugin_register_realm', [$plugin, $files, $description, $login], true);
	}
}

if (!function_exists('api_plugin_hook_function')) {
	function api_plugin_hook_function($name, $value = null) {
		return test_call_override('api_plugin_hook_function', [$name, $value], $value);
	}
}

if (!function_exists('api_plugin_user_realm_auth')) {
	function api_plugin_user_realm_auth($file) {
		return test_call_override('api_plugin_user_realm_auth', [$file], true);
	}
}

if (!function_exists('api_plugin_replicate_config')) {
	function api_plugin_replicate_config() {
		return test_call_override('api_plugin_replicate_config', [], true);
	}
}

if (!function_exists('read_config_option')) {
	function read_config_option($name, $force = false) {
		return test_call_override('read_config_option', [$name, $force], '');
	}
}

if (!function_exists('set_config_option')) {
	function set_config_option($name, $value) {
		return test_call_override('set_config_option', [$name, $value], true);
	}
}

if (!function_exists('read_user_setting')) {
	function read_user_setting($name, $default = '', $force = false) {
		return test_call_override('read_user_setting', [$name, $default, $force], $default);
	}
}

if (!function_exists('html_escape')) {
	function html_escape($string) {
		return test_call_override('html_escape', [$string], htmlspecialchars((string) $string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}
}

if (!function_exists('__')) {
	function __($text, ...$args) {
		return test_call_override('__', array_merge([$text], $args), $text);
	}
}

if (!function_exists('__esc')) {
	function __esc($text, ...$args) {
		return test_call_override('__esc', array_merge([$text], $args), html_escape(__($text)));
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $output = false, $environ = '', $level = 0) {
		return test_call_override('cacti_log', [$message, $output, $environ, $level], null);
	}
}

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($array) {
		return is_array($array) || $array instanceof Countable ? count($array) : 0;
	}
}

if (!function_exists('is_realm_allowed')) {
	function is_realm_allowed($realm) {
		return test_call_override('is_realm_allowed', [$realm], true);
	}
}

if (!function_exists('raise_message')) {
	function raise_message($id, $text = '', $level = MESSAGE_LEVEL_INFO) {
		return test_call_override('raise_message', [$id, $text, $level], null);
	}
}

if (!function_exists('html_header')) {
	function html_header($items, $span = 1) {
		return test_call_override('html_header', [$items, $span], null);
	}
}

if (!function_exists('html_header_sort')) {
	function html_header_sort($display_text, $sort_column, $sort_direction, $rows = 1, $url = '') {
		return test_call_override('html_header_sort', [$display_text, $sort_column, $sort_direction, $rows, $url], null);
	}
}

if (!function_exists('html_start_box')) {
	function html_start_box($title, $width, $tabs, $colspan, $align, $add_link) {
		return test_call_override('html_start_box', [$title, $width, $tabs, $colspan, $align, $add_link], null);
	}
}

if (!function_exists('html_end_box')) {
	function html_end_box($trailing_br = true, $ret = false) {
		return test_call_override('html_end_box', [$trailing_br, $ret], null);
	}
}

if (!function_exists('form_input_validate')) {
	function form_input_validate($value, $name, $regex, $optional, $error) {
		return test_call_override('form_input_validate', [$value, $name, $regex, $optional, $error], $value);
	}
}

if (!function_exists('is_error_message')) {
	function is_error_message() {
		return test_call_override('is_error_message', [], false);
	}
}

if (!function_exists('sql_save')) {
	function sql_save($array, $table, $key = 'id') {
		return test_call_override('sql_save', [$array, $table, $key], isset($array[$key]) ? $array[$key] : 1);
	}
}

if (!function_exists('get_request_var')) {
	function get_request_var($name) {
		return test_call_override('get_request_var', [$name], $GLOBALS['request'][$name] ?? '');
	}
}

if (!function_exists('get_nfilter_request_var')) {
	function get_nfilter_request_var($name) {
		return test_call_override('get_nfilter_request_var', [$name], $GLOBALS['request'][$name] ?? '');
	}
}

if (!function_exists('get_filter_request_var')) {
	function get_filter_request_var($name, $filter = FILTER_DEFAULT, $options = []) {
		return test_call_override('get_filter_request_var', [$name, $filter, $options], $GLOBALS['request'][$name] ?? '');
	}
}

if (!function_exists('isset_request_var')) {
	function isset_request_var($name) {
		return test_call_override('isset_request_var', [$name], isset($GLOBALS['request'][$name]));
	}
}

if (!function_exists('isempty_request_var')) {
	function isempty_request_var($name) {
		return test_call_override('isempty_request_var', [$name], !isset($GLOBALS['request'][$name]) || $GLOBALS['request'][$name] === '');
	}
}

if (!function_exists('set_request_var')) {
	function set_request_var($name, $value) {
		$GLOBALS['request'][$name] = $value;

		return test_call_override('set_request_var', [$name, $value], null);
	}
}

if (!function_exists('kill_session_var')) {
	function kill_session_var($name) {
		unset($_SESSION[$name]);

		return test_call_override('kill_session_var', [$name], null);
	}
}

if (!function_exists('load_current_session_value')) {
	function load_current_session_value($name, $session_name, $default) {
		return test_call_override('load_current_session_value', [$name, $session_name, $default], $default);
	}
}

if (!function_exists('validate_store_request_vars')) {
	function validate_store_request_vars($filters, $session_prefix) {
		return test_call_override('validate_store_request_vars', [$filters, $session_prefix], null);
	}
}

if (!function_exists('get_order_string')) {
	function get_order_string() {
		return test_call_override('get_order_string', [], '');
	}
}

if (!function_exists('filter_value')) {
	function filter_value($value, $filter, $href = '') {
		return test_call_override('filter_value', [$value, $filter, $href], html_escape($value));
	}
}

if (!function_exists('csrf_check')) {
	function csrf_check($fatal = true) {
		return test_call_override('csrf_check', [$fatal], true);
	}
}

if (!function_exists('get_username')) {
	function get_username($user_id) {
		return test_call_override('get_username', [$user_id], 'admin');
	}
}

if (!function_exists('sanitize_search_string')) {
	function sanitize_search_string($value) {
		return test_call_override('sanitize_search_string', [$value], $value);
	}
}

if (!function_exists('title_trim')) {
	function title_trim($string, $length = 60) {
		return test_call_override('title_trim', [$string, $length], strlen($string) > $length ? substr($string, 0, $length - 3) . '...' : $string);
	}
}

if (!function_exists('form_alternate_row')) {
	function form_alternate_row($id = '', $new_row = false, $rowid = 0) {
		return test_call_override('form_alternate_row', [$id, $new_row, $rowid], null);
	}
}

if (!function_exists('form_end_row')) {
	function form_end_row() {
		return test_call_override('form_end_row', [], null);
	}
}

if (!function_exists('form_start')) {
	function form_start($action, $id = '') {
		return test_call_override('form_start', [$action, $id], null);
	}
}

if (!function_exists('form_save_button')) {
	function form_save_button($cancel_url, $mode = 'save', $extra_url_args = '', $show_delete = true) {
		return test_call_override('form_save_button', [$cancel_url, $mode, $extra_url_args, $show_delete], null);
	}
}

if (!function_exists('draw_edit_form')) {
	function draw_edit_form($form_array) {
		return test_call_override('draw_edit_form', [$form_array], null);
	}
}

if (!function_exists('get_selected_theme')) {
	function get_selected_theme() {
		return test_call_override('get_selected_theme', [], 'classic');
	}
}

if (!function_exists('plugin_test_read_source')) {
	/**
	 * Read a plugin source file as text, for tests that assert on patterns
	 * in the source (naming conventions, hardening comments, etc.) rather
	 * than executing it.
	 *
	 * @param string $relative_file File name relative to the plugin root.
	 *
	 * @return string
	 */
	function plugin_test_read_source($relative_file) {
		$path = realpath(__DIR__ . '/../' . $relative_file);

		if ($path === false) {
			throw new RuntimeException("Unable to resolve required file: {$relative_file}");
		}

		$contents = file_get_contents($path);

		if ($contents === false) {
			throw new RuntimeException("Unable to read required file: {$relative_file}");
		}

		return $contents;
	}
}

/**
 * Load a plugin source file at global scope.
 *
 * Some plugin files define data as file-scope variables that the rest of
 * the plugin reads as globals, and they read $config while doing so.
 * Requiring them from inside a method would make both halves of that
 * method-local, so the require happens here and any variable the file
 * introduced is published to $GLOBALS.
 *
 * @param string $path Absolute path to the file.
 *
 * @return void
 */
function syslog_test_load($path) {
	global $config;

	$__before = get_defined_vars();

	require_once $path;

	foreach (get_defined_vars() as $__name => $__value) {
		if (!array_key_exists($__name, $__before) && strncmp($__name, '__', 2) !== 0) {
			$GLOBALS[$__name] = $__value;
		}
	}
}

/**
 * Load a plugin source file relative to the plugin root, once per process.
 *
 * A plain function rather than a TestCase method: Pest's directory-wide
 * pest()->extend()->in() binding proved unreliable across Pest versions
 * (tests kept getting the default PHPUnit\Framework\TestCase instead of our
 * TestCase, causing "Call to undefined method ...::loadPluginSource()"), so
 * tests call this directly instead of $this->loadPluginSource().
 *
 * @param string $file File name relative to the plugin root.
 *
 * @return void
 */
function syslog_load_plugin_source($file) {
	syslog_test_load(dirname(__DIR__) . '/' . $file);
}
