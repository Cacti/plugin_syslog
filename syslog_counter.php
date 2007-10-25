<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007 The Cacti Group                                      |
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

$no_http_headers = true;

// PHP5 uses a different base path apparently
if (file_exists('include/auth.php')) {
	if (file_exists(dirname(__FILE__) . '/../../include/global.php')) {
		require(dirname(__FILE__) . '/../../include/global.php');
	} else {
		require(dirname(__FILE__) . '/../../include/config.php');
	}
} else {
	chdir('../../');
	if (file_exists(dirname(__FILE__) . '/../../include/global.php')) {
		require(dirname(__FILE__) . '/../../include/global.php');
	} else {
		require(dirname(__FILE__) . '/../../include/config.php');
	}
}

$sli = read_config_option("syslog_last_incoming");
$slt = read_config_option("syslog_last_total");

require($config['base_path'] . '/plugins/syslog/config.php');
$link = mysql_connect($syslogdb_hostname, $syslogdb_username, $syslogdb_password) or die('');
mysql_select_db($syslogdb_default) or die('');

$result = mysql_query("SHOW TABLE STATUS LIKE '" . $syslog_config["incomingTable"] . "'") or die('');
$line = mysql_fetch_array($result, MYSQL_ASSOC);
$i_rows = $line['Auto_increment'];

$result = mysql_query("SHOW TABLE STATUS LIKE '" . $syslog_config["syslogTable"] . "'") or die('');
$line = mysql_fetch_array($result, MYSQL_ASSOC);
$total_rows = $line['Auto_increment'];


if ($sli == "")
	$sql = "insert into settings values ('syslog_last_incoming','$i_rows')";
else
	$sql = "update settings set value = '$i_rows' where name = 'syslog_last_incoming'";
$result = db_execute($sql) or die (mysql_error());

if ($slt == "")
	$sql = "insert into settings values ('syslog_last_total','$total_rows')";
else
	$sql = "update settings set value = '$total_rows' where name = 'syslog_last_total'";
$result = db_execute($sql);


if ($sli == '')
	$sli = 0;

if ($slt == '')
	$slt = 0;

print "total:" . ($total_rows-$slt) . " incoming:" . ($i_rows-$sli);

?>