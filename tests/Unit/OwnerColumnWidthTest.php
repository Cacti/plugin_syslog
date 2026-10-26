<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Rule owners use the Cacti username width on the Syslog database connection.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

function owner_width_setup(?string $failed_table = null): stdClass {
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslogdb_default'] = 'syslogdb';
	$GLOBALS['syslog_cnn'] = new stdClass();

	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('includes/schema.php');

	$state = new stdClass();
	$state->updates = [];

	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', static fn() => true);
	test_override('syslog_db_add_column', static fn() => true);
	test_override('db_update_table', function ($table, $data, $remove, $log, $connection) use ($state, $failed_table) {
		foreach ($data['columns'] ?? [] as $column) {
			if ($column['name'] === 'user' && in_array($table, ['syslog_alert', 'syslog_remove', 'syslog_reports', 'syslog_device_rule'], true)) {
				$state->updates[$table] = compact('column', 'remove', 'connection');

				return $table !== $failed_table;
			}
		}

		return true;
	});

	return $state;
}

it('upgrades every rule owner column through the standard schema helper', function () {
	$state = owner_width_setup();

	expect(syslog_ensure_table_structures())->toBeTrue()
		->and(array_keys($state->updates))->toBe(['syslog_alert', 'syslog_remove', 'syslog_reports', 'syslog_device_rule']);

	foreach ($state->updates as $update) {
		expect($update['column'])->toBe(['name' => 'user', 'type' => 'varchar(50)', 'NULL' => false, 'default' => ''])
			->and($update['remove'])->toBeFalse()
			->and($update['connection'])->toBe($GLOBALS['syslog_cnn']);
	}
});

it('fails the upgrade when an owner column cannot be updated', function () {
	$state = owner_width_setup('syslog_reports');

	expect(syslog_ensure_table_structures())->toBeFalse()
		->and(array_keys($state->updates))->toBe(['syslog_alert', 'syslog_remove', 'syslog_reports']);
});

it('creates the owner columns at the Cacti username width', function () {
	$schema = plugin_test_read_source('includes/schema.php');

	expect($schema)->not->toContain('`user` varchar(32)')
		->and($schema)->not->toContain("'name' => 'user', 'type' => 'varchar(32)'");
});
