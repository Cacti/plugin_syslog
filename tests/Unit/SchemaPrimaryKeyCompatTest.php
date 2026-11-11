<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * syslog_db_update_table() keeps table creation working on Cacti releases
 * older than 1.2.32, whose db_table_create() fataled on an array primary key.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

function schema_primary_capture(string $cacti_version, bool $table_exists): array {
	$GLOBALS['config']['cacti_version'] = $cacti_version;

	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('includes/schema.php');

	$captured = new stdClass();
	$captured->primary = 'unset';

	test_override('syslog_db_table_exists', static fn() => $table_exists);
	test_override('db_update_table', function ($table, $data) use ($captured) {
		$captured->primary = $data['primary'] ?? null;

		return true;
	});

	$definition = [
		'type'    => 'InnoDB',
		'columns' => [['name' => 'name', 'type' => 'varchar(64)', 'NULL' => false, 'default' => '']],
		'primary' => ['name']
	];

	expect(syslog_db_update_table('syslog_status', $definition, false, true, new stdClass()))->toBeTrue();

	return ['primary' => $captured->primary];
}

it('passes a string primary when creating a table on Cacti older than 1.2.32', function () {
	$result = schema_primary_capture('1.2.30', false);

	expect($result['primary'])->toBe('name');
});

it('keeps an array primary when the table already exists on older Cacti', function () {
	$result = schema_primary_capture('1.2.30', true);

	expect($result['primary'])->toBe(['name']);
});

it('keeps an array primary on Cacti 1.2.32 and newer', function () {
	$result = schema_primary_capture('1.2.32', false);

	expect($result['primary'])->toBe(['name']);
});

it('joins a composite primary with back-ticks on older Cacti during creation', function () {
	$GLOBALS['config']['cacti_version'] = '1.2.31';

	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('includes/schema.php');

	$captured = new stdClass();
	$captured->primary = 'unset';

	test_override('syslog_db_table_exists', static fn() => false);
	test_override('db_update_table', function ($table, $data) use ($captured) {
		$captured->primary = $data['primary'] ?? null;

		return true;
	});

	$definition = [
		'type'    => 'InnoDB',
		'columns' => [
			['name' => 'dashboard_id', 'type' => 'int(10)', 'NULL' => false, 'default' => '0'],
			['name' => 'type', 'type' => 'varchar(5)', 'NULL' => false, 'default' => ''],
			['name' => 'item_id', 'type' => 'int(10)', 'NULL' => false, 'default' => '0']
		],
		'primary' => ['dashboard_id', 'type', 'item_id']
	];

	expect(syslog_db_update_table('syslog_dashboards_perm', $definition, false, true, new stdClass()))->toBeTrue()
		->and($captured->primary)->toBe('dashboard_id`,`type`,`item_id');
});
