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

function plugin_syslog_install () {
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

	syslog_setup_table_new();
}

function plugin_syslog_uninstall () {
	global $config, $cnn_id, $syslog_incoming_config, $database_default, $database_hostname, $database_username, $syslog_cnn;

	/* database connection information, must be loaded always */
	include($config["base_path"] . '/plugins/syslog/config.php');
	include_once($config["base_path"] . '/plugins/syslog/functions.php');

	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_incoming`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_alert`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_remove`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_reports`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_facilities`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_logs`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_removed`", true, $syslog_cnn);
	db_execute("DROP TABLE IF EXISTS `" . $syslogdb_default . "`.`syslog_hosts`", true, $syslog_cnn);
}

function plugin_syslog_check_config () {
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

function syslog_check_upgrade() {
	global $config, $cnn_id, $syslog_cnn, $database_default;
	include_once($config["library_path"] . "/database.php");
	include_once($config["library_path"] . "/functions.php");

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'syslog.php', 'slslog_removal.php', 'syslog_alerts.php', 'syslog_reports.php');
	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$current = syslog_version();
	$current = $current['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='syslog'");

	if ($current != $old) {
		/* update realms for old versions */
		if ($old < "1.0" || $old = '') {
			api_plugin_register_realm('syslog', 'syslog.php', 'Plugin -> Syslog User', 1);
			api_plugin_register_realm('syslog', array('syslog_alerts.php', 'syslog_removal.php', 'syslog_reports.php'), 'Plugin -> Syslog Administration', 1);

			/* get the realm id's and change from old to new */
			$user  = db_fetch_cell("SELECT id FROM plugin_realms WHERE file='syslog.php'");
			$admin = db_fetch_cell("SELECT id FROM plugin_realms WHERE file='syslog_alerts.php'");

			if ($user >  0) {
				$users = db_fetch_assoc("SELECT user_id FROM user_auth_realm WHERE realm_id=37");
				if (sizeof($users)) {
				foreach($users as $u) {
					db_execute("INSERT INTO user_auth_realm
						(realm_id, user_id) VALUES ($user, " . $u["user_id"] . ")
						ON DUPLICATE KEY UPDATE realm_id=VALUES(realm_id)");
					db_execute("DELETE FROM user_auth_realm
						WHERE user_id=" . $u["user_id"] . "
						AND realm_id=$user");
				}
				}
			}

			if ($admin > 0) {
				$admins = db_fetch_assoc("SELECT user_id FROM user_auth_realm WHERE realm_id=38");
				if (sizeof($admins)) {
				foreach($admins as $user) {
					db_execute("INSERT INTO user_auth_realm
						(realm_id, user_id) VALUES ($admin, " . $user["user_id"] . ")
						ON DUPLICATE KEY UPDATE realm_id=VALUES(realm_id)");
					db_execute("DELETE FROM user_auth_realm
						WHERE user_id=" . $user["user_id"] . "
						AND realm_id=$admin");
				}
				}
			}

			/* change the structure of the syslog table for performance sake */
			$mysqlVersion = getMySQLVersion("syslog");
			if ($mysqlVersion > 5) {
				db_execute("ALTER TABLE syslog MODIFY COLUMN message varchar(1024) DEFAULT NULL, ADD COLUMN logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER priority, ADD INDEX logtime(logtime);", true, $syslog_cnn);
			}else{
				db_execute("ALTER TABLE syslog ADD COLUMN logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER priority, ADD INDEX logtime(logtime);", true, $syslog_cnn);
			}
			db_execute("UPDATE syslog SET logtime=TIMESTAMP(`date`, `time`)", true, $syslog_cnn);
			db_execute("ALTER TABLE syslog DROP COLUMN `date`, DROP COLUMN `time`", true, $syslog_cnn);

			/* get the database table names */
			$rows = db_fetch_assoc("SHOW TABLES FROM `" . $syslogdb_default . "`", false, $syslog_cnn);
			if (sizeof($rows)) {
			foreach($rows as $row) {
				$tables[] = $row["Tables_in_" . $syslogdb_default];
			}
			}

			/* create the reports table */
			if (!in_array('syslog_logs', $tables)) {
				db_execute("CREATE TABLE `" . $syslogdb_default . "`.`syslog_logs` (
					host varchar(32) default NULL,
					facility varchar(10) default NULL,
					priority varchar(10) default NULL,
					level varchar(10) default NULL,
					tag varchar(10) default NULL,
					logtime timestamp NOT NULL default '0000-00-00 00:00:00',
					program varchar(15) default NULL,
					msg " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " default NULL,
					seq int(10) unsigned NOT NULL auto_increment,
					PRIMARY KEY (seq),
					KEY host (host),
					KEY seq (seq),
					KEY program (program),
					KEY logtime (logtime),
					KEY priority (priority),
					KEY facility (facility)) TYPE=MyISAM;", true, $syslog_cnn);
			}

			/* create the soft removal table */
			if (!in_array("syslog_removed", $tables)) {
				db_execute("CREATE TABLE `" . $syslogdb_default . "`.`syslog_removed` LIKE `syslog`", true, $syslog_cnn);
			}

			/* create the soft removal table */
			if (!in_array("syslog_facilities", $tables)) {
				db_execute("CREATE TABLE  `". $syslogdb_default . "`.`syslog_facilities` (
					`host` varchar(128) NOT NULL,
					`facility` varchar(10) NOT NULL,
					PRIMARY KEY  (`host`,`facility`)) ENGINE=MyISAM;", true, $syslog_cnn);
			}

			/* create the host reference table */
			if (!in_array('syslog_hosts', $tables)) {
				db_execute("CREATE TABLE `" . $syslogdb_default . "`.`syslog_hosts` (
					`host` VARCHAR(128) NOT NULL,
					`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
					PRIMARY KEY (`host`),
					KEY last_updated (`last_updated`)
					) TYPE=MyISAM
					COMMENT='Contains all hosts currently in the syslog table'", true, $syslog_cnn);
			}

			/* check upgrade of syslog_alert */
			$sql     = "DESCRIBE syslog_alert";
			$columns = array();
			$array = db_fetch_assoc($sql, true, $syslog_cnn);

			if (sizeof($array)) {
			foreach ($array as $row) {
				$columns[] = $array["Field"];
			}
			}

			if (!in_array("enabled", $columns)) {
				db_execute("ALTER TABLE syslog_alert MODIFY COLUMN message varchar(128) DEFAULT NULL, ADD COLUMN enabled CHAR(2) DEFAULT 'on' AFTER type;", true, $syslog_cnn);
			}

			/* check upgrade of syslog_alert */
			$sql     = "DESCRIBE syslog_remove";
			$columns = array();
			$array = db_fetch_assoc($sql, true, $syslog_cnn);

			if (sizeof($array)) {
			foreach ($array as $row) {
				$columns[] = $array["Field"];
			}
			}

			if (!in_array("enabled", $columns)) {
				db_execute("ALTER TABLE syslog_remove MODIFY COLUMN message varchar(128) DEFAULT NULL, ADD COLUMN enabled CHAR(2) DEFAULT 'on' AFTER type;", true, $syslog_cnn);
			}

			if (!in_array("method", $columns)) {
				db_execute("ALTER TABLE syslog_remove ADD COLUMN method CHAR(5) DEFAULT 'del' AFTER enabled;", true, $syslog_cnn);
			}
		}

		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='syslog'");
	}
}

function getMySQLVersion($db = "cacti") {
	global $syslog_cnn;

	if ($db == "cacti") {
		$dbInfo = db_fetch_cell("SHOW GLOBAL VARIABLES LIKE 'version'");
	}else{
		$dbInfo = db_fetch_cell("SHOW GLOBAL VARIABLES LIKE 'version'", '', true, $syslog_cnn);
	}

	if (sizeof($dbInfo)) {
		return floatval($dbInfo["Value"]);
	}
	return "";
}

function syslog_setup_table_new() {
	global $config, $cnn_id, $syslog_incoming_config, $database_default, $database_hostname, $database_username, $syslog_cnn;

	/* database connection information, must be loaded always */
	include_once($config["base_path"] . '/plugins/syslog/config.php');
	include_once($config["base_path"] . '/plugins/syslog/functions.php');

	$tables  = array();

	/* Connect to the Syslog Database */
	if ((strtolower($database_hostname) == strtolower($syslogdb_hostname)) &&
		($database_default == $syslogdb_default)) {
		/* move on, using Cacti */
		$syslog_cnn = $cnn_id;
	}else{
		if (!isset($syslogdb_port)) {
			$syslogdb_port = "3306";
		}

		$syslog_cnn = db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type, $syslogdb_port);
	}

	$mysqlVersion = getMySQLVersion("syslog");

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog` (
		facility varchar(10) default NULL,
		priority varchar(10) default NULL,
		logtime TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
		host varchar(128) default NULL,
		message " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " NOT NULL default '',
		seq bigint unsigned NOT NULL auto_increment,
		PRIMARY KEY (seq),
		KEY logtime (logtime),
		KEY host (host),
		KEY priority (priority),
		KEY facility (facility)) TYPE=MyISAM;", true, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_alert` (
		id int(10) NOT NULL auto_increment,
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		message VARCHAR(128) NOT NULL default '',
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		email varchar(255) default NULL,
		notes varchar(255) default NULL,
		PRIMARY KEY (id)) TYPE=MyISAM;", true, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_incoming` (
		facility varchar(10) default NULL,
		priority varchar(10) default NULL,
		`date` date default NULL,
		`time` time default NULL,
		host varchar(128) default NULL,
		message " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " NOT NULL DEFAULT '',
		seq bigint unsigned NOT NULL auto_increment,
		`status` tinyint(4) NOT NULL default '0',
		PRIMARY KEY (seq),
		KEY `status` (`status`)) TYPE=MyISAM;", true, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_remove` (
		id int(10) NOT NULL auto_increment,
		name varchar(255) NOT NULL default '',
		`type` varchar(16) NOT NULL default '',
		enabled CHAR(2) DEFAULT 'on',
		method CHAR(5) DEFAULT 'del',
		message VARCHAR(128) NOT NULL default '',
		`user` varchar(32) NOT NULL default '',
		`date` int(16) NOT NULL default '0',
		notes varchar(255) default NULL,
		PRIMARY KEY (id)) TYPE=MyISAM;", true, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_reports` (
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
		PRIMARY KEY (id)) TYPE=MyISAM;", false, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_hosts` (
		`host` VARCHAR(128) NOT NULL,
		`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
		PRIMARY KEY (`host`),
		KEY last_updated (`last_updated`)
		) TYPE=MyISAM
		COMMENT='Contains all hosts currently in the syslog table'", true, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `". $syslogdb_default . "`.`syslog_facilities` (
		`host` varchar(128) NOT NULL,
		`facility` varchar(10) NOT NULL,
		PRIMARY KEY  (`host`,`facility`)) ENGINE=MyISAM;", true, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_removed` LIKE `" . $syslogdb_default . "`.`syslog`", true, $syslog_cnn);

	db_execute("CREATE TABLE IF NOT EXISTS `" . $syslogdb_default . "`.`syslog_logs` (
		host varchar(32) default NULL,
		facility varchar(10) default NULL,
		priority varchar(10) default NULL,
		level varchar(10) default NULL,
		tag varchar(10) default NULL,
		logtime timestamp NOT NULL default '0000-00-00 00:00:00',
		program varchar(15) default NULL,
		msg " . ($mysqlVersion > 5 ? "varchar(1024)":"text") . " default NULL,
		seq bigint unsigned NOT NULL auto_increment,
		PRIMARY KEY (seq),
		KEY host (host),
		KEY seq (seq),
		KEY program (program),
		KEY logtime (logtime),
		KEY priority (priority),
		KEY facility (facility)) TYPE=MyISAM;", true, $syslog_cnn);
}

function syslog_version () {
	return array(
		'name'     => 'syslog',
		'version'  => '1.0',
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

function syslog_config_settings() {
	global $tabs, $settings;

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
		"syslog_refresh" => array(
			"friendly_name" => "Refresh Interval",
			"description" => "This is the time in seconds before the page refreshes.  (1 - 300)",
			"method" => "textbox",
			"default" => "300",
			"max_length" => 3,
		),
		"syslog_retention" => array(
			"friendly_name" => "Syslog Retention",
			"description" => "This is the number of days to keep events.  (0 - 365, 0 = unlimited)",
			"method" => "textbox",
			"default" => "30",
			"max_length" => 3,
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
		"syslog_emer_bg" => array(
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
		"syslog_emer_fg" => array(
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
	if (basename($_SERVER["PHP_SELF"]) == "syslog_removal.php") {
		return 99999;
	}

	if (basename($_SERVER["PHP_SELF"]) == "syslog_alerts.php") {
		return 99999;
	}

	if (basename($_SERVER["PHP_SELF"]) != "syslog.php") {
		return $refresh;
	}

	$r = read_config_option("syslog_refresh");

	if ($r == '' or $r < 1) {
		return $refresh;
	}

	return $r;
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
	global $syslog_actions, $menu, $message_types;
	global $syslog_levels, $syslog_freqs, $syslog_times;
	global $syslog_colors, $syslog_text_colors;

	$syslog_actions = array(
		1 => "Delete",
		2 => "Disable",
		3 => "Enable"
		);

	$syslog_levels = array(
		1 => 'emer',
		2 => 'crit',
		3 => 'alert',
		4 => 'err',
		5 => 'warn',
		6 => 'notice',
		7 => 'info',
		8 => 'debug',
		9 => 'other'
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
		$syslot_text_colors = $_SESSION["syslog_text_colors"];
	}

	$message_types = array(
		'messageb' => 'Begins with',
		'messagec' => 'Contains',
		'messagee' => 'Ends with',
		'host'     => 'Hostname is',
		'facility' => 'Facility is');

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

	$nav["syslog.php:"]                = array("title" => "Syslog", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog.php", "level" => "1");
	$nav["syslog_removal.php:"]        = array("title" => "Syslog Removals", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog_removal.php", "level" => "1");
	$nav["syslog_removal.php:edit"]    = array("title" => "(Edit)", "mapping" => "index.php:,syslog_removal.php:", "url" => "syslog_removal.php", "level" => "2");
	$nav["syslog_removal.php:actions"] = array("title" => "(Actions)", "mapping" => "index.php:,syslog_removal.php:", "url" => "syslog_removal.php", "level" => "2");
	$nav["syslog_alerts.php:"]         = array("title" => "Syslog Alerts", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog_alerts.php", "level" => "1");
	$nav["syslog_alerts.php:edit"]     = array("title" => "(Edit)", "mapping" => "index.php:,syslog_alerts.php:", "url" => "syslog_alerts.php", "level" => "2");
	$nav["syslog_alerts.php:actions"]  = array("title" => "(Actions)", "mapping" => "index.php:,syslog_alerts.php:", "url" => "syslog_alerts.php", "level" => "2");
	$nav["syslog_reports.php:"]        = array("title" => "Syslog Reports", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog_reports.php", "level" => "1");
	$nav["syslog_reports.php:edit"]    = array("title" => "(Edit)", "mapping" => "index.php:,syslog_alerts.php:", "url" => "syslog_alerts.php", "level" => "2");
	$nav["syslog.php:actions"]         = array("title" => "Syslog", "mapping" => "index.php:", "url" => $config['url_path'] . "plugins/syslog/syslog.php", "level" => "1");

	return $nav;
}

function syslog_config_insert() {
	global $config, $cnn_id, $syslog_incoming_config, $database_default, $database_hostname, $database_username, $syslog_cnn;

	/* database connection information, must be loaded always */
	include($config["base_path"] . '/plugins/syslog/config.php');
	include_once($config["base_path"] . '/plugins/syslog/functions.php');

	/* Connect to the Syslog Database */
	if ((strtolower($database_hostname) == strtolower($syslogdb_hostname)) &&
		($database_default == $syslogdb_default)) {
		/* move on, using Cacti */
		$syslog_cnn = $cnn_id;
	}else{
		if (!isset($syslogdb_port)) {
			$syslogdb_port = "3306";
		}

		$syslog_cnn = db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type, $syslogdb_port);
	}
}

function syslog_graph_buttons($graph_elements = array()) {
	global $config, $timespan, $graph_timeshifts;

	include("./plugins/syslog/config.php");

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
				$sql = "SELECT host FROM `" . $syslogdb_default . "`.`syslog_hosts` WHERE host LIKE '%%" . $host["hostname"] . "%%'";

				if (sizeof(db_fetch_row($sql))) {
					print "<a href='" . $config["url_path"] . "plugins/syslog/syslog.php?host%5B%5D=" . $host["hostname"] . "&date1=" . $date1 . "&date2=" . $date2 . "&efacility=0&elevel=0'><img src='" . $config['url_path'] . "plugins/syslog/images/view_syslog.gif' border='0' alt='Display Syslog in Range' title='Display Syslog in Range' style='padding: 3px;'></a><br>";
				}
			}
		}
	}
}

?>
