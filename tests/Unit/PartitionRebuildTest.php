<?php

function partition_rebuild_runner(): string {
	$name = 'SyslogRebuildFixture\\syslog_upart_run';

	if (!function_exists($name)) {
		$source = plugin_test_read_source('cli/upgrade_partitioning.php');
		preg_match('/^function syslog_upart_run\(.*?^\}/ms', $source, $match);
		$path = tempnam(sys_get_temp_dir(), 'syslog-rebuild-unit-');
		file_put_contents($path, '<?php namespace SyslogRebuildFixture;
			function syslog_db_is_mariadb(): bool { return \test_call_override("rebuild_mariadb", [], true); }
			function syslog_upart_rebuild(...$args): bool { return \test_call_override("syslog_upart_rebuild", $args, false); }
			' . $match[0]);

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
		'precision' => 'sec'
	];
	test_override('read_config_option', static fn($key) => [
		'syslog_install_engine' => 'InnoDB', 'syslog_retention' => '30',
		'syslog_partition_ahead_days' => '3'
	][$key] ?? '');
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
			return $state->precision === 'ms' ? 'timestamp(3)' : 'timestamp';
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
		->toContain('does NOT pause them')->toContain('10,000-row copies')
		->not->toContain('Type "yes"')
		->and($state->rebuilds)->toBe([['syslog', false, true, 'InnoDB', 30, 3, '+05:30']]);
	expect($state->settings)->toBe([]);
	expect(array_column($state->queries, 0))->toContain('SELECT RELEASE_LOCK(?)');
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

it('keeps the legacy converter nondestructive once every pipeline table is converted', function () {
	$state = partition_rebuild_setup();
	$state->tables = ['syslog', 'syslog_removed', 'syslog_incoming', 'syslog_replication_output'];
	$state->precision = 'ms';
	$run = partition_rebuild_runner();
	ob_start();
	$ok = $run(true, true, false, true);
	ob_end_clean();

	expect($ok)->toBeTrue()
		->and($state->rebuilds)->toBe([])
		->and($state->executions)->toBe([]);
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

it('runs both CLI entrypoints with their real argument parsing and compatible legacy defaults', function () {
	$base = sys_get_temp_dir() . '/syslog-rebuild-cli-' . bin2hex(random_bytes(6));
	mkdir($base . '/plugins/syslog/cli', 0700, true);
	mkdir($base . '/plugins/syslog/includes', 0700);
	mkdir($base . '/include', 0700);
	$paths = [
		'include/cli_check.php' => '<?php',
		'plugins/syslog/cli/upgrade_partitioning.php' => plugin_test_read_source('cli/upgrade_partitioning.php'),
		'plugins/syslog/cli/updatedb_convert_ms.php' => plugin_test_read_source('cli/updatedb_convert_ms.php'),
		'plugins/syslog/setup.php' => <<<'PHP'
<?php
$syslogdb_default = 'fixture';
function syslog_determine_config() { define('SYSLOG_CONFIG', __FILE__); }
function syslog_connect() { return true; }
function read_config_option($key) { return ['syslog_install_engine'=>'InnoDB','syslog_retention'=>'30'][$key] ?? ''; }
function syslog_db_table_exists($table, ...$args) { return in_array($table, ['syslog','syslog_removed','syslog_incoming','syslog_replication_output'], true); }
function syslog_db_fetch_cell_prepared($sql, ...$args) {
	if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) { return 1; }
	throw new RuntimeException('Unexpected database activity on an already-converted legacy invocation: ' . $sql);
}
PHP,
		'plugins/syslog/includes/schema.php' => <<<'PHP'
<?php
function syslog_db_is_mariadb() { return true; }
function syslog_detect_logtime_precision($table) { return 'ms'; }
function syslog_partition_ahead_days() { return 3; }
PHP
	];

	try {
		foreach ($paths as $path => $content) {
			file_put_contents($base . '/' . $path, $content);
		}

		foreach ([
			['upgrade_partitioning.php', ['--help'], 0, '--convert-ms'],
			['updatedb_convert_ms.php', ['--help'], 0, 'Legacy default DELETES messages'],
			['updatedb_convert_ms.php', ['--confirm'], 0, 'No tables require rebuilding.'],
			['updatedb_convert_ms.php', ['--confirm', '--preserve-data'], 0, 'No tables require rebuilding.'],
			['upgrade_partitioning.php', ['--discard-data', '--preserve-data'], 1, 'cannot be combined']
		] as [$entry, $args, $expected_status, $expected_output]) {
			$process = proc_open(array_merge([PHP_BINARY, '-d', 'xdebug.mode=off', $base . '/plugins/syslog/cli/' . $entry], $args),
				[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			expect(is_resource($process))->toBeTrue();
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			expect(proc_close($process))->toBe($expected_status)
				->and($output)->toContain($expected_output);
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
