<?php

$source = file_get_contents(dirname(__DIR__, 2) . '/syslog.php');
$start = strpos($source, 'function saved_search_delete()');
$end = strpos($source, 'function saved_search_global()', $start);
eval(substr($source, $start, $end - $start));
function get_username($id) { return 'tester'; }
function get_filter_request_var($key, $filter) { return 7; }
function get_request_var($key) { return 'syslog'; }
function syslog_db_fetch_row_prepared($sql, $params) { return $GLOBALS['row']; }
function syslog_saved_search_admin() { return $GLOBALS['admin']; }
function syslog_db_execute_prepared($sql, $params) { $GLOBALS['writes']++; return true; }
function kill_session_var($key) { unset($_SESSION[$key]); }
function __($text, $domain = '') { return $text; }
$syslogdb_default = 'syslog';
foreach ([false, true] as $admin) {
	foreach (['', 'on'] as $global) {
		foreach (['tester', 'someone-else'] as $owner) {
			$_SESSION = ['sess_user_id' => 2];
			$row = ['user' => $owner, 'is_global' => $global];
			$writes = 0;
			$result = json_decode(saved_search_delete(), true);
			$allowed = $owner === 'tester' || ($global === 'on' && $admin);
			if ($writes !== (int) $allowed || isset($result['error']) === $allowed) {
				throw new RuntimeException('Deletion permissions do not match ownership and administration');
			}
		}
	}
}
echo "saved_search_delete_permissions_test passed\n";
