<?php
// Local command fixture: record delivery context without contacting a service.
file_put_contents($argv[1], json_encode([
	'event' => getenv('SYSLOG_EVENT'),
	'breach_id' => getenv('SYSLOG_BREACH_ID'),
	'alert_id' => getenv('SYSLOG_ALERT_ID'),
	'hostname' => getenv('SYSLOG_HOSTNAME'),
	'poller_id' => getenv('SYSLOG_POLLER_ID'),
	'arguments' => array_slice($argv, 2)
]) . "\n", FILE_APPEND);
if (in_array('--fail', $argv, true) || (in_array('--fail-clear-once', $argv, true) && getenv('SYSLOG_EVENT') === 'clear'
	&& count(array_filter(file($argv[1], FILE_IGNORE_NEW_LINES), static fn($line) => json_decode($line, true)['event'] === 'clear')) === 1)) {
	print "Temporary failure\n";
	exit(75);
}
