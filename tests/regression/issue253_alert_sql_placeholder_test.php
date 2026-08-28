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

issue253_assert(strpos($hostSql['sql'], 'AND `status` = 1') !== false, 'Host alert SQL must select processed incoming rows.');
issue253_assert(strpos($hostSql['sql'], 'AND `seq` <= ?') !== false, 'Host alert SQL must bound rows by sequence.');
issue253_assert(count($hostSql['params']) === 2, 'Host alert SQL must pass two prepared parameters.');
issue253_assert($hostSql['params'][1] === 55, 'Host alert sequence parameter should be the processing boundary.');

issue253_assert(strpos($progSql['sql'], 'AND `status` = 1') !== false, 'Program alert SQL must select processed incoming rows.');
issue253_assert(strpos($progSql['sql'], 'AND `seq` <= ?') !== false, 'Program alert SQL must bound rows by sequence.');
issue253_assert(count($progSql['params']) === 2, 'Program alert SQL must pass two prepared parameters.');
issue253_assert($progSql['params'][1] === 66, 'Program alert sequence parameter should be the processing boundary.');

print "issue253_alert_sql_placeholder_test passed\n";
