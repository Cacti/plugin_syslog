<?php

function partition_rebuild_runner(): string {
	$name = 'SyslogRebuildFixture\\syslog_upart_run';

	if (!function_exists($name)) {
		$source = plugin_test_read_source('cli/upgrade_partitioning.php');
		preg_match('/^function syslog_upart_run\(.*?^\}/ms', $source, $run);
		preg_match('/^function syslog_upart_run_locked\(.*?^\}/ms', $source, $locked);
		preg_match('/^function syslog_upart_live_workers\(.*?^\}/ms', $source, $workers);
		preg_match('/^function syslog_upart_widen\(.*?^\}/ms', $source, $widen);
		preg_match('/^function syslog_upart_logtime_type\(.*?^\}/ms', $source, $type);
		$path = tempnam(sys_get_temp_dir(), 'syslog-rebuild-unit-');
		file_put_contents($path, '<?php namespace SyslogRebuildFixture;
			function syslog_db_is_mariadb(): bool { return \test_call_override("rebuild_mariadb", [], true); }
			function syslog_upart_rebuild(...$args): bool { return \test_call_override("syslog_upart_rebuild", $args, false); }
			function syslog_upart_source_zone_error(...$args): string { return \test_call_override("syslog_upart_source_zone_error", $args, ""); }
			function posix_kill($pid, $signal) { return \test_call_override("posix_kill", [$pid, $signal], false); }
			function posix_get_last_error() { return \test_call_override("posix_get_last_error", [], 3); }
			' . $run[0] . "\n" . $locked[0] . "\n" . $workers[0] . "\n" . $widen[0] . "\n" . $type[0]);

		try {
			require $path;
		} finally {
			unlink($path);
		}
	}

	return $name;
}

function partition_rebuild_setup(): stdClass {
	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('includes/schema.php');
	$GLOBALS['syslogdb_default'] = 'fixture';
	$state = (object) [
		'version' => '11.8-MariaDB',
		'tables' => ['syslog'],
		'bytes' => '10737418240',
		'lock' => 1,
		'queries' => [],
		'rebuilds' => [],
		'settings' => [],
		'executions' => [],
		'statements' => [],
		'precision' => 'sec',
		'enabled' => '',
		'assoc' => [],
		'legacy' => [],
		'retention' => '30',
		'granularity' => '',
		'stored_zone' => '',
		'column_type' => null
	];
	$GLOBALS['config']['poller_id'] = 1;
	test_override('read_config_option', static fn($key) => [
		'syslog_install_engine' => 'InnoDB', 'syslog_retention' => $state->retention,
		'syslog_partition_ahead_days' => '3', 'syslog_enabled' => $state->enabled,
		'syslog_partition_granularity' => $state->granularity,
		'syslog_upart_source_time_zone' => $state->stored_zone
	][$key] ?? '');
	test_override('syslog_db_fetch_assoc_prepared', static function ($sql) use ($state) {
		$state->assoc[] = $sql;

		return str_contains($sql, "DATA_TYPE = 'datetime'") ? $state->legacy : [];
	});
	test_override('syslog_db_execute', static function ($sql) use ($state) {
		$state->statements[] = $sql;

		return true;
	});
	test_override('syslog_db_table_exists', static fn($table) => in_array($table, $state->tables, true));
	test_override('syslog_db_fetch_row', static fn() => ['Value' => $state->version]);
	test_override('set_config_option', static function ($key, $value) use ($state) {
		$state->settings[$key] = $value;
	});
	test_override('syslog_db_execute_prepared', static function ($sql, $params) use ($state) {
		$state->executions[] = [$sql, $params];

		return true;
	});
	test_override('rebuild_mariadb', static fn() => stripos($state->version, 'mariadb') !== false);
	test_override('syslog_db_fetch_cell', static fn($sql) => match (true) {
		str_contains($sql, 'VERSION()') => $state->version,
		str_contains($sql, 'time_zone') => '+05:30',
		str_contains($sql, 'lock_wait_timeout') => '86400',
		default => 'STRICT_TRANS_TABLES,NO_ZERO_DATE'
	});
	test_override('syslog_db_fetch_cell_prepared', static function ($sql, $params) use ($state) {
		$state->queries[] = [$sql, $params];

		if (str_contains($sql, 'GET_LOCK')) {
			return $state->lock;
		}

		if (str_contains($sql, 'COLUMN_TYPE')) {
			return $state->column_type ?? ($state->precision === 'ms' ? 'timestamp(3)' : 'timestamp');
		}

		return $state->bytes;
	});
	test_override('syslog_upart_rebuild', static function (...$args) use ($state) {
		$state->rebuilds[] = $args;
		$state->precision = $args[1] ? 'ms' : $state->precision;

		return true;
	});

	return $state;
}

it('reports database size, equal extra space and several-hour duration even without a prompt', function () {
	$state = partition_rebuild_setup();
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, false);
	$output = ob_get_clean();

	expect($ok)->toBeTrue()
		->and($output)->toContain('10.00 GiB (10,737,418,240 bytes)')
		->toContain('AT LEAST another 10.00 GiB (10,737,418,240 bytes)')
		->toContain('DATABASE SERVER')->toContain('SEVERAL HOURS')
		->toContain('does NOT pause')->toContain('50,000-row copies')
		->toContain('receive new sequence numbers')
		->not->toContain('Type "yes"')
		->and($state->rebuilds)->toBe([['syslog', false, true, 'InnoDB', 30, 3, 'day', 8192, 50000, null]])
		->and($state->queries[0])->toBe(['SELECT GET_LOCK(?, 0)', [syslog_processing_lock_name()]]);
	expect($state->settings)->toBe([]);
	expect(array_column($state->queries, 0))->toContain('SELECT RELEASE_LOCK(?)');
});

it('saves --partition=hourly once confirmed and rebuilds with at least two days ahead', function () {
	$state = partition_rebuild_setup();
	test_override('syslog_upart_rebuild', static function (...$args) use ($state) {
		$state->rebuilds[] = $args;
		expect($state->settings)->toBe(['syslog_partition_granularity' => 'hour']);

		return true;
	});
	test_override('read_config_option', static fn($key) => [
		'syslog_install_engine' => 'InnoDB', 'syslog_retention' => '30', 'syslog_partition_ahead_days' => '1'
	][$key] ?? '');
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, false, true, 'hour');
	$output = ob_get_clean();

	expect($ok)->toBeTrue()
		->and($output)->toContain('Rebuilding hourly partitions')
		->and($state->rebuilds)->toBe([['syslog', false, true, 'InnoDB', 30, 2, 'hour', 8192, 50000, null]]);
});

it('refuses hourly partitioning beyond the server partition limit or with indefinite retention', function () {
	foreach ([['365', '10.11.6-MariaDB'], ['60', '10.0.3-MariaDB'], ['60', '5.6.51'], ['0', '11.4.2-MariaDB']] as [$retention, $version]) {
		$state = partition_rebuild_setup();
		$state->retention = $retention;
		$state->version = $version;
		$run = partition_rebuild_runner();

		expect($run(true, false, true, 'hour'))->toBeFalse()
			->and($state->queries)->toBe([])
			->and($state->settings)->toBe([])
			->and($state->rebuilds)->toBe([]);
	}
});

it('refuses to rebuild while Syslog processing is enabled', function () {
	$state = partition_rebuild_setup();
	$state->enabled = 'on';
	$run = partition_rebuild_runner();

	expect($run(true, false))->toBeFalse()
		->and($state->queries)->toBe([])
		->and($state->rebuilds)->toBe([]);
});

it('refuses to rebuild while processing or another rebuild holds the processing lock', function () {
	$state = partition_rebuild_setup();
	$state->lock = 0;
	$run = partition_rebuild_runner();

	expect($run(true, false))->toBeFalse()
		->and($state->queries)->toBe([['SELECT GET_LOCK(?, 0)', [syslog_processing_lock_name()]]])
		->and($state->rebuilds)->toBe([]);
});

it('refuses to rebuild while transfer workers launched before the lock are still alive', function () {
	$state = partition_rebuild_setup();
	$run   = partition_rebuild_runner();
	$errno = 3;

	test_override('db_fetch_assoc', static fn($sql) => str_contains($sql, "FROM processes WHERE tasktype = 'syslog' AND taskname = 'child'")
		? [['pid' => '101'], ['pid' => '102'], ['pid' => '103']] : []);
	// 101 is alive, 102 is a stale row left by a crashed worker, 103 runs as another user.
	test_override('posix_kill', static function ($pid) use (&$errno) {
		$errno = $pid === 103 ? 1 : 3;

		return $pid === 101;
	});
	test_override('posix_get_last_error', static function () use (&$errno) {
		return $errno;
	});

	expect($run(true, false))->toBeFalse()
		->and($state->rebuilds)->toBe([])
		->and($state->statements)->toBe([])
		->and(end($state->queries))->toBe(['SELECT RELEASE_LOCK(?)', [syslog_processing_lock_name()]]);

	// Only stale rows remain: the rebuild proceeds.
	test_override('posix_kill', static fn() => false);
	$errno = 3;
	ob_start();
	$ok = $run(true, false);
	ob_end_clean();

	expect($ok)->toBeTrue();
});

it('refuses legacy DATETIME history before any change and releases the lock', function () {
	$state = partition_rebuild_setup();
	$state->legacy = [['TABLE_NAME' => 'syslog']];
	$run = partition_rebuild_runner();

	expect($run(true, false))->toBeFalse()
		->and($state->rebuilds)->toBe([])
		->and($state->statements)->toBe([])
		->and(end($state->queries))->toBe(['SELECT RELEASE_LOCK(?)', [syslog_processing_lock_name()]]);
});

it('refuses a source time zone that fails validation before any change', function () {
	$state = partition_rebuild_setup();
	$state->legacy = [['TABLE_NAME' => 'syslog']];
	test_override('syslog_upart_source_zone_error', static fn($zone, $tables) => "zone $zone rejected for " . implode(',', $tables));
	$run = partition_rebuild_runner();

	expect($run(true, false, true, null, 'Mars/Olympus'))->toBeFalse()
		->and($state->rebuilds)->toBe([])
		->and($state->statements)->toBe([])
		->and($state->settings)->toBe([])
		->and(end($state->queries))->toBe(['SELECT RELEASE_LOCK(?)', [syslog_processing_lock_name()]]);
});

it('copies legacy DATETIME history in the validated source zone and clears the retry zone afterwards', function () {
	$state = partition_rebuild_setup();
	$state->legacy = [['TABLE_NAME' => 'syslog']];
	$checked = [];
	test_override('syslog_upart_source_zone_error', static function ($zone, $tables) use (&$checked) {
		$checked[] = [$zone, $tables];

		return '';
	});
	test_override('set_config_option', static function ($key, $value) use ($state) {
		$state->settings[] = [$key, $value];
	});
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, false, true, null, 'America/New_York');
	$output = ob_get_clean();

	expect($ok)->toBeTrue()
		->and($checked)->toBe([['America/New_York', ['syslog']]])
		->and($output)->toContain('read as America/New_York wall-clock time')
		->and($state->rebuilds)->toBe([['syslog', false, true, 'InnoDB', 30, 3, 'day', 8192, 50000, 'America/New_York']])
		->and($state->settings)->toBe([['syslog_upart_source_time_zone', 'America/New_York'], ['syslog_upart_source_time_zone', '']]);
});

it('refuses to resume an interrupted DATETIME copy in a different source zone', function () {
	$state = partition_rebuild_setup();
	$state->tables[] = 'syslog_rebuild';
	$state->legacy = [['TABLE_NAME' => 'syslog_rebuild']];
	$state->stored_zone = 'Europe/Berlin';
	$run = partition_rebuild_runner();

	expect($run(true, false, true, null, 'America/New_York'))->toBeFalse()
		->and($state->rebuilds)->toBe([])
		->and($state->settings)->toBe([]);
});

it('converts a legacy DATETIME incoming queue in place in the source zone', function () {
	$state = partition_rebuild_setup();
	$state->tables = ['syslog_incoming'];
	$state->legacy = [['TABLE_NAME' => 'syslog_incoming']];
	test_override('syslog_db_fetch_row', static fn($sql) => str_contains($sql, 'CRC32')
		? ['row_count' => '2', 'digest' => '99'] : ['Value' => $state->version]);
	test_override('syslog_db_execute', static function ($sql) use ($state) {
		$state->statements[] = $sql;
		$state->precision = str_starts_with($sql, 'ALTER TABLE') ? 'ms' : $state->precision;

		return true;
	});
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, true, true, null, 'America/New_York');
	ob_end_clean();

	expect($ok)->toBeTrue()
		->and($state->rebuilds)->toBe([])
		->and($state->executions)->toContain(['SET SESSION time_zone = ?', ['America/New_York']])
		->and($state->statements)->toContain("ALTER TABLE `fixture`.`syslog_incoming` MODIFY COLUMN logtime timestamp(3) NOT NULL DEFAULT '0000-00-00 00:00:00.000'");
});

it('converts a millisecond DATETIME(3) incoming queue to TIMESTAMP(3) instead of skipping it', function () {
	$state = partition_rebuild_setup();
	$state->tables = ['syslog_incoming'];
	$state->legacy = [['TABLE_NAME' => 'syslog_incoming']];
	$state->column_type = 'datetime(3)';
	test_override('syslog_db_fetch_row', static fn($sql) => str_contains($sql, 'CRC32')
		? ['row_count' => '1', 'digest' => '7'] : ['Value' => $state->version]);
	test_override('syslog_db_execute', static function ($sql) use ($state) {
		$state->statements[] = $sql;
		$state->column_type = str_starts_with($sql, 'ALTER TABLE') ? 'timestamp(3)' : $state->column_type;

		return true;
	});
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, true, true, null, 'America/New_York');
	ob_end_clean();

	expect($ok)->toBeTrue()
		->and($state->statements)->toContain("ALTER TABLE `fixture`.`syslog_incoming` MODIFY COLUMN logtime timestamp(3) NOT NULL DEFAULT '0000-00-00 00:00:00.000'");

	// Without the conversion the final type check refuses to record success.
	$state = partition_rebuild_setup();
	$state->tables = ['syslog_incoming'];
	$state->legacy = [['TABLE_NAME' => 'syslog_incoming']];
	$state->column_type = 'datetime(3)';
	test_override('syslog_db_fetch_row', static fn($sql) => str_contains($sql, 'CRC32')
		? ['row_count' => '1', 'digest' => '7'] : ['Value' => $state->version]);
	ob_start();
	$ok = $run(true, true, true, null, 'America/New_York');
	ob_end_clean();

	expect($ok)->toBeFalse()
		->and($state->settings)->toBe(['syslog_upart_source_time_zone' => 'America/New_York']);
});

it('discards without a column-type query when no table needs checking', function () {
	$state = partition_rebuild_setup();
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, false, false);
	ob_end_clean();

	expect($ok)->toBeTrue()
		->and(array_filter($state->assoc, static fn($sql) => str_contains($sql, 'information_schema.COLUMNS')))->toBe([])
		->and($state->rebuilds)->toBe([['syslog', false, false, 'InnoDB', 30, 3, 'day', 8192, 50000, null]]);
});

it('widens the incoming queue in place so its sequence identities are kept', function () {
	$state = partition_rebuild_setup();
	$state->tables[] = 'syslog_incoming';
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, true);
	ob_end_clean();

	expect($ok)->toBeTrue()
		->and(array_column($state->rebuilds, 0))->toBe(['syslog'])
		->and($state->statements)->toContain("ALTER TABLE `fixture`.`syslog_incoming` MODIFY COLUMN logtime timestamp(3) NOT NULL DEFAULT '0000-00-00 00:00:00.000'")
		->and($state->settings)->toBe(['syslog_install_logtime_precision' => 'ms']);
});

it('refuses to rebuild when the database size is unreadable', function () {
	$state = partition_rebuild_setup();
	$state->bytes = false;
	$run = partition_rebuild_runner();

	expect($run(true, false))->toBeFalse()
		->and($state->rebuilds)->toBe([]);
	expect(array_column($state->queries, 0))->toContain('SELECT RELEASE_LOCK(?)');
});

it('prevents a destructive retry from deleting retained originals', function () {
	$state = partition_rebuild_setup();
	$state->tables[] = 'syslog_rebuild';
	$run = partition_rebuild_runner();

	expect($run(true, true, false))->toBeFalse()
		->and($state->rebuilds)->toBe([]);
});

it('rejects millisecond conversion on MySQL before any rebuild', function () {
	$state = partition_rebuild_setup();
	$state->version = '8.4.0';
	$run = partition_rebuild_runner();

	expect($run(true, true))->toBeFalse()
		->and($state->rebuilds)->toBe([]);
});

it('leaves the precision setting unchanged and restores the session after a failed copy', function () {
	$state = partition_rebuild_setup();
	test_override('syslog_upart_rebuild', static fn() => false);
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, true);
	ob_end_clean();

	expect($ok)->toBeFalse();
	expect($state->settings)->toBe([])
		->and($state->executions)->toContain(['SET SESSION time_zone = ?', ['+05:30']])
		->toContain(['SET SESSION sql_mode = ?', ['STRICT_TRANS_TABLES,NO_ZERO_DATE']]);
});

it('plans UTC daily boundaries for retention and outlying future records', function () {
	$plan = syslog_test_extract_function('cli/upgrade_partitioning.php', 'syslog_upart_partition_sql');
	$today = gmmktime(0, 0, 0, 11, 19, 2026);
	$sql = $plan(2, 1, $today - 400 * 86400, $today + 3 * 86400, $today + 43200);

	expect($sql)->toContain('PARTITION d20261118 VALUES LESS THAN (' . $today . ')')
		->toContain('PARTITION d20261122 VALUES LESS THAN (' . ($today + 4 * 86400) . ')')
		->toEndWith('PARTITION dMaxValue VALUES LESS THAN MAXVALUE)')
		->and(substr_count($sql, 'PARTITION d'))->toBe(6);
});

it('covers historical days for indefinite retention and refuses excessive partition counts', function () {
	$plan = syslog_test_extract_function('cli/upgrade_partitioning.php', 'syslog_upart_partition_sql');
	$today = gmmktime(0, 0, 0, 11, 19, 2026);

	expect($plan(0, 1, $today - 2 * 86400, null, $today))
		->toContain('PARTITION d20261117 VALUES LESS THAN (' . ($today - 86400) . ')')
		->and($plan(0, 1, $today - 8190 * 86400, null, $today))->toBeNull();
});

it('prints readable CLI help and rejects conflicting preservation arguments', function () {
	$base = sys_get_temp_dir() . '/syslog-rebuild-cli-' . bin2hex(random_bytes(6));
	mkdir($base . '/plugins/syslog/cli', 0700, true);
	mkdir($base . '/plugins/syslog/includes', 0700);
	mkdir($base . '/include', 0700);
	$paths = [
		'include/cli_check.php' => '<?php define("COPYRIGHT_YEARS", "Copyright (C) 2004-2026 The Cacti Group");',
		'plugins/syslog/cli/upgrade_partitioning.php' => plugin_test_read_source('cli/upgrade_partitioning.php'),
		'plugins/syslog/setup.php' => '<?php function plugin_syslog_version() { return ["version" => "5.0"]; }',
		'plugins/syslog/includes/schema.php' => '<?php'
	];

	try {
		foreach ($paths as $path => $content) {
			file_put_contents($base . '/' . $path, $content);
		}

		foreach ([
			['upgrade_partitioning.php', ['--help'], 0, [
				'Preserve existing messages (the default)',
				'utility rebuilds the tables.' . PHP_EOL . PHP_EOL . 'Existing precision',
				'--partition     - Save and apply this Partitioning Level',
				'--convert-ms is selected.' . PHP_EOL . PHP_EOL . 'With preservation',
				'Syslog Partition Upgrade Utility, Version 5.0, Copyright (C)',
				PHP_EOL . PHP_EOL . 'usage: upgrade_partitioning.php',
				'row counts are verified.' . PHP_EOL . PHP_EOL . 'Legacy DATETIME',
				'otherwise use --discard-data.' . PHP_EOL . PHP_EOL . 'Options:',
				'--source-time-zone - IANA zone',
				'several hours.' . PHP_EOL . PHP_EOL . 'On failure',
				'writers first.' . PHP_EOL . PHP_EOL . 'The current database size'
			]],
			['upgrade_partitioning.php', ['--version'], 0, 'Syslog Partition Upgrade Utility, Version 5.0, Copyright (C)'],
			['upgrade_partitioning.php', ['-V'], 0, 'Syslog Partition Upgrade Utility, Version 5.0, Copyright (C)'],
			['upgrade_partitioning.php', ['-v'], 0, 'Syslog Partition Upgrade Utility, Version 5.0, Copyright (C)'],
			['upgrade_partitioning.php', ['--discard-data', '--preserve-data'], 1, 'cannot be combined'],
			['upgrade_partitioning.php', ['--partition=weekly'], 1, '--partition must be hourly or daily']
		] as [$entry, $args, $expected_status, $expected_output]) {
			$process = proc_open(array_merge([PHP_BINARY, '-d', 'xdebug.mode=off', $base . '/plugins/syslog/cli/' . $entry], $args),
				[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			expect(is_resource($process))->toBeTrue();
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			expect(proc_close($process))->toBe($expected_status);
			foreach ((array) $expected_output as $text) {
				expect($output)->toContain($text);
			}
		}
	} finally {
		foreach (array_keys($paths) as $path) {
			if (is_file($base . '/' . $path)) {
				unlink($base . '/' . $path);
			}
		}

		foreach (['plugins/syslog/cli', 'plugins/syslog/includes', 'plugins/syslog', 'plugins', 'include', ''] as $directory) {
			rmdir($base . '/' . $directory);
		}
	}
});
