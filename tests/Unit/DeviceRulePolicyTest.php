<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

it('keeps global maintenance closed unless a device rule explicitly permits a priority', function () {
	syslog_load_plugin_source('functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog';
	$GLOBALS['syslog_incoming_config'] = ['hostField' => 'host', 'priorityField' => 'priority_id'];

	$active = syslog_device_rule_sql(true);
	// Maintenance must not let a host with no device rule through: an EXISTS
	// filter is required while maintenance is active.
	expect($active['sql'])->toContain('AND EXISTS')
		->toContain("dr.allow_maintenance = 'on'")
		->toContain('pass_through_priority')
		// An active device pause still applies during maintenance.
		->toContain('AND NOT EXISTS');
	expect($active['params'])->toHaveCount(1);

	$inactive = syslog_device_rule_sql(false);
	expect($inactive['sql'])->not->toContain('AND EXISTS')
		->toContain('AND NOT EXISTS');
});
