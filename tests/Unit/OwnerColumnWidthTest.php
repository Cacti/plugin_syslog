<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Rule owner columns hold a full Cacti username and are widened once on existing installs.
 */

afterEach(function () {
	syslog_test_reset_globals();
});


function owner_width_setup(?string $marker, ?callable $execute = null): stdClass {
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslogdb_default']    = 'syslogdb';

	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('includes/schema.php');

	$state = new stdClass();

	$state->alters = [];
	$state->status = [];

	test_override('syslog_db_table_exists', static fn() => true);
	test_override('syslog_db_column_exists', static fn() => true);
	test_override('syslog_db_add_column', static fn() => true);
	test_override('db_update_table', static fn() => true);
	test_override('syslog_db_fetch_cell_prepared', static fn($sql, $params) => ($params[0] ?? '') === 'schema_user_width' ? $marker : '');
	test_override('syslog_db_execute', function ($sql) use ($state, $execute) {
		$state->alters[] = $sql;

		return $execute ? $execute($sql) : true;
	});
	test_override('syslog_db_execute_prepared', function ($sql, $params) use ($state) {
		if (str_contains($sql, 'syslog_status') && ($params[0] ?? '') === 'schema_user_width') {
			$state->status[] = $params[1];
		}

		return true;
	});

	return $state;
}


it('widens the owner column of every rule table once', function () {
	$state = owner_width_setup(null);

	expect(syslog_ensure_table_structures())->toBeTrue();

	$alters = array_values(array_filter($state->alters, static fn($sql) => str_starts_with($sql, 'ALTER TABLE') && str_contains($sql, '`user` varchar(50)')));

	expect($alters)->toHaveCount(4)
		->and($alters[0])->toContain('`syslogdb`.`syslog_alert`')
		->and($alters[3])->toContain('`syslogdb`.`syslog_device_rule`')
		->and($state->status)->toBe(['50']);
});


it('does not repeat the owner column change after it succeeded', function () {
	$state = owner_width_setup('50');

	expect(syslog_ensure_table_structures())->toBeTrue()
		->and(array_filter($state->alters, static fn($sql) => str_starts_with($sql, 'ALTER TABLE') && str_contains($sql, '`user` varchar(50)')))->toBe([])
		->and($state->status)->toBe([]);
});


it('does not record the owner column change when a table cannot be altered', function () {
	$state = owner_width_setup(null, static fn($sql) => !str_contains($sql, 'syslog_reports'));

	expect(syslog_ensure_table_structures())->toBeFalse()
		->and($state->status)->toBe([]);
});


it('does not record the owner column change when the marker cannot be stored', function () {
	owner_width_setup(null);
	test_override('syslog_db_execute_prepared', static fn() => false);

	expect(syslog_ensure_table_structures())->toBeFalse();
});


it('creates the owner columns at the Cacti username width', function () {
	$schema = plugin_test_read_source('includes/schema.php');

	expect($schema)->not->toContain('`user` varchar(32)')
		->and($schema)->not->toContain("'name' => 'user', 'type' => 'varchar(32)'");
});
