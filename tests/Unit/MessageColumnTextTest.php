<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

it('uses TEXT for message columns on fresh and upgraded installations', function () {
	$schema = plugin_test_read_source('includes/schema.php');
	$setup  = plugin_test_read_source('setup.php');

	expect($schema)->not->toMatch('/message\s+varchar\(2048\)/i')
		->not->toMatch('/TEXT\s+NOT\s+NULL\s+DEFAULT\s+[\'\"]{1}[\'\"]{1}/i')
		->toContain("'syslog_incoming'           => 'TEXT NOT NULL'")
		->toContain("'syslog_reports'            => 'TEXT DEFAULT NULL'")
		->toContain("MODIFY COLUMN `message` \$definition")
		->toContain('message TEXT NOT NULL')
		->toContain('message TEXT default NULL');

	expect($setup)->toContain('syslog_ensure_message_text();');
});
