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
	default:
		include_once($config['base_path'] . "/include/top_header.php");

		syslog_report();

		include_once($config['base_path'] . "/include/bottom_footer.php");
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset($_POST["save_component_report"])) && (empty($_POST["add_dq_y"]))) {
		$reportid = api_syslog_report_save($_POST["id"], $_POST["name"], $_POST["type"],
			$_POST["message"], $_POST["timespan"], $_POST["timepart"], $_POST["body"],
			$_POST["email"], $_POST["notes"], $_POST["enabled"]);

		if ((is_error_message()) || ($_POST["id"] != $_POST["_id"])) {
			header("Location: syslog_reports.php?action=edit&id=" . (empty($id) ? $_POST["id"] : $id));
		}else{
			header("Location: syslog_reports.php");
		}
	}
}

/* ------------------------
    The "actions" function
   ------------------------ */

function form_actions() {
	global $colors, $syslog_cnn, $config, $syslog_actions, $fields_syslog_action_edit;

	/* if we are to save this form, instead of display it */
	if (isset($_POST["selected_items"])) {
		$selected_items = unserialize(stripslashes($_POST["selected_items"]));

		if ($_POST["drp_action"] == "1") { /* delete */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_report_remove($selected_items[$i]);
			}
		}else if ($_POST["drp_action"] == "2") { /* disable */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_report_disable($selected_items[$i]);
			}
		}else if ($_POST["drp_action"] == "3") { /* enable */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_report_enable($selected_items[$i]);
			}
		}

		header("Location: syslog_reports.php");

		exit;
	}

	include_once($config['base_path'] . "/include/top_header.php");

	html_start_box("<strong>" . $syslog_actions{$_POST["drp_action"]} . "</strong>", "60%", $colors["header_panel"], "3", "center", "");

	print "<form action='syslog_reports.php' method='post'>\n";

	/* setup some variables */
	$report_array = array(); $report_list = "";

	/* loop through each of the clusters selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (ereg("^chk_([0-9]+)$", $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$report_info = db_fetch_cell("SELECT name FROM syslog_reports WHERE id=" . $matches[1], '', true, $syslog_cnn);
			$report_list  .= "<li>" . $report_info . "<br>";
			$report_array[] = $matches[1];
		}
	}

	if (sizeof($report_array)) {
		if ($_POST["drp_action"] == "1") { /* delete */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Report(s) will be deleted</p>
						<ul>$report_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";

			$title = "Delete Syslog Report(s)";
		}else if ($_POST["drp_action"] == "2") { /* disable */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Report(s) will be disabled</p>
						<ul>$report_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";

			$title = "Disable Syslog Report(s)";
		}else if ($_POST["drp_action"] == "3") { /* enable */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>If you click 'Continue', the following Syslog Report(s) will be enabled</p>
						<ul>$report_list</ul>";
						print "</td></tr>
					</td>
				</tr>\n";

			$title = "Enable Syslog Report(s)";
		}

		$save_html = "<input type='button' value='Cancel' onClick='window.history.back()'>&nbsp;<input type='submit' value='Continue' title='$title";
	}else{
		print "<tr><td bgcolor='#" . $colors["form_alternate1"]. "'><span class='textError'>You must select at least one Syslog Report.</span></td></tr>\n";
		$save_html = "<input type='button' value='Return' onClick='window.history.back()'>";
	}

	print "	<tr>
			<td align='right' bgcolor='#eaeaea'>
				<input type='hidden' name='action' value='actions'>
				<input type='hidden' name='selected_items' value='" . (isset($report_array) ? serialize($report_array) : '') . "'>
				<input type='hidden' name='drp_action' value='" . $_POST["drp_action"] . "'>
				$save_html
			</td>
		</tr>
		";

	html_end_box();

	include_once($config['base_path'] . "/include/bottom_footer.php");
}

function api_syslog_report_save($id, $name, $type, $message, $timespan, $timepart, $body,
	$email, $nodes, $enabled) {
	global $config, $syslog_cnn;

	/* get the username */
	$username = db_fetch_cell("select username from user_auth where id=" . $_SESSION["sess_user_id"]);

	if ($id) {
		$save["id"] = $id;
	}else{
		$save["id"] = "";
	}

	$save["name"]     = form_input_validate($name,     "name",     "", false, 3);
	$save["type"]     = form_input_validate($type,     "type",     "", false, 3);
	$save["message"]  = form_input_validate($message,  "message",  "", false, 3);
	$save["timespan"] = form_input_validate($timespan, "timespan", "", false, 3);
	$save["timepart"] = form_input_validate($timepart, "timepart", "", false, 3);
	$save["body"]     = form_input_validate($body,     "body",     "", false, 3);
	$save["email"]    = form_input_validate($email,    "email",    "", true, 3);
	$save["notes"]    = form_input_validate($notes,    "notes",    "", true, 3);
	$save["enabled"]  = form_input_validate($enabled,  "enabled",  "", false, 3);
	$save["date"]     = time();
	$save["user"]     = $username;

	$id = 0;
	$id = sql_save($save, "syslog_reports", "id", true, $syslog_cnn);

	if ($id) {
		raise_message(1);
	}else{
		raise_message(2);
	}

	return $id;
}

function api_syslog_report_remove($id) {
	global $syslog_cnn;

	db_execute("DELETE FROM syslog_reports WHERE id='" . $id . "'", true, $syslog_cnn);
}

function api_syslog_report_disable($id) {
	global $syslog_cnn;

	db_execute("UPDATE syslog_reports SET enabled='' WHERE id='" . $id . "'", true, $syslog_cnn);
}

function api_syslog_report_enable($id) {
	global $syslog_cnn;

	db_execute("UPDATE syslog_reports SET enabled='on' WHERE id='" . $id . "'", true, $syslog_cnn);
}

/* ---------------------
    Reports Functions
   --------------------- */

function syslog_get_report_records(&$sql_where) {
	global $syslog_cnn;

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
		FROM syslog_reports
		$sql_where
		ORDER BY ". get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") .
		" LIMIT " . (get_request_var_request("rows")*(get_request_var_request("page")-1)) . "," . get_request_var_request("rows");

	return db_fetch_assoc($query_string, true, $syslog_cnn);
}

function syslog_action_edit() {
	global $colors, $syslog_cnn, $message_types, $syslog_freqs, $syslog_times;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("type"));
	/* ==================================================== */

	if (isset($_GET["id"])) {
		$report = db_fetch_row("SELECT *
			FROM syslog_reports
			WHERE id=" . $_GET["id"], true, $syslog_cnn);
		$header_label = "[edit: " . $report["name"] . "]";
	}else{
		$header_label = "[new]";

		$report["name"] = "New Report Record";
	}

	html_start_box("<strong>Report Edit</strong> $header_label", "100%", $colors["header"], "3", "center", "");

	$fields_syslog_report_edit = array(
	"spacer0" => array(
		"method" => "spacer",
		"friendly_name" => "Report Details"
		),
	"name" => array(
		"method" => "textbox",
		"friendly_name" => "Report Name",
		"description" => "Please describe this Report.",
		"value" => "|arg1:name|",
		"max_length" => "250"
		),
	"enabled" => array(
		"method" => "drop_array",
		"friendly_name" => "Enabled?",
		"description" => "Is this Report Enabled?",
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
	"timespan" => array(
		"method" => "drop_array",
		"friendly_name" => "Report Frequency",
		"description" => "How often should this Report be sent to the distribution list?",
		"value" => "|arg1:timespan|",
		"array" => $syslog_freqs,
		"default" => "del"
		),
	"timepart" => array(
		"method" => "drop_array",
		"friendly_name" => "Send Time",
		"description" => "What time of day should this report be sent?",
		"value" => "|arg1:timepart|",
		"array" => $syslog_times,
		"default" => "del"
		),
	"message" => array(
		"friendly_name" => "Syslog Message Match String",
		"description" => "The matching component of the syslog message.",
		"method" => "textbox",
		"max_length" => "255",
		"value" => "|arg1:message|",
		"default" => "",
		),
	"body" => array(
		"friendly_name" => "Report Body Text",
		"textarea_rows" => "5",
		"textarea_cols" => "60",
		"description" => "The information that will be contained in the body of the report.",
		"method" => "textarea",
		"class" => "textAreaNotes",
		"value" => "|arg1:body|",
		"default" => "",
		),
	"email" => array(
		"friendly_name" => "Report e-mail Addresses",
		"textarea_rows" => "3",
		"textarea_cols" => "60",
		"description" => "Comma delimited list of e-mail addresses to send the report to.",
		"method" => "textarea",
		"class" => "textAreaNotes",
		"value" => "|arg1:email|",
		"default" => "",
		),
	"notes" => array(
		"friendly_name" => "Report Notes",
		"textarea_rows" => "3",
		"textarea_cols" => "60",
		"description" => "Space for Notes on the Removal rule",
		"method" => "textarea",
		"class" => "textAreaNotes",
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
	"save_component_report" => array(
		"method" => "hidden",
		"value" => "1"
		)
	);

	draw_edit_form(array(
		"config" => array("form_name" => "chk"),
		"fields" => inject_form_variables($fields_syslog_report_edit, (isset($report) ? $report : array()))
		));

	html_end_box();

	form_save_button("syslog_reports.php", "", "id");
}

function syslog_report() {
	global $colors, $syslog_cnn, $syslog_actions, $message_types, $syslog_freqs, $syslog_times, $item_rows, $config;

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
	if (isset($_REQUEST["clear_x"])) {
		kill_session_var("sess_syslog_report_page");
		kill_session_var("sess_syslog_report_rows");
		kill_session_var("sess_syslog_report_filter");
		kill_session_var("sess_syslog_report_enabled");
		kill_session_var("sess_syslog_report_sort_column");
		kill_session_var("sess_syslog_report_sort_direction");

		$_REQUEST["page"] = 1;
		unset($_REQUEST["filter"]);
		unset($_REQUEST["enabled"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = 0;
		$changed += check_changed("filter", "sess_syslog_report_filter");
		$changed += check_changed("enabled", "sess_syslog_report_enabled");
		$changed += check_changed("rows", "sess_syslog_report_rows");
		$changed += check_changed("sort_column", "sess_syslog_report_sort_column");
		$changed += check_changed("sort_direction", "sess_syslog_report_sort_direction");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_syslog_report_paage", "1");
	load_current_session_value("rows", "sess_syslog_report_rows", "20");
	load_current_session_value("enabled", "sess_syslog_report_enabled", "-1");
	load_current_session_value("filter", "sess_syslog_report_filter", "");
	load_current_session_value("sort_column", "sess_syslog_report_sort_column", "name");
	load_current_session_value("sort_direction", "sess_syslog_report_sort_direction", "ASC");

	html_start_box("<strong>Syslog Removal Rule Filters</strong>", "100%", $colors["header"], "3", "center", "syslog_reports.php?action=edit&type=1");

	include("plugins/syslog/html/syslog_report_filter.php");

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	$sql_where = "";
	$reports   = syslog_get_report_records($sql_where);
	$rows_query_string = "SELECT COUNT(*)
		FROM syslog_reports
		$sql_where";
	$total_rows = db_fetch_cell($rows_query_string, '', true, $syslog_cnn);

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
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $_REQUEST["rows"], $total_rows, "syslog_reports.php");

	$nav = "<tr bgcolor='#" . $colors["header"] . "' class='noprint'>
				<td colspan='16'>
					<table width='100%' cellspacing='0' cellpadding='0' border='0'>
						<tr>
							<td align='left' class='textHeaderDark'>
								<strong>&lt;&lt; "; if ($_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='" . $config['url_path'] . "plugins/syslog/syslog_reports.php?page=" . ($_REQUEST["page"]-1) . "'>"; } $nav .= "Previous"; if ($_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
							</td>\n
							<td align='center' class='textHeaderDark'>
								Showing Rows " . (($_REQUEST["rows"]*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $_REQUEST["rows"]) || ($total_rows < ($_REQUEST["rows"]*$_REQUEST["page"]))) ? $total_rows : ($_REQUEST["rows"]*$_REQUEST["page"])) . " of $total_rows [$url_page_select]
							</td>\n
							<td align='right' class='textHeaderDark'>
								<strong>"; if (($_REQUEST["page"] * $_REQUEST["rows"]) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . $config['url_path'] . "plugins/syslog/syslog_reports.php?page=" . ($_REQUEST["page"]+1) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $_REQUEST["rows"]) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
							</td>\n
						</tr>
					</table>
				</td>
			</tr>\n";

	print $nav;

	$display_text = array(
		"name" => array("Removal<br>Name", "ASC"),
		"enabled" => array("<br>Enabled", "ASC"),
		"type" => array("Match<br>Type", "ASC"),
		"message" => array("Search<br>String", "ASC"),
		"timespan" => array("<br>Frequency", "ASC"),
		"timepart" => array("Send<br>Time", "ASC"),
		"lastsent" => array("Last<br>Sent", "ASC"),
		"date" => array("Last<br>Modified", "ASC"),
		"user" => array("By<br>User", "DESC"));

	html_header_sort_checkbox($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

	$i = 0;
	if (sizeof($reports) > 0) {
		foreach ($reports as $report) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i, 'line' . $report["id"]); $i++;
			form_selectable_cell("<a class='linkEditMain' href='" . $config['url_path'] . "plugins/syslog/syslog_reports.php?action=edit&id=" . $report["id"] . "'>" . (($_REQUEST["filter"] != "") ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim(htmlentities($report["name"]), read_config_option("max_title_data_source"))) : htmlentities($report["name"])) . "</a>", $report["id"]);
			form_selectable_cell((($report["enabled"] == "on") ? "Yes" : ""), $report["id"]);
			form_selectable_cell($message_types[$report["type"]], $report["id"]);
			form_selectable_cell($report["message"], $report["id"]);
			form_selectable_cell($syslog_freqs[$report["timespan"]], $report["id"]);
			form_selectable_cell($syslog_times[$report["timepart"]], $report["id"]);
			form_selectable_cell(($report["lastsent"] == 0 ? "Never": date("Y-m-d H:i:s", $report["lastsent"])), $report["id"]);
			form_selectable_cell(date("Y-m-d H:i:s", $report["date"]), $report["id"]);
			form_selectable_cell($report["user"], $report["id"]);
			form_checkbox_cell($report["name"], $report["id"]);
			form_end_row();
		}
	}else{
		print "<tr><td colspan='4'><em>No Syslog Removal Rules Defined</em></td></tr>";
	}
	html_end_box(false);

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($syslog_actions);
}

?>
