<?php

it('expires partitions by UTC upper boundary regardless of names, gaps or timezone', function ($expression, $straddles) {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';
	$original_timezone = date_default_timezone_get();
	$cutoff = (intdiv(time(), 86400) - 6) * 86400;
	$day_mode = $expression === 'TO_DAYS(`logtime`)';
	$expiry = $day_mode ? 1000 : $cutoff;
	$unit = $day_mode ? 1 : 86400;
	$rows = [
		['PARTITION_NAME' => 'misleading_future_label', 'PARTITION_DESCRIPTION' => (string) ($expiry - 5 * $unit)],
		['PARTITION_NAME' => 'expires_at_cutoff', 'PARTITION_DESCRIPTION' => (string) ($straddles ? $expiry + $unit : $expiry)],
		['PARTITION_NAME' => 'spans_cutoff', 'PARTITION_DESCRIPTION' => (string) ($expiry + 2 * $unit)],
		['PARTITION_NAME' => 'future', 'PARTITION_DESCRIPTION' => (string) ($expiry + 10 * $unit)],
		['PARTITION_NAME' => 'dMaxValue', 'PARTITION_DESCRIPTION' => 'MAXVALUE'],
	];
	foreach ($rows as &$row) { $row['PARTITION_EXPRESSION'] = $expression; }
	unset($row);
	test_override('read_config_option', static fn($name) => $name === 'syslog_retention' ? '7' : '');
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use ($rows) {
		expect($params)->toBe(['syslog_test', 'syslog']);

		return $rows;
	});
	test_override('syslog_db_fetch_cell_prepared', function ($sql, $params) use ($cutoff) {
		if ($sql === 'SELECT TO_DAYS(?)') {
			expect($params)->toBe([gmdate('Y-m-d', $cutoff)]);

			return '1000';
		}

		return 1;
	});
	try {
		foreach (['UTC', 'America/New_York', 'Pacific/Auckland'] as $timezone) {
			date_default_timezone_set($timezone);
			$dropped = [];
			test_override('syslog_db_execute_prepared', function ($sql) use (&$dropped) {
				preg_match('/DROP PARTITION `([^`]+)`/', $sql, $matches);
				$dropped[] = $matches[1];

				return true;
			});
			expect(syslog_partition_remove('syslog'))->toBe($straddles ? 1 : 2);
			expect($dropped)->toBe($straddles ? ['misleading_future_label']
				: ['misleading_future_label', 'expires_at_cutoff']);
		}
	} finally { date_default_timezone_set($original_timezone); }
})->with([
	['UNIX_TIMESTAMP(`logtime`)', false], ['UNIX_TIMESTAMP(`logtime`)', true],
	['TO_DAYS(`logtime`)', false], ['TO_DAYS(`logtime`)', true],
]);

it('preserves indefinite retention and stops safely on invalid metadata or failed drops', function ($case) {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog_test';
	$row = [
		'PARTITION_NAME' => 'expired',
		'PARTITION_DESCRIPTION' => '1',
		'PARTITION_EXPRESSION' => 'unix_timestamp(logtime)',
	];
	if ($case === 'bad name') { $row['PARTITION_NAME'] = 'unsafe`name'; }
	if ($case === 'missing boundary') { unset($row['PARTITION_DESCRIPTION']); }
	if ($case === 'bad boundary') { $row['PARTITION_DESCRIPTION'] = 'unknown'; }
	if ($case === 'bad expression') { $row['PARTITION_EXPRESSION'] = 'unknown(logtime)'; }
	if ($case === 'cutoff failure') { $row['PARTITION_EXPRESSION'] = 'to_days(logtime)'; }
	if ($case === 'safety partition') { $row['PARTITION_NAME'] = 'dMaxValue'; }
	if ($case === 'renamed safety partition') { $row['PARTITION_DESCRIPTION'] = 'MAXVALUE'; }
	$days = match ($case) { 'indefinite' => '0', 'invalid retention' => 'oops', default => '1' };
	$released = 0;
	$attempts = 0;
	test_override('read_config_option', static fn() => $days);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => $case === 'metadata failure' ? false : [$row, $row]);
	test_override('syslog_db_fetch_cell_prepared', function ($sql) use (&$released) {
		if (str_contains($sql, 'RELEASE_LOCK')) { $released++; }

		return $sql === 'SELECT TO_DAYS(?)' ? false : 1;
	});
	test_override('syslog_db_execute_prepared', function () use (&$attempts) {
		$attempts++;

		return false;
	});

	expect(syslog_partition_remove('syslog_removed'))->toBe(0);
	expect($released)->toBe(1)->and($attempts)->toBe($case === 'drop failure' ? 1 : 0);
})->with([
	'indefinite', 'invalid retention', 'metadata failure', 'bad name', 'missing boundary',
	'bad boundary', 'bad expression', 'cutoff failure', 'safety partition', 'renamed safety partition', 'drop failure',
]);
