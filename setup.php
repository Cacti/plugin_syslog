<?php
/*******************************************************************************

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
	$plugin_hooks['top_header_tabs']['syslog'] = 'syslog_show_tab';
	$plugin_hooks['top_graph_header_tabs']['syslog'] = 'syslog_show_tab';
	$plugin_hooks['config_arrays']['syslog'] = 'syslog_config_arrays';
	$plugin_hooks['draw_navigation_text']['syslog'] = 'syslog_draw_navigation_text';
	$plugin_hooks['config_form']['syslog'] = 'syslog_config_form';
	$plugin_hooks['top_graph_refresh']['syslog'] = 'syslog_top_graph_refresh';
	$plugin_hooks['config_settings']['syslog'] = 'syslog_config_settings';
	$plugin_hooks['poller_bottom']['syslog'] = 'syslog_poller_bottom';
}

function syslog_version () {
	return array( 'name' 	=> 'syslog',
			'version' 	=> '0.5.1',
			'longname'	=> 'Syslog Monitoring',
			'author'	=> 'Jimmy Conner',
			'homepage'	=> 'http://cactiusers.org',
			'email'	=> 'jimmy@sqmail.org',
			'url'		=> 'http://cactiusers.org/cacti/versions.php'
			);
}

function syslog_check_dependencies() {
	global $plugins;
	if (in_array('settings', $plugins))
		return true;
	return false;
}

function syslog_poller_bottom () {
	global $config;

	$p = dirname(__FILE__);
	$command_string = read_config_option("path_php_binary");
	if ($config["cacti_server_os"] == "unix") {
		$extra_args = "-q " . $p . "/syslog_process.php";
	} else {
		$extra_args = "-q " . strtolower($p . "/syslog_process.php");
	}
	exec_background($command_string, $extra_args);
}

function syslog_config_settings () {
	global $tabs, $settings;

	if (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) != 'settings.php')
		return;

	$settings["visual"]["syslog_header"] = array(
		"friendly_name" => "Syslog Events",
		"method" => "spacer"
		);
	$settings["visual"]["num_rows_syslog"] = array(
		"friendly_name" => "Rows Per Page",
		"description" => "The number of rows to display on a single page for viewing Syslog Events.",
		"method" => "textbox",
		"default" => "30",
		"max_length" => "3"
		);

	$tabs["misc"] = "Misc";

	$temp = array(
		"syslog_header" => array(
			"friendly_name" => "Syslog Events",
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

	);

	if (isset($settings["misc"]))
		$settings["misc"] = array_merge($settings["misc"], $temp);
	else
		$settings["misc"]=$temp;
}

function syslog_top_graph_refresh ($refresh) {
	if (basename($_SERVER["PHP_SELF"]) == "syslog_remove.php")
		return 99999;
	if (basename($_SERVER["PHP_SELF"]) == "syslog_alert.php")
		return 99999;
	if (basename($_SERVER["PHP_SELF"]) != "syslog.php")
		return $refresh;
	$r = read_config_option("syslog_refresh");
	if ($r == '' or $r < 1)
		return $refresh;
	return $r;
}

function syslog_show_tab () {
	global $config;
	if (api_user_realm_auth('syslog.php')) {
		print '<a href="' . $config['url_path'] . 'plugins/syslog/syslog.php"><img src="' . $config['url_path'] . 'plugins/syslog/images/tab_syslog.gif" alt="syslog" align="absmiddle" border="0"></a>';
	}
	syslog_setup_table();
}

function syslog_config_arrays () {
	global $user_auth_realms, $user_auth_realm_filenames;
	$user_auth_realms[37]='View Syslog';
	$user_auth_realm_filenames['syslog.php'] = 37;

	$user_auth_realms[38]='Configure Syslog Alerts / Reports';
	$user_auth_realm_filenames['syslog_alert.php'] = 38;
	$user_auth_realm_filenames['syslog_remove.php'] = 38;
	$user_auth_realm_filenames['syslog_reports.php'] = 38;
}

function syslog_draw_navigation_text ($nav) {
   $nav["syslog.php:"] = array("title" => "Syslog", "mapping" => "index.php:", "url" => "syslog.php", "level" => "1");
   $nav["syslog_remove.php:"] = array("title" => "Syslog Removals", "mapping" => "index.php:", "url" => "syslog_remove.php", "level" => "1");
   $nav["syslog_alert.php:"] = array("title" => "Syslog Alerts", "mapping" => "index.php:", "url" => "syslog_alert.php", "level" => "1");
   $nav["syslog_reports.php:"] = array("title" => "Syslog Reports", "mapping" => "index.php:", "url" => "syslog_reports.php", "level" => "1");
   $nav["syslog.php:actions"] = array("title" => "Syslog", "mapping" => "index.php:", "url" => "syslog.php", "level" => "1");
   return $nav;
}

function syslog_setup_table () {
// Return for now until I fix the seperate database issue
return;
	global $config, $database_default;
	include_once($config["library_path"] . "/database.php");
	$sql = "show tables from `" . $database_default . "`";
	
	$result = db_fetch_assoc($sql) or die (mysql_error());

	$tables = array();

	foreach($result as $index => $arr) {
		foreach ($arr as $t) {
			$tables[] = $t;
		}
	}

	if (!in_array('syslog_logs', $tables)) {
		$sql = "CREATE TABLE syslog_logs (
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
		$result = mysql_query($sql) or die (mysql_error());
	}
}


if (!function_exists('api_user_realm_auth')) {
	include_once($config['base_path'] . '/plugins/syslog/compatibility.php');
}

