<?php
/** SQLite fixture for preserving Syslog grants while retiring the umbrella realm.
 * Run: php tests/regression/rule_realm_upgrade_test.php
 */
require dirname(__DIR__, 2) . '/setup.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE plugin_realms (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin TEXT, file TEXT, display TEXT)');
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER, PRIMARY KEY(user_id, realm_id))');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER, PRIMARY KEY(group_id, realm_id))');
$db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
function db_fetch_assoc_prepared($sql, $params = []) {
	global $db;
	$stmt = $db->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = []) {
	global $db, $fail_grants, $invalidated;
	if (!empty($fail_grants) && str_starts_with($sql, 'INSERT IGNORE INTO user_auth_group_realm')) {
		return false;
	}
	if (str_starts_with($sql, 'DELETE FROM user_auth_cache')) {
		$invalidated[] = (int) $params[0];
		return true;
	}
	if (str_starts_with($sql, 'UPDATE user_auth SET reset_perms')) {
		return true;
	}
	return $db->prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql))->execute($params);
}
function db_begin_transaction() { global $db; return $db->beginTransaction(); }
function db_commit_transaction() { global $db; return $db->commit(); }
function db_rollback_transaction() { global $db; return $db->rollBack(); }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

$db->exec("INSERT INTO plugin_realms(plugin,file,display) VALUES
	('syslog','syslog.php','Syslog User'),
	('syslog','syslog_alerts.php,syslog_removal.php,syslog_reports.php,syslog_saved_searches.php,syslog_dashboards.php','Legacy Administration'),
	('syslog','syslog_administrator.php','Syslog Administrator')");
$db->exec('INSERT INTO user_auth_realm VALUES (1,101),(2,102),(3,103)');
$db->exec('INSERT INTO user_auth_group_realm VALUES (10,102),(11,103)');
$db->exec('INSERT INTO user_auth_group_members VALUES (10,5),(11,4)');
$invalidated = [];

check(syslog_upgrade_create_permission_realms(), 'Granular realm creation succeeds');
sort($invalidated);
check($invalidated === [1,2,5], 'Legacy grant migration invalidates affected sessions');
$invalidated = [];
check(syslog_upgrade_consolidate_rule_realms(), 'Duplicate realm repair succeeds');
check(syslog_upgrade_explicit_realm_grants(), 'Explicit-grant migration succeeds');

$realms = $db->query("SELECT id, display FROM plugin_realms WHERE plugin = 'syslog'")->fetchAll(PDO::FETCH_ASSOC);
$ids = [];
foreach ($realms as $realm) {
	$ids[$realm['display']] = (int) $realm['id'] + 100;
}
foreach (['Rule Viewer', 'Rule Administrator', 'Syslog Administration', 'Share Saved Templates', 'Share Dashboards'] as $display) {
	check(isset($ids[$display]), "$display exists");
}
check(!isset($ids['Syslog Administrator']), 'Umbrella realm retired');
foreach (['user_auth_realm' => ['user_id', 3], 'user_auth_group_realm' => ['group_id', 11]] as $table => [$owner, $id]) {
	foreach ($ids as $realm_id) {
		$count = $db->query("SELECT COUNT(*) FROM $table WHERE $owner = $id AND realm_id = $realm_id")->fetchColumn();
		check((int) $count === 1, 'Umbrella grants copied to each explicit realm');
	}
}
check((int) $db->query('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = 2 AND realm_id = ' . $ids['Rule Viewer'])->fetchColumn() === 1,
	'Legacy rule administrator can open viewer pages');
check((int) $db->query('SELECT COUNT(*) FROM user_auth_group_realm WHERE group_id = 10 AND realm_id = ' . $ids['Rule Viewer'])->fetchColumn() === 1,
	'Rule administrator group can open viewer pages');
check((int) $db->query('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = 1 AND realm_id = ' . $ids['Rule Administrator'])->fetchColumn() === 0,
	'Viewer was not elevated to administrator');
sort($invalidated);
check($invalidated === [2,3,4,5], 'Changed direct and group permissions invalidate active sessions');

$before = $db->query('SELECT user_id, realm_id FROM user_auth_realm ORDER BY user_id, realm_id')->fetchAll(PDO::FETCH_NUM);
$invalidated = [];
check(syslog_upgrade_explicit_realm_grants(), 'Repeated migration succeeds');
check($before === $db->query('SELECT user_id, realm_id FROM user_auth_realm ORDER BY user_id, realm_id')->fetchAll(PDO::FETCH_NUM),
	'Repeated migration changes no grants');
check($invalidated === [], 'Repeated migration does not invalidate sessions again');

$fail_grants = true;
$before = $db->query('SELECT group_id, realm_id FROM user_auth_group_realm ORDER BY group_id, realm_id')->fetchAll(PDO::FETCH_NUM);
check(!syslog_upgrade_explicit_realm_grants(), 'Failed grant copy aborts migration');
check($before === $db->query('SELECT group_id, realm_id FROM user_auth_group_realm ORDER BY group_id, realm_id')->fetchAll(PDO::FETCH_NUM),
	'Failed migration rolls back');

$setup = file_get_contents(dirname(__DIR__, 2) . '/setup.php');
check(str_contains($setup, "api_plugin_register_realm('syslog', 'syslog.php'"), 'Fresh install uses Cacti realm API');
check(!str_contains($setup, "api_plugin_register_realm('syslog', 'syslog_administrator.php'"), 'Fresh install has no umbrella realm');
check(!str_contains($setup, 'user_auth_realm_filenames'), 'Plugin does not rewrite Cacti filename maps');
print "rule_realm_upgrade_test passed\n";
