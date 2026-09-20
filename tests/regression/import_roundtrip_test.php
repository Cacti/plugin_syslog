<?php
namespace SyslogImportTest;
require_once dirname(__DIR__, 2) . '/functions.php';
const MESSAGE_LEVEL_ERROR = 1;
const MESSAGE_LEVEL_INFO = 2;
function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
function header($value) {}
function __($value, ...$args) { return $value; }
function __esc($value, ...$args) { return $value; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function syslog_allow_edits() { return true; }
function set_request_var($key, $value) { $_POST[$key] = $value; }
function isset_request_var($key) { return isset($_POST[$key]); }
function get_nfilter_request_var($key) { return $_POST[$key] ?? ''; }
function sanitize_unserialize_selected_items($value) { return unserialize($value, ['allowed_classes' => false]); }
function syslog_get_import_xml_payload($url) { return $GLOBALS['payload']; }
function syslog_db_fetch_row_prepared(...$args) { return $GLOBALS['fixture']; }
function syslog_dashboard_panels($id) { return $GLOBALS['panels']; }
function syslog_fetch_item_shares(...$args) { return ['users' => [['id' => 7]], 'groups' => [['id' => 8]]]; }
function syslog_db_fetch_cell_prepared(...$args) { return $GLOBALS['existing_id']; }
function syslog_db_column_exists(...$args) { return true; }
function syslog_sql_save($save, $table, $key) { $GLOBALS['saved'] = $save; return $save['id'] ?: 12; }
function syslog_save_item_shares($kind, $id, $users, $groups) { $GLOBALS['shares'] = [$users, $groups]; }
function syslog_db_execute_prepared($sql, $params) { $GLOBALS['queries'][] = [$sql, $params]; return true; }
function raise_message($id, $text, $level) { $GLOBALS['messages'][] = [$text, $level]; }
function load_function($file, $name) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
	$start = strpos($source, 'function ' . $name . '(');
	$end = strpos($source, "\n}", $start) + 2;
	eval('namespace ' . __NAMESPACE__ . ';' . substr($source, $start, $end - $start));
}

$fixtures = [
	'syslog_alert' => ['name' => 'Alert', 'hash' => 'a', 'method' => '0', 'severity' => '1', 'message' => 'error'],
	'syslog_remove' => ['name' => 'Removal', 'hash' => 'r', 'method' => 'trans', 'message' => 'noise'],
	'syslog_saved_searches' => ['name' => 'Search', 'hash' => 's', 'search' => 'error AND router'],
	'syslog_dashboards' => ['name' => 'Dashboard', 'hash' => 'd', 'panels' => []]
];
foreach ($fixtures as $source => $fixture) {
	$payload = \syslog_rules_array2json($source, [$fixture]);
	foreach ($fixtures as $target => $_) {
		check((\syslog_parse_rule_import($payload, $target) !== false) === ($source === $target), "$source -> $target type check");
		check((\syslog_parse_rule_import(json_encode([$fixture]), $target) !== false) === ($source === $target), "$source -> $target legacy JSON check");
	}
}
check(\syslog_parse_rule_import('{invalid', 'syslog_alert') === false, 'Malformed JSON rejected');
check(\syslog_parse_rule_import(json_encode(['templates' => [$fixtures['syslog_alert'], $fixtures['syslog_remove']]]), 'syslog_alert') === false, 'Mixed imports rejected before writes');

$db = 'syslog';
foreach (['syslog_saved_searches' => 'syslog_saved_search', 'syslog_dashboards' => 'syslog_dashboard'] as $table => $prefix) {
	// Cacti's form_save_button submits action=save, including multipart imports.
	$_POST = ['action' => 'save', 'save_component_import' => '1'];
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . $table . '.php');
	$start = strpos($source, "if ((isset_request_var('import')");
	check($start !== false, 'Import dispatch exists');
	$end = strpos($source, "\n}", $start) + 2;
	eval('namespace ' . __NAMESPACE__ . ';' . substr($source, $start, $end - $start));
	check($_POST['action'] === 'import', 'Native import submission reaches importer');
	load_function($table . '.php', $prefix . '_export');
	load_function($table . '.php', $prefix . '_import');
	$GLOBALS['fixture'] = $fixtures[$table] + ['id' => 3, 'user' => 'admin', 'is_global' => 'on', 'date' => 123, 'updated' => 124];
	unset($GLOBALS['fixture']['panels']);
	$GLOBALS['panels'] = [['id' => 9, 'dashboard_id' => 3, 'title' => 'Errors', 'expression' => 'error', 'width' => 2, 'height' => 420]];
	$_POST = ['selected_items' => serialize(['3'])];
	ob_start();
	call_user_func(__NAMESPACE__ . '\\' . $prefix . '_export');
	$GLOBALS['payload'] = ob_get_clean();
	foreach ([0, 3] as $existing) {
		$GLOBALS['existing_id'] = $existing;
		$GLOBALS['messages'] = $GLOBALS['queries'] = [];
		call_user_func(__NAMESPACE__ . '\\' . $prefix . '_import');
		check($GLOBALS['saved']['hash'] === $fixtures[$table]['hash'], 'Hash survives round trip');
		check($GLOBALS['saved']['id'] === $existing, 'Reimport updates matching hash');
		check($GLOBALS['shares'] === [[7], [8]], 'Sharing survives round trip');
		check(count($GLOBALS['messages']) === 1 && $GLOBALS['messages'][0][1] === MESSAGE_LEVEL_INFO, 'Success is reported');
		if ($table === 'syslog_saved_searches') {
			check($GLOBALS['saved']['search'] === $fixtures[$table]['search'], 'Search survives round trip');
		} else {
			check(count($GLOBALS['queries']) === 2, 'Panels replaced');
			check($GLOBALS['queries'][1][1][11] === 2 && $GLOBALS['queries'][1][1][12] === 420, 'Panel dimensions preserved');
		}
	}
	if ($table === 'syslog_dashboards') {
		$GLOBALS['payload'] = \syslog_rules_array2json($table, [$fixtures[$table] + ['user' => 'admin']]);
		$GLOBALS['queries'] = [];
		call_user_func(__NAMESPACE__ . '\\' . $prefix . '_import');
		check(count($GLOBALS['queries']) === 1 && str_starts_with($GLOBALS['queries'][0][0], 'DELETE'), 'Empty import clears existing panels');
	}
	$GLOBALS['payload'] = \syslog_rules_array2json('syslog_remove', [$fixtures['syslog_remove']]);
	$GLOBALS['saved'] = null;
	$GLOBALS['messages'] = [];
	call_user_func(__NAMESPACE__ . '\\' . $prefix . '_import');
	check($GLOBALS['saved'] === null && $GLOBALS['messages'][0][1] === MESSAGE_LEVEL_ERROR, 'Wrong type reports an error without writes');
}
print "import_roundtrip_test passed\n";
