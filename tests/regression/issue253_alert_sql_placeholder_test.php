<?php

$GLOBALS['syslogdb_default']       = 'syslogdb';
$GLOBALS['syslog_incoming_config'] = [
	'hostField'    => 'host',
	'programField' => 'program',
	'facilityField'=> 'facility',
	'textField'    => 'message'
];

require_once dirname(__DIR__, 2) . '/functions.php';

function issue253_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$hostAlert = [
	'type'    => 'host',
	'message' => 'router1'
];

$programAlert = [
	'type'    => 'program',
	'message' => 'sshd'
];

$hostSql = syslog_get_alert_sql($hostAlert, 55);
$progSql = syslog_get_alert_sql($programAlert, 66);

issue253_assert(strpos($hostSql['sql'], 'AND `status` = ?') !== false, 'Host alert SQL must keep status as a placeholder.');
issue253_assert(strpos($hostSql['sql'], '?55') === false, 'Host alert SQL must not concatenate uniqueID into SQL text.');
issue253_assert(count($hostSql['params']) === 2, 'Host alert SQL must pass two prepared parameters.');
issue253_assert($hostSql['params'][1] === 55, 'Host alert status param should be the uniqueID.');

issue253_assert(strpos($progSql['sql'], 'AND `status` = ?') !== false, 'Program alert SQL must keep status as a placeholder.');
issue253_assert(strpos($progSql['sql'], '?66') === false, 'Program alert SQL must not concatenate uniqueID into SQL text.');
issue253_assert(count($progSql['params']) === 2, 'Program alert SQL must pass two prepared parameters.');
issue253_assert($progSql['params'][1] === 66, 'Program alert status param should be the uniqueID.');

print "issue253_alert_sql_placeholder_test passed\n";
