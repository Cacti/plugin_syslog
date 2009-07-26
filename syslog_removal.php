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

		syslog_removal();

		include_once($config['base_path'] . "/include/bottom_footer.php");
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if ((isset($_POST["save_component_removal"])) && (empty($_POST["add_dq_y"]))) {
		$removalid = api_syslog_removal_save($_POST["id"], $_POST["name"], $_POST["type"],
			$_POST["message"], $_POST["method"], $_POST["notes"], $_POST["enabled"]);

		if ((is_error_message()) || ($_POST["id"] != $_POST["_id"])) {
			header("Location: syslog_removal.php?action=edit&id=" . (empty($id) ? $_POST["id"] : $id));
		}else{
			header("Location: syslog_removal.php");
		}
	}
}

/* ------------------------
    The "actions" function
   ------------------------ */

function form_actions() {
	global $colors, $config, $syslog_actions, $fields_syslog_action_edit;

	/* if we are to save this form, instead of display it */
	if (isset($_POST["selected_items"])) {
		$selected_items = unserialize(stripslashes($_POST["selected_items"]));

		if ($_POST["drp_action"] == "1") { /* delete */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_removal_remove($selected_items[$i]);
			}
		}else if ($_POST["drp_action"] == "2") { /* disable */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_removal_disable($selected_items[$i]);
			}
		}else if ($_POST["drp_action"] == "3") { /* enable */
			for ($i=0; $i<count($selected_items); $i++) {
				/* ================= input validation ================= */
				input_validate_input_number($selected_items[$i]);
				/* ==================================================== */

				api_syslog_removal_enable($selected_items[$i]);
			}
		}

		header("Location: syslog_removal.php");

		exit;
	}

	include_once($config['base_path'] . "/include/top_header.php");

	html_start_box("<strong>" . $syslog_actions{$_POST["drp_action"]} . "</strong>", "60%", $colors["header_panel"], "3", "center", "");

	print "<form action='syslog_removal.php' method='post'>\n";

	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

	/* setup some variables */
	$removal_list = ""; $i = 0;

	/* loop through each of the clusters selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (ereg("^chk_([0-9]+)$", $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$removal_info = db_fetch_cell("SELECT name FROM syslog_remove WHERE id=" . $matches[1]);
			$removal_list  .= "<li>" . $removal_info . "<br>";
			$removal_array[$i] = $matches[1];
		}

		$i++;
	}

	if ($_POST["drp_action"] == "1") { /* delete */
		print "	<tr>
				<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
					<p>Are you sure you want to delete the following syslog removal rule(s)?</p>
					<p>$removal_list</p>";
					print "</td></tr>
				</td>
			</tr>\n
			";
	}else if ($_POST["drp_action"] == "2") { /* disable */
		print "	<tr>
				<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
					<p>Are you sure you want to disable polling of the following syslog removal rule(s)?</p>
					<p>$removal_list</p>";
					print "</td></tr>
				</td>
			</tr>\n
			";
	}else if ($_POST["drp_action"] == "3") { /* enable */
		print "	<tr>
				<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
					<p>Are you sure you want to enable the following syslog removal rule(s)?</p>
					<p>$removal_list</p>";
					print "</td></tr>
				</td>
			</tr>\n
			";
	}

	if (!isset($removal_array)) {
		print "<tr><td bgcolor='#" . $colors["form_alternate1"]. "'><span class='textError'>You must select at least one removal rule.</span></td></tr>\n";
		$save_html = "";
	}else{
		$save_html = "<input type='image' src='" . $config['url_path'] . "images/button_yes.gif' alt='Save' align='absmiddle'>";
	}

	print "	<tr>
			<td colspan='2' align='right' bgcolor='#eaeaea'>
				<input type='hidden' name='action' value='actions'>
				<input type='hidden' name='selected_items' value='" . (isset($cluster_array) ? serialize($cluster_array) : '') . "'>
				<input type='hidden' name='drp_action' value='" . $_POST["drp_action"] . "'>
				<a href='" . $config['url_path'] . "plugins/syslog/syslog_removal.php'><img src='" . $config['url_path'] . "images/button_no.gif' alt='Cancel' align='absmiddle' border='0'></a>
				$save_html
			</td>
		</tr>
		";

	html_end_box();

	include_once($config['base_path'] . "/include/bottom_footer.php");
}

function api_syslog_removal_save($id, $name, $type, $message, $method, $notes, $enabled) {
	global $config;

	include('plugins/syslog/config.php');

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
	$save["method"]  = form_input_validate($method,  "method",  "", false, 3);
	$save["notes"]   = form_input_validate($notes,   "notes",   "", true, 3);
	$save["enabled"] = form_input_validate($enabled, "enabled", "", false, 3);
	$save["date"]    = time();
	$save["user"]    = $username;

	$id = 0;
	if (!is_error_message()) {
		/* connect to syslog instead of Cacti */
		db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

		$id = sql_save($save, "syslog_remove");

		if ($id) {
			raise_message(1);
		}else{
			raise_message(2);
		}
	}

	return $id;
}

function api_syslog_removal_remove($id) {
	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

	db_execute("DELETE FROM syslog_remove WHERE id='" . $id . "'");
}

function api_syslog_removal_disable($id) {
	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

	db_execute("UPDATE syslog_remove SET enabled='' WHERE id='" . $id . "'");
}

function api_syslog_removal_enable($id) {
	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

	db_execute("UPDATE syslog_remove SET enabled='on' WHERE id='" . $id . "'");
}

/* ---------------------
    Removal Functions
   --------------------- */

function syslog_removal_remove() {
	global $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	/* ==================================================== */

	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

	if (!isset($_GET["confirm"])) {
		include("./include/top_header.php");
		form_confirm("Are You Sure?", "Are you sure you want to delete the syslog removal rule(s)?<strong>'" . db_fetch_cell("SELECT name FROM syslog_remove WHERE id=" . $_GET["id"]) . "'</strong>?", "syslog_removal.php", "syslog_removal.php?action=remove&id=" . $_GET["id"]);
		include_once($config['base_path'] . "/include/bottom_footer.php");
		exit;
	}

	if (isset($_GET["confirm"])) {
		api_syslog_removal_remove($_GET["clusterid"]);
	}
}

function syslog_get_removal_records() {
	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

	$query_string = "SELECT *
		FROM syslog_remove
		ORDER BY " . $_REQUEST["sort_column"] . " " . $_REQUEST["sort_direction"];

	return db_fetch_assoc($query_string);
}

function syslog_action_edit() {
	global $colors, $fields_syslog_removal_edit;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("type"));
	/* ==================================================== */

	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

	display_output_messages();

	if ((!isset($_REQUEST["type"])) ||
		(($_REQUEST["type"] == 1) && (isset($_RQUEST["id"])))) {
		$removal = db_fetch_row("SELECT *
			FROM syslog_remove
			WHERE id=" . $_GET["id"]);
		$header_label = "[edit: " . $removal["name"] . "]";
	}else{
		if (isset($_REQUEST["id"])) {
			$removal = db_fetch_row("SELECT *
				FROM syslog
				WHERE " . $syslog_config['id'] . "=" . $_REQUEST["id"]);
		}

		$header_label = "[new]";

		$removal["name"] = "New Removal Record";
	}

	html_start_box("<strong>Removal Rule Edit</strong> $header_label", "100%", $colors["header"], "3", "center", "");

	draw_edit_form(array(
		"config" => array("form_name" => "chk"),
		"fields" => inject_form_variables($fields_syslog_removal_edit, (isset($removal) ? $removal : array()))
		));

	html_end_box();

	form_save_button("syslog_removal.php", "", "id");
}

function check_changed($request, $session) {
	if ((isset($_REQUEST[$request])) && (isset($_SESSION[$session]))) {
		if ($_REQUEST[$request] != $_SESSION[$session]) {
			return 1;
		}
	}
}

function syslog_removal() {
	global $colors, $syslog_actions, $message_types, $item_rows, $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("id"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("enabled"));
	input_validate_input_number(get_request_var_request("rows_selector"));
	/* ==================================================== */

	include('plugins/syslog/config.php');

	/* connect to syslog instead of Cacti */
	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

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
		kill_session_var("sess_syslog_removal_page");
		kill_session_var("sess_syslog_removal_rows_selector");
		kill_session_var("sess_syslog_removal_filter");
		kill_session_var("sess_syslog_removal_enabled");
		kill_session_var("sess_syslog_removal_sort_column");
		kill_session_var("sess_syslog_removal_sort_direction");

		$_REQUEST["page"] = 1;
		unset($_REQUEST["filter"]);
		unset($_REQUEST["enabled"]);
		unset($_REQUEST["rows_selector"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = 0;
		$changed += check_changed("filter", "sess_syslog_removal_filter");
		$changed += check_changed("enabled", "sess_syslog_removal_enabled");
		$changed += check_changed("rows_selector", "sess_syslog_removal_rows_selector");
		$changed += check_changed("sort_column", "sess_syslog_removal_sort_column");
		$changed += check_changed("sort_direction", "sess_syslog_removal_sort_direction");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_syslog_removal_paage", "1");
	load_current_session_value("rows_selector", "sess_syslog_removal_rows_selector", "20");
	load_current_session_value("enabled", "sess_syslog_removal_enabled", "-1");
	load_current_session_value("filter", "sess_syslog_removal_filter", "");
	load_current_session_value("sort_column", "sess_syslog_removal_sort_column", "name");
	load_current_session_value("sort_direction", "sess_syslog_removal_sort_direction", "ASC");

	html_start_box("<strong>Syslog Removal Rule Filters</strong>", "100%", $colors["header"], "3", "center", "syslog_removal.php?action=edit&type=1");

	include("plugins/syslog/html/syslog_removal_filter.php");

	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	$sql_where = "";

	$removals = syslog_get_removal_records($sql_where);

	$rows_query_string = "SELECT COUNT(*)
		FROM syslog_remove
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
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $_REQUEST["rows_selector"], $total_rows, "syslog_removal.php");

	$nav = "<tr bgcolor='#" . $colors["header"] . "' class='noprint'>
				<td colspan='16'>
					<table width='100%' cellspacing='0' cellpadding='0' border='0'>
						<tr>
							<td align='left' class='textHeaderDark'>
								<strong>&lt;&lt; "; if ($_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='" . $config['url_path'] . "plugins/syslog/syslog_removal.php?page=" . ($_REQUEST["page"]-1) . "'>"; } $nav .= "Previous"; if ($_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
							</td>\n
							<td align='center' class='textHeaderDark'>
								Showing Rows " . (($_REQUEST["rows_selector"]*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $_REQUEST["rows_selector"]) || ($total_rows < ($_REQUEST["rows_selector"]*$_REQUEST["page"]))) ? $total_rows : ($_REQUEST["rows_selector"]*$_REQUEST["page"])) . " of $total_rows [$url_page_select]
							</td>\n
							<td align='right' class='textHeaderDark'>
								<strong>"; if (($_REQUEST["page"] * $_REQUEST["rows_selector"]) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . $config['url_path'] . "plugins/syslog/syslog_removal.php?page=" . ($_REQUEST["page"]+1) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $_REQUEST["rows_selector"]) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
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
		"method" => array("<br>Method", "DESC"),
		"date" => array("Last<br>Modified", "ASC"),
		"user" => array("By<br>User", "DESC"));

	html_header_sort_checkbox($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);
$_REQUEST["filter"] = "";
	$i = 0;
	if (sizeof($removals) > 0) {
		foreach ($removals as $removal) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i, 'line' . $removal["id"]); $i++;
			form_selectable_cell("<a class='linkEditMain' href='" . $config['url_path'] . "plugins/syslog/syslog_removal.php?action=edit&id=" . $removal["id"] . "'>" . (($_REQUEST["filter"] != "") ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim(htmlentities($data_source["name_cache"]), read_config_option("max_title_data_source"))) : htmlentities($removal["name"])) . "</a>", $removal["id"]);
			form_selectable_cell((($removal["enabled"] == "on") ? "Yes" : ""), $removal["id"]);
			form_selectable_cell($message_types[$removal["type"]], $removal["id"]);
			form_selectable_cell($removal["message"], $removal["id"]);
			form_selectable_cell((($removal["method"] == "del") ? "Deletion" : "Transfer"), $removal["id"]);
			form_selectable_cell(date("Y-m-d H:i:s", $removal["date"]), $removal["id"]);
			form_selectable_cell($removal["user"], $removal["id"]);
			form_checkbox_cell($removal["name"], $removal["id"]);
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
