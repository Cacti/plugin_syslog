<?php
/*******************************************************************************
 ex: set tabstop=4 shiftwidth=4 autoindent:

    Author ......... Jimmy Conner
    Contact ........ jimmy@sqmail.org
    Home Site ...... http://cactiusers.org
    Program ........ Syslog Plugin for Cacti
    Purpose ........ Sylog viewer for cacti
    Originally released as aloe by: sidewinder <sidewinder@shitworks.com>
    Modified by: Harlequin harlequin@cyberonic.com> as h.aloe

*******************************************************************************/

include_once($config["base_path"] . "/plugins/syslog/database.php");

function plugin_syslog_install() {
	global $config, $syslog_upgrade;
	static $bg_inprocess = false;

	include(dirname(__FILE__) . "/config.php");

	syslog_connect();

	$syslog_exists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE 'syslog'"));
	$db_version    = syslog_get_mysql_version("syslog");

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("days"));
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

	api_plugin_register_realm('syslog', 'syslog.php', 'Plugin -> Syslog User', 1);
	api_plugin_register_realm('syslog', 'syslog_alerts.php,syslog_removal.php,syslog_reports.php', 'Plugin -> Syslog Administration', 1);

	//print "<pre>";print_r($_GET);print "</pre>";
	if (isset($_GET["install"]) || isset($_GET["return"]) || isset($_GET["cancel"])) {
		if (!$bg_inprocess) {
			syslog_execute_update($syslog_exists, $_GET);
			$bg_inprocess = true;
		}
	}else{
		syslog_install_advisor($syslog_exists, $db_version);
		exit;
	}
}

function syslog_execute_update($syslog_exists, $options) {
	global $config;

	if (isset($options["cancel"])) {
		header("Location:" . $config["url_path"] . "plugins.php?mode=uninstall&id=syslog&uninstall&uninstall_method=all");
		exit;
	}elseif (isset($options["return"])) {
		db_execute("DELETE FROM plugin_config WHERE directory='syslog'");
		db_execute("DELETE FROM plugin_realms WHERE plugin='syslog'");
		db_execute("DELETE FROM plugin_db_changes WHERE plugin='syslog'");
		db_execute("DELETE FROM plugin_hooks WHERE name='syslog'");
	}elseif (isset($options["upgrade_type"])) {
		if ($options["upgrade_type"] == "truncate") {
			syslog_setup_table_new($options);
		}else{
			syslog_upgrade_pre_oneoh_tables($options);
		}
	}else{
		syslog_setup_table_new($options);
	}

	db_execute("REPLACE INTO settings SET name='syslog_retention', value='" . $options["days"] . "'");
}

function plugin_syslog_uninstall () {
	global $config, $cnn_id, $syslog_incoming_config, $database_default, $database_hostname, $database_username;

	/* database connection information, must be loaded always */
	include(dirname(__FILE__) . '/config.php');
	include_once(dirname(__FILE__) . '/functions.php');

	syslog_connect();

	//print "<pre>";print_r($_GET);print "</pre>";
	if (isset($_GET["cancel"]) || isset($_GET["return"])) {
		header("Location:" . $config["url_path"] . "plugins.php");
		exit;
	}elseif (isset($_GET["uninstall"])) {
		if ($_GET["uninstall_method"] == "all") {
			/* do the big tables first */
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_removed`");

			/* do the settings tables last */
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_incoming`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_alert`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_remove`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_reports`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_facilities`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_statistics`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_host_facilities`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_priorities`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_logs`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_hosts`");
		}else{
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog`");
			syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_removed`");
		}
	}else{
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

function plugin_syslog_version() {
	return syslog_version();
}

function syslog_connect() {
	global $config, $cnn_id, $syslog_cnn, $database_default;

	include(dirname(__FILE__) . "/config.php");
	include_once(dirname(__FILE__) . "/functions.php");

	/* Connect to the Syslog Database */
	if (empty($syslog_cnn)) {
		if ((strtolower($database_hostname) == strtolower($syslogdb_hostname)) &&
			($database_default == $syslogdb_default)) {
			/* move on, using Cacti */
			$syslog_cnn = $cnn_id;
		}else{
			if (!isset($syslogdb_port)) {
				$syslogdb_port = "3306";
			}
			$cnn_str = "mysqli://$syslogdb_username:$syslogdb_password@$syslogdb_hostname/$syslogdb_default?persist=0&port=$syslogdb_port";
			$syslog_cnn = NewADOConnection($cnn_str);
			if ($syslog_cnn == false) {
					echo "Can not connect\n";exit;
			}
		}
	}
}

function syslog_check_upgrade() {
	global $config, $cnn_id, $syslog_levels, $database_default, $syslog_upgrade;

	include(dirname(__FILE__) . "/config.php");

	syslog_connect();

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'syslog.php', 'slslog_removal.php', 'syslog_alerts.php', 'syslog_reports.php');
	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$present = syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE 'syslog'");
	$old_pia = false;
	if (sizeof($present)) {
		$old_table = syslog_db_fetch_row("SHOW COLUMNS FROM `" . $syslogdb_default . "`.`syslog` LIKE 'time'");
		if (sizeof($old_table)) {
			$old_pia = true;
		}
	}

	/* don't let this script timeout */
	ini_set("max_execution_time", 0);

	$current = syslog_version();
	$current = $current['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='syslog'");
	if ($current != $old || $old_pia) {
		/* update realms for old versions */
		if ($old < 1.0 || $old = '' || $old_pia) {
			plugin_syslog_install();
		}else{
			if ($old < 1.01) {
				syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` ADD COLUMN command varchar(255) DEFAULT NULL AFTER email;");
			}

			if ($old < 1.05) {
				$realms = db_fetch_assoc("SELECT * FROM plugin_realms WHERE file='Array'");
				if (sizeof($realms)) {
				foreach($realms as $realm) {
					db_execute("DELETE FROM plugin_realms WHERE id=" . $realm["id"]);
					db_execute("DELETE FROM auto_auth_realm WHERE realm_id=" . ($realm["id"]+100));
				}
				}
			}

			if ($old < 1.10) {
				$emerg = syslog_db_fetch_cell("SELECT priority_id FROM `" . $syslogdb_default . "`.`syslog_priorities` WHERE priority='emerg'");
				if ($emerg) {
					syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog` SET priority_id=1 WHERE priority_id=$emerg");
					syslog_db_execute("DELETE FROM syslog_priorities WHERE priority_id=$emerg");
				}
				syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_priorities` SET priority='emerg' WHERE priority='emer'");
			}

			if ($old < 1.20) {
				syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_statistics` (
					`host_id` INTEGER UNSIGNED NOT NULL,
					`facility_id` INTEGER UNSIGNED NOT NULL,
					`priority_id` INTEGER UNSIGNED NOT NULL,
					`insert_time` TIMESTAMP NOT NULL,
					`records` INTEGER UNSIGNED NOT NULL,
					PRIMARY KEY (`host_id`, `facility_id`, `priority_id`, `insert_time`),
					INDEX `host_id`(`host_id`),
					INDEX `facility_id`(`facility_id`),
					INDEX `priority_id`(`priority_id`),
					INDEX `insert_time`(`insert_time`))
					ENGINE = MyISAM
					COMMENT = 'Maintains High Level Statistics';");

				syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` ADD COLUMN repeat_alert int(10) unsigned NOT NULL DEFAULT '0' AFTER `enabled`");
				syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` ADD COLUMN open_ticket char(2) NOT NULL DEFAULT '' AFTER `repeat_alert`");
			}
		}

		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='syslog'");
	}
}

function syslog_upgrade_pre_oneoh_tables($options = false, $isbackground = false) {
	global $config, $cnn_id, $syslog_levels, $database_default, $syslog_upgrade;

	include(dirname(__FILE__) . "/config.php");

	$syslog_levels = array(
		1 => 'emerg',
		2 => 'crit',
		3 => 'alert',
		4 => 'err',
		5 => 'warn',
		6 => 'notice',
		7 => 'info',
		8 => 'debug',
		9 => 'other'
		);

	if ($isbackground) {
		$table = 'syslog_pre_upgrade';
	}else{
		$table = 'syslog';
	}

	/* validate some simple information */
	$mysqlVersion = syslog_get_mysql_version("syslog");
	$truncate     = ((isset($options["upgrade_type"]) && $options["upgrade_type"] == "truncate") ? true:false);
	$upgrade_type = (isset($options["upgrade_type"]) ? $options["upgrade_type"]:"inline");
	$engine       = ((isset($options["engine"]) && $options["engine"] == "innodb") ? "InnoDB":"MyISAM");
	$partitioned  = ((isset($options["db_type"]) && $options["db_type"] == "part") ? true:false);
	$syslogexists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE '$table'"));

	/* disable collection for a bit */
	set_config_option('syslog_enabled', '');

	if ($upgrade_type == "truncate") return;

	if ($upgrade_type == "inline" || $isbackground) {
		syslog_setup_table_new($options);

		api_plugin_register_realm('syslog', 'syslog.php', 'Plugin -> Syslog User', 1);
		api_plugin_register_realm('syslog', 'syslog_alerts.php,syslog_removal.php,syslog_reports.php', 'Plugin -> Syslog Administration', 1);

		/* get the realm id's and change from old to new */
		$user  = db_fetch_cell("SELECT id FROM plugin_realms WHERE file='syslog.php'")+100;
		$admin = db_fetch_cell("SELECT id FROM plugin_realms WHERE file='syslog_alerts.php'")+100;

		if ($user > 100) {
			$users = db_fetch_assoc("SELECT user_id FROM user_auth_realm WHERE realm_id=37");
			if (sizeof($users)) {
			foreach($users as $u) {
				db_execute("INSERT INTO user_auth_realm
					(realm_id, user_id) VALUES ($user, " . $u["user_id"] . ")
					ON DUPLICATE KEY UPDATE realm_id=VALUES(realm_id)");
				db_execute("DELETE FROM user_auth_realm
					WHERE user_id=" . $u["user_id"] . "
					AND realm_id=37");
			}
			}
		}

		if ($admin > 100) {
			$admins = db_fetch_assoc("SELECT user_id FROM user_auth_realm WHERE realm_id=38");
			if (sizeof($admins)) {
			foreach($admins as $user) {
				db_execute("INSERT INTO user_auth_realm
					(realm_id, user_id) VALUES ($admin, " . $user["user_id"] . ")
					ON DUPLICATE KEY UPDATE realm_id=VALUES(realm_id)");
				db_execute("DELETE FROM user_auth_realm
					WHERE user_id=" . $user["user_id"] . "
					AND realm_id=38");
			}
			}
		}

		/* get the database table names */
		$rows = syslog_db_fetch_assoc("SHOW TABLES FROM `" . $syslogdb_default . "`");
		if (sizeof($rows)) {
		foreach($rows as $row) {
			$tables[] = $row["Tables_in_" . $syslogdb_default];
		}
		}

		/* create the reports table */
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_logs` (
			alert_id integer unsigned not null default '0',
			logseq bigint unsigned NOT NULL,
			logtime TIMESTAMP NOT NULL default '0000-00-00 00:00:00',
			logmsg " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " default NULL,
			host varchar(32) default NULL,
			facility varchar(10) default NULL,
			priority varchar(10) default NULL,
			count integer unsigned NOT NULL default '0',
			html blob default NULL,
			seq bigint unsigned NOT NULL auto_increment,
			PRIMARY KEY (seq),
			KEY logseq (logseq),
			KEY alert_id (alert_id),
			KEY host (host),
			KEY seq (seq),
			KEY logtime (logtime),
			KEY priority (priority),
			KEY facility (facility)) ENGINE=$engine;");

		/* create the soft removal table */
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_host_facilities` (
			`host_id` int(10) unsigned NOT NULL,
			`facility_id` int(10) unsigned NOT NULL,
			`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY  (`host_id`,`facility_id`)) ENGINE=$engine;");

		/* create the host reference table */
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_hosts` (
			`host_id` int(10) unsigned NOT NULL auto_increment,
			`host` VARCHAR(128) NOT NULL,
			`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY (`host`),
			KEY host_id (`host_id`),
			KEY last_updated (`last_updated`)) ENGINE=$engine
			COMMENT='Contains all hosts currently in the syslog table'");

		/* check upgrade of syslog_alert */
		$sql     = "DESCRIBE `" . $syslogdb_default . "`.`syslog_alert`";
		$columns = array();
		$array = syslog_db_fetch_assoc($sql);

		if (sizeof($array)) {
		foreach ($array as $row) {
			$columns[] = $row["Field"];
		}
		}

		if (!in_array("enabled", $columns)) {
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` MODIFY COLUMN message varchar(128) DEFAULT NULL, ADD COLUMN enabled CHAR(2) DEFAULT 'on' AFTER type;");
		}

		if (!in_array("method", $columns)) {
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` ADD COLUMN method int(10) unsigned NOT NULL default '0' AFTER name");
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` ADD COLUMN num int(10) unsigned NOT NULL default '1' AFTER method");
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` ADD COLUMN severity INTEGER UNSIGNED NOT NULL default '0' AFTER name");
		}

		if (!in_array("command", $columns)) {
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_alert` ADD COLUMN command varchar(255) DEFAULT NULL AFTER email;");
		}

		/* check upgrade of syslog_alert */
		$sql     = "DESCRIBE `" . $syslogdb_default . "`.`syslog_remove`";
		$columns = array();
		$array = syslog_db_fetch_assoc($sql);

		if (sizeof($array)) {
		foreach ($array as $row) {
			$columns[] = $row["Field"];
		}
		}

		if (!in_array("enabled", $columns)) {
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_remove` MODIFY COLUMN message varchar(128) DEFAULT NULL, ADD COLUMN enabled CHAR(2) DEFAULT 'on' AFTER type;");
		}

		if (!in_array("method", $columns)) {
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`syslog_remove` ADD COLUMN method CHAR(5) DEFAULT 'del' AFTER enabled;");
		}

		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_hosts`");
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_hosts` (
			`host_id` int(10) unsigned NOT NULL auto_increment,
			`host` VARCHAR(128) NOT NULL,
			`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY (`host`),
			KEY host_id (`host_id`),
			KEY last_updated (`last_updated`)) TYPE=$engine
			COMMENT='Contains all hosts currently in the syslog table'");

		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_facilities`");
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_facilities` (
			`facility_id` int(10) unsigned NOT NULL auto_increment,
			`facility` varchar(10) NOT NULL,
			`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY (`facility`),
			KEY facility_id (`facility_id`)) ENGINE=$engine;");

		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_priorities`");
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_priorities` (
			`priority_id` int(10) unsigned NOT NULL auto_increment,
			`priority` varchar(10) NOT NULL,
			`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY  (`priority`),
			KEY priority_id (`priority_id`)) ENGINE=$engine;");

		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_host_facilities`");
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_host_facilities` (
			`host_id` int(10) unsigned NOT NULL,
			`facility_id` int(10) unsigned NOT NULL,
			`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY  (`host_id`,`facility_id`)) ENGINE=$engine;");

		/* populate the tables */
		syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_hosts` (host) SELECT DISTINCT host FROM `" . $syslogdb_default . "`.`$table`");
		syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_facilities` (facility) SELECT DISTINCT facility FROM `" . $syslogdb_default . "`.`$table`");
		foreach($syslog_levels as $id => $priority) {
			syslog_db_execute("REPLACE INTO `" . $syslogdb_default . "`.`syslog_priorities` (priority_id, priority) VALUES ($id, '$priority')");
		}

		/* a bit more horsepower please */
		syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_host_facilities`
			(host_id, facility_id)
			SELECT host_id, facility_id
			FROM ((SELECT DISTINCT host, facility
				FROM `" . $syslogdb_default . "`.`$table`) AS s
				INNER JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
				ON s.host=sh.host
				INNER JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
				ON sf.facility=s.facility)");

		/* change the structure of the syslog table for performance sake */
		$mysqlVersion = syslog_get_mysql_version("syslog");
		if ($mysqlVersion >= 5) {
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`$table`
				MODIFY COLUMN message varchar(1024) DEFAULT NULL,
				ADD COLUMN facility_id int(10) UNSIGNED NULL AFTER facility,
				ADD COLUMN priority_id int(10) UNSIGNED NULL AFTER facility_id,
				ADD COLUMN host_id int(10) UNSIGNED NULL AFTER priority_id,
				ADD COLUMN logtime DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER priority,
				ADD INDEX facility_id (facility_id),
				ADD INDEX priority_id (priority_id),
				ADD INDEX host_id (host_id),
				ADD INDEX logtime(logtime);");
		}else{
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`$table`
				ADD COLUMN facility_id int(10) UNSIGNED NULL AFTER host,
				ADD COLUMN priority_id int(10) UNSIGNED NULL AFTER facility_id,
				ADD COLUMN host_id int(10) UNSIGNED NULL AFTER priority_id,
				ADD COLUMN logtime DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER priority,
				ADD INDEX facility_id (facility_id),
				ADD INDEX priority_id (priority_id),
				ADD INDEX host_id (host_id),
				ADD INDEX logtime(logtime);");
		}

		/* convert dates and times to timestamp */
		syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`$table` SET logtime=TIMESTAMP(`date`, `time`)");

		/* update the host_ids */
		$hosts = syslog_db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_hosts`");
		if (sizeof($hosts)) {
		foreach($hosts as $host) {
			syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`$table`
				SET host_id=" . $host["host_id"] . "
				WHERE host='" . $host["host"] . "'");
		}
		}

		/* update the priority_ids */
		$priorities = $syslog_levels;
		if (sizeof($priorities)) {
		foreach($priorities as $id => $priority) {
			syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`$table`
				SET priority_id=" . $id . "
				WHERE priority='" . $priority . "'");
		}
		}

		/* update the facility_ids */
		$fac = syslog_db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_facilities`");
		if (sizeof($fac)) {
		foreach($fac as $f) {
			syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`$table`
				SET facility_id=" . $f["facility_id"] . "
				WHERE facility='" . $f["facility"] . "'");
		}
		}

		if (!$isbackground) {
			syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`$table`
				DROP COLUMN `date`,
				DROP COLUMN `time`,
				DROP COLUMN `host`,
				DROP COLUMN `facility`,
				DROP COLUMN `priority`");
		}else{
			while ( true ) {
				$fetch_size = '10000';
				$sequence   = syslog_db_fetch_cell("SELECT max(seq) FROM (SELECT seq FROM `" . $syslogdb_default . "`.`$table` ORDER BY seq LIMIT $fetch_size) AS preupgrade");

				if ($sequence > 0 && $sequence != '') {
					syslog_db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog` (facility_id, priority_id, host_id, logtime, message)
						SELECT facility_id, priority_id, host_id, logtime, message
						FROM `" . $syslogdb_default . "`.`$table`
						WHERE seq<$sequence");
					syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`$table` WHERE seq<=$sequence");
				}else{
					syslog_db_execute("DROP TABLE `" . $syslogdb_default . "`.`$table`");
					break;
				}
			}
		}

		/* create the soft removal table */
		syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_removed`");
		syslog_db_execute("CREATE TABLE `" . $syslogdb_default . "`.`syslog_removed` LIKE `" . $syslogdb_default . "`.`syslog`");
	}else{
		include_once($config['base_path'] . "/lib/poller.php");
		$p = dirname(__FILE__);
		$command_string = read_config_option("path_php_binary");
		$extra_args = ' -q ' . $config['base_path'] . '/plugins/syslog/syslog_upgrade.php --type=' . $options["db_type"] . ' --engine=' . $engine . ' --days=' . $options["days"];
		cacti_log("SYSLOG NOTE: Launching Background Syslog Database Upgrade Process", false, "SYSTEM");
		exec_background($command_string, $extra_args);
	}

	/* reenable syslog xferral */
	set_config_option('syslog_enabled', 'on');
}

function syslog_get_mysql_version($db = "cacti") {
	if ($db == "cacti") {
		$dbInfo = db_fetch_row("SHOW GLOBAL VARIABLES LIKE 'version'");
	}else{
		$dbInfo = syslog_db_fetch_row("SHOW GLOBAL VARIABLES LIKE 'version'");
	}

	if (sizeof($dbInfo)) {
		return floatval($dbInfo["Value"]);
	}
	return "";
}

function syslog_create_partitioned_syslog_table($engine = "MyISAM", $days = 30) {
	global $config, $cnn_id, $syslog_incoming_config, $syslog_levels, $database_default, $database_hostname, $database_username;

	include(dirname(__FILE__) . "/config.php");

	$sql = "CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog` (
			facility_id int(10) default NULL,
			priority_id int(10) default NULL,
			host_id int(10) default NULL,
			logtime DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			message " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " NOT NULL default '',
			seq bigint unsigned NOT NULL auto_increment,
			KEY (seq),
			KEY logtime (logtime),
			KEY host_id (host_id),
			KEY priority_id (priority_id),
			KEY facility_id (facility_id)) ENGINE=$engine
			PARTITION BY RANGE (TO_DAYS(logtime))\n";

	$now = time();

	$parts = "";
	for($i = $days; $i >= -1; $i--) {
		$timestamp = $now - ($i * 86400);
		$date     = date('Y-m-d', $timestamp);
		$format   = date("Ymd", $timestamp - 86400);
		$parts .= ($parts != "" ? ",\n":"(") . " PARTITION d" . $format . " VALUES LESS THAN (TO_DAYS('" . $date . "'))";
	}
	$parts .= ",\nPARTITION dMaxValue VALUES LESS THAN MAXVALUE);";

	syslog_db_execute($sql . $parts);
}

function syslog_setup_table_new($options) {
	global $config, $cnn_id, $settings, $syslog_incoming_config, $syslog_levels, $database_default, $database_hostname, $database_username;

	include(dirname(__FILE__) . "/config.php");

	$tables  = array();

	$syslog_levels = array(
		1 => 'emerg',
		2 => 'crit',
		3 => 'alert',
		4 => 'err',
		5 => 'warn',
		6 => 'notice',
		7 => 'info',
		8 => 'debug',
		9 => 'other'
		);

	syslog_connect();

	/* validate some simple information */
	$mysqlVersion = syslog_get_mysql_version("syslog");
	$truncate     = ((isset($options["upgrade_type"]) && $options["upgrade_type"] == "truncate") ? true:false);
	$engine       = ((isset($options["engine"]) && $options["engine"] == "innodb") ? "InnoDB":"MyISAM");
	$partitioned  = ((isset($options["db_type"]) && $options["db_type"] == "part") ? true:false);
	$syslogexists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE 'syslog'"));

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog`");
	if (!$partitioned) {
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog` (
			facility_id int(10) default NULL,
			priority_id int(10) default NULL,
			host_id int(10) default NULL,
			logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
			message " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " NOT NULL default '',
			seq bigint unsigned NOT NULL auto_increment,
			PRIMARY KEY (seq),
			KEY logtime (logtime),
			KEY host_id (host_id),
			KEY priority_id (priority_id),
			KEY facility_id (facility_id)) ENGINE=$engine;");
	}else{
		syslog_create_partitioned_syslog_table($engine, $options["days"]);
	}

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_alert`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_alert` (
		`id` int(10) NOT NULL auto_increment,
		`name` varchar(255) NOT NULL default '',
		`severity` INTEGER UNSIGNED NOT NULL default '0',
		`method` int(10) unsigned NOT NULL default '0',
		`num` int(10) unsigned NOT NULL default '1',
		`type` varchar(16) NOT NULL default '',
		`enabled` CHAR(2) default 'on',
		`repeat_alert` int(10) unsigned NOT NULL default '0',
		`open_ticket` CHAR(2) default '',
		`message` VARCHAR(128) NOT NULL default '',
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		`email` varchar(255) default NULL,
		`command` varchar(255) default NULL,
		`notes` varchar(255) default NULL,
		PRIMARY KEY (id)) ENGINE=$engine;");

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_incoming`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_incoming` (
		facility varchar(10) default NULL,
		priority varchar(10) default NULL,
		`date` date default NULL,
		`time` time default NULL,
		host varchar(128) default NULL,
		message " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " NOT NULL DEFAULT '',
		seq bigint unsigned NOT NULL auto_increment,
		`status` tinyint(4) NOT NULL default '0',
		PRIMARY KEY (seq),
		KEY `status` (`status`)) ENGINE=$engine;");

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_remove`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_remove` (
		id int(10) NOT NULL auto_increment,
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		method CHAR(5) DEFAULT 'del',
		message VARCHAR(128) NOT NULL default '',
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		notes varchar(255) default NULL,
		PRIMARY KEY (id)) ENGINE=$engine;");

	$present = syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE 'syslog_reports'");
	if (sizeof($present)) {
		$newreport = sizeof(syslog_db_fetch_row("SHOW COLUMNS FROM `" . $syslogdb_default . "`.`syslog_reports` LIKE 'body'"));
	}else{
		$newreport = true;
	}
	if ($truncate || !$newreport) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_reports`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_reports` (
		id int(10) NOT NULL auto_increment,
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		timespan int(16) NOT NULL default '0',
		timepart char(5) NOT NULL default '00:00',
		lastsent int(16) NOT NULL default '0',
		body " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " default NULL,
		message varchar(128) default NULL,
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		email varchar(255) default NULL,
		notes varchar(255) default NULL,
		PRIMARY KEY (id)) ENGINE=$engine;");

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_hosts`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_hosts` (
		`host_id` int(10) unsigned NOT NULL auto_increment,
		`host` VARCHAR(128) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`host`),
		KEY host_id (`host_id`),
		KEY last_updated (`last_updated`)) ENGINE=$engine
		COMMENT='Contains all hosts currently in the syslog table'");

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_facilities`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_facilities` (
		`facility_id` int(10) unsigned NOT NULL auto_increment,
		`facility` varchar(10) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY  (`facility`),
		KEY facility_id (`facility_id`),
		KEY last_updates (`last_updated`)) ENGINE=$engine;");

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_priorities`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_priorities` (
		`priority_id` int(10) unsigned NOT NULL auto_increment,
		`priority` varchar(10) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`priority`),
		KEY priority_id (`priority_id`),
		KEY last_updated (`last_updated`)) ENGINE=$engine;");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_host_facilities` (
		`host_id` int(10) unsigned NOT NULL,
		`facility_id` int(10) unsigned NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY  (`host_id`,`facility_id`)) ENGINE=$engine;");

	if ($truncate) syslog_db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_removed`");
	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_removed` LIKE `" . $syslogdb_default . "`.`syslog`");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_logs` (
		alert_id integer unsigned not null default '0',
		logseq bigint unsigned NOT NULL,
		logtime TIMESTAMP NOT NULL default '0000-00-00 00:00:00',
		logmsg " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " default NULL,
		host varchar(32) default NULL,
		facility varchar(10) default NULL,
		priority varchar(10) default NULL,
		count integer unsigned NOT NULL default '0',
		html blob default NULL,
		seq bigint unsigned NOT NULL auto_increment,
		PRIMARY KEY (seq),
		KEY logseq (logseq),
		KEY alert_id (alert_id),
		KEY host (host),
		KEY seq (seq),
		KEY logtime (logtime),
		KEY priority (priority),
		KEY facility (facility)) ENGINE=$engine;");

	syslog_db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_statistics` (
		`host_id` INTEGER UNSIGNED NOT NULL,
		`facility_id` INTEGER UNSIGNED NOT NULL,
		`priority_id` INTEGER UNSIGNED NOT NULL,
		`insert_time` TIMESTAMP NOT NULL,
		`records` INTEGER UNSIGNED NOT NULL,
		PRIMARY KEY (`host_id`, `facility_id`, `priority_id`, `insert_time`),
		INDEX `host_id`(`host_id`),
		INDEX `facility_id`(`facility_id`),
		INDEX `priority_id`(`priority_id`),
		INDEX `insert_time`(`insert_time`))
		ENGINE = MyISAM
		COMMENT = 'Maintains High Level Statistics';");

	foreach($syslog_levels as $id => $priority) {
		syslog_db_execute("REPLACE INTO `" . $syslogdb_default . "`.`syslog_priorities` (priority_id, priority) VALUES ($id, '$priority')");
	}

	if (!isset($settings["syslog"])) {
		syslog_config_settings();
	}

	foreach($settings["syslog"] AS $name => $values) {
		if (isset($values["default"])) {
			db_execute("REPLACE INTO `" . $database_default . "`.`settings` (name, value) VALUES ('$name', '" . $values["default"] . "')");
		}
	}
}

function syslog_version () {
	return array(
		'name'     => 'syslog',
		'version'  => '1.22',
		'longname' => 'Syslog Monitoring',
		'author'   => 'Jimmy Conner',
		'homepage' => 'http://cactiusers.org',
		'email'    => 'jimmy@sqmail.org',
		'url'      => 'http://versions.cactiusers.org/'
	);
}

function syslog_check_dependencies() {
	global $plugins;

	if (db_fetch_cell("SELECT directory FROM plugin_config WHERE directory='settings' AND status='1'") == '') {
		return false;
	}

	return true;
}

function syslog_poller_bottom() {
	global $config;

	$p = dirname(__FILE__);
	$command_string = read_config_option("path_php_binary");
	$extra_args = ' -q ' . $config['base_path'] . '/plugins/syslog/syslog_process.php';
	exec_background($command_string, $extra_args);
}

function syslog_install_advisor($syslog_exists, $db_version) {
	global $config, $colors, $syslog_retentions;

	include($config["include_path"] . "/top_header.php");

	syslog_config_arrays();

	$fields_syslog_update = array(
		"upgrade_type" => array(
			"method" => "drop_array",
			"friendly_name" => "What upgrade/install type do you wish to use",
			"description" => "When you have very large tables, performing a Truncate will be much quicker.  If you are
			concerned about archive data, you can choose either Inline, which will freeze your browser for the period
			of this upgrade, or background, which will create a background process to bring your old syslog data
			from a backup table to the new syslog format.  Again this process can take several hours.",
			"value" => "truncate",
			"array" => array("truncate" => "Truncate Syslog Table", "inline" => "Inline Upgrade", "background" => "Background Upgrade"),
		),
		"engine" => array(
			"method" => "drop_array",
			"friendly_name" => "Database Storage Engine",
			"description" => "In MySQL 5.1.6 and above, you have the option to make this a partitioned table by days.  Prior to this
			release, you only have the traditional table structure available.",
			"value" => "myisam",
			"array" => array("myisam" => "MyISAM Storage", "innodb" => "InnoDB Storage"),
		),
		"db_type" => array(
			"method" => "drop_array",
			"friendly_name" => "Database Architecutre",
			"description" => "In MySQL 5.1.6 and above, you have the option to make this a partitioned table by days.
				In MySQL 5.5 and above, you can create multiple partitions per day.
				Prior to MySQL 5.1.6, you only have the traditional table structure available.",
			"value" => "trad",
			"array" => ($db_version >= "5.1" ? array("trad" => "Traditional Table", "part" => "Partitioined Table"): array("trad" => "Traditional Table")),
		),
		"days" => array(
			"method" => "drop_array",
			"friendly_name" => "Retention Policy",
			"description" => "Choose how many days of Syslog values you wish to maintain in the database.",
			"value" => "30",
			"array" => $syslog_retentions
		),
		"mode" => array(
			"method" => "hidden",
			"value" => "install"
		),
		"id" => array(
			"method" => "hidden",
			"value" => "syslog"
		)
	);

	if ($db_version >= 5.5) {
		$fields_syslog_update["dayparts"] = array(
			"method" => "drop_array",
			"friendly_name" => "Partitions per Day",
			"description" => "Select the number of partitions per day that you wish to create.",
			"value" => "1",
			"array" => array("1" => "1 Per Day", "2" => "2 Per Day", "4" => "4 Per Day",
				"6" => "6 Per Day", "12" => "12 Per Day")
		);
	}

	if ($syslog_exists) {
		$type = "Upgrade";
	}else{
		$type = "Install";
	}

	print "<table align='center' width='80%'><tr><td>\n";
	html_start_box("<strong>Syslog " . $type . " Advisor</strong>", "100%", $colors["header"], "3", "center", "");
	print "<tr><td bgcolor='#FFFFFF'>\n";
	if ($syslog_exists) {
		print "<h2 style='color:red;'>WARNING: Syslog Upgrade is Time Consuming!!!</h2>\n";
		print "<p>The upgrade of the 'main' syslog table can be a very time consuming process.  As such, it is recommended
			that you either reduce the size of your syslog table prior to upgrading, or choose the background option</p>
			<p>If you choose the background option, your legacy syslog table will be renamed, and a new syslog table will
			be created.  Then, an upgrade process will be launched in the background.  Again, this background process can
			quite a bit of time to complete.  However, your data will be preserved</p>
			<p>Regardless of your choice,
			all existing removal and alert rules will be maintained during the upgrade process.</p>
			<p>Press <b>'Upgrade'</b> to proceed with the upgrade, or <b>'Cancel'</b> to return to the Plugins menu.</p>
			</td></tr>";
	}else{
		unset($fields_syslog_update["upgrade_type"]);
		print "<p>You have several options to choose from when installing Syslog.  The first is the Database Architecture.
			Starting with MySQL 5.1.6, you can elect to utilize Table Partitioning to prevent the size of the tables
			from becomming excessive thus slowing queries.</p>
			<p>You can also set the MySQL storage engine.  If you have not tuned you system for InnoDB storage properties,
			it is strongly recommended that you utilize the MyISAM storage engine.</p>
			<p>Can can also select the retention duration.  Please keeep in mind that if you have several hosts logging
			to syslog, this table can become quite large.  So, if not using partitioning, you might want to keep the size
			smaller.
			</td></tr>";
	}
	html_end_box();
	print "<form action='plugins.php' method='get'>\n";
	html_start_box("<strong>Syslog " . $type . " Settings</strong>", "100%", $colors["header"], "3", "center", "");
	draw_edit_form(array(
		"config" => array(),
		"fields" => inject_form_variables($fields_syslog_update, array()))
		);
	html_end_box();
	syslog_confirm_button("install", "plugins.php", $syslog_exists);
	print "</td></tr></table>\n";
	exit;
}

function syslog_uninstall_advisor() {
	global $config, $colors;

	include(dirname(__FILE__) . "/config.php");

	syslog_connect();

	$syslog_exists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `" . $syslogdb_default . "` LIKE 'syslog'"));

	include($config["include_path"] . "/top_header.php");

	$fields_syslog_update = array(
		"uninstall_method" => array(
			"method" => "drop_array",
			"friendly_name" => "What uninstall method do you want to use?",
			"description" => "When uninstalling syslog, you can remove everything, or only components, just in
			case you plan on re-installing in the future.",
			"value" => "all",
			"array" => array("all" => "Remove Everything (Logs, Tables, Settings)", "syslog" => "Syslog Data Only"),
		),
		"mode" => array(
			"method" => "hidden",
			"value" => "uninstall"
		),
		"id" => array(
			"method" => "hidden",
			"value" => "syslog"
		)
	);

	print "<form action='plugins.php' method='get'>\n";
	print "<table align='center' width='80%'><tr><td>\n";
	html_start_box("<strong>Syslog Uninstall Preferences</strong>", "100%", $colors["header"], "3", "center", "");
	draw_edit_form(array(
		"config" => array(),
		"fields" => inject_form_variables($fields_syslog_update, array()))
		);
	html_end_box();
	syslog_confirm_button("uninstall", "plugins.php", $syslog_exists);
	print "</td></tr></table>\n";
	exit;
}

function syslog_confirm_button($action, $cancel_url, $syslog_exists) {
	if ($action == 'install' ) {
		if ($syslog_exists) {
			$value = 'Upgrade';
		}else{
			$value = 'Install';
		}
	}else{
		$value = 'Uninstall';
	}

	?>
	<table align='center' width='100%' style='background-color: #ffffff; border: 1px solid #bbbbbb;'>
		<tr>
			<td bgcolor="#f5f5f5" align="right">
				<input name='<?php print ($syslog_exists ? 'return':'cancel')?>' type='submit' value='Cancel'>
				<input name='<?php print $action;?>' type='submit' value='<?php print $value;?>'>
			</td>
		</tr>
	</table>
	</form>
	<?php
}

function syslog_config_settings() {
	global $tabs, $settings, $syslog_retentions, $syslog_alert_retentions, $syslog_refresh;

	$settings["visual"]["syslog_header"] = array(
		"friendly_name" => "Syslog Settings",
		"method" => "spacer"
	);
	$settings["visual"]["num_rows_syslog"] = array(
		"friendly_name" => "Rows Per Page",
		"description" => "The number of rows to display on a single page for viewing Syslog Events.",
		"method" => "textbox",
		"default" => "30",
		"max_length" => "3"
	);

	$tabs["syslog"] = "Syslog";

	$temp = array(
		"syslog_header" => array(
			"friendly_name" => "General Settings",
			"method" => "spacer",
		),
		"syslog_enabled" => array(
			"friendly_name" => "Syslog Enabled",
			"description" => "If this checkbox is set, records will be transferred from the Syslog Incoming table to the
			main syslog table and Alerts and Reports will be enabled.  Please keep in mind that if the system is disabled
			log entries will still accumulate into the Syslog Incoming table as this is defined by the rsyslog or syslog-ng
			process.",
			"method" => "checkbox",
			"default" => "on"
		),
		"syslog_domains" => array(
			"friendly_name" => "Strip Domains",
			"description" => "A comma delimited list of domains that you wish to remove from the syslog hostname,
			Examples would be 'mydomain.com, otherdomain.com'",
			"method" => "textbox",
			"default" => "",
			"size" => 80,
			"max_length" => 255,
		),
		"syslog_validate_hostname" => array(
			"friendly_name" => "Validate Hostnames",
			"description" => "If this checkbox is set, all hostnames are validated.  If the hostname is not valid. All records are assigned
			to a special host called 'invalidhost'.  This setting can impact syslog processing time on large systems.  Therefore, use of this
			setting should only be used when other means are not in place to prevent this from happening.",
			"method" => "checkbox",
			"default" => ""
		),
		"syslog_refresh" => array(
			"friendly_name" => "Refresh Interval",
			"description" => "This is the time in seconds before the page refreshes.",
			"method" => "drop_array",
			"default" => "300",
			"array" => $syslog_refresh
		),
		"syslog_maxrecords" => array(
			"friendly_name" => "Max Report Records",
			"description" => "For Threshold based Alerts, what is the maxiumum number that you wish to
			show in the report.  This is used to limit the size of the html log and e-mail.",
			"method" => "drop_array",
			"default" => "100",
			"array" => array("20" => "20 Records", "40" => "40 Records", "60" => "60 Records", "100" => "100 Records", "200" => "200 Records", "400" => "400 Records")
		),
		"syslog_retention" => array(
			"friendly_name" => "Syslog Retention",
			"description" => "This is the number of days to keep events.",
			"method" => "drop_array",
			"default" => "30",
			"array" => $syslog_retentions
		),
		"syslog_alert_retention" => array(
			"friendly_name" => "Syslog Alert Retention",
			"description" => "This is the number of days to keep alert logs.",
			"method" => "drop_array",
			"default" => "30",
			"array" => $syslog_alert_retentions
		),
		"syslog_html" => array(
			"friendly_name" => "HTML Based e-Mail",
			"description" => "If this checkbox is set, all e-mails will be sent in HTML format.  Otherwise, e-mails will be
			sent in plain text.",
			"method" => "checkbox",
			"default" => ""
		),
		"syslog_ticket_command" => array(
			"friendly_name" => "Command for Opening Tickets",
			"description" => "This command will be executed for opening Help Desk Tickets.  The command will be required to
			parse multiple input parameters as follows: <b>--alert-name</b>, <b>--severity</b>, <b>--hostlist</b>, <b>--message</b>.
			The hostlist will be a comma delimited list of hosts impacted by the alert.",
			"method" => "textbox",
			"max_length" => 255,
			"size" => 80
		),
		"syslog_email" => array(
			"friendly_name" => "From Email Address",
			"description" => "This is the email address that syslog alerts will appear from.",
			"method" => "textbox",
			"max_length" => 128,
		),
		"syslog_emailname" => array(
			"friendly_name" => "From Display Name",
			"description" => "This is the display name that syslog alerts will appear from.",
			"method" => "textbox",
			"max_length" => 128,
		),
		"syslog_bgcolors_header" => array(
			"friendly_name" => "Event Background Colors",
			"method" => "spacer",
		),
		"syslog_emerg_bg" => array(
			"friendly_name" => "Emergency",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_crit_bg" => array(
			"friendly_name" => "Critical",
			"description" => "",
			"default" => "42",
			"method" => "drop_color"
		),
		"syslog_alert_bg" => array(
			"friendly_name" => "Alert",
			"description" => "",
			"default" => "39",
			"method" => "drop_color"
		),
		"syslog_err_bg" => array(
			"friendly_name" => "Error",
			"description" => "",
			"default" => "29",
			"method" => "drop_color"
		),
		"syslog_warn_bg" => array(
			"friendly_name" => "Warning",
			"description" => "",
			"default" => "25",
			"method" => "drop_color"
		),
		"syslog_notice_bg" => array(
			"friendly_name" => "Notice",
			"description" => "",
			"default" => "63",
			"method" => "drop_color"
		),
		"syslog_info_bg" => array(
			"friendly_name" => "Info",
			"description" => "",
			"default" => "97",
			"method" => "drop_color"
		),
		"syslog_debug_bg" => array(
			"friendly_name" => "Debug",
			"description" => "",
			"default" => "50",
			"method" => "drop_color"
		),
		"syslog_other_bg" => array(
			"friendly_name" => "Other",
			"description" => "",
			"default" => "80",
			"method" => "drop_color"
		),
		"syslog_fgcolors_header" => array(
			"friendly_name" => "Event Text Colors",
			"method" => "spacer",
		),
		"syslog_emerg_fg" => array(
			"friendly_name" => "Emergency",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_crit_fg" => array(
			"friendly_name" => "Critical",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_alert_fg" => array(
			"friendly_name" => "Alert",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_err_fg" => array(
			"friendly_name" => "Error",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_warn_fg" => array(
			"friendly_name" => "Warning",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_notice_fg" => array(
			"friendly_name" => "Notice",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_info_fg" => array(
			"friendly_name" => "Info",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_debug_fg" => array(
			"friendly_name" => "Debug",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		),
		"syslog_other_fg" => array(
			"friendly_name" => "Other",
			"description" => "",
			"default" => "1",
			"method" => "drop_color"
		)
	);

	if (isset($settings["syslog"])) {
		$settings["syslog"] = array_merge($settings["syslog"], $temp);
	}else{
		$settings["syslog"] = $temp;
	}
}

function syslog_top_graph_refresh($refresh) {
	return $refresh;
}

function syslog_show_tab() {
	global $config;

	if (api_user_realm_auth('syslog.php')) {
		if (substr_count($_SERVER["REQUEST_URI"], "syslog.php")) {
			print '<a href="' . $config['url_path'] . 'plugins/syslog/syslog.php"><img src="' . $config['url_path'] . 'plugins/syslog/images/tab_syslog_down.gif" alt="syslog" align="absmiddle" border="0"></a>';
		}else{
			print '<a href="' . $config['url_path'] . 'plugins/syslog/syslog.php"><img src="' . $config['url_path'] . 'plugins/syslog/images/tab_syslog.gif" alt="syslog" align="absmiddle" border="0"></a>';
		}
	}
}

function syslog_config_arrays () {
	global $syslog_actions, $menu, $message_types, $severities;
	global $syslog_levels, $syslog_freqs, $syslog_times, $syslog_refresh;
	global $syslog_colors, $syslog_text_colors, $syslog_retentions, $syslog_alert_retentions;

	$syslog_actions = array(
		1 => "Delete",
		2 => "Disable",
		3 => "Enable"
		);

	$syslog_levels = array(
		1 => 'emerg',
		2 => 'crit',
		3 => 'alert',
		4 => 'err',
		5 => 'warn',
		6 => 'notice',
		7 => 'info',
		8 => 'debug',
		9 => 'other'
		);

	$syslog_retentions = array(
		"0" => "Indefinate",
		"1" => "1 Day",
		"2" => "2 Days",
		"3" => "3 Days",
		"4" => "4 Days",
		"5" => "5 Days",
		"6" => "6 Days",
		"7" => "1 Week",
		"14" => "2 Weeks",
		"30" => "1 Month",
		"60" => "2 Months",
		"90" => "3 Months",
		"120" => "4 Months",
		"160" => "5 Months",
		"183" => "6 Months",
		"365" => "1 Year"
		);

	$syslog_alert_retentions = array(
		"0" => "Indefinate",
		"1" => "1 Day",
		"2" => "2 Days",
		"3" => "3 Days",
		"4" => "4 Days",
		"5" => "5 Days",
		"6" => "6 Days",
		"7" => "1 Week",
		"14" => "2 Weeks",
		"30" => "1 Month",
		"60" => "2 Months",
		"90" => "3 Months",
		"120" => "4 Months",
		"160" => "5 Months",
		"183" => "6 Months",
		"365" => "1 Year"
		);

	$syslog_refresh = array(
		9999999 => "Never",
		"60" => "1 Minute",
		"120" => "2 Minutes",
		"300" => "5 Minutes",
		"600" => "10 Minutes"
		);

	$severities = array(
		"0" => "Notice",
		"1" => "Warning",
		"2" => "Critical"
	);

	if (!isset($_SESSION["syslog_colors"])) {
		foreach($syslog_levels as $level) {
			$syslog_colors[$level] = db_fetch_cell("SELECT hex FROM colors WHERE id=" . read_config_option("syslog_" . $level . "_bg"));
			$syslog_text_colors[$level] = db_fetch_cell("SELECT hex FROM colors WHERE id=" . read_config_option("syslog_" . $level . "_fg"));
		}

		$_SESSION["syslog_colors"] = $syslog_colors;
		$_SESSION["syslog_text_colors"] = $syslog_text_colors;
	}else{
		$syslog_colors = $_SESSION["syslog_colors"];
		$syslog_text_colors = $_SESSION["syslog_text_colors"];
	}

	$message_types = array(
		'messageb' => 'Begins with',
		'messagec' => 'Contains',
		'messagee' => 'Ends with',
		'host'     => 'Hostname is',
		'facility' => 'Facility is',
		'sql'      => 'SQL Expression');

	$syslog_freqs = array('86400' => 'Last Day', '604800' => 'Last Week');

	for ($i = 0; $i <= 86400; $i+=1800) {
		$minute = $i % 3600;
		if ($minute > 0) {
			$minute = "30";
		}else{
			$minute = "00";
		}

		if ($i > 0) {
			$hour = strrev(substr(strrev("00" . intval($i/3600)),0,2));
		}else{
			$hour = "00";
		}

		$syslog_times[$i] = $hour . ":" . $minute;
	}

	$menu2 = array ();
	foreach ($menu as $temp => $temp2 ) {
		$menu2[$temp] = $temp2;
		if ($temp == 'Import/Export') {
			$menu2["Syslog Settings"]['plugins/syslog/syslog_alerts.php'] = "Alert Rules";
			$menu2["Syslog Settings"]['plugins/syslog/syslog_removal.php'] = "Removal Rules";
			$menu2["Syslog Settings"]['plugins/syslog/syslog_reports.php'] = "Report Rules";
		}
	}
	$menu = $menu2;
}

function syslog_draw_navigation_text ($nav) {
	global $config;

	$nav["syslog.php:"]                = array("title" => "Syslog", "mapping" => "", "url" => $config['url_path'] . "plugins/syslog/syslog.php", "level" => "1");
	$nav["syslog_removal.php:"]        = array("title" => "Syslog Removals", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog_removal.php", "level" => "1");
	$nav["syslog_removal.php:edit"]    = array("title" => "(Edit)", "mapping" => "index.php:,syslog_removal.php:", "url" => "syslog_removal.php", "level" => "2");
	$nav["syslog_removal.php:newedit"] = array("title" => "(Edit)", "mapping" => "index.php:,syslog_removal.php:", "url" => "syslog_removal.php", "level" => "2");
	$nav["syslog_removal.php:actions"] = array("title" => "(Actions)", "mapping" => "index.php:,syslog_removal.php:", "url" => "syslog_removal.php", "level" => "2");

	$nav["syslog_alerts.php:"]         = array("title" => "Syslog Alerts", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog_alerts.php", "level" => "1");
	$nav["syslog_alerts.php:edit"]     = array("title" => "(Edit)", "mapping" => "index.php:,syslog_alerts.php:", "url" => "syslog_alerts.php", "level" => "2");
	$nav["syslog_alerts.php:newedit"]  = array("title" => "(Edit)", "mapping" => "index.php:,syslog_alerts.php:", "url" => "syslog_alerts.php", "level" => "2");
	$nav["syslog_alerts.php:actions"]  = array("title" => "(Actions)", "mapping" => "index.php:,syslog_alerts.php:", "url" => "syslog_alerts.php", "level" => "2");

	$nav["syslog_reports.php:"]        = array("title" => "Syslog Reports", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog_reports.php", "level" => "1");
	$nav["syslog_reports.php:edit"]    = array("title" => "(Edit)", "mapping" => "index.php:,syslog_reports.php:", "url" => "syslog_reports.php", "level" => "2");
	$nav["syslog_reports.php:actions"]  = array("title" => "(Actions)", "mapping" => "index.php:,syslog_reports.php:", "url" => "syslog_reports.php", "level" => "2");
	$nav["syslog.php:actions"]         = array("title" => "Syslog", "mapping" => "", "url" => $config['url_path'] . "plugins/syslog/syslog.php", "level" => "1");

	return $nav;
}

function syslog_config_insert() {
	syslog_connect();

	syslog_check_upgrade();
}

function syslog_graph_buttons($graph_elements = array()) {
	global $config, $timespan, $graph_timeshifts;

	include(dirname(__FILE__) . "/config.php");

	if ($_REQUEST["action"] == "view") return;

	if (isset($_REQUEST["graph_end"]) && strlen($_REQUEST["graph_end"])) {
		$date1 = date("Y-m-d H:i:s", $_REQUEST["graph_start"]);
		$date2 = date("Y-m-d H:i:s", $_REQUEST["graph_end"]);
	}else{
		$date1 = $timespan["current_value_date1"];
		$date2 = $timespan["current_value_date2"];
	}

	if (isset($graph_elements[1]["local_graph_id"])) {
		$graph_local = db_fetch_row("SELECT * FROM graph_local WHERE id='" . $graph_elements[1]["local_graph_id"] . "'");

		if (isset($graph_local["host_id"])) {
			$host = db_fetch_row("SELECT * FROM host WHERE id='" . $graph_local["host_id"] . "'");

			if (sizeof($host)) {
				$host = syslog_db_fetch_row("SELECT * FROM `" . $syslogdb_default . "`.`syslog_hosts` WHERE host LIKE '%%" . $host["hostname"] . "%%'");

				if (sizeof($host)) {
					print "<a href='" . $config["url_path"] . "plugins/syslog/syslog.php?tab=syslog&host%5B%5D=" . $host["host_id"] . "&date1=" . $date1 . "&date2=" . $date2 . "&efacility=0&elevel=0'><img src='" . $config['url_path'] . "plugins/syslog/images/view_syslog.gif' border='0' alt='Display Syslog in Range' title='Display Syslog in Range' style='padding: 3px;'></a><br>";
				}
			}
		}
	}
}

?>
