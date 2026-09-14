<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #278: alert/ticket command execution must
 * escape shell arguments, resolve the executable safely, and never fall
 * back to an unguarded /bin/sh invocation.
 */

it('keeps alert and ticket command execution shell-safe', function () {
	$functions = plugin_test_read_source('functions.php');

	if (strpos($functions, 'function syslog_execute_ticket_command(') === false) {
		throw new RuntimeException('Ticket command execution helper is missing.');
	}

	if (strpos($functions, 'function syslog_execute_alert_command(') === false) {
		throw new RuntimeException('Alert command execution helper is missing.');
	}

	// Match only call sites (third arg is a string literal), not the function
	// definition whose third param is $context. Two call sites exist: one for
	// method==0 ('Ticket Command') and one for method==1 ('Command').
	if (substr_count($functions, "syslog_execute_ticket_command(\$alert, \$hostlist, '") < 2) {
		throw new RuntimeException('syslog_process_alerts() is not consistently using the ticket command helper.');
	}

	if (substr_count($functions, 'syslog_execute_alert_command($alert, $results, $hostname);') < 2) {
		throw new RuntimeException('syslog_process_alerts() is not consistently using the alert command helper.');
	}

	if (strpos($functions, "exec('/bin/sh '") !== false) {
		throw new RuntimeException('Shell fallback execution path must not appear in shared helpers.');
	}

	// open_ticket guard: function is a no-op unless open_ticket == 'on'.
	if (strpos($functions, "\$alert['open_ticket'] == 'on'") === false) {
		throw new RuntimeException("syslog_execute_ticket_command() must guard on open_ticket == 'on'.");
	}

	// empty-command guard: function is a no-op when command trims to ''.
	if (strpos($functions, "\$command != ''") === false) {
		throw new RuntimeException('syslog_execute_ticket_command() must guard on non-empty command.');
	}

	// is_executable must be called on the stripped executable, not the raw command string.
	// Old code checked is_executable($command) which broke quoted absolute paths.
	if (strpos($functions, 'is_executable($executable)') === false) {
		throw new RuntimeException('is_executable() must be called on stripped $executable, not raw $command.');
	}

	if (strpos($functions, 'is_executable($command)') !== false) {
		throw new RuntimeException('is_executable() must not be called on raw $command string.');
	}

	// PATH-lookup detection: both helpers must produce distinct error messages via
	// DIRECTORY_SEPARATOR to distinguish "no path separator" from "not executable".
	if (substr_count($functions, 'strpos($executable, DIRECTORY_SEPARATOR)') < 2) {
		throw new RuntimeException('Both helpers must detect PATH-based lookups via DIRECTORY_SEPARATOR.');
	}

	// syslog_execute_alert_command must delegate variable substitution to
	// alert_replace_variables(); an empty result falls through to is_executable('')
	// which returns false and logs the error path.
	if (strpos($functions, 'alert_replace_variables(') === false) {
		throw new RuntimeException('syslog_execute_alert_command() must call alert_replace_variables().');
	}

	// ticket command must only log on failure ($return != 0), not unconditionally.
	if (preg_match('/exec\(\$command,.*?\$return\);\s*\n\s*cacti_log\(sprintf\(\'SYSLOG NOTICE:/s', $functions)) {
		throw new RuntimeException('syslog_execute_ticket_command() must not unconditionally log success after exec().');
	}

	// syslog_execute_alert_command must not have dead assignment $returnCode = 126.
	if (preg_match('/\$returnCode\s*=\s*126/', $functions)) {
		throw new RuntimeException('syslog_execute_alert_command() must not contain dead assignment $returnCode = 126.');
	}

	// quote-stripping: executable extraction must trim surrounding quotes.
	if (strpos($functions, "trim(\$cparts[0], '\"\\'')") === false) {
		throw new RuntimeException('Executable extraction must strip surrounding quotes from command path.');
	}

	// preg_split for whitespace tokenization (handles tabs and consecutive spaces).
	if (substr_count($functions, "preg_split('/\\s+/', trim(\$command))") < 1) {
		throw new RuntimeException('Command tokenization must use preg_split for whitespace splitting.');
	}

	// non-executable error path must log SYSLOG ERROR in both helpers.
	if (substr_count($functions, 'SYSLOG ERROR:') < 2) {
		throw new RuntimeException('Both helpers must log SYSLOG ERROR when executable is missing.');
	}

	// cacti_escapeshellarg must wrap all four --arg values in ticket command.
	$ticket_fn_match = [];

	if (preg_match('/function syslog_execute_ticket_command\b.*?^}/ms', $functions, $ticket_fn_match)) {
		$ticket_body = $ticket_fn_match[0];
		$esc_count   = substr_count($ticket_body, 'cacti_escapeshellarg(');

		if ($esc_count < 4) {
			throw new RuntimeException("syslog_execute_ticket_command() must call cacti_escapeshellarg() for all 4 --arg values (found $esc_count).");
		}
	}
});
