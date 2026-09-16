<?php

$GLOBALS['config']            = ['poller_id' => 1];
$GLOBALS['syslogdb_default']  = 'cacti';
$GLOBALS['saved_table_names'] = [];

function read_config_option($name) {
	return 'off';
}

function syslog_sql_save($data, $table, $primary = '') {
	$GLOBALS['saved_table_names'][] = $table;

	return 42;
}

function raise_message($message) {
}

require_once dirname(__DIR__, 2) . '/functions.php';

syslog_sync_save(['id' => '', 'name' => 'test'], 'syslog_alert', 'id');

if ($GLOBALS['saved_table_names'] !== ['syslog_alert']) {
	throw new RuntimeException('Rule saves must pass an unqualified table name to Cacti sql_save().');
}

print "rule_save_table_name_test passed\n";
