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
 | Originally released as aloe by: sidewinder at shitworks.com             |
 | Modified by: Harlequin <harlequin@cyberonic.com>                        |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

define("MAX_DISPLAY_PAGES", 21);

/* allow guest account to see this page */
$guest_account = true;

/* initialize cacti environment */
chdir('../../');
include("./include/auth.php");

/* syslog specific database setup and functions */
include('plugins/syslog/config.php');
include_once('plugins/syslog/functions.php');

$title = "Syslog Viewer";

/* make sure syslog is setup correctly */
if (!syslog_check_dependencies()) {
	include_once(dirname(__FILE__) . "/include/top_syslog_header.php");
	cacti_log("SYSLOG: You are missing a required dependency, please install the '<a href='http://cactiusers.org/'>Settings'</a> plugin.", true, "SYSTEM");
	print "<br><br><center><font color=red>You are missing a dependency for Syslog, please install the '<a href='http://cactiusers.org'>Settings</a>' plugin.</font></color>";
	exit;
}

/* set the default tab */
load_current_session_value("tab", "sess_syslog_tab", "syslog");
$current_tab = $_REQUEST["tab"];

/* validate the syslog post/get/request information */;
if ($current_tab != "stats") {
	syslog_request_validation($current_tab);
}

/* draw the tabs */
/* display the main page */
if (isset($_REQUEST["export"])) {
	syslog_export($current_tab);
	/* clear output so reloads wont re-download */
	unset($_REQUEST["output"]);
}else{
	include_once(dirname(__FILE__) . "/include/top_syslog_header.php");

	syslog_display_tabs($current_tab);

	if ($current_tab == "current") {
		syslog_view_alarm();
	}elseif ($current_tab == "stats") {
		syslog_statistics();
	}else{
		syslog_messages($current_tab);
	}

	include_once("./include/bottom_footer.php");
}

function syslog_display_tabs($current_tab) {
	global $config;

	/* present a tabbed interface */
	$tabs_syslog = array(
		"syslog" => "Syslogs",
		"stats"  => "Statistics",
		"alerts" => "Alert Log");

	/* if they were redirected to the page, let's set that up */
	if ((isset($_REQUEST["id"]) && $_REQUEST["id"] > "0") || $current_tab == "current") {
		$current_tab = "current";
	}

	load_current_session_value("id", "sess_syslog_id", "0");
	if ((isset($_REQUEST["id"]) && $_REQUEST["id"] > "0") || $current_tab == "current") {
		$tabs_syslog["current"] = "Selected Alert";
	}

	/* draw the tabs */
	print "<table class='tabs' width='100%' cellspacing='0' cellpadding='3' border='0' align='center'><tr>\n";

	if (sizeof($tabs_syslog) > 0) {
	foreach (array_keys($tabs_syslog) as $tab_short_name) {
		print "<td style='padding:3px 10px 2px 5px;background-color:" . (($tab_short_name == $current_tab) ? "silver;" : "#DFDFDF;") .
			"white-space:nowrap;'" .
			" nowrap width='1%'" .
			" align='center' class='tab'>
			<span class='textHeader'><a href='" . $config['url_path'] .
			"plugins/syslog/syslog.php?" .
			"tab=" . $tab_short_name .
			"'>$tabs_syslog[$tab_short_name]</a></span>
		</td>\n
		<td width='1'></td>\n";
	}
	}
	print "<td></td>\n</tr></table>\n";
}

function syslog_view_alarm() {
	global $config, $colors;

	include(dirname(__FILE__) . "/config.php");
	include_once(dirname(__FILE__) . "/include/top_syslog_header.php");

	echo "<table cellpadding='3' cellspacing='0' align='center' style='width:100%;border:1px solid #" . $colors["header"] . ";'>";
	echo "<tr><td class='textHeaderDark' style='background-color:#" . $colors["header"] . ";'><strong>Syslog Alert View</strong></td></tr>";
	echo "<tr><td style='background-color:#FFFFFF;'>";

	$html = syslog_db_fetch_cell("SELECT html FROM `" . $syslogdb_default . "`.`syslog_logs` WHERE seq=" . $_REQUEST["id"]);
	echo $html;

	echo "</td></tr></table>";

	include_once("./include/bottom_footer.php");
	exit;
}

/** function generate_syslog_cssjs()
 *  This function generates the page css and javascript which controls
 *  the appearance of the syslog main page.  It supports both the legacy
 *  CSS generation code as well as the current methodology.
*/
function generate_syslog_cssjs() {
	global $colors, $config, $syslog_incoming_config;
	global $syslog_colors, $syslog_text_colors, $syslog_levels;

	/* legacy css for syslog backgrounds */
	print "\n\t\t\t<style type='text/css'>\n";
	if (sizeof($syslog_colors)) {
	foreach ($syslog_colors as $type => $color) {
		if ((isset($syslog_text_colors[$type]) && $syslog_text_colors[$type] != '')) {
			print "\t\t\t.syslog_$type {\n";
			if ($color != '') {
				print "\t\t\t\tbackground-color:#$color;\n";
			}
			if (isset($syslog_text_colors[$type]) && $syslog_text_colors[$type] != '') {
				print "\t\t\t\tcolor:#" . $syslog_text_colors[$type] . ";\n";
			}
			print "\t\t\t}\n";
		}
	}
	}
	print "\t\t\t</style>\n";

	/* this section of javascript keeps nasty calendar popups from
	 * appearing when you hit return.
	 */
	?>
	<script type="text/javascript">
	<!--

	function forceReturn(evt) {
		var evt  = (evt) ? evt : ((event) ? event : null);
		var node = (evt.target) ? evt.target : ((evt.srcElement) ? evt.srcElement : null);

		if ((evt.keyCode == 13) && (node.type=="text")) {
			document.getElementById('syslog_form').submit();
			return false;
		}
	}
	document.onkeypress = forceReturn;

	-->
	</script>
	<?php
}

/** function syslog_statistics()
 *  This function paints a table of summary statistics for syslog
 *  messages by host, facility, priority, and time range.
*/
function syslog_statistics() {
	global $title, $colors, $rows, $config;

	include(dirname(__FILE__) . "/config.php");

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("rows"));
	input_validate_input_number(get_request_var_request("refresh"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("timespan"));
	/* ==================================================== */

	/* clean up filter string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var_request("filter"));
	}

	/* clean up facility string */
	if (isset($_REQUEST["facility"])) {
		$_REQUEST["facility"] = sanitize_search_string(get_request_var_request("facility"));
	}

	/* clean up priority string */
	if (isset($_REQUEST["priority"])) {
		$_REQUEST["priority"] = sanitize_search_string(get_request_var_request("priority"));
	}

	/* clean up sort solumn */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var_request("sort_column"));
	}

	/* clean up sort direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var_request("sort_direction"));
	}

	/* if the user pushed the 'clear' button */
	if (isset($_REQUEST["clear"])) {
		kill_session_var("sess_syslog_stats_timespan");
		kill_session_var("sess_syslog_stats_rows");
		kill_session_var("sess_syslog_stats_refresh");
		kill_session_var("sess_syslog_stats_page");
		kill_session_var("sess_syslog_stats_filter");
		kill_session_var("sess_syslog_stats_facility");
		kill_session_var("sess_syslog_stats_priority");
		kill_session_var("sess_syslog_stats_sort_column");
		kill_session_var("sess_syslog_stats_sort_direction");

		$_REQUEST["page"] = 1;
		unset($_REQUEST["rows"]);
		unset($_REQUEST["timespan"]);
		unset($_REQUEST["refresh"]);
		unset($_REQUEST["page"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["facility"]);
		unset($_REQUEST["priority"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		$reset_multi = true;
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = 0;
		$changed += syslog_check_changed("timespan",       "sess_syslog_stats_timespan");
		$changed += syslog_check_changed("rows",           "sess_syslog_stats_rows");
		$changed += syslog_check_changed("refresh",        "sess_syslog_stats_refresh");
		$changed += syslog_check_changed("filter",         "sess_syslog_stats_filter");
		$changed += syslog_check_changed("facility",       "sess_syslog_stats_facility");
		$changed += syslog_check_changed("priority",       "sess_syslog_stats_priority");
		$changed += syslog_check_changed("sort_column",    "sess_syslog_stats_sort_column");
		$changed += syslog_check_changed("sort_direction", "sess_syslog_stats_sort_direction");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}

		$reset_multi = false;
	}

	/* remember search fields in session vars */
	load_current_session_value("page",           "sess_syslog_stats_page", "1");
	load_current_session_value("rows",           "sess_syslog_stats_rows", read_config_option("num_rows_syslog"));
	load_current_session_value("refresh",        "sess_syslog_stats_refresh", read_config_option("syslog_refresh"));
	load_current_session_value("filter",         "sess_syslog_stats_filter", "");
	load_current_session_value("facility",       "sess_syslog_stats_facility", "-1");
	load_current_session_value("priority",       "sess_syslog_stats_priority", "-1");
	load_current_session_value("sort_column",    "sess_syslog_stats_sort_column", "host");
	load_current_session_value("sort_direction", "sess_syslog_stats_sort_direction", "DESC");

	html_start_box("<strong>Syslog Statistics Filter</strong>", "100%", $colors["header"], "3", "center", "");
	syslog_stats_filter();
	html_end_box();

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	$sql_where   = "";
	$sql_groupby = "";

	if ($_REQUEST["rows"] == -1) {
		$row_limit = read_config_option("num_rows_syslog");
	}elseif ($_REQUEST["rows"] == -2) {
		$row_limit = 999999;
	}else{
		$row_limit = $_REQUEST["rows"];
	}

	$records = get_stats_records($sql_where, $sql_groupby, $row_limit);

	$rows_query_string = "SELECT COUNT(*)
		FROM `" . $syslogdb_default . "`.`syslog_statistics` AS ss
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
		ON ss.facility_id=sf.facility_id
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
		ON ss.priority_id=sp.priority_id
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
		ON ss.host_id=sh.host_id
		$sql_where
		$sql_groupby";

	$total_rows = sizeof(syslog_db_fetch_cell($rows_query_string));

	?>
	<script type="text/javascript">
	<!--
	function applyChange(objForm) {
		strURL = '?facility=' + objForm.facility.value;
		strURL = strURL + '&priority=' + objForm.priority.value;
		strURL = strURL + '&filter=' + objForm.filter.value;
		strURL = strURL + '&rows=' + objForm.rows.value;
		document.location = strURL;
	}
	-->
	</script>
	<?php

	/* generate page list */
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $row_limit, $total_rows, "syslog.php?tab=stats&filter=" . $_REQUEST["filter"]);

	if ($total_rows > 0) {
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
					<td colspan='13'>
						<table width='100%' cellspacing='0' cellpadding='0' border='0'>
							<tr>
								<td align='left' class='textHeaderDark'>
									<strong>&lt;&lt; "; if ($_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='syslog.php?tab=stats&page=" . ($_REQUEST["page"]-1) . "'>"; } $nav .= "Previous"; if ($_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
								</td>\n
								<td align='center' class='textHeaderDark'>
									Showing Rows " . ($total_rows == 0 ? "None" : (($row_limit*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $row_limit) || ($total_rows < ($row_limit*$_REQUEST["page"]))) ? $total_rows : ($row_limit*$_REQUEST["page"])) . " of $total_rows [$url_page_select]") . "
								</td>\n
								<td align='right' class='textHeaderDark'>
									<strong>"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "<a class='linkOverDark' href='syslog.php?tab=stats&page=" . ($_REQUEST["page"]+1) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
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
		"host" => array("Host Name", "ASC"),
		"facility" => array("Facility", "ASC"),
		"priority" => array("Priority", "ASC"),
		"records" => array("Records", "DESC"));

	html_header_sort($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

	$i = 0;
	if (sizeof($records)) {
		foreach ($records as $r) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
			echo "<td>" . $r["host"] . "</td>";
			echo "<td>" . ($_REQUEST["facility"] != "-2" ? ucfirst($r["facility"]):"-") . "</td>";
			echo "<td>" . ($_REQUEST["priority"] != "-2" ? ucfirst($r["priority"]):"-") . "</td>";
			echo "<td>" . $r["records"] . "</td>";
			form_end_row();
		}
	}else{
		print "<tr><td colspan='4'><em>No Syslog Statistics Found</em></td></tr>";
	}

	html_end_box(false);
}

function get_stats_records(&$sql_where, &$sql_groupby, $row_limit) {
	include(dirname(__FILE__) . "/config.php");

	$sql_where   = "";
	$sql_groupby = "GROUP BY sh.host";

	/* form the 'where' clause for our main sql query */
	if (!empty($_REQUEST["filter"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . "sh.host LIKE '%%" . $_REQUEST["filter"] . "%%'";
	}

	if ($_REQUEST["facility"] == "-2") {
		// Do nothing
	}elseif ($_REQUEST["facility"] != "-1") {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . "ss.facility_id=" . $_REQUEST["facility"];
		$sql_groupby .= ", sf.facility";
	}else{
		$sql_groupby .= ", sf.facility";
	}

	if ($_REQUEST["priority"] == "-2") {
		// Do nothing
	}elseif ($_REQUEST["priority"] != "-1") {
		$sql_where .= (!strlen($sql_where) ? "WHERE ": " AND ") . "ss.priority_id=" . $_REQUEST["priority"];
		$sql_groupby .= ", sp.priority";
	}else{
		$sql_groupby .= ", sp.priority";
	}

	if (!isset($_REQUEST["export"])) {
		$limit = " LIMIT " . ($row_limit*($_REQUEST["page"]-1)) . "," . $row_limit;
	} else {
		$limit = " LIMIT 10000";
	}

	$sort = $_REQUEST["sort_column"];

	$query_sql = "SELECT sh.host, sf.facility, sp.priority, sum(ss.records) AS records
		FROM `" . $syslogdb_default . "`.`syslog_statistics` AS ss
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
		ON ss.facility_id=sf.facility_id
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
		ON ss.priority_id=sp.priority_id
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
		ON ss.host_id=sh.host_id
		$sql_where
		$sql_groupby
		ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
		$limit;

	return syslog_db_fetch_assoc($query_sql);
}

function syslog_stats_filter() {
	global $colors, $config, $item_rows;
	?>
	<tr bgcolor="<?php print $colors["panel"];?>">
		<form name="stats">
		<td>
			<table cellpadding="1" cellspacing="0">
				<tr>
					<td width="70">
						&nbsp;Facility:&nbsp;
					</td>
					<td width="1">
						<select name="facility" onChange="applyChange(document.stats)">
						<option value="-1"<?php if ($_REQUEST["facility"] == "-1") {?> selected<?php }?>>All</option>
						<option value="-2"<?php if ($_REQUEST["facility"] == "-2") {?> selected<?php }?>>None</option>
						<?php
							$facilities = syslog_db_fetch_assoc("SELECT DISTINCT facility_id, facility 
								FROM syslog_facilities AS sf
								WHERE facility_id IN (SELECT DISTINCT facility_id FROM syslog_statistics)
								ORDER BY facility");

							if (sizeof($facilities)) {
							foreach ($facilities as $r) {
								print '<option value="' . $r["facility_id"] . '"'; if ($_REQUEST["facility"] == $r["facility_id"]) { print " selected"; } print ">" . ucfirst($r["facility"]) . "</option>\n";
							}
							}
						?>
						</select>
					</td>
					<td width="70">
						&nbsp;Priority:&nbsp;
					</td>
					<td width="1">
						<select name="priority" onChange="applyChange(document.stats)">
						<option value="-1"<?php if ($_REQUEST["priority"] == "-1") {?> selected<?php }?>>All</option>
						<option value="-2"<?php if ($_REQUEST["priority"] == "-2") {?> selected<?php }?>>None</option>
						<?php
							$priorities = syslog_db_fetch_assoc("SELECT DISTINCT priority_id, priority 
								FROM syslog_priorities AS sp
								WHERE priority_id IN (SELECT DISTINCT priority_id FROM syslog_statistics)
								ORDER BY priority");

							if (sizeof($priorities)) {
							foreach ($priorities as $r) {
								print '<option value="' . $r["priority_id"] . '"'; if ($_REQUEST["priority"] == $r["priority_id"]) { print " selected"; } print ">" . ucfirst($r["priority"]) . "</option>\n";
							}
							}
						?>
						</select>
					</td>
					<td width="45">
						&nbsp;Rows:&nbsp;
					</td>
					<td width="1">
						<select name="rows" onChange="applyChange(document.stats)">
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
						&nbsp;<input type="submit" name="go" value="Go" title="Search">
					</td>
					<td>
						&nbsp;<input type="submit" name="clear" value="Clear">
					</td>
				</tr>
			</table>
			<table cellpadding="1" cellspacing="0">
				<tr>
					<td width="70">
						&nbsp;Search:&nbsp;
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

/** function syslog_request_validation()
 *  This is a generic funtion for this page that makes sure that
 *  we have a good request.  We want to protect against people who
 *  like to create issues with Cacti.
*/
function syslog_request_validation($current_tab) {
	global $title, $colors, $rows, $config, $reset_multi;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("rows"));
	input_validate_input_number(get_request_var_request("removal"));
	input_validate_input_number(get_request_var_request("refresh"));
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("trimval"));
	input_validate_input_number(get_request_var_request("id"));
	/* ==================================================== */

	/* clean up filter string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var_request("filter"));
	}

	/* clean up facility string */
	if (isset($_REQUEST["efacility"])) {
		$_REQUEST["efacility"] = sanitize_search_string(get_request_var_request("efacility"));
	}

	/* clean up priority string */
	if (isset($_REQUEST["elevel"])) {
		$_REQUEST["elevel"] = sanitize_search_string(get_request_var_request("elevel"));
	}

	/* clean up sort solumn */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var_request("sort_column"));
	}

	/* clean up sort direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var_request("sort_direction"));
	}

	if ($current_tab != "alerts" && isset($_REQUEST["host"]) && $_REQUEST["host"][0] == -1) {
		kill_session_var("sess_syslog_" . $current_tab . "_hosts");
		unset($_REQUEST["host"]);
	}

	api_plugin_hook_function('syslog_request_val');

	/* if the user pushed the 'clear' button */
	if (isset($_REQUEST["button_clear_x"])) {
		kill_session_var("sess_syslog_" . $current_tab . "_hosts");
		kill_session_var("sess_syslog_" . $current_tab . "_rows");
		kill_session_var("sess_syslog_" . $current_tab . "_trimval");
		kill_session_var("sess_syslog_" . $current_tab . "_removal");
		kill_session_var("sess_syslog_" . $current_tab . "_refresh");
		kill_session_var("sess_syslog_" . $current_tab . "_page");
		kill_session_var("sess_syslog_" . $current_tab . "_filter");
		kill_session_var("sess_syslog_" . $current_tab . "_efacility");
		kill_session_var("sess_syslog_" . $current_tab . "_elevel");
		kill_session_var("sess_syslog__id");
		kill_session_var("sess_syslog_" . $current_tab . "_sort_column");
		kill_session_var("sess_syslog_" . $current_tab . "_sort_direction");

		$_REQUEST["page"] = 1;
		unset($_REQUEST["hosts"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["trimval"]);
		unset($_REQUEST["removal"]);
		unset($_REQUEST["refresh"]);
		unset($_REQUEST["page"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["efacility"]);
		unset($_REQUEST["elevel"]);
		unset($_REQUEST["id"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		$reset_multi = true;
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = 0;
		$changed += syslog_check_changed("hosts", "sess_syslog_" . $current_tab . "_hosts");
		$changed += syslog_check_changed("predefined_timespan", "sess_current_timespan");
		$changed += syslog_check_changed("date1", "sess_current_date1");
		$changed += syslog_check_changed("date2", "sess_current_date2");
		$changed += syslog_check_changed("rows", "sess_syslog_" . $current_tab . "_rows");
		$changed += syslog_check_changed("removal", "sess_syslog_" . $current_tab . "_removal");
		$changed += syslog_check_changed("refresh", "sess_syslog_" . $current_tab . "_refresh");
		$changed += syslog_check_changed("filter", "sess_syslog_" . $current_tab . "_filter");
		$changed += syslog_check_changed("efacility", "sess_syslog_" . $current_tab . "_efacility");
		$changed += syslog_check_changed("elevel", "sess_syslog_" . $current_tab . "_elevel");
		$changed += syslog_check_changed("sort_column", "sess_syslog_" . $current_tab . "_sort_column");
		$changed += syslog_check_changed("sort_direction", "sess_syslog_" . $current_tab . "_sort_direction");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}

		$reset_multi = false;
	}

	/* remember search fields in session vars */
	load_current_session_value("page", "sess_syslog_" . $current_tab . "_page", "1");
	load_current_session_value("rows", "sess_syslog_" . $current_tab . "_rows", read_config_option("num_rows_syslog"));
	load_current_session_value("trimval", "sess_syslog_" . $current_tab . "_trimval", "75");
	load_current_session_value("refresh", "sess_syslog_" . $current_tab . "_refresh", read_config_option("syslog_refresh"));
	load_current_session_value("removal", "sess_syslog_" . $current_tab . "_removal", "-1");
	load_current_session_value("filter", "sess_syslog_" . $current_tab . "_filter", "");
	load_current_session_value("efacility", "sess_syslog_" . $current_tab . "_efacility", "0");
	load_current_session_value("elevel", "sess_syslog_" . $current_tab . "_elevel", "0");
	load_current_session_value("hosts", "sess_syslog_" . $current_tab . "_hosts", "0");
	load_current_session_value("sort_column", "sess_syslog_" . $current_tab . "_sort_column", "logtime");
	load_current_session_value("sort_direction", "sess_syslog_" . $current_tab . "_sort_direction", "DESC");

	if (isset($_REQUEST["host"])) {
		$_SESSION["sess_syslog_" . $current_tab . "_hosts"] = $_REQUEST["host"];
	} else if (isset($_SESSION["sess_syslog_" . $current_tab . "_hosts"])) {
		$_REQUEST["host"] = $_SESSION["sess_syslog_" . $current_tab . "_hosts"];
	} else {
		$_REQUEST["host"][0] = "0"; /* default value */
	}
}

function get_syslog_messages(&$sql_where, $row_limit, $tab) {
	global $sql_where, $hostfilter, $current_tab, $syslog_incoming_config;

	include(dirname(__FILE__) . "/config.php");

	$sql_where = "";
	/* form the 'where' clause for our main sql query */
	if ($_REQUEST["host"][0] == -1 && $tab != "syslog") {
		$sql_where .=  "WHERE sl.host='N/A'";
	}else{
		if (!empty($_REQUEST["host"])) {
			sql_hosts_where($tab);
			if (strlen($hostfilter)) {
				$sql_where .=  "WHERE " . $hostfilter;
			}
		}
	}

	if (isset($_SESSION["sess_current_date1"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") .
			"logtime BETWEEN '" . $_SESSION["sess_current_date1"] . "'
				AND '" . $_SESSION["sess_current_date2"] . "'";
	}

	if (isset($_REQUEST["id"]) && $current_tab == "current") {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") .
			"sa.id=" . $_REQUEST["id"];
	}

	if (!empty($_REQUEST["filter"])) {
		if ($tab == "syslog") {
			$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . "message LIKE '%%" . $_REQUEST["filter"] . "%%'";
		}else{
			$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . "logmsg LIKE '%%" . $_REQUEST["filter"] . "%%'";
		}
	}

	if (!empty($_REQUEST["efacility"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . "facility_id='" . $_REQUEST["efacility"] . "'";
	}

	if (!empty($_REQUEST["elevel"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE ": " AND ") . "priority_id " . (substr_count($_REQUEST["elevel"], "o") ? "":"<") . "=" . str_replace("o","",$_REQUEST["elevel"]);
	}

	$sql_where = api_plugin_hook_function('syslog_sqlwhere', $sql_where);

	if (!isset($_REQUEST["export"])) {
		$limit = " LIMIT " . ($row_limit*($_REQUEST["page"]-1)) . "," . $row_limit;
	} else {
		$limit = " LIMIT 10000";
	}

	$sort = $_REQUEST["sort_column"];

	if ($tab == "syslog") {
		if ($_REQUEST["removal"] == "-1") {
			$query_sql = "SELECT *, 'main' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog` " .
				$sql_where . "
				ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
				$limit;
		}elseif ($_REQUEST["removal"] == "1") {
			$query_sql = "(SELECT *, 'main' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog` " .
				$sql_where . "
				) UNION (SELECT *, 'remove' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog_removed` " .
				$sql_where . ")
				ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
				$limit;
		}else{
			$query_sql = "SELECT *, 'remove' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog_removed` " .
				$sql_where . "
				ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
				$limit;
		}
	}else{
		$query_sql = "SELECT sl.*, sa.name, sa.severity
			FROM `" . $syslogdb_default . "`.`syslog_logs` AS sl
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
			ON sl.facility=sf.facility
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
			ON sl.priority=sp.priority
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
			ON sl.host=sh.host
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_alert` AS sa
			ON sl.alert_id=sa.id " .
			$sql_where . "
			ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
			$limit;
	}

	//echo $query_sql;

	return syslog_db_fetch_assoc($query_sql);
}

function syslog_filter($sql_where, $tab) {
	global $colors, $config, $graph_timespans, $graph_timeshifts, $reset_multi, $page_refresh_interval;

	include(dirname(__FILE__) . "/config.php");

	if (isset($_SESSION["sess_current_date1"])) {
		$filter_text = "</strong> [ Start: '" . $_SESSION["sess_current_date1"] . "' to End: '" . $_SESSION["sess_current_date2"] . "' ]";
	}else{
		$filter_text = "</strong>";
	}

	?>
	<script type="text/javascript">
	<!--
	// Initialize the calendar
	calendar=null;

	// This function displays the calendar associated to the input field 'id'
	function showCalendar(id) {
		var el = document.getElementById(id);
		if (calendar != null) {
			// we already have some calendar created
			calendar.hide();  // so we hide it first.
		} else {
			// first-time call, create the calendar.
			var cal = new Calendar(true, null, selected, closeHandler);
			cal.weekNumbers = false;  // Do not display the week number
			cal.showsTime = true;     // Display the time
			cal.time24 = true;        // Hours have a 24 hours format
			cal.showsOtherMonths = false;    // Just the current month is displayed
			calendar = cal;                  // remember it in the global var
			cal.setRange(1900, 2070);        // min/max year allowed.
			cal.create();
		}

		calendar.setDateFormat('%Y-%m-%d %H:%M');    // set the specified date format
		calendar.parseDate(el.value);                // try to parse the text in field
		calendar.sel = el;                           // inform it what input field we use

		// Display the calendar below the input field
		calendar.showAtElement(el, "Br");        // show the calendar

		return false;
	}

	// This function update the date in the input field when selected
	function selected(cal, date) {
		cal.sel.value = date;      // just update the date in the input field.
	}

	// This function gets called when the end-user clicks on the 'Close' button.
	// It just hides the calendar without destroying it.
	function closeHandler(cal) {
		cal.hide();                        // hide the calendar
		calendar = null;
	}

	function applyTimespanFilterChange(objForm) {
		strURL = '?predefined_timespan=' + objForm.predefined_timespan.value;
		strURL = strURL + '&predefined_timeshift=' + objForm.predefined_timeshift.value;
		document.location = strURL;
	}
	-->
	</script>
	<form style='margin:0px;padding:0px;' id="syslog_form" name="syslog_form" method="post" action="syslog.php">
	<table width="100%" cellspacing="0" cellpadding="0" border="0">
		<tr>
			<td colspan="2" style="background-color:#EFEFEF;">
				<table width='100%' cellpadding="0" cellspacing="0" border="0">
					<tr>
						<td width='100%'>
							<?php
							html_start_box("<strong>Syslog Message Filter$filter_text", "100%", $colors["header"], "1", "center", "");?>
							<tr bgcolor="<?php print $colors["panel"];?>" class="noprint">
								<td class="noprint">
									<table cellpadding="0" cellspacing="0" border="0">
										<tr>
											<td nowrap style='white-space: nowrap;' width='60'>
												&nbsp;<strong>Presets:</strong>&nbsp;
											</td>
											<td nowrap style='white-space: nowrap;' width='130'>
												<select name='predefined_timespan' onChange="applyTimespanFilterChange(document.syslog_form)">
													<?php
													if ($_SESSION["custom"]) {
														$graph_timespans[GT_CUSTOM] = "Custom";
														$_REQUEST["predefined_timespan"] = GT_CUSTOM;
														$start_val = 0;
														$end_val = sizeof($graph_timespans);
													} else {
														if (isset($graph_timespans[GT_CUSTOM])) {
															asort($graph_timespans);
															array_shift($graph_timespans);
														}
														$start_val = 1;
														$end_val = sizeof($graph_timespans)+1;
													}

													if (sizeof($graph_timespans) > 0) {
														for ($value=$start_val; $value < $end_val; $value++) {
															print "<option value='$value'"; if ($_REQUEST["predefined_timespan"] == $value) { print " selected"; } print ">" . title_trim($graph_timespans[$value], 40) . "</option>\n";
														}
													}
													?>
												</select>
											</td>
											<td nowrap style='white-space: nowrap;' width='30'>
												&nbsp;<strong>From:</strong>&nbsp;
											</td>
											<td width='150' nowrap style='white-space: nowrap;'>
												<input type='text' name='date1' id='date1' title='Graph Begin Timestamp' size='14' value='<?php print (isset($_SESSION["sess_current_date1"]) ? $_SESSION["sess_current_date1"] : "");?>'>
												&nbsp;<input style='padding-bottom: 4px;' type='image' src='<?php print $config["url_path"];?>images/calendar.gif' alt='Start date selector' title='Start date selector' border='0' align='absmiddle' onclick="return showCalendar('date1');">&nbsp;
											</td>
											<td nowrap style='white-space: nowrap;' width='20'>
												&nbsp;<strong>To:</strong>&nbsp;
											</td>
											<td width='150' nowrap style='white-space: nowrap;'>
												<input type='text' name='date2' id='date2' title='Graph End Timestamp' size='14' value='<?php print (isset($_SESSION["sess_current_date2"]) ? $_SESSION["sess_current_date2"] : "");?>'>
												&nbsp;<input style='padding-bottom: 4px;' type='image' src='<?php print $config["url_path"];?>images/calendar.gif' alt='End date selector' title='End date selector' border='0' align='absmiddle' onclick="return showCalendar('date2');">
											</td>
											<td width='125' nowrap style='white-space: nowrap;'>
												&nbsp;&nbsp;<input style='padding-bottom: 4px;' type='image' name='move_left' src='<?php print $config["url_path"];?>images/move_left.gif' alt='Left' border='0' align='absmiddle' title='Shift Left'>
												<select name='predefined_timeshift' title='Define Shifting Interval' onChange="applyTimespanFilterChange(document.syslog_form)">
													<?php
													$start_val = 1;
													$end_val = sizeof($graph_timeshifts)+1;
													if (sizeof($graph_timeshifts) > 0) {
														for ($shift_value=$start_val; $shift_value < $end_val; $shift_value++) {
															print "<option value='$shift_value'"; if ($_REQUEST["predefined_timeshift"] == $shift_value) { print " selected"; } print ">" . title_trim($graph_timeshifts[$shift_value], 40) . "</option>\n";
														}
													}
													?>
												</select>
												<input style='padding-bottom: 4px;' type='image' name='move_right' src='<?php print $config["url_path"];?>images/move_right.gif' alt='Right' border='0' align='absmiddle' title='Shift Right'>
											</td>
											<td>
												&nbsp;<input type="submit" value='Go' name='go' title="Go">
											</td>
											<td>
												&nbsp;<input type='submit' value='Clear' name='button_clear_x' title='Return to the default time span'>
											</td>
											<td>
												&nbsp;<input type='submit' value='Export' name='export' title='Export Records to CSV'>
											</td>
											<td>
												<input type='hidden' name='action' value='actions'>
												<input type='hidden' name='syslog_pdt_change' value='false'>
											</td>
										</tr>
									</table>
								</td><?php if (api_plugin_user_realm_auth('syslog_alerts.php')) {?>
								<td align='right' style='white-space:nowrap;'>
									<input type='button' value='Alerts' title='View Syslog Alert Rules' onClick='javascript:document.location="<?php print $config['url_path'] . "plugins/syslog/syslog_alerts.php";?>"'>
									<input type='button' value='Removals' title='View Syslog Removal Rules' onClick='javascript:document.location="<?php print $config['url_path'] . "plugins/syslog/syslog_removal.php";?>"'>
									<input type='button' value='Reports' title='View Syslog Reports' onClick='javascript:document.location="<?php print $config['url_path'] . "plugins/syslog/syslog_reports.php";?>"'>&nbsp;
								</td><?php }?>
							</tr>
						</table>
						<table width="100%" cellpadding="0" cellspacing="0" border="0">
							<tr bgcolor="<?php print $colors["panel"];?>" class="noprint">
								<td>
									<table cellpadding="0" cellspacing="0">
										<tr>
											<td nowrap style='white-space: nowrap;' width='60'>
												&nbsp;<strong>Search:</strong>
											</td>
											<td style='padding-right:2px;'>
												<input type="text" name="filter" size="30" value="<?php print $_REQUEST["filter"];?>">
											</td>
											<?php api_plugin_hook('syslog_extend_filter');?>
											<td style='padding-right:2px;'>
												<select name="efacility" onChange="javascript:document.getElementById('syslog_form').submit();" title="Facilities">
													<option value="0"<?php if ($_REQUEST["efacility"] == "0") {?> selected<?php }?>>All Facilities</option>
													<?php
													if (!isset($hostfilter)) $hostfilter = "";
													$efacilities = syslog_db_fetch_assoc("SELECT DISTINCT f.facility_id, f.facility
														FROM `" . $syslogdb_default . "`.`syslog_host_facilities` AS fh
														INNER JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS f
														ON f.facility_id=fh.facility_id " . (strlen($hostfilter) ? "WHERE ":"") . $hostfilter . "
														ORDER BY facility");

													if (sizeof($efacilities)) {
													foreach ($efacilities as $efacility) {
														print "<option value=" . $efacility["facility_id"]; if ($_REQUEST["efacility"] == $efacility["facility_id"]) { print " selected"; } print ">" . ucfirst($efacility["facility"]) . "</option>\n";
													}
													}
													?>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="elevel" onChange="javascript:document.getElementById('syslog_form').submit();" title="Priority Levels">
													<option value="0"<?php if ($_REQUEST["elevel"] == "0") {?> selected<?php }?>>All Priorities</option>
													<option value="1"<?php if ($_REQUEST["elevel"] == "1") {?> selected<?php }?>>Emergency</option>
													<option value="2"<?php if ($_REQUEST["elevel"] == "2") {?> selected<?php }?>>Critical++</option>
													<option value="2o"<?php if ($_REQUEST["elevel"] == "2o") {?> selected<?php }?>>Critical</option>
													<option value="3"<?php if ($_REQUEST["elevel"] == "3") {?> selected<?php }?>>Alert++</option>
													<option value="3o"<?php if ($_REQUEST["elevel"] == "3o") {?> selected<?php }?>>Alert</option>
													<option value="4"<?php if ($_REQUEST["elevel"] == "4") {?> selected<?php }?>>Error++</option>
													<option value="4o"<?php if ($_REQUEST["elevel"] == "4o") {?> selected<?php }?>>Error</option>
													<option value="5"<?php if ($_REQUEST["elevel"] == "5") {?> selected<?php }?>>Warning++</option>
													<option value="5o"<?php if ($_REQUEST["elevel"] == "5o") {?> selected<?php }?>>Warning</option>
													<option value="6"<?php if ($_REQUEST["elevel"] == "6") {?> selected<?php }?>>Notice++</option>
													<option value="6o"<?php if ($_REQUEST["elevel"] == "6o") {?> selected<?php }?>>Notice</option>
													<option value="7"<?php if ($_REQUEST["elevel"] == "7") {?> selected<?php }?>>Info++</option>
													<option value="7o"<?php if ($_REQUEST["elevel"] == "7o") {?> selected<?php }?>>Info</option>
													<option value="8"<?php if ($_REQUEST["elevel"] == "8") {?> selected<?php }?>>Debug</option>
												</select>
											</td>
											<?php if ($_REQUEST["tab"] == "syslog") {?>
											<td style='padding-right:2px;'>
												<select name="removal" onChange="javascript:document.getElementById('syslog_form').submit();" title="Removal Handling">
													<option value="1"<?php if ($_REQUEST["removal"] == "1") {?> selected<?php }?>>All Records</option>
													<option value="-1"<?php if ($_REQUEST["removal"] == "-1") {?> selected<?php }?>>Main Records</option>
													<option value="2"<?php if ($_REQUEST["removal"] == "2") {?> selected<?php }?>>Removed Records</option>
												</select>
											</td>
											<?php }?>
											<td style='padding-right:2px;'>
												<select name="rows" onChange="javascript:document.getElementById('syslog_form').submit();" title="Display Rows">
													<option value="10"<?php if ($_REQUEST["rows"] == "10") {?> selected<?php }?>>10</option>
													<option value="15"<?php if ($_REQUEST["rows"] == "15") {?> selected<?php }?>>15</option>
													<option value="20"<?php if ($_REQUEST["rows"] == "20") {?> selected<?php }?>>20</option>
													<option value="25"<?php if ($_REQUEST["rows"] == "25") {?> selected<?php }?>>25</option>
													<option value="30"<?php if ($_REQUEST["rows"] == "30") {?> selected<?php }?>>30</option>
													<option value="35"<?php if ($_REQUEST["rows"] == "35") {?> selected<?php }?>>35</option>
													<option value="40"<?php if ($_REQUEST["rows"] == "40") {?> selected<?php }?>>40</option>
													<option value="45"<?php if ($_REQUEST["rows"] == "45") {?> selected<?php }?>>45</option>
													<option value="50"<?php if ($_REQUEST["rows"] == "50") {?> selected<?php }?>>50</option>
													<option value="100"<?php if ($_REQUEST["rows"] == "100") {?> selected<?php }?>>100</option>
													<option value="200"<?php if ($_REQUEST["rows"] == "200") {?> selected<?php }?>>200</option>
													<option value="500"<?php if ($_REQUEST["rows"] == "500") {?> selected<?php }?>>500</option>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="trimval" onChange="javascript:document.getElementById('syslog_form').submit();" title="Message Trim">
													<option value="1024"<?php if ($_REQUEST["trimval"] == "1024") {?> selected<?php }?>>All Text</option>
													<option value="30"<?php if ($_REQUEST["trimval"] == "30") {?> selected<?php }?>>30 Chars</option>
													<option value="50"<?php if ($_REQUEST["trimval"] == "50") {?> selected<?php }?>>50 Chars</option>
													<option value="75"<?php if ($_REQUEST["trimval"] == "75") {?> selected<?php }?>>75 Chars</option>
													<option value="100"<?php if ($_REQUEST["trimval"] == "100") {?> selected<?php }?>>100 Chars</option>
													<option value="150"<?php if ($_REQUEST["trimval"] == "150") {?> selected<?php }?>>150 Chars</option>
													<option value="300"<?php if ($_REQUEST["trimval"] == "300") {?> selected<?php }?>>300 Chars</option>
												</select>
											</td>
											<td width="1">
												<select name="refresh" onChange="javascript:document.getElementById('syslog_form').submit();">
													<?php
													foreach($page_refresh_interval AS $seconds => $display_text) {
														print "<option value='" . $seconds . "'"; if ($_REQUEST["refresh"] == $seconds) { print " selected"; } print ">" . $display_text . "</option>\n";
													}
													?>
												</select>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<?php html_end_box(false);?>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
			<td valign="top" style="border-right: #aaaaaa 1px solid;" bgcolor='#efefef'>
				<table align="center" cellpadding="1" cellspacing="0" border="0">
					<tr>
						<td>
							<?php html_start_box("", "", $colors["header"], "3", "center", ""); ?>
							<tr>
								<td class="textHeader" nowrap>
									Select Host(s):&nbsp;
								</td>
							</tr>
							<tr>
								<td>
									<select title="Host Filters" id="host_select" name="host[]" multiple size="20" style="width: 150px; overflow: scroll; height: auto;" onChange="javascript:document.getElementById('syslog_form').submit();">
										<?php if ($tab == "syslog") { ?><option id="host_all" value="0"<?php if (((is_array($_REQUEST["host"])) && ($_REQUEST["host"][0] == "0")) || ($reset_multi)) {?> selected<?php }?>>Show All Hosts</option><?php }else{?>
										<option id="host_all" value="0"<?php if (((is_array($_REQUEST["host"])) && ($_REQUEST["host"][0] == "0")) || ($reset_multi)) {?> selected<?php }?>>Show All Logs</option>
										<option id="host_none" value="-1"<?php if (((is_array($_REQUEST["host"])) && ($_REQUEST["host"][0] == "-1"))) {?> selected<?php }?>>Threshold Logs</option><?php }?>
										<?php
										$hosts_where = "";
										$hosts_where = api_plugin_hook_function('syslog_hosts_where', $hosts_where);
										$hosts = syslog_db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_hosts` $hosts_where ORDER BY host");
										if (sizeof($hosts)) {
											foreach ($hosts as $host) {
												print "<option value=" . $host["host_id"];
												if (sizeof($_REQUEST["host"])) {
													foreach ($_REQUEST["host"] as $rh) {
														if (($rh == $host["host_id"]) &&
															(!$reset_multi)) {
															print " selected";
															break;
														}
													}
												}else{
													if (($host["host_id"] == $_REQUEST["host"]) &&
														(!$reset_multi)) {
														print " selected";
													}
												}
												print ">";
												print $host["host"] . "</option>\n";
											}
										}
										?>
									</select>
								</td>
							</tr>
							<?php html_end_box(false); ?>
						</td>
					</tr>
				</table>
			</td>
			<td width="100%" valign="top" style="padding: 0px;">
				<table width="100%" cellspacing="0" cellpadding="1">
					<tr>
						<td width="100%" valign="top"><?php display_output_messages();?>
							<?php
							if ($tab == "syslog") {
								if ($_REQUEST["removal"] == 1) {
									$total_rows = syslog_db_fetch_cell("SELECT SUM(totals)
											FROM (
											SELECT count(*) AS totals
											FROM `" . $syslogdb_default . "`.`syslog` " . $sql_where . "
											UNION
											SELECT count(*) AS totals
											FROM `" . $syslogdb_default . "`.`syslog_removed` " . $sql_where . ") AS rowcount");
								}elseif ($_REQUEST["removal"] == -1){
									$total_rows = syslog_db_fetch_cell("SELECT count(*) FROM `" . $syslogdb_default . "`.`syslog` " . $sql_where);
								}else{
									$total_rows = syslog_db_fetch_cell("SELECT count(*) FROM `" . $syslogdb_default . "`.`syslog_removed` " . $sql_where);
								}
							}else{
								$total_rows = syslog_db_fetch_cell("SELECT count(*)
									FROM `" . $syslogdb_default . "`.`syslog_logs` AS sl
									LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
									ON sl.facility=sf.facility
									LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
									ON sl.priority=sp.priority
									LEFT JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
									ON sl.host=sh.host
									LEFT JOIN `" . $syslogdb_default . "`.`syslog_alert` AS sa
									ON sl.alert_id=sa.id " .
									$sql_where);
							}
							html_start_box("", "100%", $colors["header"], "3", "center", "");
							$hostarray = "";
							if (is_array($_REQUEST["host"])) {
								foreach ($_REQUEST["host"] as $h) {
									$hostarray .= "host[]=$h&";
								}
							}else{
								$hostarray .= "host[]=" . $_REQUEST["host"] . "&";
							}

	return $total_rows;
}

/** function syslog_syslog_legend()
 *  This function displays the foreground and background colors for the syslog syslog legend
*/
function syslog_syslog_legend() {
	global $colors, $disabled_color, $notmon_color, $database_default;

	html_start_box("", "100%", $colors["header"], "3", "center", "");
	print "<tr>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_emerg_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_emerg_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Emergency</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_crit_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_crit_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Critical</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_alert_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_alert_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Alert</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_err_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_err_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Error</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_warn_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_warn_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Warning</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_notice_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_notice_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Notice</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_info_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_info_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Info</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_debug_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_debug_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Debug</b></td>";

	print "</tr>";
	html_end_box(false);
}

/** function syslog_log_legend()
 *  This function displays the foreground and background colors for the syslog log legend
*/
function syslog_log_legend() {
	global $colors, $disabled_color, $notmon_color, $database_default;

	html_start_box("", "100%", $colors["header"], "3", "center", "");
	print "<tr>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_crit_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_crit_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Critical</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_warn_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_warn_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Warning</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_notice_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_notice_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Notice</b></td>";

	$bg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_info_bg") . "'");
	$fg_color = db_fetch_cell("SELECT hex from `$database_default`.`colors` WHERE id='" . read_config_option("syslog_info_fg") . "'");
	print "<td width='10%' style='text-align:center;color:#$fg_color;background-color:#$bg_color;'><b>Informational</b></td>";

	print "</tr>";
	html_end_box(false);
}

/** function syslog_messages()
 *  This is the main page display function in Syslog.  Displays all the
 *  syslog messages that are relevant to Syslog.
*/
function syslog_messages($tab="syslog") {
	global $colors, $sql_where, $hostfilter, $severities;
	global $config, $syslog_incoming_config, $reset_multi, $syslog_levels;

	include("./include/global_arrays.php");

	/* force the initial timespan to be 30 minutes for performance reasons */
	if (!isset($_SESSION["sess_syslog_init"])) {
		$_SESSION["sess_current_timespan"] = 1;
		$_SESSION["sess_syslog_init"] = 1;
	}

	if (file_exists("./lib/timespan_settings.php")) {
		include("./lib/timespan_settings.php");
	}else{
		include("./include/html/inc_timespan_settings.php");
	}
	include(dirname(__FILE__) . "/config.php");

	/* create the custom css and javascript for the page */
	generate_syslog_cssjs();

	$url_curr_page = get_browser_query_string();

	$sql_where = "";

	if ($_REQUEST["rows"] == -1) {
		$row_limit = read_config_option("num_rows_syslog");
	}elseif ($_REQUEST["rows"] == -2) {
		$row_limit = 999999;
	}else{
		$row_limit = $_REQUEST["rows"];
	}

	$syslog_messages = get_syslog_messages($sql_where, $row_limit, $tab);
	$total_rows      = syslog_filter($sql_where, $tab);

	/* generate page list */
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $row_limit, $total_rows, "syslog.php?tab=$tab");

	if ($total_rows > 0) {
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
					<td colspan='13'>
						<table width='100%' cellspacing='0' cellpadding='0' border='0'>
							<tr>
								<td align='left' class='textHeaderDark'>
									<strong>&lt;&lt; "; if ($_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='syslog.php?tab=$tab&page=" . ($_REQUEST["page"]-1) . "'>"; } $nav .= "Previous"; if ($_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
								</td>\n
								<td align='center' class='textHeaderDark'>
									Showing Rows " . ($total_rows == 0 ? "None" : (($row_limit*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $row_limit) || ($total_rows < ($row_limit*$_REQUEST["page"]))) ? $total_rows : ($row_limit*$_REQUEST["page"])) . " of $total_rows [$url_page_select]") . "
								</td>\n
								<td align='right' class='textHeaderDark'>
									<strong>"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "<a class='linkOverDark' href='syslog.php?tab=$tab&page=" . ($_REQUEST["page"]+1) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $row_limit) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
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

	if ($tab == "syslog") {
		if (api_plugin_user_realm_auth('syslog_alerts.php')) {
			$display_text = array(
				"nosortt" => array("Actions", "ASC"),
				"host_id" => array("Host", "ASC"),
				"logtime" => array("Date", "ASC"),
				"message" => array("Message", "ASC"),
				"facility_id" => array("Facility", "ASC"),
				"priority_id" => array("Priority", "ASC"));
		}else{
			$display_text = array(
				"host_id" => array("Host", "ASC"),
				"logtime" => array("Date", "ASC"),
				"message" => array("Message", "ASC"),
				"facility_id" => array("Facility", "ASC"),
				"priority_id" => array("Priority", "ASC"));
		}

		html_header_sort($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

		$hosts      = array_rekey(syslog_db_fetch_assoc("SELECT host_id, host FROM `" . $syslogdb_default . "`.`syslog_hosts`"), "host_id", "host");
		$facilities = array_rekey(syslog_db_fetch_assoc("SELECT facility_id, facility FROM `" . $syslogdb_default . "`.`syslog_facilities`"), "facility_id", "facility");
		$priorities = array_rekey(syslog_db_fetch_assoc("SELECT priority_id, priority FROM `" . $syslogdb_default . "`.`syslog_priorities`"), "priority_id", "priority");

		$i = 0;
		if (sizeof($syslog_messages) > 0) {
			foreach ($syslog_messages as $syslog_message) {
				$title   = "'" . str_replace("\"", "", str_replace("'", "", $syslog_message["message"])) . "'";
				$tip_options = "CLICKCLOSE, 'true', WIDTH, '40', DELAY, '500', FOLLOWMOUSE, 'true', FADEIN, 450, FADEOUT, 450, BGCOLOR, '#F9FDAF', STICKY, 'true', SHADOWCOLOR, '#797C6E', TITLE, 'Message'";

				syslog_row_color($colors["alternate"], $colors["light"], $i, $priorities[$syslog_message["priority_id"]], $title);$i++;

				if (api_plugin_user_realm_auth('syslog_alerts.php')) {
					print "<td style='whitspace-nowrap;width:1%;'>";
					if ($syslog_message['mtype'] == 'main') {
						print "<a href='syslog_alerts.php?id=" . $syslog_message[$syslog_incoming_config["id"]] . "&date=" . $syslog_message["logtime"] . "&action=newedit&type=0'><img src='images/green.gif' align='absmiddle' border=0></a>
						<a href='syslog_removal.php?id=" . $syslog_message[$syslog_incoming_config["id"]] . "&date=" . $syslog_message["logtime"] . "&action=newedit&type=new&type=0'><img src='images/red.gif' align='absmiddle' border=0></a>\n";
					}
					print "</td>\n";
				}
				print "<td>" . $hosts[$syslog_message["host_id"]] . "</td>\n";
				print "<td>" . $syslog_message["logtime"] . "</td>\n";
				print "<td>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim($syslog_message[$syslog_incoming_config["textField"]], get_request_var_request("trimval"))):title_trim($syslog_message[$syslog_incoming_config["textField"]], get_request_var_request("trimval"))) . "</td>\n";
				print "<td>" . ucfirst($facilities[$syslog_message["facility_id"]]) . "</td>\n";
				print "<td>" . ucfirst($priorities[$syslog_message["priority_id"]]) . "</td>\n";
			}
		}else{
			print "<tr><td><em>No Messages</em></td></tr>";
		}

		print $nav;
		html_end_box(false);

		syslog_syslog_legend();
	}else{
		$display_text = array(
			"name" => array("Alert Name", "ASC"),
			"severity" => array("Severity", "ASC"),
			"count" => array("Count", "ASC"),
			"logtime" => array("Date", "ASC"),
			"logmsg" => array("Message", "ASC"),
			"slhost" => array("Host", "ASC"),
			"facility" => array("Facility", "ASC"),
			"priority" => array("Priority", "ASC"));

		html_header_sort($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

		$i = 0;
		if (sizeof($syslog_messages) > 0) {
			foreach ($syslog_messages as $log) {
				$title   = "'" . str_replace("\"", "", str_replace("'", "", $log["logmsg"])) . "'";
				$tip_options = "CLICKCLOSE, 'true', WIDTH, '40', DELAY, '500', FOLLOWMOUSE, 'true', FADEIN, 450, FADEOUT, 450, BGCOLOR, '#F9FDAF', STICKY, 'true', SHADOWCOLOR, '#797C6E', TITLE, 'Message'";
				switch ($log['severity']) {
				case "0":
					$color = "notice";
					break;
				case "1":
					$color = "warn";
					break;
				case "2":
					$color = "crit";
					break;
				default:
					$color = "info";
					break;
				}

				syslog_row_color($colors["alternate"], $colors["light"], $i, $color, $title);$i++;
				print "<td><a class='linkEditMain' href='" . $config["url_path"] . "plugins/syslog/syslog.php?id=" . $log["seq"] . "&tab=current'>" . (strlen($log["name"]) ? $log["name"]:"Alert Removed") . "</a></td>\n";
				print "<td>" . (isset($severities[$log["severity"]]) ? $severities[$log["severity"]]:"Unknown") . "</td>\n";
				print "<td>" . $log["count"] . "</td>\n";
				print "<td>" . $log["logtime"] . "</td>\n";
				print "<td>" . (strlen($_REQUEST["filter"]) ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim($log["logmsg"], get_request_var_request("trimval"))):title_trim($log["logmsg"], get_request_var_request("trimval"))) . "</td>\n";
				print "<td>" . $log["host"] . "</td>\n";
				print "<td>" . ucfirst($log["facility"]) . "</td>\n";
				print "<td>" . ucfirst($log["priority"]) . "</td>\n";
			}
		}else{
			print "<tr><td><em>No Messages</em></td></tr>";
		}

		print $nav;
		html_end_box(false);

		syslog_log_legend();
	}

	/* put the nav bar on the bottom as well */
									?>

								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
			</form>
			<script type='text/javascript'>
			function syslogFindPos(obj) {
				var curleft = curtop = 0;

				if (obj.offsetParent) {
					curleft = obj.offsetLeft;
					curtop  = obj.offsetTop;

					while (obj = obj.offsetParent) {
						curleft += obj.offsetLeft;
						curtop  += obj.offsetTop;
					}
				}

				return [curleft,curtop];
			}

			function setHostMultiSelect() {
				selectPos = syslogFindPos(document.getElementById("host_select"));
				textSize  = document.getElementById("host_all").scrollHeight;
				if (textSize == 0) textSize = 16;

				if (window.innerHeight) {
					height = window.innerHeight;
				}else{
					height = document.body.clientHeight;
				}
				//alert("Height:"+height+", YPos:"+selectPos[1]+", TextSize:"+textSize);

				/* the full window size of the multi-select */
				size = parseInt((height-selectPos[1]-5)/textSize);
				window.onresize = null;
				document.getElementById("host_select").size=size;
				window.onresize = this;
			}

			window.onresize = setHostMultiSelect;
			window.onload   = setHostMultiSelect;
			</script>
<?php
}
?>
