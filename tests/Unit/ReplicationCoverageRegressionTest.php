<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 */

if (!function_exists('db_connect_real')) {
	/**
	 * Proxy database connection creation through the test override registry.
	 *
	 * @param mixed ...$args Connection arguments.
	 *
	 * @return mixed
	 */
	function db_connect_real(...$args) {
		return test_call_override('db_connect_real', $args, false);
	}
}

if (!function_exists('syslog_db_connect_real')) {
	/**
	 * Proxy syslog database connections through the shared test registry.
	 *
	 * @param mixed ...$args Connection arguments.
	 *
	 * @return mixed
	 */
	function syslog_db_connect_real(...$args) {
		return test_call_override('db_connect_real', $args, false);
	}
}

if (!function_exists('exec_background')) {
	/**
	 * Proxy detached command execution through the test override registry.
	 *
	 * @param string $command Executable path.
	 * @param string $args    Command arguments.
	 *
	 * @return mixed
	 */
	function exec_background($command, $args = '') {
		return test_call_override('exec_background', [$command, $args], null);
	}
}

if (!function_exists('cacti_escapeshellarg')) {
	/**
	 * Escape shell arguments through the shared test registry.
	 *
	 * @param string $value Argument value.
	 *
	 * @return string
	 */
	function cacti_escapeshellarg($value) {
		return test_call_override('cacti_escapeshellarg', [$value], escapeshellarg($value));
	}
}

beforeEach(function () {
	unset(
		$GLOBALS['syslog_replication_connection_failed'],
		$GLOBALS['syslog_replication_main_db_connection'],
		$GLOBALS['remote_db_cnn_id'],
		$GLOBALS['syslog_replication_main_db_hostname'],
		$GLOBALS['syslog_replication_main_db_default'],
		$GLOBALS['syslog_replication_main_db_username'],
		$GLOBALS['syslog_replication_main_db_password'],
		$GLOBALS['syslog_replication_main_db_port'],
		$GLOBALS['syslog_replication_main_db_retries'],
		$GLOBALS['syslog_replication_main_db_ssl'],
		$GLOBALS['syslog_replication_main_db_ssl_key'],
		$GLOBALS['syslog_replication_main_db_ssl_cert'],
		$GLOBALS['syslog_replication_main_db_ssl_ca']
	);

	$GLOBALS['syslogdb_default'] = 'cacti';
	$GLOBALS['database_default'] = 'main_syslog';
	$GLOBALS['database_type']    = 'mysql';
});

afterEach(function () {
	unset(
		$GLOBALS['syslog_replication_connection_failed'],
		$GLOBALS['syslog_replication_main_db_connection'],
		$GLOBALS['remote_db_cnn_id'],
		$GLOBALS['__status_rows'],
		$GLOBALS['__has_backlog'],
		$GLOBALS['__lease_insert_ok'],
		$GLOBALS['__lease_owner'],
		$GLOBALS['__lease_mode'],
		$GLOBALS['__poison_mode'],
		$GLOBALS['__heartbeat'],
		$GLOBALS['__php_binary'],
		$GLOBALS['__accept_failure'],
		$GLOBALS['__delivery_failure'],
		$GLOBALS['__incoming_failure'],
		$GLOBALS['__remove_failure'],
		$GLOBALS['__transaction_failure'],
		$GLOBALS['__start_tx_ok']
	);
});

it('covers replication helper guards and custom main database selection', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$calls = [];
	$connect_calls = [];

	test_override('read_config_option', fn ($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$calls) {
		$calls[] = ['sql' => $sql, 'params' => $params];

		return true;
	});
	test_override('db_connect_real', function (...$args) use (&$connect_calls) {
		$connect_calls[] = $args;

		return (object) ['connected' => true];
	});

	syslog_load_plugin_source('includes/functions.php');

	expect(syslog_replication_cleanup_local_history([[
		'source_poller_id' => 2,
		'source_event_id'  => 100,
		'disposition'      => 'syslog_removed',
	]]))->toBeTrue()
		->and($calls)->toBeEmpty()
		->and(syslog_replication_enqueue_incoming('WHERE 1=1', [], 'bogus'))->toBeFalse();

	test_override('read_config_option', fn () => '');
	expect(syslog_replication_enqueue_incoming('WHERE 1=1', [], 'syslog'))->toBeTrue()
		->and(syslog_replication_operational_status())->toBe(['enabled' => false])
		->and(syslog_replication_has_backlog())->toBeFalse();

	$GLOBALS['config'] = ['poller_id' => 1];
	test_override('syslog_db_table_exists', fn () => false);
	expect(syslog_replication_collector_status())->toBe([]);

	$GLOBALS['database_default'] = 'bad name!';
	expect(syslog_replication_main_database())->toBeFalse();

	$GLOBALS['database_default'] = 'main_syslog';
	$GLOBALS['syslog_replication_main_db_hostname'] = 'main-db';
	$GLOBALS['syslog_replication_main_db_default']  = 'main_archive';
	test_override('read_config_option', fn ($name) => $name === 'syslog_remote_enabled' ? 'on' : '');

	$main_database = syslog_replication_main_database();

	expect($main_database['database'])->toBe('main_archive')
		->and($main_database['connection'])->toBeObject()
		->and($connect_calls)->toHaveCount(1);

	expect(syslog_replication_main_database()['connection'])->toBe($main_database['connection'])
		->and($connect_calls)->toHaveCount(1);

	unset($GLOBALS['syslog_replication_main_db_connection']);
	unset($GLOBALS['syslog_replication_main_db_hostname']);
	$GLOBALS['remote_db_cnn_id'] = 'not-an-object';
	expect(syslog_replication_main_database())->toBeFalse();
});

it('records replication errors and state transitions without log spam', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$logs = [];
	$writes = [];

	test_override('read_config_option', fn ($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$writes) {
		$writes[] = $params;

		return true;
	});
	test_override('syslog_db_fetch_assoc', fn () => $GLOBALS['__status_rows'] ?? []);
	test_override('syslog_db_fetch_cell', fn () => $GLOBALS['__has_backlog'] ?? '');
	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});

	syslog_load_plugin_source('includes/functions.php');

	syslog_replication_record_error("first line\r\nsecond line " . str_repeat('x', 280));

	expect($writes[0][0])->toBe('replication_last_error')
		->and($writes[0][1])->not->toContain("\n")
		->and(strlen($writes[0][1]))->toBe(255)
		->and($writes[1][0])->toBe('replication_last_error_time');

	$GLOBALS['config'] = ['poller_id' => 1];
	expect(syslog_replication_record_state())->toBeNull();

	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
	$GLOBALS['__status_rows'] = [['name' => 'replication_state', 'value' => 'online']];
	$GLOBALS['__has_backlog'] = '';
	$write_count = cacti_sizeof($writes);
	expect(syslog_replication_record_state())->toBe('online')
		->and(cacti_sizeof($writes))->toBe($write_count);

	unset($GLOBALS['syslog_replication_connection_failed']);
	$GLOBALS['__status_rows'] = [];
	$logs = [];
	$GLOBALS['syslog_replication_connection_failed'] = true;
	expect(syslog_replication_record_state())->toBe('offline')
		->and($logs[0])->toContain('Central synchronization unavailable');

	unset($GLOBALS['syslog_replication_connection_failed']);
	$GLOBALS['config']['connection'] = 'recovery';
	$GLOBALS['__has_backlog'] = '1';
	$logs = [];
	expect(syslog_replication_record_state())->toBe('recovery')
		->and($logs[0])->toContain('entering recovery');

	$GLOBALS['config']['connection'] = 'online';
	$GLOBALS['__has_backlog'] = '';
	$logs = [];
	expect(syslog_replication_record_state())->toBe('online')
		->and($logs[0])->toContain('synchronization recovered');
});

it('uses configured recovery bounds and lease ownership checks', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$calls = [];
	$owner = '';

	test_override('read_config_option', function ($name) {
		return match ($name) {
			'syslog_remote_enabled' => 'on',
			'syslog_replication_recovery_records_per_run' => '777',
			'syslog_replication_recovery_batch_delay_ms' => '777',
			default => '',
		};
	});
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$calls) {
		$calls[] = ['sql' => $sql, 'params' => $params];

		return !str_contains($sql, 'syslog_replication_recovery') || !str_starts_with($sql, 'INSERT')
			? true
			: ($GLOBALS['__lease_insert_ok'] ?? true);
	});
	test_override('syslog_db_fetch_cell', fn () => $GLOBALS['__lease_owner'] ?? '');

	syslog_load_plugin_source('includes/functions.php');

	expect(syslog_replication_recovery_records_per_run())->toBe(SYSLOG_REPLICATION_RECOVERY_DEFAULT_RECORDS_PER_RUN)
		->and(syslog_replication_recovery_batch_delay_us())->toBe(SYSLOG_REPLICATION_RECOVERY_DEFAULT_DELAY_MS * 1000);

	test_override('read_config_option', function ($name) {
		return match ($name) {
			'syslog_remote_enabled' => 'on',
			'syslog_replication_recovery_records_per_run' => '5000',
			'syslog_replication_recovery_batch_delay_ms' => '25',
			default => '',
		};
	});
	expect(syslog_replication_recovery_records_per_run())->toBe(5000)
		->and(syslog_replication_recovery_batch_delay_us())->toBe(25000);

	$GLOBALS['config']['connection'] = 'offline';
	expect(syslog_replication_recovery_acquire('lease-a'))->toBeFalse();

	$GLOBALS['config']['connection'] = 'online';
	$GLOBALS['__lease_insert_ok'] = false;
	expect(syslog_replication_recovery_acquire('lease-a'))->toBeFalse();

	$GLOBALS['__lease_insert_ok'] = true;
	$GLOBALS['__lease_owner'] = 'different';
	expect(syslog_replication_recovery_acquire('lease-a'))->toBeFalse();

	$GLOBALS['__lease_owner'] = 'lease-a';
	expect(syslog_replication_recovery_acquire('lease-a'))->toBeTrue()
		->and(syslog_replication_recovery_heartbeat('lease-a'))->toBeTrue();

	syslog_replication_recovery_release('lease-a');

	expect(array_column($calls, 'sql'))->toContain("UPDATE `cacti`.`syslog_replication_recovery`\n\t\tSET heartbeat_at = ? WHERE name = 'recovery' AND owner_token = ?")
		->and(implode("\n", array_column($calls, 'sql')))->toContain("DELETE FROM `cacti`.`syslog_replication_recovery`");
});

it('covers recovery worker pause and completion branches', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'recovery'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$logs = [];
	$sql = [];
	$backlog_checks = 0;
	$lease_token = '';

	test_override('read_config_option', function ($name) {
		return match ($name) {
			'syslog_remote_enabled' => 'on',
			'syslog_replication_recovery_records_per_run' => '500',
			'syslog_replication_recovery_batch_delay_ms' => '0',
			default => '',
		};
	});
	test_override('syslog_db_execute_prepared', function ($statement, $params) use (&$sql, &$lease_token) {
		$sql[] = $statement;

		if (str_starts_with($statement, 'INSERT INTO `cacti`.`syslog_replication_recovery`')) {
			$lease_token = (string) $params[0];

			if (($GLOBALS['__lease_mode'] ?? '') === 'pause') {
				$GLOBALS['syslog_replication_connection_failed'] = true;
			}
		}

		return true;
	});
	test_override('syslog_db_fetch_cell', function ($statement) use (&$backlog_checks, &$lease_token) {
		if (str_contains($statement, 'owner_token')) {
			return $lease_token;
		}

		$backlog_checks++;

		if (($GLOBALS['__lease_mode'] ?? '') === 'empty') {
			return $backlog_checks === 1 ? '1' : '';
		}

		return '1';
	});
	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});

	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['config']['connection'] = 'online';
	expect(syslog_replication_recovery_run())->toBe(0);

	$GLOBALS['config']['connection'] = 'recovery';
	$GLOBALS['__lease_mode'] = 'busy';
	test_override('syslog_db_fetch_cell', function ($statement) use (&$backlog_checks, &$lease_token) {
		if (str_contains($statement, 'owner_token')) {
			return ($GLOBALS['__lease_mode'] ?? '') === 'busy' ? 'somebody-else' : $lease_token;
		}

		$backlog_checks++;

		if (($GLOBALS['__lease_mode'] ?? '') === 'empty') {
			return $backlog_checks === 1 ? '1' : '';
		}

		return '1';
	});
	$logs = [];
	expect(syslog_replication_recovery_run())->toBe(0)
		->and(implode("\n", $logs))->toContain('active lease already owns backlog convergence');

	$GLOBALS['__lease_mode'] = 'pause';
	expect(syslog_replication_recovery_run())->toBe(0)
		->and(implode("\n", $logs))->toContain('Recovery worker acquired lease')
		->toContain('paused because Main is unavailable')
		->and(implode("\n", $sql))->toContain('DELETE FROM `cacti`.`syslog_replication_recovery`');

	unset($GLOBALS['syslog_replication_connection_failed']);
	$GLOBALS['__lease_mode'] = 'empty';
	$logs = [];
	$sql = [];
	$backlog_checks = 0;
	expect(syslog_replication_recovery_run())->toBe(0)
		->and(implode("\n", $logs))->toContain('completed; Syslog outbox is empty');
});

it('covers recovery worker poison retention and budget exhaustion', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'recovery'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$GLOBALS['syslog_cnn'] = new stdClass();
	$logs = [];
	$lease_token = '';
	$backlog_checks = 0;
	$deliveries = 0;
	$recovery_updates = 0;

	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});
	test_override('read_config_option', function ($name) {
		return match ($name) {
			'syslog_remote_enabled' => 'on',
			'syslog_replication_recovery_records_per_run' => '500',
			'syslog_replication_recovery_batch_delay_ms' => '25',
			default => '',
		};
	});
	test_override('syslog_db_fetch_cell', function ($statement) use (&$lease_token, &$backlog_checks) {
		if (str_contains($statement, 'owner_token')) {
			return $lease_token;
		}

		$backlog_checks++;

		return '1';
	});
	test_override('syslog_db_fetch_assoc_prepared', fn () => []);
	test_override('syslog_db_fetch_assoc', fn () => [[
		'source_poller_id' => 2,
		'source_event_id'  => 100,
		'facility_id'      => 16,
		'priority_id'      => 6,
		'program'          => 'app',
		'logtime_epoch'    => 1758801600,
		'host'             => 'remote-host',
		'message'          => 'hello',
		'disposition'      => 'syslog',
	]]);
	test_override('db_execute', function ($sql) {
		return $sql !== 'COMMIT' || ($GLOBALS['__poison_mode'] ?? false) === false;
	});
	test_override('db_execute_prepared', fn () => true);
	test_override('db_affected_rows', fn () => 1);
	test_override('syslog_db_execute_prepared', function ($statement, $params) use (&$lease_token, &$recovery_updates) {
		if (str_starts_with($statement, 'INSERT INTO `cacti`.`syslog_replication_recovery`')) {
			$lease_token = (string) $params[0];
			return true;
		}

		if (str_starts_with($statement, 'UPDATE `cacti`.`syslog_replication_recovery`')) {
			$recovery_updates++;
		}

		return true;
	});

	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['__poison_mode'] = true;
	expect(syslog_replication_recovery_run())->toBe(0)
		->and(implode("\n", $logs))->toContain('retained the oldest unaccepted batch');

	$GLOBALS['__poison_mode'] = false;
	unset($GLOBALS['syslog_replication_connection_failed']);
	$logs = [];
	$backlog_checks = 0;
	$recovery_updates = 0;
	expect(syslog_replication_recovery_run())->toBe(5)
		->and($recovery_updates)->toBeGreaterThan(0)
		->and(implode("\n", $logs))->toContain('execution budget reached after 5 records');
});

it('reports recovery activity and starts workers only when eligible', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online', 'base_path' => '/cacti'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$logs = [];
	$commands = [];

	test_override('read_config_option', function ($name) {
		return match ($name) {
			'syslog_remote_enabled' => 'on',
			'path_php_binary' => $GLOBALS['__php_binary'] ?? '',
			default => '',
		};
	});
	test_override('syslog_db_fetch_cell', fn () => $GLOBALS['__heartbeat'] ?? '');
	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});
	test_override('cacti_escapeshellarg', fn ($value) => "'$value'");
	test_override('exec_background', function ($php, $args) use (&$commands) {
		$commands[] = [$php, $args];
	});

	syslog_load_plugin_source('includes/functions.php');

	expect(syslog_replication_recovery_is_active())->toBeFalse();

	$GLOBALS['__heartbeat'] = (string) time();
	expect(syslog_replication_recovery_is_active())->toBeTrue();

	$GLOBALS['config']['connection'] = 'online';
	$GLOBALS['__heartbeat'] = '';
	expect(syslog_replication_start_recovery_worker())->toBeFalse();

	$GLOBALS['config']['connection'] = 'recovery';
	$GLOBALS['__heartbeat'] = (string) time();
	expect(syslog_replication_start_recovery_worker())->toBeFalse();

	$GLOBALS['__heartbeat'] = '';
	$GLOBALS['__php_binary'] = '';
	$GLOBALS['__has_backlog'] = '1';
	test_override('syslog_db_fetch_cell', function ($statement) {
		if (str_contains($statement, 'replication_output')) {
			return '1';
		}

		return $GLOBALS['__heartbeat'] ?? '';
	});
	expect(syslog_replication_start_recovery_worker())->toBeFalse()
		->and($logs[0])->toContain('path_php_binary is empty');

	$GLOBALS['__php_binary'] = '/usr/bin/php';
	expect(syslog_replication_start_recovery_worker())->toBeTrue()
		->and($commands[0])->toBe([
			'/usr/bin/php',
			" -q '/cacti/plugins/syslog/syslog_recovery.php'",
		]);
});

it('covers central acceptance validation and replay failures', function () {
	$central = new stdClass();
	$event = [
		'source_poller_id'   => 2,
		'source_event_id'    => 100,
		'delivery_batch_id'  => 'batch-id',
		'facility_id'        => 16,
		'priority_id'        => 6,
		'program'            => 'app',
		'host'               => 'remote-host',
		'logtime_epoch'      => 1758801600,
		'message'            => 'hello',
		'disposition'        => 'syslog',
	];
	$logs = [];

	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});
	test_override('db_execute_prepared', function ($sql) {
		if (($GLOBALS['__accept_failure'] ?? '') === 'receipt' && str_contains($sql, 'syslog_replication_receipts')) {
			return false;
		}

		if (($GLOBALS['__accept_failure'] ?? '') === 'collectors' && str_contains($sql, 'syslog_replication_collectors')) {
			return false;
		}

		if (($GLOBALS['__accept_failure'] ?? '') === 'lookup' && str_contains($sql, 'syslog_programs')) {
			return false;
		}

		return true;
	});
	test_override('db_affected_rows', fn () => 1);

	syslog_load_plugin_source('includes/functions.php');

	$invalid = $event;
	$invalid['disposition'] = 'bogus';

	expect(syslog_replication_accept_central($invalid, $central, 'main_syslog'))->toBeFalse()
		->and($logs[0])->toContain('invalid disposition');

	$GLOBALS['__accept_failure'] = 'receipt';
	expect(syslog_replication_accept_central($event, $central, 'main_syslog'))->toBeFalse();

	$GLOBALS['__accept_failure'] = 'collectors';
	expect(syslog_replication_accept_central($event, $central, 'main_syslog'))->toBeFalse();

	$GLOBALS['__accept_failure'] = 'lookup';
	expect(syslog_replication_accept_central($event, $central, 'main_syslog'))->toBeFalse();
});

it('covers online delivery failure handling around the central transaction', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$GLOBALS['syslog_cnn'] = new stdClass();
	$GLOBALS['database_default'] = 'main_syslog';
	$logs = [];

	test_override('read_config_option', fn ($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});
	test_override('syslog_db_fetch_assoc', fn () => [[
		'source_poller_id' => 2,
		'source_event_id'  => 100,
		'facility_id'      => 16,
		'priority_id'      => 6,
		'program'          => 'app',
		'logtime_epoch'    => 1758801600,
		'host'             => 'remote-host',
		'message'          => 'hello',
		'disposition'      => 'syslog',
	]]);
	test_override('db_execute_prepared', fn () => true);
	test_override('db_affected_rows', fn () => 1);
	test_override('syslog_db_execute_prepared', function ($sql) {
		if (($GLOBALS['__delivery_failure'] ?? '') === 'cleanup' && str_contains($sql, 'DELETE FROM `cacti`.`syslog` WHERE')) {
			return false;
		}

		if (($GLOBALS['__delivery_failure'] ?? '') === 'ack' && str_contains($sql, 'syslog_replication_output')) {
			return false;
		}

		return true;
	});
	test_override('db_execute', function ($sql) {
		if (($GLOBALS['__delivery_failure'] ?? '') === 'begin' && $sql === 'START TRANSACTION') {
			return false;
		}

		if (($GLOBALS['__delivery_failure'] ?? '') === 'commit' && $sql === 'COMMIT') {
			return false;
		}

		return true;
	});

	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['database_default'] = 'bad name!';
	expect(syslog_replication_deliver_online())->toBe(0)
		->and($logs[0])->toContain('database connection is unavailable');

	$GLOBALS['database_default'] = 'main_syslog';
	unset($GLOBALS['syslog_replication_connection_failed']);
	test_override('syslog_db_fetch_assoc', fn () => []);
	unset($GLOBALS['syslog_replication_connection_failed']);
	expect(syslog_replication_deliver_online())->toBe(0);

	test_override('syslog_db_fetch_assoc', fn () => [[
		'source_poller_id' => 2,
		'source_event_id'  => 100,
		'facility_id'      => 16,
		'priority_id'      => 6,
		'program'          => 'app',
		'logtime_epoch'    => 1758801600,
		'host'             => 'remote-host',
		'message'          => 'hello',
		'disposition'      => 'syslog',
	]]);

	$GLOBALS['__delivery_failure'] = 'begin';
	unset($GLOBALS['syslog_replication_connection_failed']);
	expect(syslog_replication_deliver_online())->toBe(0);

	$GLOBALS['__accept_failure'] = 'receipt';
	$GLOBALS['__delivery_failure'] = '';
	test_override('db_execute_prepared', function ($sql) {
		if (str_contains($sql, 'syslog_replication_receipts')) {
			return false;
		}

		return true;
	});
	unset($GLOBALS['syslog_replication_connection_failed']);
	expect(syslog_replication_deliver_online())->toBe(0);

	test_override('db_execute_prepared', fn () => true);
	$GLOBALS['__delivery_failure'] = 'commit';
	unset($GLOBALS['syslog_replication_connection_failed']);
	expect(syslog_replication_deliver_online())->toBe(0);

	$GLOBALS['__delivery_failure'] = 'cleanup';
	unset($GLOBALS['syslog_replication_connection_failed']);
	expect(syslog_replication_deliver_online())->toBe(0);

	$GLOBALS['__delivery_failure'] = 'ack';
	unset($GLOBALS['syslog_replication_connection_failed']);
	expect(syslog_replication_deliver_online())->toBe(0);
});

it('covers incoming transfer branches for outage retention and commit failures', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'offline'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$GLOBALS['syslog_cnn'] = new stdClass();
	$calls = [];

	test_override('read_config_option', fn ($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
	test_override('syslog_db_execute', fn ($sql) => $sql !== 'START TRANSACTION' || ($GLOBALS['__start_tx_ok'] ?? true));
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$calls) {
		$calls[] = ['sql' => $sql, 'params' => $params];

		if (($GLOBALS['__incoming_failure'] ?? '') === 'enqueue' && str_contains($sql, 'syslog_replication_output')) {
			return false;
		}

		if (($GLOBALS['__incoming_failure'] ?? '') === 'archive' && str_contains($sql, 'INSERT INTO `cacti`.`syslog`')) {
			return false;
		}

		if (($GLOBALS['__incoming_failure'] ?? '') === 'delete' && str_contains($sql, 'DELETE FROM `cacti`.`syslog_incoming`')) {
			return false;
		}

		return true;
	});
	test_override('db_affected_rows', fn () => 3);

	syslog_load_plugin_source('includes/functions.php');

	$GLOBALS['__start_tx_ok'] = false;
	expect(syslog_incoming_to_syslog(100, 1, 100))->toBe([
		'moved' => 0,
		'stale' => 0,
		'success' => false,
	]);

	$GLOBALS['__start_tx_ok'] = true;
	$GLOBALS['__incoming_failure'] = 'enqueue';
	expect(syslog_incoming_to_syslog(100, 1, 100))->toBe([
		'moved' => 0,
		'stale' => 0,
		'success' => false,
	]);

	$GLOBALS['__incoming_failure'] = '';
	$calls = [];
	expect(syslog_incoming_to_syslog(100, 1, 100))->toBe([
		'moved' => 3,
		'stale' => 0,
		'success' => true,
	])->and(implode("\n", array_column($calls, 'sql')))->toContain('replication_source_poller_id')
		->toContain('AND `seq` BETWEEN ? AND ?');

	$GLOBALS['config']['connection'] = 'online';
	$GLOBALS['__incoming_failure'] = 'archive';
	test_override('read_config_option', function ($name) {
		return match ($name) {
			'syslog_remote_enabled' => 'on',
			'syslog_remote_store_records' => 'on',
			default => '',
		};
	});
	expect(syslog_incoming_to_syslog(100, 1, 100))->toBe([
		'moved' => 0,
		'stale' => 0,
		'success' => false,
	]);

	$GLOBALS['config'] = ['poller_id' => 1, 'connection' => 'online'];
	$GLOBALS['__incoming_failure'] = '';
	test_override('read_config_option', fn () => '');
	expect(syslog_incoming_to_syslog(100))->toBe([
		'moved' => 3,
		'stale' => 3,
		'success' => true,
	]);
});

it('covers online outbox-only transfer, delete failures, and removal-rule rollback', function () {
	$GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
	$GLOBALS['remote_db_cnn_id'] = new stdClass();
	$GLOBALS['syslog_cnn'] = new stdClass();
	$GLOBALS['syslog_incoming_config'] = [
		'textField' => 'message',
		'facilityField' => 'facility',
	];
	$calls = [];
	$logs = [];

	test_override('read_config_option', function ($name) {
		return match ($name) {
			'syslog_remote_enabled' => 'on',
			'syslog_remote_store_records' => '',
			default => '',
		};
	});
	test_override('cacti_log', function ($message) use (&$logs) {
		$logs[] = $message;
	});
	test_override('syslog_db_execute', function ($sql) use (&$calls) {
		$calls[] = $sql;

		if (($GLOBALS['__transaction_failure'] ?? '') === 'commit' && $sql === 'COMMIT') {
			return false;
		}

		return true;
	});
	test_override('syslog_db_fetch_assoc', function ($sql) {
		if (str_contains($sql, 'syslog_remove')) {
			return [[
				'name'    => 'Archive and replicate',
				'type'    => 'sql',
				'message' => '1=1',
				'method'  => 'move',
			]];
		}

		return [];
	});
	test_override('syslog_db_fetch_cell_prepared', fn () => 1);
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$calls) {
		$calls[] = $sql;

		if (($GLOBALS['__incoming_failure'] ?? '') === 'delete' && str_contains($sql, 'DELETE FROM `cacti`.`syslog_incoming`')) {
			return false;
		}

		if (($GLOBALS['__remove_failure'] ?? '') === 'outbox' && str_contains($sql, 'syslog_replication_output')) {
			return false;
		}

		return true;
	});
	test_override('db_affected_rows', fn () => 2);

	syslog_load_plugin_source('includes/functions.php');

	expect(syslog_incoming_to_syslog(100, 1, 100))->toBe([
		'moved' => 0,
		'stale' => 0,
		'success' => true,
	]);

	$GLOBALS['__incoming_failure'] = 'delete';
	expect(syslog_incoming_to_syslog(100, 1, 100))->toBe([
		'moved' => 0,
		'stale' => 0,
		'success' => false,
	]);

	$GLOBALS['__incoming_failure'] = '';
	$GLOBALS['__transaction_failure'] = 'commit';
	expect(syslog_incoming_to_syslog(100, 1, 100))->toBe([
		'moved' => 0,
		'stale' => 0,
		'success' => false,
	]);

	$GLOBALS['__transaction_failure'] = '';
	$GLOBALS['__remove_failure'] = 'outbox';
	$calls = [];
	$logs = [];
	expect(syslog_remove_items('syslog_incoming', 100))->toBe([
		'removed' => 0,
		'xferred' => 0,
	])->and(implode("\n", $calls))->toContain('START TRANSACTION')
		->toContain('ROLLBACK')
		->and(implode("\n", $logs))->toContain('after replication outbox insert failed');
});
