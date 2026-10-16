<?php

it('requires a matching durable source identity before deleting incoming rows', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslogdb_default'] = 'syslog';
	$deletes = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$deletes) {
		if (str_starts_with($sql, 'DELETE FROM')) {
			$deletes[] = [$sql, $params];
		}

		return true;
	});
	test_override('syslog_db_execute', function ($sql) {
		expect($sql)->not->toContain('DELETE');

		return true;
	});

	expect(syslog_incoming_to_syslog(100)['stale'])->toBe(0);
	expect(syslog_incoming_to_syslog(100, 51, 100)['success'])->toBeTrue();
	expect($deletes)->toHaveCount(2);
	foreach ($deletes as [$sql, $params]) {
		expect($sql)->toContain('AND EXISTS (')
			->toContain('`syslog`.`syslog` AS receipt')
			->toContain('receipt.`replication_source_poller_id` = ?')
			->toContain('receipt.`replication_source_event_id` = syslog_incoming.seq')
			->toContain('receipt.logtime = syslog_incoming.logtime')
			->not->toContain('DATE_SUB');
	}
	expect($deletes[0][1])->toBe([100, 1])
		->and($deletes[1][1])->toBe([100, 51, 100, 1]);
});

it('uses durable outbox identities for remote incoming deletion', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 2;
	$GLOBALS['syslogdb_default'] = 'syslog';
	test_override('read_config_option', fn($name) => in_array($name, ['syslog_remote_enabled', 'syslog_remote_store_records'], true) ? 'on' : '');
	$delete = '';
	test_override('syslog_db_execute_prepared', function ($sql) use (&$delete) {
		if (str_starts_with($sql, 'DELETE FROM')) {
			$delete = $sql;
		}

		return true;
	});

	expect(syslog_incoming_to_syslog(100)['success'])->toBeTrue();
	expect($delete)->toContain('`syslog`.`syslog_replication_output` AS receipt')
		->toContain('receipt.`source_poller_id` = ?')
		->toContain('receipt.`source_event_id` = syslog_incoming.seq');
});


it('retries crash-stranded rows even when no new messages arrive', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog';
	test_override('syslog_db_fetch_cell', function ($sql) {
		expect($sql)->toContain('WHERE status IN (0, 1)');

		return 42;
	});
	test_override('syslog_db_execute_prepared', function ($sql, $params) {
		expect($sql)->toContain('SET `status` = 1')->toContain('WHERE `status` = 0');
		expect($params)->toBe([42]);

		return true;
	});
	test_override('syslog_db_fetch_cell_prepared', static fn() => 3);
	expect(syslog_preprocess_incoming_records())->toBe(['max_seq' => 42, 'incoming' => 3]);
});

it('rolls back failed local transfers without reporting archived rows', function ($failure) {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslogdb_default'] = 'syslog';
	$calls = [];
	test_override('syslog_db_execute', function ($sql) use (&$calls, $failure) {
		$calls[] = $sql;

		return $sql !== $failure;
	});
	test_override('syslog_db_execute_prepared', function ($sql) use (&$calls, $failure) {
		$operation = strtok($sql, ' ');
		$calls[] = $operation;

		return $operation !== $failure;
	});
	test_override('db_affected_rows', static fn() => 4);

	expect(syslog_incoming_to_syslog(100, 51, 100))->toBe(['moved' => 0, 'stale' => 0, 'success' => false]);
	expect($calls[0])->toBe('START TRANSACTION');
	if ($failure === 'START TRANSACTION') {
		expect($calls)->toHaveCount(1);
	} else {
		expect(end($calls))->toBe('ROLLBACK');
	}
	if ($failure === 'UPDATE') {
		expect($calls)->not->toContain('INSERT')->not->toContain('DELETE');
	}
	if ($failure === 'INSERT') {
		expect($calls)->not->toContain('DELETE');
	}
})->with(['START TRANSACTION', 'UPDATE', 'INSERT', 'DELETE', 'COMMIT']);
