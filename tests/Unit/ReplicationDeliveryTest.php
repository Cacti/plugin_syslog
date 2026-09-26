<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 */

it('delivers an exact remote outbox batch through the central receipt transaction', function () {
    $GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
    $GLOBALS['remote_db_cnn_id'] = new stdClass();
    $GLOBALS['syslog_cnn'] = new stdClass();
    $GLOBALS['syslogdb_default'] = 'cacti';
    $calls = [];

    test_override('read_config_option', fn ($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
    test_override('syslog_db_fetch_assoc', fn () => [[
        'source_poller_id' => 2, 'source_event_id' => 100, 'facility_id' => 16,
        'priority_id' => 6, 'program' => 'app', 'logtime' => '2026-09-25 12:00:00', 'logtime_epoch' => 1758801600,
        'host' => 'remote-host', 'message' => 'hello', 'disposition' => 'syslog',
    ]]);
    test_override('db_execute', function ($sql) use (&$calls) { $calls[] = $sql; return true; });
    test_override('db_execute_prepared', function ($sql, $params) use (&$calls) { $calls[] = $sql; return true; });
    test_override('db_affected_rows', fn () => 1);

    syslog_load_plugin_source('functions.php');

    expect(syslog_replication_deliver_online())->toBe(1)
        ->and(implode("\n", $calls))->toContain('START TRANSACTION')
        ->toContain('syslog_replication_receipts')
        ->toContain('INSERT INTO `cacti`.`syslog`')
        ->toContain('DELETE FROM `cacti`.`syslog_replication_output`')
        ->toContain('COMMIT');
});

it('treats an existing central receipt as an acknowledged retry without rearchiving', function () {
    $GLOBALS['syslogdb_default'] = 'cacti';
    $central = new stdClass();
    $sql = [];

    test_override('db_execute_prepared', function ($statement) use (&$sql) { $sql[] = $statement; return true; });
    test_override('db_affected_rows', fn () => 0);

    syslog_load_plugin_source('functions.php');

    expect(syslog_replication_accept_central([
        'source_poller_id' => 2, 'source_event_id' => 100, 'disposition' => 'syslog',
        'facility_id' => 16, 'priority_id' => 6, 'program' => 'app',
        'host' => 'remote-host', 'logtime' => '2026-09-25 12:00:00', 'message' => 'hello',
    ], $central))->toBeTrue()
        ->and(cacti_sizeof($sql))->toBe(1)
        ->and($sql[0])->toContain('syslog_replication_receipts');
});

it('never enables central delivery on the Main Collector', function () {
    $GLOBALS['config'] = ['poller_id' => 1, 'connection' => 'online'];
    $GLOBALS['remote_db_cnn_id'] = new stdClass();

    test_override('read_config_option', fn () => 'on');
    syslog_load_plugin_source('functions.php');

    expect(syslog_replication_delivery_is_online())->toBeFalse();
});


it('derives Syslog delivery state from Main reachability and the durable outbox', function () {
    $GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
    $GLOBALS['remote_db_cnn_id'] = new stdClass();
    $GLOBALS['syslogdb_default'] = 'cacti';
    test_override('read_config_option', fn () => 'on');
    test_override('syslog_db_fetch_cell', fn () => '');
    syslog_load_plugin_source('functions.php');

    expect(syslog_replication_get_state())->toBe('online');

    test_override('syslog_db_fetch_cell', fn () => '1');
    expect(syslog_replication_get_state())->toBe('recovery');

    // Cacti recovery still has a usable Main connection; only Syslog's own
    // outbox decides whether this is Syslog recovery.
    $GLOBALS['config']['connection'] = 'recovery';
    expect(syslog_replication_get_state())->toBe('recovery');

    $GLOBALS['config']['connection'] = 'offline';
    expect(syslog_replication_get_state())->toBe('offline');
});

it('does not assign a synchronization state to Main or non-Syslog remote collectors', function () {
    $GLOBALS['remote_db_cnn_id'] = new stdClass();
    $GLOBALS['config'] = ['poller_id' => 1, 'connection' => 'online'];
    test_override('read_config_option', fn () => 'on');
    syslog_load_plugin_source('functions.php');
    expect(syslog_replication_get_state())->toBeNull();

    $GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
    test_override('read_config_option', fn () => '');
    expect(syslog_replication_get_state())->toBeNull();
});

it('keeps the outbox untouched when central delivery is unavailable', function () {
    $GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'offline'];
    $GLOBALS['remote_db_cnn_id'] = new stdClass();
    $GLOBALS['syslogdb_default'] = 'cacti';
    $calls = [];
    test_override('read_config_option', fn () => 'on');
    test_override('syslog_db_execute_prepared', function ($sql) use (&$calls) { $calls[] = $sql; return true; });
    syslog_load_plugin_source('functions.php');

    expect(syslog_replication_deliver_online())->toBe(0)
        ->and($calls)->toBeEmpty();
});


it('returns to offline state for this process when Main fails during recovery', function () {
    $GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
    $GLOBALS['remote_db_cnn_id'] = new stdClass();
    $GLOBALS['syslog_cnn'] = new stdClass();
    $GLOBALS['syslogdb_default'] = 'cacti';
    test_override('read_config_option', fn () => 'on');
    test_override('syslog_db_fetch_assoc', fn () => [[
        'source_poller_id' => 2, 'source_event_id' => 100, 'facility_id' => 16,
        'priority_id' => 6, 'program' => 'app', 'logtime' => '2026-09-25 12:00:00',
        'host' => 'remote-host', 'message' => 'hello', 'disposition' => 'syslog',
    ]]);
    test_override('db_execute', fn ($sql) => $sql !== 'START TRANSACTION');
    syslog_load_plugin_source('functions.php');

    expect(syslog_replication_deliver_online())->toBe(0)
        ->and(syslog_replication_get_state())->toBe('offline');
});


it('uses an atomic, token-scoped expiring recovery lease', function () {
    syslog_load_plugin_source('functions.php');
    $source = file_get_contents(__DIR__ . '/../../functions.php');

    expect($source)->toContain('INSERT INTO `$syslogdb_default`.`syslog_replication_recovery`')
        ->toContain('ON DUPLICATE KEY UPDATE')
        ->toContain('heartbeat_at < ?')
        ->toContain("WHERE name = 'recovery' AND owner_token = ?");
});
it('keeps recovery bounded and delegates batch delivery to the Phase 2 primitive', function () {
    syslog_load_plugin_source('functions.php');
    $source = file_get_contents(__DIR__ . '/../../functions.php');

    expect($source)->toContain('syslog_replication_recovery_records_per_run()')
        ->toContain('syslog_replication_recovery_batch_delay_us()')
        ->toContain('syslog_replication_deliver_online()')
        ->toContain('UNIX_TIMESTAMP(logtime) AS logtime_epoch')
        ->toContain('FROM_UNIXTIME(?)')
        ->toContain('ORDER BY created_at ASC, source_poller_id ASC, source_event_id ASC');
});


it('returns on-demand operational replication telemetry without remote fan-out', function () {
    unset($GLOBALS['syslog_replication_connection_failed']);
    $GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
    $GLOBALS['remote_db_cnn_id'] = new stdClass();
    $GLOBALS['syslogdb_default'] = 'cacti';
    test_override('read_config_option', fn () => 'on');
    test_override('syslog_db_fetch_row', function ($sql) {
        return str_contains($sql, 'replication_output') ? ['pending' => '12', 'oldest_pending' => '2026-09-25 10:00:00'] : [];
    });
    test_override('syslog_db_fetch_cell', fn () => '1');
    test_override('syslog_db_fetch_assoc', fn () => []);
    syslog_load_plugin_source('functions.php');
    $telemetry = syslog_replication_operational_status();
    expect($telemetry['enabled'])->toBeTrue()->and($telemetry['state'])->toBe('recovery')->and($telemetry['pending'])->toBe(12);
});
