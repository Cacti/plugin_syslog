<?php
// Exercise the read-only information_schema summary without a Cacti database.
function syslog_db_fetch_assoc_prepared($sql, $params) {
	if (strpos($sql, 'information_schema.PARTITIONS') === false) {
		throw new RuntimeException('Partition observability must read partition metadata.');
	}
	if ($params[0] !== 'status_test') {
		throw new RuntimeException('Partition observability must use the configured Syslog database.');
	}

	if ($params[1] === 'syslog') {
		return [
			['partition_name' => 'd20260920'],
			['partition_name' => 'd20260921'],
			['partition_name' => 'dMaxValue', 'table_rows' => '42', 'data_length' => '1024', 'index_length' => '512'],
		];
	}

	return [
		['PARTITION_NAME' => 'd20260920'],
		['PARTITION_NAME' => 'dMaxValue', 'TABLE_ROWS' => null, 'DATA_LENGTH' => null, 'INDEX_LENGTH' => null],
	];
}

function check_value($actual, $expected) {
	if ($actual !== $expected) {
		throw new RuntimeException(var_export([$actual, $expected], true));
	}
}

$source = file_get_contents(__DIR__ . '/../../functions.php');
$start = strpos($source, 'function syslog_partition_observability()');
$end = strpos($source, '/**', $start + strlen('function syslog_partition_observability()'));
eval(substr($source, $start, $end - $start));

$syslogdb_default = 'status_test';
$result = syslog_partition_observability();

check_value($result['syslog']['coverage_start'], '20260920');
check_value($result['syslog']['coverage_end'], '20260921');
check_value($result['syslog']['partitions'], 2);
check_value($result['syslog']['dmax_rows'], 42);
check_value($result['syslog']['dmax_bytes'], 1536);
check_value($result['syslog_removed']['coverage_start'], '20260920');
check_value($result['syslog_removed']['dmax_rows'], null);

echo "Partition observability checks passed\n";
