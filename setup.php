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

function plugin_init_syslog() {
	global $plugin_hooks;

	$plugin_hooks['top_header_tabs']['syslog']       = 'syslog_show_tab';
	$plugin_hooks['top_graph_header_tabs']['syslog'] = 'syslog_show_tab';
	$plugin_hooks['config_arrays']['syslog']         = 'syslog_config_arrays';
	$plugin_hooks['draw_navigation_text']['syslog']  = 'syslog_draw_navigation_text';
	$plugin_hooks['config_form']['syslog']           = 'syslog_config_form';
	$plugin_hooks['top_graph_refresh']['syslog']     = 'syslog_top_graph_refresh';
	$plugin_hooks['config_settings']['syslog']       = 'syslog_config_settings';
	$plugin_hooks['poller_bottom']['syslog']         = 'syslog_poller_bottom';

	/* add graph button that allows users to zoom to syslog messages */
	$plugin_hooks['graph_buttons']['syslog']         = 'syslog_graph_buttons';
}

function syslog_version () {
	return array(
		'name' 	=> 'syslog',
		'version' 	=> '0.5.3',
		'longname'	=> 'Syslog Monitoring',
		'author'	=> 'Jimmy Conner',
		'homepage'	=> 'http://cactiusers.org',
		'email'	=> 'jimmy@sqmail.org',
		'url'		=> 'http://versions.cactiusers.org/'
	);
}

function syslog_check_dependencies() {
	global $plugins;

	if (in_array('settings', $plugins)) {
		return true;
	}

	return false;
}

function syslog_poller_bottom() {
	global $config;

	$p              = dirname(__FILE__);
	$command_string = read_config_option("path_php_binary");

	if ($config["cacti_server_os"] == "unix") {
		$extra_args = "-q " . $p . "/syslog_process.php";
	} else {
		$extra_args = "-q " . strtolower($p . "/syslog_process.php");
	}

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
			"max_length" => 3,
		),
		"syslog_retention" => array(
			"friendly_name" => "Syslog Retention",
			"description" => "This is the number of days to keep events.  (0 - 365, 0 = unlimited)",
			"method" => "textbox",
			"max_length" => 3,
		),
		"syslog_hosts" => array(
			"friendly_name" => "Host Dropdown Rows",
			"description" => "The number of Host rows to display on the main syslog page.",
			"method" => "textbox",
			"max_length" => 3,
			"default" => 25
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
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_alert_bg" => array(
			"friendly_name" => "Alert",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_err_bg" => array(
			"friendly_name" => "Error",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_warn_bg" => array(
			"friendly_name" => "Warning",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_notice_bg" => array(
			"friendly_name" => "Notice",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_info_bg" => array(
			"friendly_name" => "Info",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_debug_bg" => array(
			"friendly_name" => "Debug",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_other_bg" => array(
			"friendly_name" => "Other",
			"description" => "",
			"default" => "9",
			"method" => "drop_color"
		),
		"syslog_fgcolors_header" => array(
			"friendly_name" => "Event Text Colors",
			"method" => "spacer",
		),
		"syslog_emer_fg" => array(
			"friendly_name" => "Emergency",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_crit_fg" => array(
			"friendly_name" => "Critical",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_alert_fg" => array(
			"friendly_name" => "Alert",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_err_fg" => array(
			"friendly_name" => "Error",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_warn_fg" => array(
			"friendly_name" => "Warning",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_notice_fg" => array(
			"friendly_name" => "Notice",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_info_fg" => array(
			"friendly_name" => "Info",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_debug_fg" => array(
			"friendly_name" => "Debug",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		),
		"syslog_other_fg" => array(
			"friendly_name" => "Other",
			"description" => "",
			"default" => "0",
			"method" => "drop_color"
		)
	);

	if (isset($settings["syslog"])) {
		$settings["syslog"] = array_merge($settings["syslog"], $temp);
	}else{
		$settings["syslog"] = $temp;
	}
}

function syslog_config_form () {
	global $fields_syslog_alert_edit, $fields_reports_edit, $fields_syslog_removal_edit;
	global $message_types;

	/* file: syslog_alerts.php, action: edit */
	$fields_syslog_alert_edit = array(
	"spacer0" => array(
		"method" => "spacer",
		"friendly_name" => "Alert Details"
		),
	"name" => array(
		"method" => "textbox",
		"friendly_name" => "Alert Name",
		"description" => "Please describe this Alert.",
		"value" => "|arg1:name|",
		"max_length" => "250",
		"size" => 80
		),
	"enabled" => array(
		"method" => "drop_array",
		"friendly_name" => "Enabled?",
		"description" => "Is this Alert Enabled?",
		"value" => "|arg1:enabled|",
		"array" => array("on" => "Enabled", "" => "Disabled"),
		"default" => "on"
		),
	"type" => array(
		"method" => "drop_array",
		"friendly_name" => "String Match Type",
		"description" => "Define how you would like this string matched.",
		"value" => "|arg1:type|",
		"array" => $message_types,
		"default" => "matchesc"
		),
	"message" => array(
		"method" => "textbox",
		"friendly_name" => "Syslog Message Match String",
		"description" => "The matching component of the syslog message.",
		"value" => "|arg1:message|",
		"default" => "",
		"max_length" => "255",
		"size" => 80
		),
	"email" => array(
		"method" => "textarea",
		"friendly_name" => "E-Mails to Notify",
		"textarea_rows" => "5",
		"textarea_cols" => "60",
		"description" => "Please enter a comma delimited list of e-mail addresses to inform.",
		"value" => "|arg1:email|",
		"max_length" => "255"
		),
	"notes" => array(
		"friendly_name" => "Alert Notes",
		"textarea_rows" => "5",
		"textarea_cols" => "60",
		"description" => "Space for Notes on the Alert",
		"method" => "textarea",
		"value" => "|arg1:notes|",
		"default" => "",
		),
	"id" => array(
		"method" => "hidden_zero",
		"value" => "|arg1:id|"
		),
	"_id" => array(
		"method" => "hidden_zero",
		"value" => "|arg1:id|"
		),
	"save_component_alert" => array(
		"method" => "hidden",
		"value" => "1"
		)
	);

	/* file: syslog_removal.php, action: edit */
	$fields_syslog_removal_edit = array(
	"spacer0" => array(
		"method" => "spacer",
		"friendly_name" => "Removel Rule Details"
		),
	"name" => array(
		"method" => "textbox",
		"friendly_name" => "Removal Rule Name",
		"description" => "Please describe this Removal Rule.",
		"value" => "|arg1:name|",
		"max_length" => "250"
		),
	"enabled" => array(
		"method" => "drop_array",
		"friendly_name" => "Enabled?",
		"description" => "Is this Removal Rule Enabled?",
		"value" => "|arg1:enabled|",
		"array" => array("on" => "Enabled", "" => "Disabled"),
		"default" => "on"
		),
	"type" => array(
		"method" => "drop_array",
		"friendly_name" => "String Match Type",
		"description" => "Define how you would like this string matched.",
		"value" => "|arg1:type|",
		"array" => $message_types,
		"default" => "matchesc"
		),
	"message" => array(
		"method" => "textbox",
		"friendly_name" => "Syslog Message Match String",
		"description" => "The matching component of the syslog message.",
		"value" => "|arg1:message|",
		"default" => "",
		"max_length" => "255"
		),
	"method" => array(
		"method" => "drop_array",
		"friendly_name" => "Method of Removal",
		"value" => "|arg1:method|",
		"array" => array("del" => "Deletion", "trans" => "Transferal"),
		"default" => "del"
		),
	"notes" => array(
		"friendly_name" => "Removal Rule Notes",
		"textarea_rows" => "5",
		"textarea_cols" => "60",
		"description" => "Space for Notes on the Removal rule",
		"method" => "textarea",
		"value" => "|arg1:notes|",
		"default" => "",
		),
	"id" => array(
		"method" => "hidden_zero",
		"value" => "|arg1:id|"
		),
	"_id" => array(
		"method" => "hidden_zero",
		"value" => "|arg1:id|"
		),
	"save_component_removal" => array(
		"method" => "hidden",
		"value" => "1"
		)
	);
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

	syslog_setup_table();
}

function syslog_config_arrays () {
	global $user_auth_realms, $user_auth_realm_filenames;
	global $syslog_actions, $menu, $message_types;
	global $syslog_levels;

	$user_auth_realms[37] = 'View Syslog';
	$user_auth_realm_filenames['syslog.php'] = 37;

	$user_auth_realms[38] = 'Configure Syslog Alerts / Reports';
	$user_auth_realm_filenames['syslog_alerts.php']   = 38;
	$user_auth_realm_filenames['syslog_removal.php'] = 38;
	$user_auth_realm_filenames['syslog_reports.php'] = 38;

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
		8 => 'debug'
		);

	$message_types = array(
		'messageb' => 'Begins with',
		'messagec' => 'Contains',
		'messagee' => 'Ends with',
		'host'     => 'Hostname is',
		'facility' => 'Facility is');

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

function syslog_setup_table() {
	global $config, $database_default, $database_hostname, $database_username;

	/* database connection information, must be loaded always */
	include($config["base_path"] . '/plugins/syslog/config.php');

	/* functions, must only be included once */
	include_once($config["base_path"] . '/plugins/syslog/functions.php');

	$tables  = array();

	/* Connect to the Syslog Database */
	if ((strtolower($database_hostname) == strtolower($syslogdb_hostname)) &&
		($database_default == $syslogdb_default)) {
		$rows = db_fetch_assoc("SHOW TABLES FROM `" . $syslogdb_default . "`");

		if (sizeof($rows)) {
		foreach($rows as $row) {
			$tables[] = $row["Tables_in_" . $syslogdb_default];
		}
		}

		$cacti_route = true;
	}else{
		$link = mysql_connect($syslogdb_hostname, $syslogdb_username, $syslogdb_password) or die('Could not connect tooo the database!');
		mysql_select_db($syslogdb_default) or die('Could not change default database');

		$sql    = "SHOW TABLES FROM `" . $syslogdb_default . "`";
		$query = mysql_query($sql) or die (mysql_error());

		while ($array = mysql_fetch_array($query, MYSQL_ASSOC)) {
			$tables[] = $array["Tables_in_" . $syslogdb_default];
		}

		$cacti_route = false;
	}

	/* create the reports table */
	if (!in_array('syslog_logs', $tables)) {
		$sql = "CREATE TABLE `" . $syslogdb_default . "`.`syslog_logs` (
			host varchar(32) default NULL,
			facility varchar(10) default NULL,
			priority varchar(10) default NULL,
			level varchar(10) default NULL,
			tag varchar(10) default NULL,
			date date default NULL,
			time time default NULL,
			program varchar(15) default NULL,
			msg text,
			seq int(10) unsigned NOT NULL auto_increment,
			PRIMARY KEY (seq),
			KEY host (host),
			KEY seq (seq),
			KEY program (program),
			KEY time (time),
			KEY date (date),
			KEY priority (priority),
			KEY facility (facility)
			) TYPE=MyISAM;";

		if ($cacti_route) {
			db_execute($sql);
		}else{
			$result = mysql_query($sql) or die (mysql_error());
		}
	}

	/* create the soft removal table */
	if (!in_array($syslog_config['syslogRemovedTable'], $tables)) {
		$sql = "CREATE TABLE `" . $syslogdb_default . "`.`" . $syslog_config['syslogRemovedTable'] . "`
			LIKE `" . $syslog_config['syslogTable'] . "`";

		if ($cacti_route) {
			db_execute($sql);
		}else{
			$result = mysql_query($sql) or die (mysql_error());
		}
	}

	/* create the host reference table */
	if (!in_array('syslog_hosts', $tables)) {
		$sql = "CREATE TABLE `" . $syslogdb_default . "`.`syslog_hosts` (
			`host` VARCHAR(128) NOT NULL,
			`last_updated` TIMESTAMP NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY (`host`),
			KEY last_updated (`last_updated`)
			) TYPE=MyISAM
			COMMENT='Contains all hosts currently in the syslog table'";

		if ($cacti_route) {
			db_execute($sql);
		}else{
			$result = mysql_query($sql) or die (mysql_error());
		}
	}

	/* check upgrade of syslog_alert */
	$sql     = "DESCRIBE syslog_alert";
	$columns = array();
	if ($cacti_route) {
		$array = db_fetch_assoc($sql);

		if (sizeof($array)) {
		foreach ($array as $row) {
			$columns[] = $array["Field"];
		}
		}
	}else{
		$query = mysql_query($sql) or die (mysql_error());

		while ($array = mysql_fetch_array($query, MYSQL_ASSOC)) {
			$columns[] = $array["Field"];
		}
	}

	if (!in_array("enabled", $columns)) {
		$sql = "alter table syslog_alert add column enabled char(2) default 'on' after type;";

		if ($cacti_route) {
			db_execute($sql);
		}else{
			mysql_query($sql) or die (mysql_error());
		}
	}

	/* check upgrade of syslog_alert */
	$sql     = "DESCRIBE syslog_remove";
	$columns = array();
	if ($cacti_route) {
		$array = db_fetch_assoc($sql);

		if (sizeof($array)) {
		foreach ($array as $row) {
			$columns[] = $array["Field"];
		}
		}
	}else{
		$query = mysql_query($sql) or die (mysql_error());

		while ($array = mysql_fetch_array($query, MYSQL_ASSOC)) {
			$columns[] = $array["Field"];
		}
	}

	if (!in_array("enabled", $columns)) {
		$sql = "alter table syslog_remove add column enabled char(2) default 'on' after type;";

		if ($cacti_route) {
			db_execute($sql);
		}else{
			mysql_query($sql) or die (mysql_error());
		}
	}

	if (!in_array("method", $columns)) {
		$sql = "alter table syslog_remove add column method char(5) default 'del' after enabled;";

		if ($cacti_route) {
			db_execute($sql);
		}else{
			mysql_query($sql) or die (mysql_error());
		}
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
				$sql = "SELECT host FROM `" . $syslogdb_default . "`.`" . $syslog_config["hostTable"] . "` WHERE host LIKE '%%" . $host["hostname"] . "%%'";

				if (sizeof(db_fetch_row($sql))) {
					print "<a href='" . $config["url_path"] . "plugins/syslog/syslog.php?host%5B%5D=" . $host["hostname"] . "&date1=" . $date1 . "&date2=" . $date2 . "&efacility=0&elevel=0'><img src='" . $config['url_path'] . "plugins/syslog/images/view_syslog.gif' border='0' alt='Display Syslog in Range' title='Display Syslog in Range' style='padding: 3px;'></a><br>";
				}
			}
		}
	}
}

if (!function_exists('api_user_realm_auth')) {
	include_once($config['base_path'] . '/plugins/syslog/compatibility.php');
}

?>
