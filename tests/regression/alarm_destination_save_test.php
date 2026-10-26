<?php
// Test the actual native save function without bootstrapping an HTTP request.
const MESSAGE_LEVEL_ERROR = 1;
$_SESSION['sess_user_id'] = 1;
$syslogdb_default = 'fixture';
$saved = [];
function get_username($id) { return 'fixture'; }
function get_hash_syslog(...$args) { return str_repeat('a', 32); }
function form_input_validate($value, ...$args) { return $value; }
function is_error_message() { return false; }
function __($text, ...$args) { return $text; }
function raise_message(...$args) {}
function syslog_get_alert_sql(...$args) { return ['sql' => 'SELECT 1', 'params' => []]; }
function cacti_sizeof($items) { return count($items); }
function syslog_db_fetch_assoc_prepared(...$args) { return []; }
function syslog_sync_save($row, ...$args) { $GLOBALS['saved'] = $row; }
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog_alerts.php');
$start = strpos($source, 'function api_syslog_alert_save(');
eval(substr($source, $start, strpos($source, 'function api_syslog_alert_remove(', $start) - $start));
$args = [7, 'Rule', 1, 1, 5, 'messagec', 'error', '', '', 'on', 1, '/bin/false', 0, '', 0, '', 0, 0, 'disabled', '1', '00:00', '00:00', '', '', 0, 'minute', 5, 'minute', ''];
foreach (['syslog', 'alarmmanager', 'invalid'] as $destination) {
    $saved = [];
    $result = api_syslog_alert_save(...array_merge($args, [$destination]));
    if ($destination === 'invalid' ? ($result !== false || $saved) : ($result !== null || $saved['alarm_destination'] !== $destination)) {
        throw new RuntimeException('Destination validation or persistence failed');
    }
}
api_syslog_alert_save(...$args);
if ($saved['alarm_destination'] !== 'syslog') { throw new RuntimeException('Existing callers must default to Syslog Alarming'); }
echo "Alarm destination save checks passed.\n";
