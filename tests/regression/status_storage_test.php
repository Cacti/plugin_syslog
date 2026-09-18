<?php
// Exercise live storage formatting without a Cacti database connection.
function __($text, ...$args) { return $text; }
function syslog_db_fetch_cell($sql) {
	global $incoming;
	if (strpos($sql, '`status_test`.`syslog_incoming`') === false || strpos($sql, 'COUNT(*)') === false) {
		throw new RuntimeException('Incoming rows must use the configured Syslog database and an exact count.');
	}
	return $incoming;
}
function syslog_db_fetch_cell_prepared($sql, $params) {
	global $bytes;
	if ($params !== ['status_test', 'syslog'] || strpos($sql, 'DATA_LENGTH + INDEX_LENGTH') === false) {
		throw new RuntimeException('Size must include data and indexes for the configured Syslog table.');
	}
	return $bytes;
}
function read_config_option($key) { global $settings; return $settings[$key]; }
function check_value($actual, $expected) {
	if ($actual !== $expected) {
		throw new RuntimeException(var_export([$actual, $expected], true));
	}
}
$source = file_get_contents(__DIR__ . '/../../syslog.php');
$start = strpos($source, 'function syslog_status_storage()');
$end = strpos($source, 'function syslog_status()', $start);
eval(substr($source, $start, $end - $start));
$syslogdb_default = 'status_test';
$syslog_retentions = ['0' => 'Indefinite', '30' => '1 Month'];
$syslog_alert_retentions = ['0' => 'Indefinite', '7' => '1 Week'];
$settings = ['syslog_retention' => '30', 'syslog_alert_retention' => '0'];
$incoming = '12345'; $bytes = 1073741824;
check_value(array_values(syslog_status_storage()), ['12,345', '1.00 GiB', '1 Month', 'Indefinite']);
$incoming = '0'; $bytes = '0';
$settings = ['syslog_retention' => '0', 'syslog_alert_retention' => '7'];
check_value(array_values(syslog_status_storage()), ['0', '0 B', 'Indefinite', '1 Week']);
$incoming = false; $bytes = null;
$settings = ['syslog_retention' => '', 'syslog_alert_retention' => 'invalid'];
check_value(array_values(syslog_status_storage()), array_fill(0, 4, 'Unavailable'));
echo "Status storage checks passed\n";
