<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2011 The Cacti Group                                 |
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

chdir('../../');
include("./include/auth.php");
include_once('plugins/syslog/functions.php');

define("MAX_DISPLAY_PAGES", 21);

/* set default action */
if (!isset($_REQUEST["action"])) { $_REQUEST["action"] = ""; }

switch ($_REQUEST["action"]) {
	case 'save':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'edit':
	case 'newedit':
		include_once($config['base_path'] . "/include/top_header.php");

		syslog_action_edit();

		include_once($config['base_path'] . "/include/bottom_footer.php");
		break;
	default:
		include_once($config['base_path'] . "/include/top_header.php");

		syslog_alerts();

		include_once($config['base_path'] . "/include/bottom_footer.php");
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset($_POST["save_component_alert"])) && (empty($_POST["add_dq_y"]))) {
		$alertid = api_syslog_alert_save($_POST["id"], $_POST["name"], $_POST["method"],
			$_POST["num"], $_POST["type"], $_POST["message"], $_POST["email"],
			$_POST["notes"], $_POST["enabled"], $_POST["severity"], $_POST["command"],
			$_POST["repeat_alert"], $_POST["open_ticket"]);

		if ((is_error_message()) || ($_POST["id"] != $_POST["_id"])) {
			header("Location: syslog_alerts.php?action=edit&id=" . (empty($id) ? $_POST["id"] : $id));
		}else{
			header("Location: syslog_alerts.php");
		}
	}
}

/* ------------------------
    The "actions" function
   ------------------------ */

function form_actions() {
	global $colors, $config, $syslog_actions, $fields_syslog_action_edit;

	include(dirname(__FILE__) . "/config.php");

	/* if we are to save this form, instead of display it */
	if (isset($_POST["selected_items"])) {
		$selected_items = unserialize(stripslashes($_POST["selected_items"]));

		if ($_POST["drp_action"] == "1") { /* delete */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_alert_remove($selected_items[$i]);
			}
		}else if ($_POST["drp_action"] == "2") { /* disable */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_alert_disable($selected_items[$i]);
			}
		}else if ($_POST["drp_action"] == "3") { /* enable */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_alert_enable($selected_items[$i]);
			}
		}

		header("Location: syslog_alerts.php");

		exit;
	}

	include_once($config['base_path'] . "/include/top_header.php");

	html_start_box("<strong>" . $syslog_actions{$_POST["drp_action"]} . "</strong>", "60%", $colors["header_panel"], "3", "center", "");

	print "<form action='syslog_alerts.php' method='post'>\n";

	/* setup some variables */
	$alert_array = array(); $alert_list = "";

	/* loop through each of the clusters selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (ereg("^chk_([0-9]+)$", $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$alert_info = syslog_db_fetch_cell("SELECT name FROM `" . $syslogdb_default . "`.`syslog_alert` WHERE id=" . $matches[1]);
			$alert_list .= "<li>" . $alert_info . "</li>";
			$alert_array[] = $matches[1];
		}
	}

	if (sizeof($alert_array)) {
		if ($_POST["drp_action"] == "1") { /* delete */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Alert Rule(s) will be deleted</p>
						<ul>$alert_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";

			$title = "Delete Syslog Alert Rule(s)";
		}else if ($_POST["drp_action"] == "2") { /* disable */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Alert Rule(s) will be disabled</p>
						<ul>$alert_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";

			$title = "Disable Syslog Alert Rule(s)";
		}else if ($_POST["drp_action"] == "3") { /* enable */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Alert Rule(s) will be enabled</p>
						<ul>$alert_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";

			$title = "Enable Syslog Alert Rule(s)";
		}

		$save_html = "<input type='button' value='Cancel' onClick='window.history.back()'>&nbsp;<input type='submit' value='Continue' title='$title'";
	}else{
		print "<tr><td bgcolor='#" . $colors["form_alternate1"]. "'><span class='textError'>You must select at least one Syslog Alert Rule.</span></td></tr>\n";
		$save_html = "<input type='button' value='Return' onClick='window.history.back()'>";
	}

	print "	<tr>
			<td align='right' bgcolor='#eaeaea'>
				<input type='hidden' name='action' value='actions'>
				<input type='hidden' name='selected_items' value='" . (isset($alert_array) ? serialize($alert_array) : '') . "'>
				<input type='hidden' name='drp_action' value='" . $_POST["drp_action"] . "'>
				$save_html
			</td>
		</tr>";

	html_end_box();

	include_once($config['base_path'] . "/include/bottom_footer.php");
}

function api_syslog_alert_save($id, $name, $method, $num, $type, $message, $email, $notes,
	$enabled, $severity, $command, $repeat_alert, $open_ticket) {
	include(dirname(__FILE__) . "/config.php");

	/* get the username */
	$username = db_fetch_cell("select username from user_auth where id=" . $_SESSION["sess_user_id"]);

	if ($id) {
		$save["id"] = $id;
	}else{
		$save["id"] = "";
	}

	$save["name"]            = form_input_validate($name,            "name",     "", false, 3);
	$save["num"]             = form_input_validate($num,             "num",      "", false, 3);
	$save["message"]         = form_input_validate($message,         "message",  "", false, 3);
	$save["email"]           = form_input_validate(trim($email),     "email",    "", true, 3);
	$save["command"]         = form_input_validate($command,         "command",  "", true, 3);
	$save["notes"]           = form_input_validate($notes,           "notes",    "", true, 3);
	$save["enabled"]         = ($enabled == "on" ? "on":"");
	$save["repeat_alert"]    = form_input_validate($repeat_alert,    "repeat_alert", "", true, 3);
	$save["open_ticket"]     = ($open_ticket == "on" ? "on":"");
	$save["type"]            = $type;
	$save["severity"]        = $severity;
	$save["method"]          = $method;
	$save["user"]            = $username;
	$save["date"]            = time();

	//print "<pre>";print_r($save);print "</pre>";exit;

	if (!is_error_message()) {
		$id = 0;
		$id = syslog_sql_save($save, "`" . $syslogdb_default . "`.`syslog_alert`", "id");
		if ($id) {
			raise_message(1);
		}else{
			raise_message(2);
		}
	}

	return $id;
}

function api_syslog_alert_remove($id) {
	include(dirname(__FILE__) . "/config.php");
	syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog_alert` WHERE id='" . $id . "'");
}

function api_syslog_alert_disable($id) {
	include(dirname(__FILE__) . "/config.php");
	syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_alert` SET enabled='' WHERE id='" . $id . "'");
}

function api_syslog_alert_enable($id) {
	include(dirname(__FILE__) . "/config.php");
	syslog_db_execute("UPDATE `" . $syslogdb_default . "`.`syslog_alert` SET enabled='on' WHERE id='" . $id . "'");
}

/* ---------------------
    Alert Functions
   --------------------- */

function syslog_get_alert_records(&$sql_where, $row_limit) {
	include(dirname(__FILE__) . "/config.php");

	if (get_request_var_request("filter") != "") {
		$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") .
			"(message LIKE '%%" . get_request_var_request("filter") . "%%' OR " .
			"email LIKE '%%" . get_request_var_request("filter") . "%%' OR " .
			"notes LIKE '%%" . get_request_var_request("filter") . "%%' OR " .
			"name LIKE '%%" . get_request_var_request("filter") . "%%')";
	}

	if (get_request_var_request("enabled") == "-1") {
		// Display all status'
	}elseif (get_request_var_request("enabled") == "1") {
		$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") .
			"enabled='on'";
	}else{
		$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") .
			"enabled=''";
	}

	$query_string = "SELECT *
		FROM `" . $syslogdb_default . "`.`syslog_alert`
		$sql_where
		ORDER BY ". get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") .
		" LIMIT " . ($row_limit*(get_request_var_request("page")-1)) . "," . $row_limit;

	return syslog_db_fetch_assoc($query_string);
}

function syslog_action_edit() {
	global $colors, $message_types, $severities;

	include(dirname(__FILE__) . "/config.php");

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("type"));
	/* ==================================================== */

	if (isset($_GET["id"]) && $_GET["action"] == "edit") {
		$alert = syslog_db_fetch_row("SELECT *
			FROM `" . $syslogdb_default . "`.`syslog_alert`
			WHERE id=" . $_GET["id"]);
		$header_label = "[edit: " . $alert["name"] . "]";
	}else if (isset($_GET["id"]) && $_GET["action"] == "newedit") {
		$syslog_rec = syslog_db_fetch_row("SELECT * FROM `" . $syslogdb_default . "`.`syslog` WHERE seq=" . $_GET["id"] . " AND logtime='" . $_GET["date"] . "'");

		$header_label = "[new]";
		if (sizeof($syslog_rec)) {
			$alert["message"] = $syslog_rec["message"];
		}
		$alert["name"]    = "New Alert Rule";
	}else{
		$header_label = "[new]";

		$alert["name"] = "New Alert Rule";
	}

	$alert_retention = read_config_option("syslog_alert_retention");
	if ($alert_retention != '' && $alert_retention > 0 && $alert_retention < 365) {
		$repeat_end = ($alert_retention * 24 * 60) / 5;
	}

	$repeatarray = array(
		0 => 'Not Set', 
		1 => '5 Minutes', 
		2 => '10 Minutes', 
		3 => '15 Minutes', 
		4 => '20 Minutes', 
		6 => '30 Minutes', 
		8 => '45 Minutes', 
		12 => '1 Hour', 
		24 => '2 Hours', 
		36 => '3 Hours', 
		48 => '4 Hours', 
		72 => '6 Hours', 
		96 => '8 Hours', 
		144 => '12 Hours', 
		288 => '1 Day', 
		576 => '2 Days', 
		2016 => '1 Week', 
		4032 => '2 Weeks', 
		8640 => 'Month');

	if ($repeat_end) {
		foreach ($repeatarray as $i => $value) {
			if ($i > $repeat_end) {
				unset($repeatarray[$i]);
			}
		}
	}

	html_start_box("<strong>Alert Edit</strong> $header_label", "100%", $colors["header"], "3", "center", "");

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
	"severity" => array(
		"method" => "drop_array",
		"friendly_name" => "Severity",
		"description" => "What is the Severity Level of this Alert?",
		"value" => "|arg1:severity|",
		"array" => $severities,
		"default" => "1"
		),
	"method" => array(
		"method" => "drop_array",
		"friendly_name" => "Reporting Method",
		"description" => "Define how to Alert on the syslog messages.",
		"value" => "|arg1:method|",
		"array" => array("0" => "Individual", "1" => "Threshold"),
		"default" => "0"
		),
	"num" => array(
		"method" => "textbox",
		"friendly_name" => "Threshold",
		"description" => "For the 'Threshold' method, If the number seen is above this value
		an Alert will be triggered.",
		"value" => "|arg1:num|",
		"size" => "4",
		"max_length" => "10",
		"default" => "1"
		),
	"type" => array(
		"method" => "drop_array",
		"friendly_name" => "String Match Type",
		"description" => "Define how you would like this string matched.  If using the SQL Expression type you may use any valid SQL expression
		to generate the alarm.  Available fields include 'message', 'facility', 'priority', and 'host'.",
		"value" => "|arg1:type|",
		"array" => $message_types,
		"on_change" => "changeTypes()",
		"default" => "matchesc"
		),
	"message" => array(
		"friendly_name" => "Syslog Message Match String",
		"description" => "The matching component of the syslog message.",
		"textarea_rows" => "2",
		"textarea_cols" => "70",
		"method" => "textarea",
		"class" => "textAreaNotes",
		"value" => "|arg1:message|",
		"default" => ""
		),
	"enabled" => array(
		"method" => "drop_array",
		"friendly_name" => "Alert Enabled",
		"description" => "Is this Alert Enabled?",
		"value" => "|arg1:enabled|",
		"array" => array("on" => "Enabled", "" => "Disabled"),
		"default" => "on"
		),
	"repeat_alert" => array(
		"friendly_name" => "Re-Alert Cycle",
		"method" => "drop_array",
		"array" => $repeatarray,
		"default" => "0",
 		"description" => "Do not resend this alert again for the same host, until this amount of time has elapsed. For threshold
		based alarms, this applies to all hosts.",
		"value" => "|arg1:repeat_alert|"
		),
	"notes" => array(
		"friendly_name" => "Alert Notes",
		"textarea_rows" => "5",
		"textarea_cols" => "70",
		"description" => "Space for Notes on the Alert",
		"method" => "textarea",
		"class" => "textAreaNotes",
		"value" => "|arg1:notes|",
		"default" => "",
		),
	"spacer1" => array(
		"method" => "spacer",
		"friendly_name" => "Alert Actions"
		),
	"open_ticket" => array(
		"method" => "drop_array",
		"friendly_name" => "Open Ticket",
		"description" => "Should a Help Desk Ticket be opened for this Alert",
		"value" => "|arg1:open_ticket|",
		"array" => array("on" => "Yes", "" => "No"),
		"default" => ""
		),
	"email" => array(
		"method" => "textarea",
		"friendly_name" => "E-Mails to Notify",
		"textarea_rows" => "5",
		"textarea_cols" => "70",
		"description" => "Please enter a comma delimited list of e-mail addresses to inform.  If you
		wish to send out e-mail to a recipient in SMS format, please prefix that recipient's e-mail address
		with <b>'sms@'</b>.  For example, if the recipients SMS address is <b>'2485551212@mycarrier.net'</b>, you would
		enter it as <b>'sms@2485551212@mycarrier.net'</b> and it will be formatted as an SMS message.",
		"class" => "textAreaNotes",
		"value" => "|arg1:email|",
		"max_length" => "255"
		),
	"command" => array(
		"friendly_name" => "Alert Command",
		"textarea_rows" => "5",
		"textarea_cols" => "70",
		"description" => "When an Alert is triggered, run the following command.  The following replacement variables
		are available <b>'&lt;HOSTNAME&gt;'</b>, <b>'&lt;ALERTID&gt;'</b>, <b>'&lt;MESSAGE&gt;'</b>,
		<b>'&lt;FACILITY&gt;'</b>, <b>'&lt;PRIORITY&gt;'</b>, <b>'&lt;SEVERITY&gt;'</b>.  Please
		note that <b>'&lt;HOSTNAME&gt;'</b> is only available on individual thresholds.",
		"method" => "textarea",
		"class" => "textAreaNotes",
		"value" => "|arg1:command|",
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

	echo "<form method='post' autocomplete='off' onsubmit='changeTypes()' action='syslog_alerts.php' name='chk'>";

	draw_edit_form(array(
		"config" => array("no_form_tag" => true),
		"fields" => inject_form_variables($fields_syslog_alert_edit, (isset($alert) ? $alert : array()))
		));


	html_end_box();

	form_save_button("syslog_alerts.php", "", "id");

	?>
	<script type='text/javascript'>
	function changeTypes() {
		if (document.getElementById('type').value == 'sql') {
			document.getElementById('message').rows = 6;
		}else{
			document.getElementById('message').rows = 2;
		}
	}
	</script>
	<?php
}

function syslog_filter() {
	global $colors, $config, $item_rows;

	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="alert">
		<td>
			<table cellpadding="1" cellspacing="0">
				<tr>
					<td width="70">
						Enabled:&nbsp;
					</td>
					<td width="1">
						<select name="enabled" onChange="applyChange(document.alert)">
						<option value="-1"<?php if ($_REQUEST["enabled"] == "-1") {?> selected<?php }?>>All</option>
						<option value="1"<?php if ($_REQUEST["enabled"] == "1") {?> selected<?php }?>>Yes</option>
						<option value="0"<?php if ($_REQUEST["enabled"] == "0") {?> selected<?php }?>>No</option>
						</select>
					</td>
					<td width="45">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applyChange(document.alert)">
						<option value="-1"<?php if ($_REQUEST["rows"] == "-1") {?> selected<?php }?>>Default</option>
						<?php
							if (sizeof($item_rows) > 0) {
							foreach ($item_rows as $key => $value) {
								print '<option value="' . $key . '"'; if ($_REQUEST["rows"] == $key) { print " selected"; } print ">" . $value . "</option>\n";
							}
							}
						?>
						</select>
					</td>
					<td>
						&nbsp;<input type="submit" value="Go">
					</td>
					<td>
						&nbsp;<input type="submit" name="clear" value="Clear">
					</td>
				</tr>
			</table>
			<table cellpadding="1" cellspacing="0">
				<tr>
					<td width="70">
						Search:&nbsp;
					</td>
					<td width="1">
						<input type="text" name="filter" size="30" value="<?php print $_REQUEST["filter"];?>">
					</td>
				</tr>
			</table>
		</td>
		</form>
	</tr>
	<?php
}

function syslog_alerts() {
	global $colors, $syslog_actions, $config, $message_types, $severities;

	include(dirname(__FILE__) . "/config.php");

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("id"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("enabled"));
	input_validate_input_number(get_request_var_request("rows"));
	/* ==================================================== */

	/* clean up filter */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* if the user pushed the 'clear' button */
	if (isset($_REQUEST["clear"])) {
		kill_session_var("sess_syslog_alerts_page");
		kill_session_var("sess_syslog_alerts_rows");
		kill_session_var("sess_syslog_alerts_filter");
		kill_session_var("sess_syslog_alerts_enabled");
		kill_session_var("sess_syslog_alerts_sort_column");
		kill_session_var("sess_syslog_alerts_sort_direction");

		$_REQUEST["page"] = 1;
		unset($_REQUEST["filter"]);
		unset($_REQUEST["enabled"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = 0;
		$changed += syslog_check_changed("filter", "sess_syslog_alerts_filter");
		$changed += syslog_check_changed("enabled", "sess_syslog_alerts_enabled");
		$changed += syslog_check_changed("rows", "sess_syslog_alerts_rows");
		$changed += syslog_check_changed("sort_column", "sess_syslog_alerts_sort_column");
		$changed += syslog_check_changed("sort_direction", "sess_syslog_alerts_sort_direction");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_syslog_alerts_paage", "1");
	load_current_session_value("rows", "sess_syslog_alerts_rows", "-1");
	load_current_session_value("enabled", "sess_syslog_alerts_enabled", "-1");
	load_current_session_value("filter", "sess_syslog_alerts_filter", "");
	load_current_session_value("sort_column", "sess_syslog_alerts_sort_column", "name");
	load_current_session_value("sort_direction", "sess_syslog_alerts_sort_direction", "ASC");

	html_start_box("<strong>Syslog Alert Filters</strong>", "100%", $colors["header"], "3", "center", "syslog_alerts.php?action=edit");

	syslog_filter();

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	$sql_where = "";

	if ($_REQUEST["rows"] == "-1") {
		$row_limit = read_config_option("num_rows_syslog");
	}elseif ($_REQUEST["rows"] == -2) {
		$row_limit = 999999;
	}else{
		$row_limit = $_REQUEST["rows"];
	}

	$alerts = syslog_get_alert_records($sql_where, $row_limit);

	$rows_query_string = "SELECT COUNT(*)
		FROM `" . $syslogdb_default . "`.`syslog_alert`
		$sql_where";

	$total_rows = syslog_db_fetch_cell($rows_query_string);

	?>
	<script type="text/javascript">
	<!--
	function applyChange(objForm) {
		strURL = '?enabled=' + objForm.enabled.value;
		strURL = strURL + '&filter=' + objForm.filter.value;
		strURL = strURL + '&rows=' + objForm.rows.value;
		document.location = strURL;
	}
	-->
	</script>
	<?php

	/* generate page list */
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $row_limit, $total_rows, "syslog_alerts.php?filter=". $_REQUEST["filter"]);

	if ($total_rows > 0) {
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
					<td colspan='13'>
						<table width='100%' cellspacing='0' cellpadding='0' border='0'>
							<tr>
								<td align='left' class='textHeaderDark'>
									<strong>&lt;&lt; "; if ($_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='syslog_alerts.php?report=arp&page=" . ($_REQUEST["page"]-1) . "'>"; } $nav .= "Previous"; if ($_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
								</td>\n
								<td align='center' class='textHeaderDark'>
									Showing Rows " . ($total_rows == 0 ? "None" : (($row_limit*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $row_limit) || ($total_rows < ($row_limit*$_REQUEST["page"]))) ? $total_rows : ($row_limit*$_REQUEST["page"])) . " of $total_rows [$url_page_select]") . "
								</td>\n
								<td align='right' class='textHeaderDark'>
									<strong>"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "<a class='linkOverDark' href='syslog_alerts.php?report=arp&page=" . ($_REQUEST["page"]+1) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
								</td>\n
							</tr>
						</table>
					</td>
				</tr>\n";
	}else{
		$nav = "<tr bgcolor='#" . $colors["header"] . "' class='noprint'>
					<td colspan='22'>
						<table width='100%' cellspacing='0' cellpadding='0' border='0'>
							<tr>
								<td align='center' class='textHeaderDark'>
									No Rows Found
								</td>\n
							</tr>
						</table>
					</td>
				</tr>\n";
	}

	print $nav;

	$display_text = array(
		"name" => array("Alert<br>Name", "ASC"),
		"severity" => array("<br>Severity", "ASC"),
		"method" => array("<br>Method", "ASC"),
		"num" => array("Threshold<br>Count", "ASC"),
		"enabled" => array("<br>Enabled", "ASC"),
		"type" => array("Match<br>Type", "ASC"),
		"message" => array("Search<br>String", "ASC"),
		"email" => array("E-Mail<br>Addresses", "DESC"),
		"date" => array("Last<br>Modified", "ASC"),
		"user" => array("By<br>User", "DESC"));

	html_header_sort_checkbox($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);
	$i = 0;
	if (sizeof($alerts) > 0) {
		foreach ($alerts as $alert) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i, 'line' . $alert["id"]); $i++;
			form_selectable_cell("<a class='linkEditMain' href='" . $config['url_path'] . "plugins/syslog/syslog_alerts.php?action=edit&id=" . $alert["id"] . "'>" . (($_REQUEST["filter"] != "") ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $alert["name"]) : $alert["name"]) . "</a>", $alert["id"]);
			form_selectable_cell($severities[$alert["severity"]], $alert["id"]);
			form_selectable_cell(($alert["method"] == 1 ? "Threshold":"Individual"), $alert["id"]);
			form_selectable_cell(($alert["method"] == 1 ? $alert["num"]:"N/A"), $alert["id"]);
			form_selectable_cell((($alert["enabled"] == "on") ? "Yes" : "No"), $alert["id"]);
			form_selectable_cell($message_types[$alert["type"]], $alert["id"]);
			form_selectable_cell(title_trim($alert["message"],60), $alert["id"]);
			form_selectable_cell((substr_count($alert["email"], ",") ? "Multiple":$alert["email"]), $alert["id"]);
			form_selectable_cell(date("Y-m-d H:i:s", $alert["date"]), $alert["id"]);
			form_selectable_cell($alert["user"], $alert["id"]);
			form_checkbox_cell($alert["name"], $alert["id"]);
			form_end_row();
		}
	}else{
		print "<tr><td colspan='4'><em>No Syslog Alerts Defined</em></td></tr>";
	}
	html_end_box(false);

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($syslog_actions);
}

?>
