<?php

afterEach(function () {
	syslog_test_reset_globals();
});

it('marks only messages in a threshold-breaching batch with bounded prepared updates', function () {
	syslog_load_plugin_source('includes/functions.php');
	$updates = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$updates) {
		$updates[] = [$sql, $params];
		return true;
	});
	$rule = ['id' => 7, 'method' => 1, 'num' => 5];
	$messages = [['seq' => 101], ['seq' => 103]];
	syslog_mark_threshold_messages($rule, 4, $messages);
	syslog_mark_threshold_messages($rule, 0, $messages);
	syslog_mark_threshold_messages(['id' => 8, 'method' => 0, 'num' => 1], 5, $messages);
	syslog_mark_threshold_messages($rule, 5, []);
	expect($updates)->toBe([]);
	syslog_mark_threshold_messages($rule, 5, $messages);
	expect($updates)->toBe([['UPDATE syslog_incoming SET threshold_breached = 1 WHERE status = 1 AND seq IN (?,?)', [101, 103]]]);
	$updates = [];
	syslog_mark_threshold_messages($rule, 1001, array_map(static fn($seq) => ['seq' => $seq], range(1, 1001)));
	expect($updates)->toHaveCount(2)
		->and($updates[0][1])->toHaveCount(1000)
		->and($updates[1][1])->toBe([1001]);
});

it('logs a marker write failure without issuing further updates', function () {
	syslog_load_plugin_source('includes/functions.php');
	$attempts = 0;
	test_override('syslog_db_execute_prepared', function () use (&$attempts) { $attempts++; return false; });
	syslog_mark_threshold_messages(['id' => 7, 'method' => 1, 'num' => 5], 1001, array_map(static fn($seq) => ['seq' => $seq], range(1, 1001)));
	expect($attempts)->toBe(1);
});

it('appends nullable message breach columns without a backfill during schema repair', function () {
	$GLOBALS['config']['poller_id'] = 1;
	syslog_load_plugin_source('includes/schema.php');
	$columns = [];
	test_override('syslog_db_table_exists', static fn() => true);
	// the digest migration hooks run inside the same repair pass
	test_override('syslog_db_column_exists', static fn() => true);
	test_override('db_update_table', function ($table) {
		// The general table reconciler may drop an omitted primary key.
		expect($table)->not->toBeIn(['syslog', 'syslog_removed', 'syslog_incoming', 'syslog_replication_output']);
		return true;
	});
	test_override('syslog_db_add_column', function ($table, $column) use (&$columns) {
		if ($column['name'] === 'threshold_breached') {
			$columns[$table] = $column['NULL'];
		}
		expect($column)->not->toHaveKey('default')->not->toHaveKey('after');
		return true;
	});
	expect(syslog_ensure_table_structures())->toBeTrue();
	expect($columns)->toBe(['syslog' => true, 'syslog_removed' => true, 'syslog_incoming' => true, 'syslog_replication_output' => true]);
});

it('preserves marked and unmarked messages during central replication', function () {
	syslog_load_plugin_source('includes/functions.php');
	$archives = [];
	test_override('db_affected_rows', static fn() => 1);
	test_override('db_execute_prepared', function ($sql, $params) use (&$archives) {
		if (str_contains($sql, 'INSERT INTO `central`.`syslog`')) {
			$archives[] = [$sql, $params];
		}
		return true;
	});
	$event = ['source_poller_id' => 2, 'source_event_id' => 10, 'delivery_batch_id' => 'batch', 'disposition' => 'syslog', 'program' => 'app', 'host' => 'router', 'message' => 'error', 'logtime_epoch' => 1000, 'priority_id' => 3, 'facility_id' => 1];
	foreach ([0, 1, null] as $flag) {
		$event['threshold_breached'] = $flag;
		expect(syslog_replication_accept_central($event, new stdClass(), 'central'))->toBeTrue();
	}
	expect($archives)->toHaveCount(3)
		->and($archives[0][0])->toContain('message, threshold_breached')
		->and($archives[0][1])->toBe([1000, 3, 1, 'error', 0, 2, 10, 'app', 'router'])
		->and($archives[1][1])->toBe([1000, 3, 1, 'error', 1, 2, 10, 'app', 'router'])
		->and($archives[2][1])->toBe([1000, 3, 1, 'error', null, 2, 10, 'app', 'router']);
});

it('carries message breach flags through local archival and the replication outbox', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 1;
	$statements = [];
	test_override('syslog_db_execute_prepared', function ($sql) use (&$statements) { $statements[] = $sql; return true; });
	expect(syslog_incoming_to_syslog(100)['success'])->toBeTrue();
	$archive = implode("\n", $statements);
	expect($archive)->toContain('message, message_digest, threshold_breached')->toContain('si.threshold_breached');
	$statements = [];
	$GLOBALS['config']['poller_id'] = 2;
	test_override('read_config_option', static fn($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
	expect(syslog_replication_enqueue_incoming('WHERE si.status = 1 AND si.seq <= ?', [100], 'syslog'))->toBeTrue();
	expect(implode("\n", $statements))->toContain('message, threshold_breached, disposition')->toContain('si.threshold_breached');
});


it('stops schema repair if the add-only message column migration fails', function () {
	$GLOBALS['config']['poller_id'] = 1;
	syslog_load_plugin_source('includes/schema.php');
	$attempted = [];
	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_add_column', function ($table) use (&$attempted) {
		$attempted[] = $table;
		return false;
	});
	expect(syslog_ensure_table_structures())->toBeFalse();
	expect($attempted)->toBe(['syslog']);
});
