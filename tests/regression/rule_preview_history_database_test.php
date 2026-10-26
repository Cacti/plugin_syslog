<?php
// Uses only an explicitly supplied disposable MariaDB instance.
$socket = getenv('SYSLOG_TEST_MYSQL_SOCKET');
$dsn = $socket ? 'mysql:unix_socket=' . $socket : getenv('SYSLOG_TEST_MYSQL_DSN');
if (!$dsn) { fwrite(STDERR, "Set SYSLOG_TEST_MYSQL_SOCKET or SYSLOG_TEST_MYSQL_DSN.\n"); exit(2); }
$pdo = new PDO($dsn, 'root', getenv('SYSLOG_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$syslogdb_default = 'syslog_preview_' . bin2hex(random_bytes(4));
$syslog_incoming_config = ['textField'=>'message','timeField'=>'logtime','hostField'=>'host','programField'=>'program','facilityField'=>'facility_id','priorityField'=>'priority_id'];
$calls = [];
$checks = 0;
function cacti_sizeof($value) { return count($value); }
function __($text, ...$args) { return $text; }
function syslog_db_fetch_assoc_prepared($sql, $params = [], ...$args) {
    if (!preg_match('/^SELECT\s/i', $sql)) { throw new RuntimeException('Preview attempted a write'); }
    $GLOBALS['calls'][] = $sql;
    if (!empty($GLOBALS['fail_history']) && str_contains($sql, 'ORDER BY seq DESC LIMIT 10000')) { return false; }
    $q = $GLOBALS['pdo']->prepare($sql); $q->execute($params); return $q->fetchAll();
}
function syslog_db_fetch_cell_prepared($sql, $params = [], ...$args) { $rows = syslog_db_fetch_assoc_prepared($sql, $params); return $rows === false ? false : array_values($rows[0] ?? [null])[0]; }
function syslog_db_table_exists($table, ...$args) { return true; }
require dirname(__DIR__, 2) . '/includes/functions.php';
function check_preview($condition, $message) { $GLOBALS['checks']++; if (!$condition) { throw new RuntimeException($message); } }
function preview_rule(array $conditions): array { return ['type'=>'filter','message'=>json_encode(['version'=>1,'conditions'=>$conditions], JSON_THROW_ON_ERROR)]; }
$prefix = ['join'=>'AND','negative'=>false,'field'=>'message','operator'=>'begins','value'=>'Disconnect'];
try {
    $pdo->exec("CREATE DATABASE `$syslogdb_default`");
    $pdo->exec("USE `$syslogdb_default`");
    $pdo->exec('CREATE TABLE syslog_incoming (seq BIGINT PRIMARY KEY, logtime DATETIME, message TEXT, host VARCHAR(255), program VARCHAR(255), facility_id INT, priority_id INT, raw_message TEXT, raw_time DATETIME)');
    $pdo->exec('CREATE TABLE syslog (seq BIGINT PRIMARY KEY, logtime DATETIME, message TEXT, host_id INT, program_id INT, facility_id INT, priority_id INT)');
    $pdo->exec('CREATE TABLE syslog_hosts (host_id INT PRIMARY KEY, host VARCHAR(255))');
    $pdo->exec('CREATE TABLE syslog_programs (program_id INT PRIMARY KEY, program VARCHAR(255))');
    $pdo->exec("INSERT INTO syslog_hosts VALUES (1,'router01')");
    $pdo->exec("INSERT INTO syslog_programs VALUES (1,'sshd')");
    $values = [];
    for ($i=1; $i<=10001; $i++) {
        $message = $i===1 ? 'Disconnect outside preview range' : ($i===10001 ? 'Disconnected from user sean 192.168.2.100 port 47934' : 'Other message');
        $values[] = "($i,'2026-10-26 12:53:48'," . $pdo->quote($message) . ',1,1,1,3)';
    }
    $pdo->exec('INSERT INTO syslog VALUES ' . implode(',', $values));
    $rule = preview_rule([$prefix]);
    $result = syslog_rule_preview($rule, 'alert', 100000);
    check_preview($result['error']==='' && $result['count']===1, 'Empty incoming table falls back to stored prefix match');
    check_preview($result['rows'][0]['message']==='Disconnected from user sean 192.168.2.100 port 47934', 'Original example matches prefix');
    check_preview($result['history_limit']===10000 && $result['rows'][0]['seq']==='10001', 'History scan is bounded before filtering');
    check_preview($result['rows'][0]['host']==='router01' && $result['rows'][0]['program']==='sshd', 'Normalized history names are restored');
    $host = ['join'=>'AND','negative'=>false,'field'=>'host','operator'=>'begins','value'=>'router'];
    $program = ['join'=>'AND','negative'=>false,'field'=>'program','operator'=>'=','value'=>'sshd'];
    check_preview(syslog_rule_preview(preview_rule([$prefix,$host,$program]))['count']===1, 'Shared normalized compiler handles host and program conditions');
    $syslog_incoming_config['textField']='raw_message'; $syslog_incoming_config['timeField']='raw_time';
    check_preview(syslog_rule_preview($rule)['rows'][0]['message']===$result['rows'][0]['message'], 'History uses canonical fields with custom incoming mappings');
    $syslog_incoming_config['textField']='message'; $syslog_incoming_config['timeField']='logtime';
    $pdo->exec("INSERT INTO syslog_incoming(seq,logtime,message,host,program) VALUES (1,'2026-10-26 13:00:00','Disconnected incoming','router01','sshd')");
    $incoming = syslog_rule_preview($rule);
    check_preview($incoming['count']===1 && !isset($incoming['history_limit']) && $incoming['rows'][0]['message']==='Disconnected incoming', 'Incoming matches keep existing priority and shape');
    $pdo->exec('DELETE FROM syslog_incoming');
    $calls=[];
    check_preview(syslog_rule_preview($rule,'removal')['count']===0 && count($calls)===2, 'Removal previews retain incoming-only scope');
    $calls=[];
    check_preview(syslog_rule_preview(['type'=>'sql','message'=>"message LIKE 'Disconnect%'"])['count']===0 && count($calls)===2, 'SQL previews are not rewritten for historical data');
    $invalid=preview_rule([array_replace($prefix,['field'=>'unknown'])]);
    $calls=[];
    check_preview(syslog_rule_preview($invalid)['error']!=='' && !$calls, 'Invalid filter fails before any query');
    $fail_history=true;
    check_preview(syslog_rule_preview($rule)['error']!=='', 'History read failures report an error instead of no matches');
    $fail_history=false;
    $pdo->exec("INSERT INTO syslog VALUES (10002,'2026-10-26 13:00:00','Disconnect%_ literal',1,1,1,3)");
    check_preview(syslog_rule_preview(preview_rule([array_replace($prefix,['value'=>'Disconnect%_'])]))['count']===1, 'Historical LIKE wildcards remain literal');
    check_preview((int)$pdo->query('SELECT COUNT(*) FROM syslog')->fetchColumn()===10002, 'Preview preserves history');
    for ($i=10003; $i<=10017; $i++) { $pdo->exec("INSERT INTO syslog VALUES ($i,'2026-10-26 13:00:00','Disconnected sample',1,1,1,3)"); }
    $bounded=syslog_rule_preview($rule,'alert',100000);
    check_preview(count($bounded['rows'])===10 && $bounded['count']===17, 'Historical samples retain the ten-row response cap');
    echo "Rule preview history MariaDB checks passed: $checks\n";
} finally { $pdo->exec("DROP DATABASE IF EXISTS `$syslogdb_default`"); }
