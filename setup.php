<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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

function plugin_syslog_install() {
	global $config, $syslog_upgrade;
	static $bg_inprocess = false;

	syslog_determine_config();

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	} else {
		raise_message('syslog_info', __('Please rename either your config.php.dist or config_local.php.dist files in the syslog directory, and change setup your database before installing.', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location:' . $config['url_path'] . 'plugins.php?header=false');
		exit;
	}

	syslog_connect();

	$syslog_exists = sizeof(syslog_db_fetch_row('SHOW TABLES FROM `' . $syslogdb_default . "` LIKE 'syslog'"));

	/* ================= input validation ================= */
	get_filter_request_var('days');
	/* ==================================================== */

	api_plugin_register_hook('syslog', 'config_arrays',         'syslog_config_arrays',        'setup.php');
	api_plugin_register_hook('syslog', 'draw_navigation_text',  'syslog_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('syslog', 'config_settings',       'syslog_config_settings',      'setup.php');
	api_plugin_register_hook('syslog', 'top_header_tabs',       'syslog_show_tab',             'setup.php');
	api_plugin_register_hook('syslog', 'top_graph_header_tabs', 'syslog_show_tab',             'setup.php');
	api_plugin_register_hook('syslog', 'top_graph_refresh',     'syslog_top_graph_refresh',    'setup.php');
	api_plugin_register_hook('syslog', 'poller_bottom',         'syslog_poller_bottom',        'setup.php');
	api_plugin_register_hook('syslog', 'graph_buttons',         'syslog_graph_buttons',        'setup.php');
	api_plugin_register_hook('syslog', 'config_insert',         'syslog_config_insert',        'setup.php');
	api_plugin_register_hook('syslog', 'utilities_list',        'syslog_utilities_list',       'setup.php');
	api_plugin_register_hook('syslog', 'utilities_action',      'syslog_utilities_action',     'setup.php');

	/* hook for table replication */
	api_plugin_register_hook('syslog', 'replicate_out',         'syslog_replicate_out',        'setup.php');

	api_plugin_register_realm('syslog', 'syslog.php', 'Plugin -> Syslog User', 1);
	api_plugin_register_realm('syslog', 'syslog_alerts.php,syslog_removal.php,syslog_reports.php', 'Plugin -> Syslog Administration', 1);

	if (isset_request_var('install')) {
		if (!$bg_inprocess) {
			syslog_execute_update($syslog_exists, $_REQUEST);
			$bg_inprocess = true;

			return true;
		}
	} elseif (isset($syslog_install_options) && cacti_sizeof($syslog_install_options)) {
		/* hack for syslog so IBM Spectrum LSF RTM can install syslog without user interaction with preset defaults */
		if (!$bg_inprocess) {
			syslog_execute_update($syslog_exists, $syslog_install_options);
			$bg_inprocess = true;
		}
	} elseif (isset_request_var('cancel')) {
		header('Location:' . $config['url_path'] . 'plugins.php?mode=uninstall&id=syslog&uninstall&uninstall_method=all');
		exit;
	} else {
		syslog_install_advisor($syslog_exists);
		exit;
	}
}

function syslog_execute_update($syslog_exists, $options) {
	global $config;

	if (isset($options['cancel'])) {
		header('Location:' . $config['url_path'] . 'plugins.php?mode=uninstall&id=syslog&uninstall&uninstall_method=all');
		exit;
	} elseif (isset($options['return'])) {
		db_execute('DELETE FROM plugin_config WHERE directory="syslog"');
		db_execute('DELETE FROM plugin_realms WHERE plugin="syslog"');
		db_execute('DELETE FROM plugin_db_changes WHERE plugin="syslog"');
		db_execute('DELETE FROM plugin_hooks WHERE name="syslog"');
	} elseif (isset($options['upgrade_type'])) {
		if ($options['upgrade_type'] == 'truncate') {
			syslog_setup_table_new($options);
		}
	} else {
		syslog_setup_table_new($options);
	}

	db_execute_prepared('REPLACE INTO settings
		SET name="syslog_retention", value = ?',
		array($options['days']));
}

function plugin_syslog_uninstall() {
	global $config, $syslogdb_default;

	syslog_determine_config();
	syslog_connect();

	if (isset_request_var('cancel') || isset_request_var('return')) {
		header('Location:' . $config['url_path'] . 'plugins.php?header=false');
		exit;
	} elseif (isset_request_var('uninstall_method')) {
		if (get_nfilter_request_var('uninstall_method') == 'all') {
			/* do the big tables first */
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_removed`');

			/* do the settings tables last */
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_incoming`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_alert`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_remove`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_reports`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_facilities`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_statistics`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_host_facilities`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_priorities`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_logs`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_hosts`');
		} else {
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog`');
			syslog_db_execute('DROP TABLE IF EXISTS `' . $syslogdb_default . '`.`syslog_removed`');
		}
	} else {
		syslog_uninstall_advisor();
		exit;
	}
}

function plugin_syslog_check_config() {
	/* Here we will check to ensure everything is configured */
	syslog_check_upgrade();
	return true;
}

function plugin_syslog_upgrade() {
	/* Here we will upgrade to the newest version */
	syslog_check_upgrade();
	return false;
}

function syslog_connect() {
	global $config, $syslog_cnn, $syslogdb_default, $local_db_cnn_id, $remote_db_cnn_id;

	syslog_determine_config();

	// Handle remote syslog processing
	include(SYSLOG_CONFIG);
	include_once(dirname(__FILE__) . '/functions.php');
	include_once(dirname(__FILE__) . '/database.php');

	$connect_remote = false;
	$connected      = true;

	/* Connect to the Syslog Database */
	if (empty($syslog_cnn)) {
		if ($config['poller_id'] == 1) {
			if ($use_cacti_db == true) {
				$syslog_cnn = $local_db_cnn_id;
				$connected = true;
			} else {
				$connect_remote = true;
			}
		} elseif (isset($config['syslog_remote_db'])) {
			if ($use_cacti_db == true) {
				$syslog_cnn = $local_db_cnn_id;
				$connected = true;
			} else {
				$connect_remote = true;
			}
		} else {
			if ($use_cacti_db == true) {
				$syslog_cnn = $remote_db_cnn_id;
				$connected = true;
			} else {
				$connect_remote = true;
			}
		}

		if ($connect_remote) {
			if (!isset($syslogdb_port)) {
				$syslogdb_port = '3306';
			}

			if (!isset($syslogdb_retries)) {
				$syslogdb_retries = '5';
			}

			if (!isset($syslogdb_ssl)) {
			    $syslogdb_ssl = false;
			}

			if (!isset($syslogdb_ssl_key)) {
			    $syslogdb_ssl_key = '';
			}

			if (!isset($syslogdb_ssl_cert)) {
			    $syslogdb_ssl_cert = '';
			}

			if (!isset($syslogdb_ssl_ca)) {
			    $syslogdb_ssl_ca = '';
			}

			$syslog_cnn = syslog_db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type, $syslogdb_port, $syslogdb_retries, $syslogdb_ssl, $syslogdb_ssl_key, $syslogdb_ssl_cert, $syslogdb_ssl_ca);

			if ($syslog_cnn == false) {
				print "FATAL Can not connect\n";
				$connected = false;
			}
		}

		if ($connected && !syslog_db_table_exists('syslog') && api_plugin_is_enabled('syslog')) {
			cacti_log('Setting Up Database Tables Since they do not exist', false, 'SYSLOG');

			if (!isset($syslog_install_options)) {
				$syslog_install_options = array();
			}

			syslog_setup_table_new($syslog_install_options);
		}
	}

	return $connected;
}

function syslog_check_upgrade() {
	global $config, $syslogdb_default, $syslog_levels, $syslog_upgrade;

	syslog_determine_config();
	syslog_connect();

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'syslog.php', 'syslog_removal.php', 'syslog_alerts.php', 'syslog_reports.php');
	if (substr($_SERVER['SCRIPT_FILENAME'], -18) != 'syslog_process.php' &&  !in_array(get_current_page(), $files)) {
		return;
	}

	$present = syslog_db_fetch_row('SHOW TABLES FROM `' . $syslogdb_default . "` LIKE 'syslog'");
	$old_pia = false;
	if (cacti_sizeof($present)) {
		$old_table = syslog_db_fetch_row('SHOW COLUMNS FROM `' . $syslogdb_default . "`.`syslog` LIKE 'time'");
		if (cacti_sizeof($old_table)) {
			$old_pia = true;
		}
	}

	/* don't let this script timeout */
	ini_set('max_execution_time', 0);

	$version = plugin_syslog_version();
	$current = $version['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='syslog'");

	if ($current != $old) {
		if ($old_pia || $old < 2) {
			print __('Syslog 2.0 Requires an Entire Reinstall.  Please uninstall Syslog and Remove all Data before Installing.  Migration is possible, but you must plan this in advance.  No automatic migration is supported.', 'syslog') . "\n";
			exit;
		} elseif ($old == 2) {
			syslog_db_execute('ALTER TABLE syslog_statistics
				ADD COLUMN id BIGINT UNSIGNED auto_increment FIRST,
				DROP PRIMARY KEY,
				ADD PRIMARY KEY(id),
				ADD UNIQUE INDEX (`host_id`,`facility_id`,`priority_id`,`program_id`,`insert_time`)');
		}

		api_plugin_register_hook('syslog', 'replicate_out', 'syslog_replicate_out', 'setup.php', 1);

		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='syslog'");
		db_execute("UPDATE plugin_config SET
			version='" . $version['version'] . "',
			name='" . $version['longname'] . "',
			author='" . $version['author'] . "',
			webpage='" . $version['homepage'] . "'
			WHERE directory='" . $version['name'] . "' ");

		if (!syslog_db_column_exists('syslog_alert', 'hash')) {
			syslog_db_add_column('syslog_alert', array(
				'name'     => 'hash',
				'type'     => 'varchar(32)',
				'NULL'     => false,
				'default'  => '',
				'after'    => 'id')
			);

			syslog_db_add_column('syslog_remove', array(
				'name'     => 'hash',
				'type'     => 'varchar(32)',
				'NULL'     => false,
				'default'  => '',
				'after'    => 'id')
			);

			syslog_db_add_column('syslog_reports', array(
				'name'     => 'hash',
				'type'     => 'varchar(32)',
				'NULL'     => false,
				'default'  => '',
				'after'    => 'id')
			);
		}

		if (syslog_db_column_exists('syslog_incoming', 'date')) {
			syslog_db_execute("ALTER TABLE syslog_incoming
				DROP COLUMN date,
				CHANGE COLUMN `time` logtime timestamp default '0000-00-00';");
		}

		$alerts = syslog_db_fetch_assoc('SELECT *
			FROM syslog_alert
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($alerts)) {
			foreach($alerts as $a) {
				$hash = get_hash_syslog($a['id'], 'syslog_alert');
				syslog_db_execute_prepared('UPDATE syslog_alert
					SET hash = ?
					WHERE id = ?',
					array($hash, $a['id']));
			}
		}

		$removes = syslog_db_fetch_assoc('SELECT *
			FROM syslog_remove
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($removes)) {
			foreach($removes as $r) {
				$hash = get_hash_syslog($r['id'], 'syslog_remove');
				syslog_db_execute_prepared('UPDATE syslog_remove
					SET hash = ?
					WHERE id = ?',
					array($hash, $r['id']));
			}
		}

		$reports = syslog_db_fetch_assoc('SELECT *
			FROM syslog_reports
			WHERE hash IS NULL OR hash = ""');

		if (cacti_sizeof($reports)) {
			foreach($reports as $r) {
				$hash = get_hash_syslog($r['id'], 'syslog_reports');
				syslog_db_execute_prepared('UPDATE syslog_reports
					SET hash = ?
					WHERE id = ?',
					array($hash, $r['id']));
			}
		}

		if (!syslog_db_column_exists('syslog_alert', 'level')) {
			syslog_db_add_column('syslog_alert', array(
				'name'     => 'level',
				'type'     => 'int(10)',
				'unsigned' => true,
				'NULL'     => false,
				'default'  => '0',
				'after'    => 'method')
			);
		}

		if (!syslog_db_column_exists('syslog_alert', 'notify')) {
			syslog_db_add_column('syslog_alert', array(
				'name'     => 'notify',
				'type'     => 'int(10)',
				'unsigned' => true,
				'NULL'     => false,
				'default'  => '0',
				'after'    => 'email')
			);
		}

		if (!syslog_db_column_exists('syslog_alert', 'body')) {
			syslog_db_add_column('syslog_alert', array(
				'name'     => 'body',
				'type'     => 'varchar(8192)',
				'NULL'     => false,
				'default'  => '',
				'after'    => 'message')
			);
		}

		if (!syslog_db_column_exists('syslog_reports', 'notify')) {
			syslog_db_add_column('syslog_reports', array(
				'name'     => 'notify',
				'type'     => 'int(10)',
				'unsigned' => true,
				'NULL'     => false,
				'default'  => '0',
				'after'    => 'email')
			);
		}

		syslog_db_execute('ALTER TABLE syslog_reports MODIFY column body VARCHAR(8192) NOT NULL default ""');
	}
}

function syslog_create_partitioned_syslog_table($engine = 'InnoDB', $days = 30) {
	global $config, $syslogdb_default, $syslog_levels;

	syslog_determine_config();
	syslog_connect();

	$sql = "CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog` (
		facility_id int(10) unsigned default NULL,
		priority_id int(10) unsigned default NULL,
		program_id int(10) unsigned default NULL,
		host_id int(10) unsigned default NULL,
		logtime DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		message varchar(1024) NOT NULL default '',
		seq bigint unsigned NOT NULL auto_increment,
		PRIMARY KEY(seq, logtime),
		INDEX `seq` (`seq`),
		INDEX logtime (logtime),
		INDEX program_id (program_id),
		INDEX host_id (host_id),
		INDEX priority_id (priority_id),
		INDEX facility_id (facility_id))
		ENGINE=$engine
		PARTITION BY RANGE (TO_DAYS(logtime))\n";

	$now = time();

	$parts = '';
	for($i = $days; $i >= -1; $i--) {
		$timestamp = $now - ($i * 86400);
		$date     = date('Y-m-d', $timestamp);
		$format   = date('Ymd', $timestamp - 86400);
		$parts .= ($parts != '' ? ",\n":"(") . " PARTITION d" . $format . " VALUES LESS THAN (TO_DAYS('" . $date . "'))";
	}
	$parts .= ",\nPARTITION dMaxValue VALUES LESS THAN MAXVALUE);";

	syslog_db_execute($sql . $parts);
}

function syslog_setup_table_new($options) {
	global $config, $settings, $syslogdb_default, $syslog_levels;

	syslog_determine_config();
	syslog_connect();

	$tables  = array();

	$syslog_levels = array(
		0 => 'emerg',
		1 => 'crit',
		2 => 'alert',
		3 => 'err',
		4 => 'warn',
		5 => 'notice',
		6 => 'info',
		7 => 'debug',
		8 => 'other'
	);

	// Set default if they are not set.
	if (!cacti_sizeof($options)) {
		$options['upgrade_type'] = read_config_option('syslog_install_upgrade_type');
		$options['engine']       = read_config_option('syslog_install_engine');
		$options['db_type']      = read_config_option('syslog_install_db_type');
		$options['days']         = read_config_option('syslog_install_days');

		if (empty($options['upgrade_type'])) {
			$options['upgrade_type'] = 'upgrade';
		}

		if (empty($options['engine'])) {
			$options['engine'] = 'InnoDB';
		}

		if (empty($options['db_type'])) {
			$options['db_type'] = 'part';
		}

		if (empty($options['days'])) {
			$options['days'] = 30;
		}
	}

	/* validate some simple information */
	$truncate     = isset($options['upgrade_type']) && $options['upgrade_type'] == 'truncate' ? true:false;
	$engine       = isset($options['engine']) && $options['engine'] == 'innodb' ? 'InnoDB':'MyISAM';
	$partitioned  = isset($options['db_type']) && $options['db_type'] == 'part' ? true:false;
	$syslogexists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE 'syslog'"));

	/* set table construction settings for the remote pollers */
	set_config_option('syslog_install_upgrade_type', empty($options['upgrade_type'])?'':$options['upgrade_type'], true);
	set_config_option('syslog_install_engine',       empty($options['engine'])      ?'':$options['engine'], true);
	set_config_option('syslog_install_db_type',      empty($options['db_type'])     ?'':$options['db_type'], true);
	set_config_option('syslog_install_days',         empty($options['days'])        ?'':$options['days'], true);

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog`");
	}

	if (!$partitioned) {
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog` (
			facility_id int(10) unsigned default NULL,
			priority_id int(10) unsigned default NULL,
			program_id int(10) unsigned default NULL,
			host_id int(10) unsigned default NULL,
			logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
			message varchar(2048) NOT NULL default '',
			seq bigint unsigned NOT NULL auto_increment,
			PRIMARY KEY (seq, logtime),
			INDEX `seq` (`seq`),
			INDEX logtime (logtime),
			INDEX program_id (program_id),
			INDEX host_id (host_id),
			INDEX priority_id (priority_id),
			INDEX facility_id (facility_id))
			ENGINE=$engine;");
	} else {
		syslog_create_partitioned_syslog_table($engine, $options['days']);
	}

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_alert`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_alert` (
		`id` int(10) NOT NULL auto_increment,
		`hash` varchar(32) NOT NULL default '',
		`name` varchar(255) NOT NULL default '',
		`severity` int(10) UNSIGNED NOT NULL default '0',
		`method` int(10) unsigned NOT NULL default '0',
		`level` int(10) unsigned NOT NULL default '0',
		`num` int(10) unsigned NOT NULL default '1',
		`type` varchar(16) NOT NULL default '',
		`enabled` CHAR(2) default 'on',
		`repeat_alert` int(10) unsigned NOT NULL default '0',
		`open_ticket` CHAR(2) default '',
		`message` VARCHAR(2048) NOT NULL default '',
		`body` VARCHAR(8192) NOT NULL default '',
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		`email` varchar(255) default NULL,
		`notify` int(10) unsigned NOT NULL default '0',
		`command` varchar(255) default NULL,
		`notes` varchar(255) default NULL,
		PRIMARY KEY (id))
		ENGINE=$engine;");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_incoming`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_incoming` (
		facility_id int(10) unsigned default NULL,
		priority_id int(10) unsigned default NULL,
		program varchar(40) default NULL,
		logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
		host varchar(64) default NULL,
		message varchar(2048) NOT NULL DEFAULT '',
		seq bigint unsigned NOT NULL auto_increment,
		`status` tinyint(4) NOT NULL default '0',
		PRIMARY KEY (seq),
		INDEX program (program),
		INDEX `status` (`status`))
		ENGINE=$engine;");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_remove`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_remove` (
		id int(10) NOT NULL auto_increment,
		`hash` varchar(32) NOT NULL default '',
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		method CHAR(5) DEFAULT 'del',
		message VARCHAR(2048) NOT NULL default '',
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		notes varchar(255) default NULL,
		PRIMARY KEY (id))
		ENGINE=$engine;");

	$present = syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE 'syslog_reports'");

	if (cacti_sizeof($present)) {
		$newreport = sizeof(syslog_db_fetch_row("SHOW COLUMNS FROM `" . $syslogdb_default . "`.`syslog_reports` LIKE 'body'"));
	} else {
		$newreport = true;
	}

	if ($truncate || !$newreport) {
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_reports`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_reports` (
		id int(10) NOT NULL auto_increment,
		`hash` varchar(32) NOT NULL default '',
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		timespan int(16) NOT NULL default '0',
		timepart char(5) NOT NULL default '00:00',
		lastsent int(16) NOT NULL default '0',
		body varchar(8192) NOT NULL default '0',
		message varchar(2048) default NULL,
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		email varchar(255) default NULL,
		notify int(10) unsigned NOT NULL default '0',
		notes varchar(255) default NULL,
		PRIMARY KEY (id))
		ENGINE=$engine;");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_hosts`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_programs` (
		`program_id` int(10) unsigned NOT NULL auto_increment,
		`program` VARCHAR(40) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`program`),
		INDEX host_id (`program_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=$engine
		COMMENT='Contains all programs currently in the syslog table'");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_hosts` (
		`host_id` int(10) unsigned NOT NULL auto_increment,
		`host` VARCHAR(64) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`host`),
		INDEX host_id (`host_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=$engine
		COMMENT='Contains all hosts currently in the syslog table'");

	syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_facilities`");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_facilities` (
		`facility_id` int(10) unsigned NOT NULL,
		`facility` varchar(10) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY  (`facility_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=$engine;");

	syslog_db_execute("INSERT INTO `" .  $syslogdb_default . "`.`syslog_facilities` (facility_id, facility) VALUES
		(0,'kern'), (1,'user'), (2,'mail'), (3,'daemon'), (4,'auth'), (5,'syslog'), (6,'lpd'), (7,'news'),
		(8,'uucp'), (9,'crond'), (10,'authpriv'), (11,'ftpd'), (12,'ntpd'), (13,'logaudit'), (14,'logalert'),
		(15,'crond'), (16,'local0'), (17,'local1'), (18,'local2'), (19,'local3'), (20,'local4'), (21,'local5'),
		(22,'local6'), (23,'local7');");

	syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_priorities`");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_priorities` (
		`priority_id` int(10) unsigned NOT NULL,
		`priority` varchar(10) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`priority_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=$engine;");

	syslog_db_execute("INSERT INTO `" .  $syslogdb_default . "`.`syslog_priorities` (priority_id, priority) VALUES
		(0,'emerg'), (1,'alert'), (2,'crit'), (3,'err'), (4,'warning'), (5,'notice'), (6,'info'), (7,'debug'), (8,'other');");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_host_facilities` (
		`host_id` int(10) unsigned NOT NULL,
		`facility_id` int(10) unsigned NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY  (`host_id`,`facility_id`))
		ENGINE=$engine;");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_removed`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_removed` LIKE `" . $syslogdb_default . "`.`syslog`");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_logs` (
		alert_id int(10) unsigned not null default '0',
		logseq bigint unsigned NOT NULL,
		logtime TIMESTAMP NOT NULL default '0000-00-00 00:00:00',
		logmsg varchar(1024) default NULL,
		host varchar(64) default NULL,
		facility_id int(10) unsigned default NULL,
		priority_id int(10) unsigned default NULL,
		program_id int(10) unsigned default NULL,
		count integer unsigned NOT NULL default '0',
		html blob default NULL,
		seq bigint unsigned NOT NULL auto_increment,
		PRIMARY KEY (seq),
		INDEX `logseq` (`logseq`),
		INDEX `program_id` (`program_id`),
		INDEX `alert_id` (`alert_id`),
		INDEX `host` (`host`),
		INDEX `logtime` (`logtime`),
		INDEX `priority_id` (`priority_id`),
		INDEX `facility_id` (`facility_id`))
		ENGINE=$engine;");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_statistics` (
		`id` bigint UNSIGNED auto_increment,
		`host_id` int(10) UNSIGNED NOT NULL,
		`facility_id` int(10) UNSIGNED NOT NULL,
		`priority_id` int(10) UNSIGNED NOT NULL,
		`program_id` int(10) unsigned default NULL,
		`insert_time` TIMESTAMP NOT NULL,
		`records` int(10) UNSIGNED NOT NULL,
		PRIMARY KEY (`id`),
		UNIQUE KEY `unique_pk` (`host_id`, `facility_id`, `priority_id`, `program_id`, `insert_time`),
		INDEX `host_id`(`host_id`),
		INDEX `facility_id`(`facility_id`),
		INDEX `priority_id`(`priority_id`),
		INDEX `program_id` (`program_id`),
		INDEX `insert_time`(`insert_time`))
		ENGINE = $engine
		COMMENT = 'Maintains High Level Statistics';");

	if (!isset($settings['syslog'])) {
		syslog_config_settings();
	}

	foreach($settings['syslog'] AS $name => $values) {
		if (isset($values['default'])) {
			set_config_option($name, $values['default']);
		}
	}
}

function syslog_replicate_out($data) {
	syslog_determine_config();
	syslog_connect();

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		$remote_poller_id = $data['remote_poller_id'];
		$rcnn_id          = $data['rcnn_id'];
		$class            = $data['class'];

		cacti_log('INFO: Replacting for the Syslog Plugin', false, 'REPLICATE');

		if ($class == 'all') {
			$tdata = syslog_db_fetch_assoc('SELECT * FROM syslog_alert');
			replicate_out_table($rcnn_id, $tdata, 'syslog_alert', $remote_poller_id);
			$tdata = syslog_db_fetch_assoc('SELECT * FROM syslog_remove');
			replicate_out_table($rcnn_id, $tdata, 'syslog_remove', $remote_poller_id);
			$tdata = syslog_db_fetch_assoc('SELECT * FROM syslog_reports');
			replicate_out_table($rcnn_id, $tdata, 'syslog_reports', $remote_poller_id);
		}
	}

	return $data;
}

function syslog_replicate_in() {
	syslog_determine_config();
	syslog_connect();

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		$data = db_fetch_assoc('SELECT * FROM syslog_alert');
		syslog_replace_data('syslog_alert', $data);

		$data = db_fetch_assoc('SELECT * FROM syslog_remove');
		syslog_replace_data('syslog_remove', $data);

		$data = db_fetch_assoc('SELECT * FROM syslog_reports');
		syslog_replace_data('syslog_reports', $data);
	}
}

function syslog_replace_data($table, &$data) {
	if (cacti_sizeof($data)) {
		$sqlData  = array();
		$sqlQuery = array();
		$columns  = array_keys($data[0]);

		$create = db_fetch_row('SHOW CREATE TABLE ' . $table);
		if (isset($create["CREATE TABLE `$table`"]) || isset($create['Create Table'])) {
			if (isset($create["CREATE TABLE `$table`"])) {
				$create_sql = $create["CREATE TABLE `$table`"];
			} else {
				$create_sql = $create['Create Table'];
			}
		}

		if (!syslog_db_table_exists($table)) {
			syslog_db_execute($create);
			syslog_db_execute("TRUNCATE TABLE $table");
		}

		// Make the prefix
		$sql_prefix = "INSERT INTO $table (`" . implode('`,`', $columns) . '`) VALUES ';

		// Make the suffix
		$sql_suffix = ' ON DUPLICATE KEY UPDATE ';
		foreach($columns as $c) {
			$sql_suffix .= " $c = VALUES($c),";
		}
		$sql_suffix = trim($sql_suffix, ',');

		// Construct the prepared statement
		foreach($data as $row) {
			$sqlQuery[] = '(' . trim(str_repeat('?, ', cacti_sizeof($columns)), ', ') . ')';

			foreach($row as $col) {
				$sqlData[] = $col;
			}
		}

		$sql = implode(', ', $sqlQuery);

		syslog_db_execute_prepared($sql_prefix . $sql . $sql_suffix, $sqlData);
	}
}

function plugin_syslog_version() {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/syslog/INFO', true);
	return $info['info'];
}

function syslog_check_dependencies() {
	return true;
}

function syslog_poller_bottom() {
	global $config;

	if (syslog_config_safe()) {
		$command_string = read_config_option('path_php_binary');
		$extra_args = ' -q ' . $config['base_path'] . '/plugins/syslog/syslog_process.php';
		exec_background($command_string, $extra_args);
	} else {
		cacti_log('WARNING: You have installed the Syslog plugin, but you have not properly set a config.php or config_local.php', false, 'POLLER');
	}
}

function syslog_install_advisor($syslog_exists) {
	global $config, $syslog_retentions;

	top_header();

	syslog_config_arrays();

	$fields_syslog_update = array(
		'upgrade_type' => array(
			'method' => 'drop_array',
			'friendly_name' => __('What upgrade/install type do you wish to use', 'syslog'),
			'description' => __('When you have very large tables, performing a Truncate will be much quicker.  If you are concerned about archive data, you can choose either Inline, which will freeze your browser for the period of this upgrade, or background, which will create a background process to bring your old syslog data from a backup table to the new syslog format.  Again this process can take several hours.', 'syslog'),
			'value' => 'truncate',
			'array' => array(
				'truncate' => __('Truncate Syslog Table', 'syslog'),
				'inline' => __('Inline Upgrade', 'syslog'),
				'background' => __('Background Upgrade', 'syslog')
			)
		),
		'engine' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Database Storage Engine', 'syslog'),
			'description' => __('You have the option to make this a partitioned table by days.', 'syslog'),
			'value' => 'innodb',
			'array' => array(
				'myisam' => __('MyISAM Storage', 'syslog'),
				'innodb' => __('InnoDB Storage', 'syslog')
			)
		),
		'db_type' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Database Architecture', 'syslog'),
			'description' => __('You have the option to make this a partitioned table by days.  You can create multiple partitions per day.', 'syslog'),
			'value' => 'part',
			'array' => array(
				'trad' => __('Traditional Table', 'syslog'),
				'part' => __('Partitioned Table', 'syslog')
			)
		),
		'days' => array(
			'method' => 'drop_array',
			'friendly_name' => __('Retention Policy', 'syslog'),
			'description' => __('Choose how many days of Syslog values you wish to maintain in the database.', 'syslog'),
			'value' => '30',
			'array' => $syslog_retentions
		),
		'mode' => array(
			'method' => 'hidden',
			'value' => 'install'
		),
		'install' => array(
			'method' => 'hidden',
			'value' => 'true'
		),
		'id' => array(
			'method' => 'hidden',
			'value' => 'syslog'
		)
	);

	$fields_syslog_update['dayparts'] = array(
		'method' => 'drop_array',
		'friendly_name' => __('Partitions per Day', 'syslog'),
		'description' => __('Select the number of partitions per day that you wish to create.', 'syslog'),
		'value' => '1',
		'array' => array(
			'1'  => __('%d Per Day', 1, 'syslog'),
			'2'  => __('%d Per Day', 2, 'syslog'),
			'4'  => __('%d Per Day', 4, 'syslog'),
			'6'  => __('%d Per Day', 6, 'syslog'),
			'12' => __('%d Per Day', 12, 'syslog')
		)
	);

	if ($syslog_exists) {
		$type = __('Upgrade', 'syslog');
	} else {
		$type = __('Install', 'syslog');
	}

	print "<table align='center' width='80%'><tr><td>\n";
	html_start_box(__('Syslog %s Advisor', $type, 'syslog') . '<', '100%', '', '3', 'center', '');
	print "<tr><td>\n";

	if ($syslog_exists) {
		print "<h2 style='color:red;'>" . __('WARNING: Syslog Upgrade is Time Consuming!!!', 'syslog') . "</h2>\n";
		print "<p>" . __('The upgrade of the \'main\' syslog table can be a very time consuming process.  As such, it is recommended that you either reduce the size of your syslog table prior to upgrading, or choose the background option</p> <p>If you choose the background option, your legacy syslog table will be renamed, and a new syslog table will be created.  Then, an upgrade process will be launched in the background.  Again, this background process can quite a bit of time to complete.  However, your data will be preserved</p> <p>Regardless of your choice, all existing removal and alert rules will be maintained during the upgrade process.</p> <p>Press <b>\'Upgrade\'</b> to proceed with the upgrade, or <b>\'Cancel\'</b> to return to the Plugins menu.', 'syslog') . "</p></td></tr>";
	} else {
		unset($fields_syslog_update['upgrade_type']);
		print "<p>" . __('You have several options to choose from when installing Syslog.  The first is the Database Architecture.  You should elect to utilize Table Partitioning to prevent the size of the tables from becoming excessive thus slowing queries.', 'syslog') . '</p><p>' . __('You can also set the MySQL storage engine.  If you have not tuned you system for InnoDB storage properties, it is strongly recommended that you utilize the MyISAM storage engine.', 'syslog') . '</p><p>' . __('You can also select the retention duration.  Please keep in mind that if you have several hosts logging to syslog, this table can become quite large.  So, if not using partitioning, you might want to keep the size smaller.', 'syslog') . "</p></td></tr>";
	}
	html_end_box();
	print "<form action='plugins.php' method='get'>\n";
	html_start_box(__('Syslog %s Settings', $type, 'syslog'), '100%', '', '3', 'center', '');
	draw_edit_form(array(
		'config' => array(),
		'fields' => inject_form_variables($fields_syslog_update, array()))
		);
	html_end_box();
	syslog_confirm_button('install', 'plugins.php', $syslog_exists);
	print "</td></tr></table>\n";

	bottom_footer();
	exit;
}

function syslog_uninstall_advisor() {
	global $config, $syslogdb_default;

	syslog_determine_config();
	include(SYSLOG_CONFIG);
	syslog_connect();

	$syslog_exists = sizeof(syslog_db_fetch_row('SHOW TABLES FROM `' . $syslogdb_default . "` LIKE 'syslog'"));

	top_header();

	$fields_syslog_update = array(
		'uninstall_method' => array(
			'method' => 'drop_array',
			'friendly_name' => __('What uninstall method do you want to use?', 'syslog'),
			'description' => __('When uninstalling syslog, you can remove everything, or only components, just in case you plan on re-installing in the future.', 'syslog'),
			'value' => 'all',
			'array' => array('all' => __('Remove Everything (Logs, Tables, Settings)', 'syslog'), 'syslog' => __('Syslog Data Only', 'syslog')),
		),
		'mode' => array(
			'method' => 'hidden',
			'value' => 'uninstall'
		),
		'uninstall' => array(
			'method' => 'hidden',
			'value' => 'true'
		),
		'id' => array(
			'method' => 'hidden',
			'value' => 'syslog'
		)
	);

	form_start('plugins.php');

	print "<table align='center' width='80%'><tr><td>\n";

	html_start_box(__('Syslog Uninstall Preferences', 'syslog'), '100%', '', '3', 'center', '');

	draw_edit_form(array(
		'config' => array(),
		'fields' => inject_form_variables($fields_syslog_update, array()))
	);

	html_end_box();

	syslog_confirm_button('uninstall', 'plugins.php', $syslog_exists);

	print "</td></tr></table>\n";

	bottom_footer();
	exit;
}

function syslog_confirm_button($action, $cancel_url, $syslog_exists) {
	if ($action == 'install' ) {
		if ($syslog_exists) {
			$value = __('Upgrade', 'syslog');
		} else {
			$value = __('Install', 'syslog');
		}
	} else {
		$value = __('Uninstall', 'syslog');
	}

	?>
	<table align='center' width='100%'>
		<tr>
			<td class='saveRow' align='right'>
				<input id='<?php print ($syslog_exists ? 'return':'cancel')?>' type='button' value='<?php print __('Cancel', 'syslog');?>'>
				<input id='<?php print $action;?>' type='submit' value='<?php print $value;?>'>
				<script type='text/javascript'>
				$(function() {
					$('form').submit(function(event) {
						event.preventDefault();
						strURL = $(this).attr('action');
						strURL += (strURL.indexOf('?') >= 0 ? '&':'?') + 'header=false';
						json = $(this).serializeObject();
						$.post(strURL, json).done(function(data) {
							$('#main').html(data);
							applySkin();
							window.scrollTo(0, 0);
						});
					});

					$('#cancel, #return').click(function() {
						loadPageNoHeader('plugins.php?header=false');
					});
				});
				</script>
			</td>
		</tr>
	</table>
	</form>
	<?php
}

function syslog_config_settings() {
	global $config, $tabs, $formats, $settings, $syslog_retentions, $syslog_alert_retentions, $syslog_refresh;

	include_once($config['base_path'] . '/lib/reports.php');

	if (get_nfilter_request_var('tab') == 'syslog') {
		$formats = reports_get_format_files();
	} elseif (empty($formats)) {
		$formats = array();
	}

	$tabs['syslog'] = __('Syslog', 'syslog');

	$temp = array(
		'syslog_header' => array(
			'friendly_name' => __('General Settings', 'syslog'),
			'method' => 'spacer',
		),
		'syslog_enabled' => array(
			'friendly_name' => __('Syslog Enabled', 'syslog'),
			'description' => __('If this checkbox is set, records will be transferred from the Syslog Incoming table to the main syslog table and Alerts and Reports will be enabled.  Please keep in mind that if the system is disabled log entries will still accumulate into the Syslog Incoming table as this is defined by the rsyslog or syslog-ng process.', 'syslog'),
			'method' => 'checkbox',
			'default' => 'on'
		),
		'syslog_statistics' => array(
			'friendly_name' => __('Enable Statistics Gathering', 'syslog'),
			'description' => __('If this checkbox is set, statistics on where syslog messages are arriving from will be maintained.  This statistical information can be used to render things such as heat maps.', 'syslog'),
			'method' => 'checkbox',
			'default' => ''
		),
		'syslog_domains' => array(
			'friendly_name' => __('Strip Domains', 'syslog'),
			'description' => __('A comma delimited list of domains that you wish to remove from the syslog hostname, Examples would be \'mydomain.com, otherdomain.com\'', 'syslog'),
			'method' => 'textbox',
			'default' => '',
			'size' => 80,
			'max_length' => 255,
		),
		'syslog_validate_hostname' => array(
			'friendly_name' => __('Validate Hostnames', 'syslog'),
			'description' => __('If this checkbox is set, all hostnames are validated.  If the hostname is not valid. All records are assigned to a special host called \'invalidhost\'.  This setting can impact syslog processing time on large systems.  Therefore, use of this setting should only be used when other means are not in place to prevent this from happening.', 'syslog'),
			'method' => 'checkbox',
			'default' => ''
		),
		'syslog_refresh' => array(
			'friendly_name' => __('Refresh Interval', 'syslog'),
			'description' => __('This is the time in seconds before the page refreshes.', 'syslog'),
			'method' => 'drop_array',
			'default' => '300',
			'array' => $syslog_refresh
		),
		'syslog_maxrecords' => array(
			'friendly_name' => __('Max Report Records', 'syslog'),
			'description' => __('For Threshold based Alerts, what is the maximum number that you wish to show in the report.  This is used to limit the size of the html log and Email.', 'syslog'),
			'method' => 'drop_array',
			'default' => '100',
			'array' => array(
				20  => __('%d Records', 20, 'syslog'),
				40  => __('%d Records', 40, 'syslog'),
				60  => __('%d Records', 60, 'syslog'),
				100 => __('%d Records', 100, 'syslog'),
				200 => __('%d Records', 200, 'syslog'),
				400 => __('%d Records', 400, 'syslog')
			)
		),
		'syslog_ticket_command' => array(
			'friendly_name' => __('Command for Opening Tickets', 'syslog'),
			'description' => __('This command will be executed for opening Help Desk Tickets.  The command will be required to parse multiple input parameters as follows: <b>--alert-name</b>, <b>--severity</b>, <b>--hostlist</b>, <b>--message</b>.  The hostlist will be a comma delimited list of hosts impacted by the alert.', 'syslog'),
			'method' => 'textbox',
			'max_length' => 255,
			'size' => 80
		),
		'syslog_html_header' => array(
			'friendly_name' => __('HTML Notification Settings', 'syslog'),
			'method' => 'spacer',
			'collapsible' => 'true'
		),
		'syslog_html' => array(
			'friendly_name' => __('Enable HTML Based Email', 'syslog'),
			'description' => __('If this checkbox is set, all Emails will be sent in HTML format.  Otherwise, Emails will be sent in plain text.', 'syslog'),
			'method' => 'checkbox',
			'default' => 'on'
		),
		'syslog_format_file' => array(
			'friendly_name' => __('Format File to Use', 'syslog'),
			'method' => 'drop_array',
			'default' => 'default.format',
			'description' => __('Choose the custom html wrapper and CSS file to use.  This file contains both html and CSS to wrap around your report.  If it contains more than simply CSS, you need to place a special <REPORT> tag inside of the file.  This format tag will be replaced by the report content.  These files are located in the \'formats\' directory.', 'syslog'),
			'array' => $formats
		),
		'syslog_retention_header' => array(
			'friendly_name' => __('Data Retention Settings', 'syslog'),
			'method' => 'spacer',
			'collapsible' => 'true'
		),
		'syslog_retention' => array(
			'friendly_name' => __('Syslog Retention', 'syslog'),
			'description' => __('This is the number of days to keep events.', 'syslog'),
			'method' => 'drop_array',
			'default' => '30',
			'array' => $syslog_retentions
		),
		'syslog_alert_retention' => array(
			'friendly_name' => __('Syslog Alert Retention', 'syslog'),
			'description' => __('This is the number of days to keep alert logs.', 'syslog'),
			'method' => 'drop_array',
			'default' => '30',
			'array' => $syslog_alert_retentions
		),
		'syslog_remote_header' => array(
			'friendly_name' => __('Remote Message Processing', 'syslog'),
			'method' => 'spacer',
		),
		'syslog_remote_enabled' => array(
			'friendly_name' => __('Enable Remote Data Collector Message Processing', 'syslog'),
			'description' => __('If your Remote Data Collectors have their own Syslog databases and process their messages independently, check this checkbox.  By checking this Checkbox, your Remote Data Collectors will need to maintain their own \'config_local.php\' file in order to inform Syslog to use an independent database for message display and processing.  Please use the template file \'config_local.php.dist\' for this purpose.  WARNING: Syslog tables will be automatically created as soon as this option is enabled.', 'syslog'),
			'method' => 'checkbox',
			'default' => ''
		),
		'syslog_remote_sync_rules' => array(
			'friendly_name' => __('Remote Data Collector Rules Sync', 'syslog'),
			'description' => __('If your Remote Data Collectors have their own Syslog databases and process thrie messages independently, check this checkbox if you wish the Main Cacti databases Alerts, Removal and Report rules to be sent to the Remote Cacti System.', 'syslog'),
			'method' => 'checkbox',
			'default' => ''
		),
	);

	if (isset($settings['syslog'])) {
		$settings['syslog'] = array_merge($settings['syslog'], $temp);
	} else {
		$settings['syslog'] = $temp;
	}
}

function syslog_top_graph_refresh($refresh) {
	return $refresh;
}

function syslog_show_tab() {
	global $config;

	if (!syslog_config_safe()) {
		return;
	}

	if (api_user_realm_auth('syslog.php')) {
		if (substr_count($_SERVER['REQUEST_URI'], 'syslog.php')) {
			print '<a href="' . $config['url_path'] . 'plugins/syslog/syslog.php"><img src="' . $config['url_path'] . 'plugins/syslog/images/tab_syslog_down.gif" alt="' . __('Syslog', 'syslog') . '"></a>';
		} else {
			print '<a href="' . $config['url_path'] . 'plugins/syslog/syslog.php"><img src="' . $config['url_path'] . 'plugins/syslog/images/tab_syslog.gif" alt="' . __('Syslog', 'syslog') . '"></a>';
		}
	}
}

function syslog_determine_config() {
	global $config;

	// Setup the syslog database settings path
	if (!defined('SYSLOG_CONFIG')) {
		if (file_exists(dirname(__FILE__) . '/config_local.php')) {
			define('SYSLOG_CONFIG', dirname(__FILE__) . '/config_local.php');
			$config['syslog_remote_db'] = true;
		} elseif (file_exists(dirname(__FILE__) . '/config.php')) {
			define('SYSLOG_CONFIG', dirname(__FILE__) . '/config.php');
			$config['syslog_remote_db'] = false;
		}
	}
}

function syslog_config_safe() {
	global $config;

	$files = array(
		dirname(__FILE__) . '/config_local.php',
		dirname(__FILE__) . '/config.php'
	);

	foreach($files as $file) {
		if (file_exists($file) && is_readable($file)) {
			return true;
		}
	}

	return false;
}

function syslog_config_arrays () {
	global $syslog_actions, $config, $menu, $message_types, $severities, $messages;
	global $syslog_levels, $syslog_facilities, $syslog_freqs, $syslog_times, $syslog_refresh;
	global $syslog_retentions, $syslog_alert_retentions, $menu_glyphs;

	syslog_determine_config();

	$syslog_actions = array(
		1 => __('Delete', 'syslog'),
		2 => __('Disable', 'syslog'),
		3 => __('Enable', 'syslog'),
		4 => __('Export', 'syslog')
	);

	$syslog_levels = array(
		0 => 'emerg',
		1 => 'crit',
		2 => 'alert',
		3 => 'err',
		4 => 'warn',
		5 => 'notice',
		6 => 'info',
		7 => 'debug',
		8 => 'other'
	);

	$syslog_facilities = array(
		0 => 'kernel',
		1 => 'user',
		2 => 'mail',
		3 => 'daemon',
		4 => 'auth',
		5 => 'syslog',
		6 => 'lpr',
		7 => 'news',
		8 => 'uucp',
		9 => 'cron',
		10 => 'authpriv',
		11 => 'ftp',
		12 => 'ntp',
		13 => 'log audit',
		14 => 'log alert',
		15 => 'cron',
		16 => 'local0',
		17 => 'local1',
		18 => 'local2',
		19 => 'local3',
		20 => 'local4',
		21 => 'local5',
		22 => 'local6',
		23 => 'local7'
	);

	$syslog_retentions = array(
		'0'   => __('Indefinite', 'syslog'),
		'1'   => __('%d Day', 1, 'syslog'),
		'2'   => __('%d Days', 2, 'syslog'),
		'3'   => __('%d Days', 3, 'syslog'),
		'4'   => __('%d Days', 4, 'syslog'),
		'5'   => __('%d Days', 5, 'syslog'),
		'6'   => __('%d Days', 6, 'syslog'),
		'7'   => __('%d Week', 1, 'syslog'),
		'14'  => __('%d Weeks', 2, 'syslog'),
		'30'  => __('%d Month', 1, 'syslog'),
		'60'  => __('%d Months', 2, 'syslog'),
		'90'  => __('%d Months', 3, 'syslog'),
		'120' => __('%d Months', 4, 'syslog'),
		'160' => __('%d Months', 5, 'syslog'),
		'183' => __('%d Months', 6, 'syslog'),
		'365' => __('%d Year', 1, 'syslog')
	);

	$syslog_alert_retentions = array(
		'0'   => __('Indefinite', 'syslog'),
		'1'   => __('%d Day', 1, 'syslog'),
		'2'   => __('%d Days', 2, 'syslog'),
		'3'   => __('%d Days', 3, 'syslog'),
		'4'   => __('%d Days', 4, 'syslog'),
		'5'   => __('%d Days', 5, 'syslog'),
		'6'   => __('%d Days', 6, 'syslog'),
		'7'   => __('%d Week', 1, 'syslog'),
		'14'  => __('%d Weeks', 2, 'syslog'),
		'30'  => __('%d Month', 1, 'syslog'),
		'60'  => __('%d Months', 2, 'syslog'),
		'90'  => __('%d Months', 3, 'syslog'),
		'120' => __('%d Months', 4, 'syslog'),
		'160' => __('%d Months', 5, 'syslog'),
		'183' => __('%d Months', 6, 'syslog'),
		'365' => __('%d Year', 1, 'syslog')
	);

	$syslog_refresh = array(
		9999999 => __('Never', 'syslog'),
		'60'    => __('%d Minute', 1, 'syslog'),
		'120'   => __('%d Minutes', 2, 'syslog'),
		'300'   => __('%d Minutes', 5, 'syslog'),
		'600'   => __('%d Minutes', 10, 'syslog')
	);

	$severities = array(
		'0' => __('Notice', 'syslog'),
		'1' => __('Warning', 'syslog'),
		'2' => __('Critical', 'syslog')
	);

	$message_types = array(
		'messageb' => __('Begins with', 'syslog'),
		'messagec' => __('Contains', 'syslog'),
		'messagee' => __('Ends with', 'syslog'),
		'host'     => __('Hostname is', 'syslog'),
		'program'  => __('Program is', 'syslog'),
		'facility' => __('Facility is', 'syslog'),
		'sql'      => __('SQL Expression', 'syslog')
	);

	$syslog_freqs = array(
		'86400'  => __('Daily', 'syslog'),
		'604800' => __('Weekly', 'syslog')
	);

	for ($i = 0; $i <= 86400; $i+=1800) {
		$minute = $i % 3600;
		if ($minute > 0) {
			$minute = '30';
		} else {
			$minute = '00';
		}

		if ($i > 0) {
			$hour = strrev(substr(strrev('00' . intval($i/3600)),0,2));
		} else {
			$hour = '00';
		}

		$syslog_times[$i] = $hour . ':' . $minute;
	}

	if (syslog_config_safe()) {
		$menu2 = array ();
		foreach ($menu as $temp => $temp2 ) {
			$menu2[$temp] = $temp2;
			if ($temp == __('Import/Export')) {
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_alerts.php']  = __('Alert Rules', 'syslog');
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_removal.php'] = __('Removal Rules', 'syslog');
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_reports.php'] = __('Report Rules', 'syslog');
			}
		}
		$menu = $menu2;

		$menu_glyphs[__('Syslog Settings', 'syslog')] = 'fa fa-life-ring';
	}

	if (function_exists('auth_augment_roles')) {
		auth_augment_roles(__('Normal User'), array('syslog.php'));
		auth_augment_roles(__('System Administration'), array('syslog_alerts.php', 'syslog_removal.php', 'syslog_reports.php'));
	}

	if (isset($_SESSION['syslog_info']) && $_SESSION['syslog_info'] != '') {
		$messages['syslog_info'] = array('message' => $_SESSION['syslog_info'], 'type' => 'info');
	}

	if (isset($_SESSION['syslog_error']) && $_SESSION['syslog_error'] != '') {
		$messages['syslog_error'] = array('message' => $_SESSION['syslog_error'], 'type' => 'error');
	}
}

function syslog_draw_navigation_text ($nav) {
	global $config;

	$nav['syslog.php:']                = array('title' => __('Syslog', 'syslog'), 'mapping' => '', 'url' => $config['url_path'] . 'plugins/syslog/syslog.php', 'level' => '1');
	$nav['syslog_removal.php:']        = array('title' => __('Syslog Removals', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_removal.php', 'level' => '1');
	$nav['syslog_removal.php:edit']    = array('title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_removal.php:', 'url' => 'syslog_removal.php', 'level' => '2');
	$nav['syslog_removal.php:newedit'] = array('title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_removal.php:', 'url' => 'syslog_removal.php', 'level' => '2');
	$nav['syslog_removal.php:actions'] = array('title' => __('(Actions)', 'syslog'), 'mapping' => 'index.php:,syslog_removal.php:', 'url' => 'syslog_removal.php', 'level' => '2');

	$nav['syslog_alerts.php:']         = array('title' => __('Syslog Alerts', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_alerts.php', 'level' => '1');
	$nav['syslog_alerts.php:edit']     = array('title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_alerts.php:', 'url' => 'syslog_alerts.php', 'level' => '2');
	$nav['syslog_alerts.php:newedit']  = array('title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_alerts.php:', 'url' => 'syslog_alerts.php', 'level' => '2');
	$nav['syslog_alerts.php:actions']  = array('title' => __('(Actions)', 'syslog'), 'mapping' => 'index.php:,syslog_alerts.php:', 'url' => 'syslog_alerts.php', 'level' => '2');

	$nav['syslog_reports.php:']        = array('title' => __('Syslog Reports', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_reports.php', 'level' => '1');
	$nav['syslog_reports.php:edit']    = array('title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_reports.php:', 'url' => 'syslog_reports.php', 'level' => '2');
	$nav['syslog_reports.php:actions'] = array('title' => __('(Actions)', 'syslog'), 'mapping' => 'index.php:,syslog_reports.php:', 'url' => 'syslog_reports.php', 'level' => '2');
	$nav['syslog.php:actions']         = array('title' => __('Syslog', 'syslog'), 'mapping' => '', 'url' => $config['url_path'] . 'plugins/syslog/syslog.php', 'level' => '1');

	return $nav;
}

function syslog_config_insert() {
	if (!syslog_config_safe()) {
		return;
	}

	syslog_determine_config();
	include(SYSLOG_CONFIG);
	syslog_connect();

	syslog_check_upgrade();
}

function syslog_graph_buttons($graph_elements = array()) {
	global $config, $timespan, $graph_timeshifts;

	if (!syslog_config_safe()) {
		return;
	}

	syslog_determine_config();
	include(SYSLOG_CONFIG);
	syslog_connect();

	if (get_nfilter_request_var('action') == 'view') {
		return;
	}

	if (get_current_page() == 'graph_view.php') {
		if (isset_request_var('graph_end') && strlen(get_filter_request_var('graph_end'))) {
			$date1 = date('Y-m-d H:i:s', get_filter_request_var('graph_start'));
			$date2 = date('Y-m-d H:i:s', get_filter_request_var('graph_end'));
		} else {
			$date1 = $timespan['current_value_date1'];
			$date2 = $timespan['current_value_date2'];
		}
	} else {
		return;
	}

	if (isset($graph_elements[1]['local_graph_id'])) {
		$host_id = db_fetch_cell_prepared('SELECT host_id
			FROM graph_local
			WHERE id = ?',
			array($graph_elements[1]['local_graph_id']));

		$sql_where   = '';

		if (!empty($host_id)) {
			$host  = db_fetch_row_prepared('SELECT id, description, hostname
				FROM host WHERE id = ?',
				array($host_id));

			if (cacti_sizeof($host)) {
				if (!is_ipaddress($host['description'])) {
					$parts = explode('.', $host['description']);
					$sql_where = 'WHERE host LIKE ' . db_qstr($parts[0] . '.%') . ' OR host = ' . db_qstr($host['description']);
				} else {
					$sql_where = 'WHERE host = ' . db_qstr($host['description']);
				}

				if (!is_ipaddress($host['hostname'])) {
					$parts = explode('.', $host['hostname']);
					$sql_where .= ($sql_where != '' ? ' OR ':'WHERE ') . 'host LIKE ' . db_qstr($parts[0] . '.%') . ' OR host = ' . db_qstr($host['hostname']);
				} else {
					$sql_where .= ($sql_where != '' ? ' OR ':'WHERE ') . 'host = ' . db_qstr($host['hostname']);
				}

				if ($sql_where != '') {
					$host_id = syslog_db_fetch_cell('SELECT host_id FROM syslog_hosts ' . $sql_where);

					if ($host_id) {
						print "<a class='iconLink' href='" . htmlspecialchars($config['url_path'] . 'plugins/syslog/syslog.php?tab=syslog&reset=1&host=' . $host_id . '&date1=' . $date1 . '&date2=' . $date2) . "' title='" . __('Display Syslog in Range', 'syslog') . "'><i class='deviceRecovering fas fa-exclamation-triangle'></i></a><br>";
					}
				}
			}
		}
	}
}

function syslog_utilities_action($action) {
	global $config, $refresh;

	if (!syslog_config_safe()) {
		return;
	}

	if ($action == 'purge_syslog_hosts') {
		$records = 0;

		syslog_db_execute('DELETE FROM syslog_hosts
			WHERE host_id NOT IN (
				SELECT DISTINCT host_id
				FROM syslog
				UNION
				SELECT DISTINCT host_id
				FROM syslog_removed
			)');
		$records += syslog_db_affected_rows();

		syslog_db_execute('DELETE FROM syslog_host_facilities
			WHERE host_id NOT IN (
				SELECT DISTINCT host_id
				FROM syslog
				UNION
				SELECT DISTINCT host_id
				FROM syslog_removed
			)');
		$records += syslog_db_affected_rows();

		syslog_db_execute('DELETE FROM syslog_statistics
			WHERE host_id NOT IN (
				SELECT DISTINCT host_id
				FROM syslog
				UNION
				SELECT DISTINCT host_id
				FROM syslog_removed
			)');
		$records += syslog_db_affected_rows();

		raise_message('syslog_info', __('There were %s Device records removed from the Syslog database', $records, 'syslog'), MESSAGE_LEVEL_INFO);

		header('Location: utilities.php');
		exit;
	}

	return $action;
}

function syslog_utilities_list() {
	global $config;

	if (!syslog_config_safe()) {
		return;
	}

	html_header(array(__('Syslog Utilities', 'syslog')), 2); ?>

	<tr class='even'>
		<td>
			<a class='hyperLink' href='utilities.php?action=purge_syslog_hosts'><?php print __('Purge Syslog Devices', 'syslog');?></a>
		</td>
		<td>
			<?php print __('This menu pick provides a means to remove Devices that are no longer reporting into Cacti\'s syslog server.', 'syslog');?>
		</td>
	</tr>
	<?php
}

