<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Alert command tokens are substituted in a single pass, so a value that
 * itself contains a token name is passed through as plain text.
 */

function alert_command_tokens_setup(): void {
	syslog_load_plugin_source('includes/functions.php');

	global $severities, $syslog_levels, $syslog_facilities;

	$severities        = [2 => 'Critical'];
	$syslog_levels     = [3 => 'err'];
	$syslog_facilities = [1 => 'user'];
}

it('passes a value that contains a token name through unchanged', function () {
	alert_command_tokens_setup();

	$alert   = ['id' => 5, 'severity' => 2, 'command' => '/bin/echo <HOSTNAME> <MESSAGE>'];
	$results = ['priority_id' => 3, 'facility_id' => 1, 'message' => 'link down'];

	$command = alert_replace_variables($alert, $results, 'a<MESSAGE>b');

	expect($command)->toBe("/bin/echo 'a<MESSAGE>b' 'link down'");

	// The shell must see exactly two arguments.
	exec('printf "%s\n" ' . substr($command, strlen('/bin/echo ')), $lines);

	expect($lines)->toBe(['a<MESSAGE>b', 'link down']);
});

it('substitutes every documented token', function () {
	alert_command_tokens_setup();

	$alert   = ['id' => 7, 'severity' => 2, 'command' => '/x <ALERTID> <HOSTNAME> <PRIORITY> <FACILITY> <MESSAGE> <SEVERITY>'];
	$results = ['priority_id' => 3, 'facility_id' => 1, 'message' => 'link down'];

	expect(alert_replace_variables($alert, $results, 'r1'))->toBe("/x '7' 'r1' 'err' 'user' 'link down' 'Critical'");
});
