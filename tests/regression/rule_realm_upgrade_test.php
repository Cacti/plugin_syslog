<?php
/** SQLite fixture for repeated realm migrations, grants and UI grouping.
 * Run: php tests/regression/rule_realm_upgrade_test.php /path/to/cacti/lib/plugins.php
 */
require dirname(__DIR__, 2) . '/setup.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE plugin_realms (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin TEXT, file TEXT, display TEXT)');
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER, PRIMARY KEY(user_id, realm_id))');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER, PRIMARY KEY(group_id, realm_id))');
function db_fetch_assoc_prepared($sql, $params = []) {
	global $db;
	$stmt = $db->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = []) {
	global $db, $fail_grants;
	if (!empty($fail_grants) && strpos($sql, 'INSERT IGNORE INTO user_auth_group_realm') === 0) { return false; }
	return $db->prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql))->execute($params);
}
function db_begin_transaction() { global $db; return $db->beginTransaction(); }
function db_commit_transaction() { global $db; return $db->commit(); }
function db_rollback_transaction() { global $db; return $db->rollBack(); }
function api_plugin_replicate_config() {}
function api_plugin_valid_entrypoint($plugin, $function) { return true; }
function api_plugin_user_realm_auth($file) { return false; }
function is_realm_allowed($realm) {
	global $allowed_realms;
	return in_array($realm, $allowed_realms ?? [], true);
}
function cacti_sizeof($value) { return count($value); }
function __($text, $domain = '') { return $text; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

// Exercise Cacti's actual filename-overlap registration algorithm.
$source = file_get_contents($argv[1]);
$start = strpos($source, 'function api_plugin_register_realm(');
$end = strpos($source, 'function api_plugin_remove_realms(', $start);
eval(substr($source, $start, $end - $start));

$viewer_files = 'syslog_alerts.php,syslog_removal.php,syslog_reports.php';
$admin_file = 'syslog_rule_administrator.php';
$syslog_administrator_file = 'syslog_administrator.php';
foreach ([['syslog.php', 'Syslog User'], [$admin_file, 'Rule Administrator'], [$admin_file, 'Rule Administrator'], [$admin_file, 'Rule Viewer'], [$viewer_files, 'Rule Viewer']] as $row) {
	db_execute_prepared('INSERT INTO plugin_realms(plugin,file,display) VALUES(?,?,?)', ['syslog', $row[0], $row[1]]);
}
// Overlapping and disjoint grants must survive, including group-only access.
$db->exec('INSERT INTO user_auth_realm VALUES (1,102),(1,103),(2,103),(3,105)');
$db->exec('INSERT INTO user_auth_group_realm VALUES (9,103),(10,105)');
$before = $db->query('SELECT * FROM plugin_realms')->fetchAll();
$grants_before = $db->query('SELECT * FROM user_auth_realm')->fetchAll();
$fail_grants = true;
check(!syslog_upgrade_consolidate_rule_realms(), 'A failed grant copy must abort repair');
check($before === $db->query('SELECT * FROM plugin_realms')->fetchAll(), 'Failure must retain all realm records');
check($grants_before === $db->query('SELECT * FROM user_auth_realm')->fetchAll(), 'Failure must roll back grant changes');
$fail_grants = false;

$_SESSION['sess_auth_names'] = ['syslog_alerts.php' => 999];
$user_auth_roles = ['Syslog' => [999]];
for ($request = 0; $request < 50; $request++) {
	syslog_upgrade_saved_search_realm();
	syslog_upgrade_dashboard_realm();
	syslog_upgrade_rule_permissions();
	check(syslog_upgrade_consolidate_rule_realms(), 'Repair must succeed');
	api_plugin_register_realm('syslog', $viewer_files, 'Rule Viewer', false);
	api_plugin_register_realm('syslog', $admin_file, 'Rule Administrator', false);
	api_plugin_register_realm('syslog', $syslog_administrator_file, 'Syslog Administrator', false);
	syslog_refresh_permission_roles();
	check((int) $db->query('SELECT COUNT(*) FROM plugin_realms')->fetchColumn() === 4, 'Repeated upgrades must not add realms');
	check($user_auth_roles['Syslog'] === [101, 102, 104, 106], 'Every surviving realm must appear once under Syslog');
	check($user_auth_realm_filenames['syslog_alerts.php'] === 104, 'Viewer filename must use the surviving Viewer realm');
	check($user_auth_realm_filenames[$admin_file] === 102, 'Administrator must remain separate');
	check(!isset($user_auth_realm_filenames['syslog_dashboards.php']), 'Viewer must not acquire dashboard administration');
}
$syslog_administrator_id = (int) $db->query("SELECT id FROM plugin_realms WHERE file = '$syslog_administrator_file'")->fetchColumn() + 100;
$allowed_realms = [$syslog_administrator_id];
syslog_refresh_permission_roles();
foreach (['syslog.php', 'syslog_alerts.php', 'syslog_removal.php', 'syslog_reports.php', $admin_file, 'syslog_saved_searches.php', 'syslog_saved_searches_share.php', 'syslog_dashboards.php', 'syslog_dashboards_share.php'] as $page) {
	check(($user_auth_realm_filenames[$page] ?? 0) === $syslog_administrator_id, 'Syslog Administrator must imply every Syslog permission');
}
$allowed_realms = [];
syslog_refresh_permission_roles();

// A Rule Administrator must be able to see and open the rule menu entries
// without acquiring saved-search or dashboard administration.
$rule_administrator_id = (int) $db->query("SELECT id FROM plugin_realms WHERE display = 'Rule Administrator'")->fetchColumn() + 100;
$allowed_realms = [$rule_administrator_id];
syslog_refresh_permission_roles();
foreach (['syslog_alerts.php', 'syslog_removal.php', 'syslog_reports.php'] as $page) {
	check(($user_auth_realm_filenames[$page] ?? 0) === $rule_administrator_id, 'Rule Administrator must imply rule menu access');
}
foreach (['syslog_saved_searches.php', 'syslog_dashboards.php'] as $page) {
	check(($user_auth_realm_filenames[$page] ?? 0) !== $rule_administrator_id, 'Rule Administrator must not acquire template or dashboard administration');
}
$allowed_realms = [];
syslog_refresh_permission_roles();

check($db->query('SELECT user_id,realm_id FROM user_auth_realm ORDER BY user_id')->fetchAll(PDO::FETCH_NUM) === [[1,102],[2,102],[3,104]], 'Preserve all user grants without elevating Viewer');
check($db->query('SELECT group_id,realm_id FROM user_auth_group_realm ORDER BY group_id')->fetchAll(PDO::FETCH_NUM) === [[9,102],[10,104]], 'Preserve group grants and remove obsolete IDs');
check(!isset($_SESSION['sess_auth_names']['syslog_alerts.php']), 'Invalidate stale filename cache');

// A single corrupted Viewer must also be repaired before registration.
db_execute_prepared('UPDATE plugin_realms SET file = ? WHERE id = ?', [$admin_file, 4]);
check(syslog_upgrade_consolidate_rule_realms(), 'Repair a single corrupted Viewer');
check($db->query('SELECT file FROM plugin_realms WHERE id=4')->fetchColumn() === $viewer_files, 'Restore canonical Viewer files');

// A legacy combined administration realm becomes Rule Administrator only;
// saved-search templates and dashboards must be granted separately.
db_execute_prepared('INSERT INTO plugin_realms(plugin,file,display) VALUES(?,?,?)', [
	'syslog',
	'syslog_alerts.php,syslog_removal.php,syslog_reports.php,syslog_saved_searches.php,syslog_dashboards.php',
	'Syslog Administration'
]);
$legacy_id = (int) $db->lastInsertId();
syslog_upgrade_rule_permissions();
$legacy_files = (string) $db->query("SELECT file FROM plugin_realms WHERE id = $legacy_id")->fetchColumn();
check($legacy_files === $admin_file, 'Legacy rule administration must not retain templates or dashboards');
print "rule_realm_upgrade_test passed (50 upgrade cycles, grants, rollback, grouping, stale cache)\n";
