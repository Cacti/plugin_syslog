<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2011 The Cacti Group                                 |
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

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}

$no_http_headers = true;

chdir(dirname(__FILE__) . '/../../');
include(dirname(__FILE__) . '/../../include/global.php');

echo "NOTE: Fixing Warning vs. Warn Errors\n";
$found = syslog_db_fetch_row("SELECT * FROM syslog_priorities WHERE priority='warning' LIMIT 1");
if (sizeof($found)) {
	syslog_db_execute("UPDATE syslog SET priority_id=5 WHERE priority_id=" . $found["priority_id"]);
	syslog_db_execute("UPDATE syslog_statistics SET priority_id=5 WHERE priority_id=" . $found["priority_id"]);
	syslog_db_execute("DELETE FROM syslog_priorities WHERE priority='warning'");
}
echo "NOTE: Finished\n";

?>
