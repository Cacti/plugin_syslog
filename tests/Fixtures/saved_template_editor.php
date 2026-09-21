<?php
// Render the real template view with Cacti helpers and fixture data, without a DB.
$cacti = $argv[1];
function __($text, ...$args) { return $text; }
function __esc($text, ...$args) { return html_escape($text); }
function get_current_page() { return 'syslog_saved_searches.php'; }
function cacti_sizeof($items) { return is_array($items) ? count($items) : 0; }
function cacti_count($items) { return count($items); }
function is_realm_allowed($realm) { return false; }
function sanitize_uri($uri) { return $uri; }
function read_config_option($key) { return ''; }
function api_plugin_hook_function($name, $value) { return $value; }
function syslog_db_fetch_assoc($sql) { return []; }
function is_resource_writable($path) { return false; }
// Descriptions are static fixture text; vendor dependencies are not installed here.
class HTMLPurifier_Config {
	static function createDefault() { return new self(); }
	function set($key, $value) {}
}
class HTMLPurifier {
	function __construct($config) {}
	function purify($value) { return htmlspecialchars($value, ENT_QUOTES); }
}
$config = ['poller_id' => 1, 'url_path' => '/cacti/', 'base_path' => $cacti];
$_REQUEST = ['edit' => 7];
require $cacti . '/lib/html.php';
require $cacti . '/lib/html_form.php';
require $cacti . '/lib/html_utility.php';
require dirname(__DIR__, 2) . '/functions.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog_saved_searches.php');
eval(substr($source, strpos($source, 'function syslog_template_list(')));
$row = ['id' => 7, 'name' => 'Router errors', 'user' => 'admin', 'search' => 'host = "router-1" AND (message contains "error" OR priority = "warning") AND logtime last "86400"'];
if (($argv[2] ?? '') === 'list') {
	syslog_template_list([$row]);
} else {
	syslog_template_edit($row, '');
}
