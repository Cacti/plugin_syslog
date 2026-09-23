<?php

it('keeps global maintenance closed unless a device rule explicitly permits a priority', function () {
	syslog_load_plugin_source('functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog';
	$GLOBALS['syslog_incoming_config'] = ['hostField' => 'host', 'priorityField' => 'priority_id'];

	$active = syslog_device_rule_sql(true);
	expect($active['sql'])->toContain('AND EXISTS', 'Maintenance must not let a host with no device rule through')
		->toContain("dr.allow_maintenance = 'on'")
		->toContain('pass_through_priority')
		->toContain('AND NOT EXISTS', 'An active device pause still applies during maintenance');
	expect($active['params'])->toHaveCount(1);

	$inactive = syslog_device_rule_sql(false);
	expect($inactive['sql'])->not->toContain('AND EXISTS')
		->toContain('AND NOT EXISTS');
});
