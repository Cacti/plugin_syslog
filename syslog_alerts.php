<?php
/*
 ex: set tabstop=4 shiftwidth=4 autoindent:
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007 The Cacti Group                                      |
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
		include_once($config['base_path'] . "/include/top_header.php");

		syslog_action_edit();

		include_once($config['base_path'] . "/include/bottom_footer.php");
		break;
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
		$alertid = api_syslog_alert_save($_POST["id"], $_POST["name"], $_POST["type"],
			$_POST["message"], $_POST["email"], $_POST["notes"], $_POST["enabled"]);

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
	global $colors, $config, $syslog_cnn, $syslog_actions, $fields_syslog_action_edit;

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

			$alert_info = db_fetch_cell("SELECT name FROM syslog_alert WHERE id=" . $matches[1], '', true, $syslog_cnn);
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
		}else if ($_POST["drp_action"] == "2") { /* disable */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Alert Rule(s) will be disabled</p>
						<ul>$alert_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";
		}else if ($_POST["drp_action"] == "3") { /* enable */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Alert Rule(s) will be enabled</p>
						<ul>$alert_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";
		}

		$save_html = "<input type='button' value='Cancel' onClick='window.history.back()'>&nbsp;<input type='submit' value='Continue' title='$title";
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

function api_syslog_alert_save($id, $name, $type, $message, $email, $notes, $enabled) {
	global $syslog_cnn;

	/* get the username */
	$username = db_fetch_cell("select username from user_auth where id=" . $_SESSION["sess_user_id"]);

	if ($id) {
		$save["id"] = $id;
	}else{
		$save["id"] = "";
	}

	$save["name"]    = form_input_validate($name,    "name",    "", false, 3);
	$save["type"]    = form_input_validate($type,    "type",    "", false, 3);
	$save["message"] = form_input_validate($message, "message", "", false, 3);
	$save["email"]   = form_input_validate($email,   "email",   "", false, 3);
	$save["notes"]   = form_input_validate($notes,   "notes",   "", true, 3);
	$save["enabled"] = form_input_validate($enabled, "enabled", "", false, 3);
	$save["date"]    = time();
	$save["user"]    = $username;

	$id = 0;
	$id = sql_save($save, "syslog_alert", "id", true, $syslog_cnn);
	if ($id) {
		raise_message(1);
	}else{
		raise_message(2);
	}

	return $id;
}

function api_syslog_alert_remove($id) {
	global $syslog_cnn;
	db_execute("DELETE FROM syslog_alert WHERE id='" . $id . "'", true, $syslog_cnn);
}

function api_syslog_alert_disable($id) {
	global $syslog_cnn;
	db_execute("UPDATE syslog_alert SET enabled='' WHERE id='" . $id . "'", true, $syslog_cnn);
}

function api_syslog_alert_enable($id) {
	global $syslog_cnn;
	db_execute("UPDATE syslog_alert SET enabled='on' WHERE id='" . $id . "'", true, $syslog_cnn);
}

/* ---------------------
    Alert Functions
   --------------------- */

function syslog_alert_remove() {
	global $config, $syslog_cnn;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	/* ==================================================== */

	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

	if (!isset($_GET["confirm"])) {
		include("./include/top_header.php");
		form_confirm("Are You Sure?", "Are you sure you want to delete the syslog alert<strong>'" . db_fetch_cell("SELECT name FORM syslog_alert WHERE id=" . $_GET["id"], '', true, $syslog_cnn) . "'</strong>?", "syslog_alerts.php", "syslog_alerts.php?action=remove&id=" . $_GET["id"]);
		include_once($config['base_path'] . "/include/bottom_footer.php");
		exit;
	}

	if (isset($_GET["confirm"])) {
		api_syslog_alert_remove($_GET["id"]);
	}
}

function syslog_get_alert_records() {
	global $syslog_cnn;

	$query_string = "SELECT *
		FROM syslog_alert
		ORDER BY " . $_REQUEST["sort_column"] . " " . $_REQUEST["sort_direction"];

	return db_fetch_assoc($query_string, true, $syslog_cnn);
}

function syslog_action_edit() {
	global $colors, $syslog_cnn, $message_types;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("type"));
	/* ==================================================== */

	if (isset($_GET["id"])) {
		$alert = db_fetch_row("SELECT *
			FROM syslog_alert
			WHERE id=" . $_GET["id"], true, $syslog_cnn);
		$header_label = "[edit: " . $alert["name"] . "]";
	}else{
		$header_label = "[new]";

		$alert["name"] = "New Alert Rule";
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

	draw_edit_form(array(
		"config" => array("form_name" => "chk"),
		"fields" => inject_form_variables($fields_syslog_alert_edit, (isset($alert) ? $alert : array()))
		));

	html_end_box();

	form_save_button("syslog_alerts.php", "", "id");
}

function syslog_alerts() {
	global $colors, $syslog_actions, $item_rows, $config, $message_types;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("id"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("enabled"));
	input_validate_input_number(get_request_var_request("rows_selector"));
	/* ==================================================== */

	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

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
	if (isset($_REQUEST["clear_x"])) {
		kill_session_var("sess_syslog_alerts_page");
		kill_session_var("sess_syslog_alerts_rows_selector");
		kill_session_var("sess_syslog_alerts_filter");
		kill_session_var("sess_syslog_alerts_enabled");
		kill_session_var("sess_syslog_alerts_sort_column");
		kill_session_var("sess_syslog_alerts_sort_direction");

		$_REQUEST["page"] = 1;
		unset($_REQUEST["filter"]);
		unset($_REQUEST["enabled"]);
		unset($_REQUEST["rows_selector"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = 0;
		$changed += check_changed("filter", "sess_syslog_alerts_filter");
		$changed += check_changed("enabled", "sess_syslog_alerts_enabled");
		$changed += check_changed("rows_selector", "sess_syslog_alerts_rows_selector");
		$changed += check_changed("sort_column", "sess_syslog_alerts_sort_column");
		$changed += check_changed("sort_direction", "sess_syslog_alerts_sort_direction");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_syslog_alerts_paage", "1");
	load_current_session_value("rows_selector", "sess_syslog_alerts_rows_selector", "20");
	load_current_session_value("enabled", "sess_syslog_alerts_enabled", "-1");
	load_current_session_value("filter", "sess_syslog_alerts_filter", "");
	load_current_session_value("sort_column", "sess_syslog_alerts_sort_column", "name");
	load_current_session_value("sort_direction", "sess_syslog_alerts_sort_direction", "ASC");

	html_start_box("<strong>Syslog Alert Filters</strong>", "100%", $colors["header"], "3", "center", "syslog_alerts.php?action=edit");

	include("plugins/syslog/html/syslog_alert_filter.php");

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	$sql_where = "";

	$alerts = syslog_get_alert_records($sql_where);

	$rows_query_string = "SELECT COUNT(*)
		FROM syslog_alert
		$sql_where";

	$total_rows = db_fetch_cell($rows_query_string);

	?>
	<script type="text/javascript">
	<!--
	function applyChange(objForm) {
		strURL = '?enabled=' + objForm.enabled.value;
		strURL = strURL + '&filter=' + objForm.filter.value;
		strURL = strURL + '&rows_selector=' + objForm.rows_selector.value;
		document.location = strURL;
	}
	-->
	</script>
	<?php

	/* generate page list */
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $_REQUEST["rows_selector"], $total_rows, "syslog_alerts.php");

	$nav = "<tr bgcolor='#" . $colors["header"] . "' class='noprint'>
				<td colspan='16'>
					<table width='100%' cellspacing='0' cellpadding='0' border='0'>
						<tr>
							<td align='left' class='textHeaderDark'>
								<strong>&lt;&lt; "; if ($_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='" . $config['url_path'] . "plugins/syslog/syslog_alerts.php?page=" . ($_REQUEST["page"]-1) . "'>"; } $nav .= "Previous"; if ($_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
							</td>\n
							<td align='center' class='textHeaderDark'>
								Showing Rows " . (($_REQUEST["rows_selector"]*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $_REQUEST["rows_selector"]) || ($total_rows < ($_REQUEST["rows_selector"]*$_REQUEST["page"]))) ? $total_rows : ($_REQUEST["rows_selector"]*$_REQUEST["page"])) . " of $total_rows [$url_page_select]
							</td>\n
							<td align='right' class='textHeaderDark'>
								<strong>"; if (($_REQUEST["page"] * $_REQUEST["rows_selector"]) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . $config['url_path'] . "plugins/syslog/syslog_alerts.php?page=" . ($_REQUEST["page"]+1) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $_REQUEST["rows_selector"]) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
							</td>\n
						</tr>
					</table>
				</td>
			</tr>\n";

	print $nav;

	$display_text = array(
		"name" => array("Alert<br>Name", "ASC"),
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
			form_selectable_cell("<a class='linkEditMain' href='" . $config['url_path'] . "plugins/syslog/syslog_alerts.php?action=edit&id=" . $alert["id"] . "'>" . (($_REQUEST["filter"] != "") ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim(htmlentities($data_source["name_cache"]), read_config_option("max_title_data_source"))) : htmlentities($alert["name"])) . "</a>", $alert["id"]);
			form_selectable_cell((($alert["enabled"] == "on") ? "Yes" : ""), $alert["id"]);
			form_selectable_cell($message_types[$alert["type"]], $alert["id"]);
			form_selectable_cell(title_trim($alert["message"],60), $alert["id"]);
			form_selectable_cell(str_replace(",",",<br>", $alert["email"]), $alert["id"]);
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
