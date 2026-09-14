<?php

$root      = dirname(__DIR__, 2);
$functions = file_get_contents($root . '/functions.php');
$setup     = file_get_contents($root . '/setup.php');

if (preg_match_all('/function\s+syslog_json_safe\s*\(/', $functions) !== 1) {
	throw new RuntimeException('Alarm editor JSON helper must be declared exactly once in functions.php.');
}

if (!str_contains($setup, 'ALTER TABLE syslog_alert MODIFY column message TEXT NOT NULL')) {
	throw new RuntimeException('Existing alarm message columns must migrate to TEXT.');
}

if (!preg_match('/`message`\s+TEXT\s+NOT NULL/', $setup)) {
	throw new RuntimeException('Fresh alarm tables must use TEXT for structured filter documents.');
}

if (preg_match('/message\s+VARCHAR\(8192\)/i', $setup)) {
	throw new RuntimeException('Alarm filter storage must not consume 8192 inline row bytes.');
}

print "alarm_filter_upgrade_test passed\n";
