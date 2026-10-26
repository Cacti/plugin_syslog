<?php
// Render the real command field definitions with Cacti's form controls.
$cacti = $argv[1];
function __($text, ...$args) { return $text; }
function __esc($text, ...$args) { return html_escape($text); }
function get_current_page() { return 'syslog_alerts.php'; }
function cacti_sizeof($items) { return is_array($items) ? count($items) : 0; }
function cacti_count($items) { return count($items); }
function is_realm_allowed($realm) { return false; }
function sanitize_uri($uri) { return $uri; }
function null_out_substitutions($value) { return $value; }
function read_config_option($key) { return ''; }
function api_plugin_installed($plugin) { return ($GLOBALS['argv'][2] ?? 'installed') === 'installed'; }
function api_plugin_hook_function($name, $value) { return $value; }
function is_resource_writable($path) { return false; }
class HTMLPurifier_Config {
	static function createDefault() { return new self(); }
	function set($key, $value) {}
}
class HTMLPurifier {
	function __construct($config) {}
	function purify($value) { return htmlspecialchars($value, ENT_QUOTES); }
}
$config = ['poller_id' => 1, 'url_path' => '/cacti/', 'base_path' => $cacti];
$_SESSION = [];
require $cacti . '/include/global_constants.php';
function cacti_html_context_escape($value, $context) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
require $cacti . '/lib/html.php';
require $cacti . '/lib/html_form.php';
require $cacti . '/lib/html_utility.php';
require dirname(__DIR__, 2) . '/includes/functions.php';
function syslog_alert_maintenance_day_options() { return []; }
function syslog_alert_maintenance_time_options() { return []; }
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog_alerts.php');
$start = strpos($source, '$fields_syslog_alert_edit = [');
$end = strpos($source, "\n\tform_start(", $start);
$severities = $message_types = $lists = $repeatarray = [];
$threshold_window_controls = $threshold_recovery_controls = '';
eval(substr($source, $start, $end - $start));
$fields_syslog_alert_edit = array_intersect_key($fields_syslog_alert_edit, array_flip(['alarm_destination', 'report_method', 'header_email', 'notify', 'email', 'body', 'spacer1', 'open_ticket', 'command', 'recovery_command']));
$alert = ['alarm_destination' => 'syslog', 'method' => 1, 'command' => '/usr/local/bin/notify.sh raise', 'recovery_command' => '/usr/local/bin/notify.sh clear <HOSTNAME>'];
form_start('syslog_alerts.php', 'syslog_edit');
draw_edit_form(['config' => ['no_form_tag' => true], 'fields' => inject_form_variables($fields_syslog_alert_edit, $alert)]);
print '</form>';
preg_match('/function changeMethod\(\) \{.*?\n\t\}/s', $source, $script);
print '<script>' . file_get_contents(dirname(__DIR__, 2) . '/js/alarm-destination.js') . 'var notifyExists = true;' . $script[0] . '</script>';
