<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #253: alert SQL must be built with
 * placeholders bound via prepared-statement parameters, not interpolated.
 */

it('binds host and program alert SQL through prepared-statement placeholders', function () {
	$GLOBALS['syslogdb_default']       = 'syslogdb';
	$GLOBALS['syslog_incoming_config'] = [
		'hostField'     => 'host',
		'programField'  => 'program',
		'facilityField' => 'facility',
		'textField'     => 'message',
	];

	$this->loadPluginSource('functions.php');

	$hostAlert = ['type' => 'host', 'message' => 'router1'];
	$programAlert = ['type' => 'program', 'message' => 'sshd'];

	$hostSql = syslog_get_alert_sql($hostAlert, 55);
	$progSql = syslog_get_alert_sql($programAlert, 66);

	expect(strpos($hostSql['sql'], 'AND `status` = 1'))->not->toBeFalse('Host alert SQL must select processed incoming rows.');
	expect(strpos($hostSql['sql'], 'AND `seq` <= ?'))->not->toBeFalse('Host alert SQL must bound rows by sequence.');
	expect($hostSql['params'])->toHaveCount(2, 'Host alert SQL must pass two prepared parameters.');
	expect($hostSql['params'][1])->toBe(55, 'Host alert sequence parameter should be the processing boundary.');

	expect(strpos($progSql['sql'], 'AND `status` = 1'))->not->toBeFalse('Program alert SQL must select processed incoming rows.');
	expect(strpos($progSql['sql'], 'AND `seq` <= ?'))->not->toBeFalse('Program alert SQL must bound rows by sequence.');
	expect($progSql['params'])->toHaveCount(2, 'Program alert SQL must pass two prepared parameters.');
	expect($progSql['params'][1])->toBe(66, 'Program alert sequence parameter should be the processing boundary.');
});
