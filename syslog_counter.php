<?php
$no_http_headers = true;


chdir('../../');

include("./include/config.php");

$sli = read_config_option("syslog_last_incoming");
$slt = read_config_option("syslog_last_total");

include('plugins/syslog/config.php');
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