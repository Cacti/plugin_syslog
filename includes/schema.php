<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Schema and migration helpers. */

/**
 * Ensure message columns use TEXT storage.
 *
 * This type-aware migration runs from Cacti's configuration hook and alters
 * only columns that do not already use TEXT.
 *
 * @return void
 */
function syslog_ensure_message_text(): void {
	global $syslogdb_default;

	$tables = [
		'syslog'                    => 'TEXT NOT NULL',
		'syslog_removed'            => 'TEXT NOT NULL',
		'syslog_incoming'           => 'TEXT NOT NULL',
		'syslog_replication_output' => 'TEXT NOT NULL',
		'syslog_reports'            => 'TEXT DEFAULT NULL',
	];

	foreach ($tables as $table => $definition) {
		if (!syslog_db_table_exists($table, false)) {
			continue;
		}

		$type = syslog_db_fetch_cell_prepared(
			'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = \'message\'',
			[$syslogdb_default, $table],
			'',
			false
		);

		if (strtolower((string) $type) !== 'text') {
			syslog_db_execute("ALTER TABLE `$syslogdb_default`.`$table` MODIFY COLUMN `message` $definition");
		}
	}
}

/**
 * Add an immutable remote-event identity to locally retained history.
 *
 * A remote collector uses these nullable columns only while the Main
 * Collector is unavailable and "Store records on remote collector" is off.
 * They let recovery remove exactly the temporary local copies after central
 * receipt, without risking removal of otherwise identical log messages.
 *
 * @return void
 */
function syslog_ensure_replication_history_columns(): void {
	global $syslogdb_default;

	if (!syslog_db_table_exists('syslog', false)
		|| syslog_db_column_exists('syslog', 'replication_source_event_id', false)) {
		return;
	}

	syslog_db_add_column('syslog', [
		'name'     => 'replication_source_poller_id',
		'type'     => 'int(10) unsigned',
		'NULL'     => true,
		'after'    => 'seq'
	]);
	syslog_db_add_column('syslog', [
		'name'     => 'replication_source_event_id',
		'type'     => 'bigint unsigned',
		'NULL'     => true,
		'after'    => 'replication_source_poller_id'
	]);
	syslog_db_execute("ALTER TABLE `$syslogdb_default`.`syslog`
		ADD KEY `replication_source` (`replication_source_poller_id`, `replication_source_event_id`)");
}

/** Ensure remote replication transactions use transactional tables. */
function syslog_ensure_replication_storage_engine(): void {
	global $config, $syslogdb_default;

	if (!isset($config['poller_id']) || (int) $config['poller_id'] <= 1 || !syslog_remote_collector_requires_innodb()) {
		return;
	}

	foreach (['syslog_incoming', 'syslog', 'syslog_removed'] as $table) {
		$engine = syslog_db_fetch_cell_prepared(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
			[$syslogdb_default, $table],
			'',
			false
		);

		if (is_string($engine) && strcasecmp($engine, 'InnoDB') !== 0) {
			syslog_db_execute("ALTER TABLE `$syslogdb_default`.`$table` ENGINE=InnoDB");
		}
	}
}

/** Create the device-wide alert handling rules table on all Syslog collectors. */
function syslog_create_device_rule_table(): void {
	global $syslogdb_default;

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_device_rule` (
		`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`host` varchar(64) NOT NULL,
		`enabled` char(2) NOT NULL DEFAULT 'on',
		`mute_mode` varchar(16) NOT NULL DEFAULT 'none',
		`mute_until` int(10) unsigned NOT NULL DEFAULT '0',
		`pass_through_priority` int(10) NOT NULL DEFAULT '-1',
		`allow_maintenance` char(2) NOT NULL DEFAULT '',
		`notes` varchar(255) NOT NULL DEFAULT '',
		`user` varchar(32) NOT NULL DEFAULT '',
		`date` int(10) unsigned NOT NULL DEFAULT '0',
		PRIMARY KEY (`id`),
		UNIQUE KEY `host` (`host`),
		KEY `enabled` (`enabled`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");
}

/** Create or update saved-search storage. */
function syslog_ensure_saved_search_tables(): void {
	global $syslog_cnn;

	db_update_table('syslog_saved_searches', [
		'type' => 'InnoDB',
		'columns' => [
			['name' => 'id', 'type' => 'int(10)', 'NULL' => false, 'auto_increment' => true],
			['name' => 'hash', 'type' => 'varchar(32)', 'NULL' => false, 'default' => ''],
			['name' => 'name', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
			['name' => 'search', 'type' => 'text', 'NULL' => false],
			['name' => 'removal', 'type' => 'int(10)', 'NULL' => false, 'default' => '1'],
			['name' => 'grouping', 'type' => 'int(10)', 'NULL' => false, 'default' => '0'],
			['name' => 'user', 'type' => 'varchar(32)', 'NULL' => false, 'default' => ''],
			['name' => 'is_global', 'type' => 'char(2)', 'NULL' => false, 'default' => ''],
			['name' => 'date', 'type' => 'int(16)', 'NULL' => false, 'default' => '0']
		],
		'primary' => ['id'],
		'keys' => [['name' => 'owner', 'columns' => ['user']]]
	], false, true, $syslog_cnn);
}

/** Create or update share-grant tables. */
function syslog_ensure_share_tables(): void {
	global $syslog_cnn;

	foreach (['syslog_dashboards_perm' => 'dashboard_id', 'syslog_saved_searches_perm' => 'search_id'] as $table => $id) {
		db_update_table($table, [
			'type' => 'InnoDB',
			'columns' => [
				['name' => $id, 'type' => 'int(10)', 'NULL' => false, 'default' => '0'],
				['name' => 'type', 'type' => 'varchar(5)', 'NULL' => false, 'default' => ''],
				['name' => 'item_id', 'type' => 'int(10)', 'NULL' => false, 'default' => '0']
			],
			'primary' => [$id, 'type', 'item_id']
		], false, true, $syslog_cnn);
	}
}

/** Create or update dashboard storage. */
function syslog_ensure_dashboard_tables(): void {
	global $syslog_cnn;

	db_update_table('syslog_dashboards', [
		'type' => 'InnoDB',
		'columns' => [
			['name' => 'id', 'type' => 'int(10)', 'NULL' => false, 'auto_increment' => true],
			['name' => 'hash', 'type' => 'varchar(32)', 'NULL' => false, 'default' => ''],
			['name' => 'name', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
			['name' => 'user', 'type' => 'varchar(32)', 'NULL' => false, 'default' => ''],
			['name' => 'is_global', 'type' => 'char(2)', 'NULL' => false, 'default' => ''],
			['name' => 'date', 'type' => 'int(16)', 'NULL' => false, 'default' => '0'],
			['name' => 'updated', 'type' => 'int(16)', 'NULL' => false, 'default' => '0']
		],
		'primary' => ['id'],
		'keys' => [['name' => 'owner', 'columns' => ['user']]]
	], false, true, $syslog_cnn);

	db_update_table('syslog_dashboard_panels', [
		'type' => 'InnoDB',
		'columns' => [
			['name' => 'id', 'type' => 'int(10)', 'NULL' => false, 'auto_increment' => true],
			['name' => 'dashboard_id', 'type' => 'int(10)', 'NULL' => false, 'default' => '0'],
			['name' => 'title', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
			['name' => 'expression', 'type' => 'text', 'NULL' => false],
			['name' => 'source', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'syslog'],
			['name' => 'removal', 'type' => 'int(10)', 'NULL' => false, 'default' => '1'],
			['name' => 'kind', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'timeseries'],
			['name' => 'chart', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'line'],
			['name' => 'field', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'host'],
			['name' => 'interval', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'dashboard'],
			['name' => 'timespan', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'dashboard'],
			['name' => 'top_n', 'type' => 'int(10)', 'NULL' => false, 'default' => '10'],
			['name' => 'width', 'type' => 'smallint(5)', 'unsigned' => true, 'NULL' => false, 'default' => '1'],
			['name' => 'height', 'type' => 'smallint(5)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
			['name' => 'position', 'type' => 'int(10)', 'NULL' => false, 'default' => '0'],
			['name' => 'date', 'type' => 'int(16)', 'NULL' => false, 'default' => '0']
		],
		'primary' => ['id'],
		'keys' => [['name' => 'dashboard', 'columns' => ['dashboard_id']]]
	], false, true, $syslog_cnn);
}

/** Create or update status storage. */
function syslog_ensure_status_table(): void {
	global $syslog_cnn;

	db_update_table('syslog_status', [
		'type' => 'InnoDB',
		'columns' => [
			['name' => 'name', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
			['name' => 'value', 'type' => 'text', 'NULL' => false],
			['name' => 'updated', 'type' => 'int(16)', 'NULL' => false, 'default' => '0']
		],
		'primary' => ['name']
	], false, true, $syslog_cnn);
}

/**
 * Create the durable, Syslog-owned remote collector replication outbox.
 *
 * source_poller_id/source_event_id is the immutable distributed identity.
 * The portable payload uses incoming-table values rather than local
 * host/program surrogate IDs. Delivery is intentionally deferred.
 *
 * @return void
 */
function syslog_create_replication_output_table(): void {
	global $syslogdb_default;

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_replication_output` (
		`source_poller_id` int(10) unsigned NOT NULL COMMENT 'Originating Cacti data collector ID',
		`source_event_id` bigint unsigned NOT NULL COMMENT 'Immutable local syslog_incoming sequence',
		`facility_id` int(10) unsigned default NULL COMMENT 'Portable syslog facility value',
		`priority_id` int(10) unsigned default NULL COMMENT 'Portable syslog priority value',
		`program` varchar(40) default NULL COMMENT 'Source program text',
		`logtime` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'Original event timestamp',
		`host` varchar(64) default NULL COMMENT 'Source host text',
		`message` TEXT NOT NULL COMMENT 'Source message text',
		`disposition` varchar(16) NOT NULL COMMENT 'Local archival target: syslog or syslog_removed',
		`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Outbox creation time',
		`acknowledged_at` timestamp NULL DEFAULT NULL COMMENT 'Reserved for future destination acknowledgement',
		`attempts` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'Reserved for future delivery retries',
		PRIMARY KEY (`source_poller_id`, `source_event_id`),
		KEY `backlog` (`acknowledged_at`, `created_at`, `source_poller_id`, `source_event_id`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");
}

/**
 * Create the Main Collector receipt boundary for remote Syslog delivery.
 *
 * The partitioned history tables deliberately retain their existing keys:
 * MySQL requires every unique key on a partitioned table to include the
 * partitioning column. A small unpartitioned receipt table therefore owns
 * distributed idempotency and is committed with the archival insert.
 *
 * @return void
 */
function syslog_create_replication_receipts_table(): void {
	global $config, $syslogdb_default;

	if (isset($config['poller_id']) && (int) $config['poller_id'] > 1) {
		return;
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_replication_receipts` (
		`source_poller_id` int(10) unsigned NOT NULL,
		`source_event_id` bigint unsigned NOT NULL,
		`disposition` varchar(16) NOT NULL,
		`accepted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (`source_poller_id`, `source_event_id`),
		KEY `accepted_at` (`accepted_at`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");
}

/** Create main-collector telemetry for the latest batch from each remote poller. */
function syslog_create_replication_collectors_table(): void {
	global $config, $syslogdb_default;

	if (isset($config['poller_id']) && (int) $config['poller_id'] > 1) {
		return;
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_replication_collectors` (
		`source_poller_id` int(10) unsigned NOT NULL,
		`last_batch_id` char(32) NOT NULL,
		`last_batch_count` int(10) unsigned NOT NULL DEFAULT '0',
		`last_received` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (`source_poller_id`),
		KEY `last_received` (`last_received`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");
}

/** Create the plugin-owned, expiring local recovery-worker lease table. */
function syslog_create_replication_recovery_table(): void {
	global $syslogdb_default;

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_replication_recovery` (
		`name` varchar(32) NOT NULL,
		`owner_token` char(32) NOT NULL,
		`acquired_at` int(10) unsigned NOT NULL,
		`heartbeat_at` int(10) unsigned NOT NULL,
		`pid` int(10) unsigned NOT NULL DEFAULT '0',
		PRIMARY KEY (`name`),
		KEY `heartbeat_at` (`heartbeat_at`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");
}

/**
 * Create the partitioned Syslog table, including past and future partitions.
 *
 * @param string           $engine     The storage engine to create the table with.
 * @param float|int|string $days       The number of days of retention to provision.
 * @param int|string|false $ahead_days The number of days of partitions to create
 *                                     ahead of today, or false when unconfigured.
 *
 * @return void
 */
function syslog_create_partitioned_syslog_table($engine = 'InnoDB', $days = 30, $ahead_days = 3) {
	global $config, $syslogdb_default, $syslog_levels;

	syslog_connect();

	$engine = syslog_validate_storage_engine($engine);

	// Guard the partition loop against non-numeric or extreme values: a
	// crafted 'days' option would otherwise loop indefinitely or emit a
	// very large DDL statement.
	$days = (int) $days;

	if ($days < 0 || $days > 365) {
		$days = 30;
	}

	if (!is_numeric($ahead_days) || (int) $ahead_days < 1 || (int) $ahead_days > 7) {
		$ahead_days = 3;
	}

	$ahead_days = (int) $ahead_days;

	if (stripos($engine, 'aria') !== false) {
		$row_format = 'ROW_FORMAT=Page';
	} else {
		$row_format = 'ROW_FORMAT=Dynamic';
	}

	$sql = "CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog` (
		facility_id int(10) unsigned default NULL,
		priority_id int(10) unsigned default NULL,
		program_id int(10) unsigned default NULL,
		host_id int(10) unsigned default NULL,
		logtime timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
		message TEXT NOT NULL,
		seq bigint unsigned NOT NULL auto_increment,
		replication_source_poller_id int(10) unsigned default NULL,
		replication_source_event_id bigint unsigned default NULL,
		PRIMARY KEY(seq, logtime),
		INDEX `seq` (`seq`),
		INDEX `replication_source` (`replication_source_poller_id`, `replication_source_event_id`),
		INDEX logtime (logtime),
		INDEX program_id (program_id),
		INDEX host_id (host_id),
		INDEX priority_id (priority_id),
		INDEX facility_id (facility_id))
		ENGINE=$engine
		$row_format
		PARTITION BY RANGE (UNIX_TIMESTAMP(logtime))\n";

	$now = time();

	$parts = '';

	/*
	 * Partition boundaries are integer epochs computed in PHP and injected
	 * as numeric literals. This keeps both MySQL and PHP session time zones
	 * out of the equation: the boundary is always the next UTC midnight
	 * after the labeled day.
	 *
	 * $days counts today as one of the retained days, so the historical
	 * loop bound is $days - 1 (today plus $days - 1 prior days = $days
	 * total). This must stay in lockstep with the $days + $ahead_days
	 * "keep_partitions" math in syslog_partition_remove(), or newly
	 * installed tables over-provision by one partition and the very next
	 * retention prune immediately deletes the oldest one.
	 *
	 * $days = 0 is a valid "Indefinite" retention setting (never pruned by
	 * syslog_partition_remove(), which only prunes when $days > 0), but
	 * $days - 1 would then be -1 and skip creating today's concrete
	 * partition entirely. Clamp the starting bound to 0 so today is always
	 * created regardless of retention.
	 */
	for ($i = max($days - 1, 0); $i >= (0 - $ahead_days); $i--) {
		$day_epoch      = $now - ($i * 86400);
		$boundary_epoch = (intdiv($day_epoch, 86400) + 1) * 86400;
		$format         = gmdate('Ymd', $day_epoch);

		$parts .= ($parts !== '' ? ",\n" : '(') . ' PARTITION d' . $format . ' VALUES LESS THAN (' . $boundary_epoch . ')';
	}

	$parts .= ",\nPARTITION dMaxValue VALUES LESS THAN MAXVALUE);";

	syslog_db_execute($sql . $parts);
}

/**
 * Create the Syslog database tables, dropping existing ones on truncate.
 *
 * @param array<string, mixed> $options The install options, either from the
 *                                      request, saved settings, or the Syslog
 *                                      config file.
 *
 * @return void
 */
function syslog_setup_table_new(array $options, bool $repair = false): void {
	global $config, $settings, $syslogdb_default, $syslog_levels, $syslog_cnn;

	syslog_connect();

	$tables  = [];

	$syslog_levels = [
		0 => 'emerg',
		1 => 'crit',
		2 => 'alert',
		3 => 'err',
		4 => 'warn',
		5 => 'notice',
		6 => 'info',
		7 => 'debug',
		8 => 'other'
	];

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

	// Partitioned tables are the only supported architecture.  Traditional
	// (non-partitioned) tables are deprecated, so the 'trad' architecture
	// silently upgrades to partitioned table creation no matter how the
	// options were supplied (request, saved settings, or legacy config.php).
	if (!isset($options['db_type']) || $options['db_type'] != 'part') {
		if (isset($options['db_type']) && $options['db_type'] == 'trad') {
			cacti_log("SYSLOG WARNING: The 'trad' database architecture setting is deprecated.  Partitioned tables are the only supported architecture; creating partitioned tables", false, 'SYSLOG');
		}

		$options['db_type'] = 'part';
	}

	// validate some simple information
	$truncate     = !$repair && isset($options['upgrade_type']) && $options['upgrade_type'] == 'truncate';
	$engine       = syslog_install_storage_engine(isset($options['engine']) ? $options['engine'] : 'InnoDB', $options);
	$syslogexists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `$syslogdb_default` LIKE 'syslog'"));

	// Aria is only supported on MariaDB.  If a legacy setting requests Aria
	// on MySQL, fall back to InnoDB so table creation cannot fail.
	if (stripos($engine, 'aria') !== false) {
		$database = syslog_db_fetch_row('SHOW GLOBAL VARIABLES LIKE "version"');
		$version  = is_array($database) && isset($database['Value']) ? (string) $database['Value'] : '';

		if (stripos($version, 'mariadb') === false) {
			cacti_log('SYSLOG WARNING: Aria storage engine requested on a non-MariaDB database.  Falling back to InnoDB', false, 'SYSLOG');

			$engine = 'InnoDB';
		}
	}

	// Partition retention days are required for partitioned table creation
	// regardless of how the options were supplied.  '0' is a valid value
	// (Indefinite retention) and must not be coerced.
	if (!isset($options['days']) || !is_numeric($options['days']) || (int) $options['days'] < 0) {
		$options['days'] = 30;
	}

	// set table construction settings for the remote pollers
	if (!$repair) {
		set_config_option('syslog_install_upgrade_type', empty($options['upgrade_type']) ? '' : $options['upgrade_type'], true);
		set_config_option('syslog_install_engine',       $engine, true);
		set_config_option('syslog_install_db_type',      empty($options['db_type']) ? '' : $options['db_type'], true);
		set_config_option('syslog_install_days',         empty($options['days']) ? '' : $options['days'], true);
	}

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog`");
	}

	// The syslog table is created partitioned; the helper also selects the
	// matching ROW_FORMAT for the chosen engine.
	syslog_create_partitioned_syslog_table($engine, $options['days'], read_config_option('syslog_partition_ahead_days'));

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_alert`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_alert` (
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
		`cooldown_minutes` int(10) NOT NULL default '-1',
		`deduplication_minutes` int(10) NOT NULL default '-1',
		`suppression_schedule` text NOT NULL,
		`maintenance_mode` varchar(16) NOT NULL default 'inherit',
		`maintenance_days` varchar(32) NOT NULL default '1,2,3,4,5',
		`maintenance_start` char(5) NOT NULL default '00:00',
		`maintenance_end` char(5) NOT NULL default '00:00',
		`maintenance_datetime_start` varchar(16) NOT NULL default '',
		`maintenance_datetime_end` varchar(16) NOT NULL default '',
		`open_ticket` CHAR(2) default '',
		`message` TEXT NOT NULL,
		`body` VARCHAR(8192) NOT NULL default '',
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		`email` varchar(255) default NULL,
		`notify` int(10) unsigned NOT NULL default '0',
		`command` varchar(255) default NULL,
		`notes` varchar(255) default NULL,
		PRIMARY KEY (id))
		ENGINE=InnoDB");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_incoming`");
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_replication_output`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_incoming` (
		facility_id int(10) unsigned default NULL,
		priority_id int(10) unsigned default NULL,
		program varchar(40) default NULL,
		logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
		host varchar(64) default NULL,
		message TEXT NOT NULL,
		seq bigint unsigned NOT NULL auto_increment,
		`status` tinyint(4) NOT NULL default '0',
		PRIMARY KEY (seq),
		INDEX program (program),
		INDEX `status` (`status`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

syslog_create_replication_output_table();
syslog_create_replication_receipts_table();
syslog_create_replication_collectors_table();
syslog_create_replication_recovery_table();

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_alert_suppression` (
		`alert_id` int(10) unsigned NOT NULL,
		`scope_key` varchar(255) NOT NULL,
		`dedup_hash` char(40) NOT NULL,
		`last_sent` int(10) unsigned NOT NULL default '0',
		PRIMARY KEY (`alert_id`, `scope_key`, `dedup_hash`),
		INDEX `last_sent` (`last_sent`))
		ENGINE=InnoDB");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_remove`");
	}

	if (stripos($engine, 'aria') !== false) {
		$row_format = 'ROW_FORMAT=Page';
	} else {
		$row_format = 'ROW_FORMAT=Dynamic';
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_remove` (
		id int(10) NOT NULL auto_increment,
		`hash` varchar(32) NOT NULL default '',
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		method CHAR(5) DEFAULT 'del',
		message TEXT NOT NULL,
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		notes varchar(255) default NULL,
		PRIMARY KEY (id))
		ENGINE=$engine
		$row_format");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_reports`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_reports` (
		id int(10) NOT NULL auto_increment,
		`hash` varchar(32) NOT NULL default '',
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		timespan int(16) NOT NULL default '0',
		timepart char(5) NOT NULL default '00:00',
		lastsent int(16) NOT NULL default '0',
		body varchar(8192) NOT NULL default '0',
		message TEXT default NULL,
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		email varchar(255) default NULL,
		notify int(10) unsigned NOT NULL default '0',
		notes varchar(255) default NULL,
		PRIMARY KEY (id))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");
	db_update_table('syslog_reports', [
		'columns' => [['name' => 'body', 'type' => 'varchar(8192)', 'NULL' => false, 'default' => '0', 'after' => 'lastsent']],
		'primary' => ['id']
	], false, true, $syslog_cnn);

	syslog_ensure_status_table();

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_hosts`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_programs` (
		`program_id` int(10) unsigned NOT NULL auto_increment,
		`program` VARCHAR(40) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`program`),
		INDEX host_id (`program_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic
		COMMENT='Contains all programs currently in the syslog table'");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_hosts` (
		`host_id` int(10) unsigned NOT NULL auto_increment,
		`host` VARCHAR(64) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`host`),
		INDEX host_id (`host_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic
		COMMENT='Contains all hosts currently in the syslog table'");

	$facilities_exists = syslog_db_table_exists('syslog_facilities', false);

	if (!$repair) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_facilities`");
		$facilities_exists = false;
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_facilities` (
		`facility_id` int(10) unsigned NOT NULL,
		`facility` varchar(10) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY  (`facility_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	if (!$facilities_exists) {
		syslog_db_execute("INSERT INTO `$syslogdb_default`.`syslog_facilities` (facility_id, facility) VALUES
		(0,'kern'), (1,'user'), (2,'mail'), (3,'daemon'), (4,'auth'), (5,'syslog'), (6,'lpd'), (7,'news'),
		(8,'uucp'), (9,'crond'), (10,'authpriv'), (11,'ftpd'), (12,'ntpd'), (13,'logaudit'), (14,'logalert'),
		(15,'crond'), (16,'local0'), (17,'local1'), (18,'local2'), (19,'local3'), (20,'local4'), (21,'local5'),
		(22,'local6'), (23,'local7')");
	}

	$priorities_exists = syslog_db_table_exists('syslog_priorities', false);

	if (!$repair) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_priorities`");
		$priorities_exists = false;
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_priorities` (
		`priority_id` int(10) unsigned NOT NULL,
		`priority` varchar(10) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`priority_id`),
		INDEX last_updated (`last_updated`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	if (!$priorities_exists) {
		syslog_db_execute("INSERT INTO `$syslogdb_default`.`syslog_priorities` (priority_id, priority) VALUES
		(0,'emerg'), (1,'alert'), (2,'crit'), (3,'err'), (4,'warning'), (5,'notice'), (6,'info'), (7,'debug'), (8,'other')");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_host_facilities` (
		`host_id` int(10) unsigned NOT NULL,
		`facility_id` int(10) unsigned NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY  (`host_id`,`facility_id`))
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	if ($truncate) {
		syslog_db_execute("DROP TABLE IF EXISTS `$syslogdb_default`.`syslog_removed`");
	}

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_removed` LIKE `$syslogdb_default`.`syslog`");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_logs` (
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
		ENGINE=InnoDB
		ROW_FORMAT=Dynamic");

	syslog_ensure_saved_search_tables();
	syslog_ensure_share_tables();
	syslog_ensure_dashboard_tables();
	syslog_create_device_rule_table();
	if (!$repair) {
		syslog_dashboard_seed_default();
	}

	if (!$repair) {
		if (!isset($settings['syslog'])) {
			syslog_config_settings();
		}

		foreach ($settings['syslog'] as $name => $values) {
			if (isset($values['default']) && db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [$name]) === false) {
				set_config_option($name, $values['default']);
			}
		}
		set_config_option('syslog_install_upgrade_type', 'upgrade');
	}
}

/** Complete an interrupted install or upgrade without dropping existing data. */
function syslog_ensure_table_structures(): bool {
	global $config;

	require_once(__DIR__ . '/settings.php');

	$tables = [
		'syslog', 'syslog_alert', 'syslog_incoming', 'syslog_alert_suppression',
		'syslog_remove', 'syslog_reports', 'syslog_status', 'syslog_programs',
		'syslog_hosts', 'syslog_facilities', 'syslog_priorities', 'syslog_host_facilities',
		'syslog_removed', 'syslog_logs', 'syslog_device_rule',
		'syslog_replication_output', 'syslog_replication_recovery'
	];
	if ((int) $config['poller_id'] <= 1) {
		$tables[] = 'syslog_replication_receipts';
		$tables[] = 'syslog_replication_collectors';
	}
	$rebuilt = false;

	foreach ($tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			syslog_setup_table_new([], true);
			$rebuilt = true;
			break;
		}
	}

	if (!$rebuilt) {
		syslog_ensure_status_table();
		syslog_ensure_saved_search_tables();
		syslog_ensure_share_tables();
		syslog_ensure_dashboard_tables();
	}
	$tables = array_merge($tables, [
		'syslog_saved_searches', 'syslog_saved_searches_perm',
		'syslog_dashboards', 'syslog_dashboards_perm', 'syslog_dashboard_panels'
	]);

	foreach ($tables as $table) {
		if (!syslog_db_table_exists($table, false)) {
			cacti_log("SYSLOG ERROR: Schema upgrade did not create $table", false, 'SYSLOG');
			return false;
		}
	}

	return true;
}
