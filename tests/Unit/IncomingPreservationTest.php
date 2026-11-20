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

it('strips program boundary delimiters only from the active incoming batch', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'main';
	$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$db->exec('CREATE TABLE syslog_incoming (seq INTEGER PRIMARY KEY, status INTEGER, program TEXT)');
	$insert = $db->prepare('INSERT INTO syslog_incoming VALUES (?, ?, ?)');
	$programs = [
		['(o-bridge)', 'o-bridge'],
		['(sd-pam)', 'sd-pam'],
		['(systemd)', 'systemd'],
		['gdm-launch-environment]', 'gdm-launch-environment'],
		['[sshd]', 'sshd'],
		['[(systemd)]', 'systemd'],
		['](leading', 'leading'],
		['trailing([', 'trailing'],
		['worker[7](helper)-main', 'worker[7](helper)-main'],
		['plain-program', 'plain-program'],
		['([])', ''],
		[null, null]
	];
	foreach ($programs as $index => [$program]) {
		$insert->execute([$index + 1, 1, $program]);
	}
	$insert->execute([100, 0, '(pending)']);
	$insert->execute([101, 1, '[next-batch]']);
	$insert->execute([102, 2, '(processed)']);
	test_override('syslog_db_fetch_assoc_prepared', static function ($sql, $params) use ($db) {
		$query = $db->prepare($sql);
		$query->execute($params);

		return $query->fetchAll(PDO::FETCH_ASSOC);
	});
	test_override('syslog_db_execute_prepared', static function ($sql, $params) use ($db) {
		$query = $db->prepare($sql);

		return $query->execute($params);
	});

	expect(syslog_strip_incoming_programs(100))->toBeTrue()
		->and($db->query('SELECT program FROM syslog_incoming ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN))
		->toBe([...array_column($programs, 1), '(pending)', '[next-batch]', '(processed)']);
});

it('bounds program normalization updates and continues beyond the first batch', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'main';
	$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$db->exec('CREATE TABLE syslog_incoming (seq INTEGER PRIMARY KEY, status INTEGER, program TEXT)');
	$insert = $db->prepare('INSERT INTO syslog_incoming VALUES (?, 1, ?)');
	foreach (range(1, 251) as $seq) {
		$insert->execute([$seq, '(worker-' . $seq . ')']);
	}
	$updates = [];
	test_override('syslog_db_fetch_assoc_prepared', static function ($sql, $params) use ($db) {
		$query = $db->prepare($sql);
		$query->execute($params);

		return $query->fetchAll(PDO::FETCH_ASSOC);
	});
	test_override('syslog_db_execute_prepared', static function ($sql, $params) use ($db, &$updates) {
		$updates[] = count($params);
		$query = $db->prepare($sql);

		return $query->execute($params);
	});

	expect(syslog_strip_incoming_programs(251))->toBeTrue()
		->and($updates)->toBe([751, 4])
		->and($db->query('SELECT program FROM syslog_incoming ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN))
		->toBe(array_map(static fn($seq) => 'worker-' . $seq, range(1, 251)));
});

it('leaves the incoming batch for retry when program normalization fails', function ($failure) {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['syslogdb_default'] = 'syslog';
	$logs = [];
	$hooks = [];
	test_override('syslog_db_fetch_cell', static fn() => 42);
	test_override('syslog_db_fetch_cell_prepared', static fn() => 3);
	test_override('syslog_db_fetch_assoc_prepared', static fn() => $failure === 'select' ? false : [['program' => '(systemd)']]);
	test_override('syslog_db_execute_prepared', static fn($sql) => $failure !== 'update' || !str_contains($sql, 'CASE program'));
	test_override('cacti_log', static function ($message) use (&$logs) { $logs[] = $message; });
	test_override('api_plugin_hook', static function ($hook) use (&$hooks) { $hooks[] = $hook; });

	expect(syslog_preprocess_incoming_records())->toBe(['max_seq' => 0, 'incoming' => 0])
		->and($logs)->toBe(['ERROR: Unable to normalize incoming program names; this batch will be retried.'])
		->and($hooks)->toBe([]);
})->with(['select', 'update']);

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
