<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 2) . '/includes/functions.php';

it('requires InnoDB when any Remote Data Collector option is enabled', function () {
	$settings = [];
	test_override('read_config_option', function ($name) use (&$settings) {
		return $settings[$name] ?? '';
	});

	foreach (['syslog_remote_enabled', 'syslog_remote_sync_rules', 'syslog_remote_store_records'] as $option) {
		expect(syslog_remote_collector_requires_innodb([$option => 'on']))->toBeTrue();
		expect(syslog_install_storage_engine_is_compatible('Aria', [$option => 'on']))->toBeFalse();
		expect(syslog_install_storage_engine('Aria', [$option => 'on']))->toBe('InnoDB');
	}

	expect(syslog_remote_collector_requires_innodb([
		'syslog_remote_enabled'       => '',
		'syslog_remote_sync_rules'    => '',
		'syslog_remote_store_records' => ''
	]))->toBeFalse();

	$settings['syslog_remote_enabled'] = 'on';
	expect(syslog_install_storage_engine_is_compatible('Aria'))->toBeFalse();
	expect(syslog_install_storage_engine_is_compatible('InnoDB'))->toBeTrue();
	expect(syslog_install_storage_engine_is_compatible('Aria', ['syslog_remote_enabled' => '']))->toBeTrue();
	expect(syslog_install_storage_engine('Aria'))->toBe('InnoDB');
	expect(syslog_install_storage_engine('Aria', ['syslog_remote_enabled' => '']))->toBe('Aria');
});

it('normalizes transactional remote tables to InnoDB before replication runs', function () {
	$GLOBALS['config']          = ['poller_id' => 2];
	$GLOBALS['syslogdb_default'] = 'remote_syslog';
	$calls = [];

	test_override('read_config_option', fn ($name) => $name === 'syslog_remote_enabled' ? 'on' : '');
	test_override('syslog_db_fetch_cell_prepared', fn ($sql, $params) => $params[1] === 'syslog' ? 'Aria' : 'InnoDB');
	test_override('syslog_db_execute', function ($sql) use (&$calls) {
		$calls[] = $sql;

		return true;
	});
	syslog_load_plugin_source('includes/schema.php');

	syslog_ensure_replication_storage_engine();

	expect($calls)->toHaveCount(1)
		->and($calls[0])->toContain('ALTER TABLE `remote_syslog`.`syslog` ENGINE=InnoDB');
});

it('includes the Remote Data Collector options in the install advisor and saves them', function () {
	$installer = plugin_test_read_source('includes/installer.php');
	$setup     = plugin_test_read_function_source('syslog_setup_table_new');
	$update    = plugin_test_read_source('setup.php');

	foreach (['syslog_remote_enabled', 'syslog_remote_sync_rules', 'syslog_remote_store_records'] as $option) {
		expect(strpos($installer, "'" . $option . "' => ["))->not->toBeFalse();
		expect(strpos($update, "set_config_option(\$option"))->not->toBeFalse();
	}

	expect(strpos($setup, 'syslog_install_storage_engine('))->not->toBeFalse();
	$compatibility_check = strpos($update, 'if (!syslog_install_storage_engine_is_compatible(');
	$settings_save       = strpos($update, 'set_config_option($option', $compatibility_check);
	$table_setup         = strpos($update, 'syslog_setup_table_new($options, $syslog_exists', $compatibility_check);

	expect($compatibility_check)->not->toBeFalse();
	expect($settings_save)->toBeGreaterThan($compatibility_check);
	expect($table_setup)->toBeGreaterThan($compatibility_check);
	expect(strpos($installer, "'config' => ['no_form_tag' => true]"))->not->toBeFalse();
	expect(strpos($installer, "option[value=\"aria\"]').detach()"))->not->toBeFalse();
	expect(strpos($installer, "engine.val('innodb')"))->not->toBeFalse();
	expect(strpos($installer, "event.stopImmediatePropagation()"))->not->toBeFalse();
	expect(strpos($installer, "color:#000;font-weight:bold;"))->not->toBeFalse();
	expect(strpos($installer, 'syslog_engine_compatibility_notice'))->not->toBeFalse();

	$notice = strpos($installer, 'syslog_engine_compatibility_notice');
	$settings_box = strpos($installer, "html_start_box(__('Syslog %s Settings'");
	expect($notice)->toBeLessThan($settings_box);
	expect($setup)->toContain('syslog_replication_output`');
	expect($setup)->toContain('syslog_replication_recovery`');
});
