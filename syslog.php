<?php
/*
 ex: set tabstop=4 shiftwidth=4 autoindent:
 +-------------------------------------------------------------------------+
 | Copyright (C) 2005 Electric Sheep Studios                               |
 | Originally by Shitworks, 2004                                           |
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
 | h.aloe: a syslog monitoring addon for Ian Berry's Cacti                 |
 +-------------------------------------------------------------------------+
 | Originally released as aloe by: sidewinder at shitworks.com             |
 | Modified by: Harlequin <harlequin@cyberonic.com>                        |
 | 2005-11-10 -- ver 0.1.1 beta                                            |
 |   - renamed to h.aloe                                                   |
 |   - updated to work with Cacti 8.6g                                     |
 |   - included Cacti time selector                                        |
 |   - various other modifications                                         |
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
	cacti_log("SYSLOG: You are missing a required dependency, please install the '<a href='http://cactiusers.org/'>Settings'</a> plugin.", true, "POLLER");
	print "<br><br><center><font color=red>You are missing a dependency for Syslog, please install the '<a href='http://cactiusers.org'>Settings</a>' plugin.</font></color>";
	exit;
}

/* validate the syslog post/get/request information */;
syslog_request_validation();

/* display the main page */
if (isset($_REQUEST["export_x"])) {
	syslog_export();

	/* clear output so reloads wont re-download */
	unset($_REQUEST["output"]);
}else{
	include_once(dirname(__FILE__) . "/include/top_syslog_header.php");

	syslog_messages();

	include_once("./include/bottom_footer.php");
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
		if ((isset($syslog_text_colors[$type]) && $syslog_text_colors[$type] > 0)) {
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

/** function syslog_request_validation()
 *  This is a generic funtion for this page that makes sure that
 *  we have a good request.  We want to protect against people who
 *  like to create issues with Cacti.
*/
function syslog_request_validation() {
	global $title, $colors, $rows, $config, $reset_multi;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("rows"));
	input_validate_input_number(get_request_var_request("removal"));
	input_validate_input_number(get_request_var_request("refresh"));
	input_validate_input_number(get_request_var_request("page"));
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
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort direction */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* if the user pushed the 'clear' button */
	if (isset($_REQUEST["button_clear_x"])) {
		kill_session_var("sess_syslog_hosts");
		kill_session_var("sess_syslog_rows");
		kill_session_var("sess_syslog_removal");
		kill_session_var("sess_syslog_refresh");
		kill_session_var("sess_syslog_page");
		kill_session_var("sess_syslog_filter");
		kill_session_var("sess_syslog_efacility");
		kill_session_var("sess_syslog_elevel");
		kill_session_var("sess_syslog_sort_column");
		kill_session_var("sess_syslog_sort_direction");

		$_REQUEST["page"] = 1;
		unset($_REQUEST["hosts"]);
		unset($_REQUEST["rows"]);
		unset($_REQUEST["removal"]);
		unset($_REQUEST["refresh"]);
		unset($_REQUEST["page"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["efacility"]);
		unset($_REQUEST["elevel"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);
		$reset_multi = true;
	}else{
		/* if any of the settings changed, reset the page number */
		$changed = 0;
		$changed += check_changed("hosts", "sess_syslog_hosts");
		$changed += check_changed("predefined_timespan", "sess_current_timespan");
		$changed += check_changed("date1", "sess_current_date1");
		$changed += check_changed("date2", "sess_current_date2");
		$changed += check_changed("rows", "sess_syslog_rows");
		$changed += check_changed("removal", "sess_syslog_removal");
		$changed += check_changed("refresh", "sess_syslog_refresh");
		$changed += check_changed("filter", "sess_syslog_filter");
		$changed += check_changed("efacility", "sess_syslog_efacility");
		$changed += check_changed("elevel", "sess_syslog_elevel");
		$changed += check_changed("sort_column", "sess_syslog_sort_column");
		$changed += check_changed("sort_direction", "sess_syslog_sort_direction");

		if ($changed) {
			$_REQUEST["page"] = "1";
		}

		$reset_multi = false;
	}

	/* remember search fields in session vars */
	load_current_session_value("page", "sess_syslog_page", "1");
	load_current_session_value("rows", "sess_syslog_rows", read_config_option("num_rows_syslog"));
	load_current_session_value("refresh", "sess_syslog_refresh", read_config_option("syslog_refresh"));
	load_current_session_value("removal", "sess_syslog_removal", "-1");
	load_current_session_value("filter", "sess_syslog_filter", "");
	load_current_session_value("efacility", "sess_syslog_efacility", "0");
	load_current_session_value("elevel", "sess_syslog_elevel", "0");
	load_current_session_value("hosts", "sess_syslog_hosts", "localhost");
	load_current_session_value("sort_column", "sess_syslog_sort_column", "logtime");
	load_current_session_value("sort_direction", "sess_syslog_sort_direction", "DESC");

	if (isset($_REQUEST["host"])) {
		$_SESSION["sess_syslog_hosts"] = $_REQUEST["host"];
	} else if (isset($_SESSION["sess_syslog_hosts"])) {
		$_REQUEST["host"] = $_SESSION["sess_syslog_hosts"];
	} else {
		$_REQUEST["host"][0] = "0"; /* default value */
	}
}

function get_syslog_messages(&$sql_where) {
	global $sql_where, $syslog_cnn, $hostfilter, $syslog_incoming_config;

	$sql_where = "";
	/* form the 'where' clause for our main sql query */
	if (!empty($_REQUEST["host"])) {
		sql_hosts_where();
		if (strlen($hostfilter)) {
			$sql_where .=  "WHERE " . $hostfilter;
		}
	}

	if (isset($_SESSION["sess_current_date1"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") .
			"logtime>='" . date("Y-m-d", strtotime($_SESSION["sess_current_date1"])) . "'";
	}

	if (!empty($_REQUEST["filter"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . $syslog_incoming_config["textField"] . " LIKE '%%" . $_REQUEST["filter"] . "%%'";
	}

	if (!empty($_REQUEST["efacility"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . $syslog_incoming_config["facilityField"] . "='" . $_REQUEST["efacility"] . "'";
	}

	if (!empty($_REQUEST["elevel"])) {
		switch($_REQUEST["elevel"]) {
		case 1:
			$mysql_in = "IN('emer')";

			break;
		case 2:
			$mysql_in = "IN('emer', 'alert')";

			break;
		case 3:
			$mysql_in = "IN('emer', 'alert', 'crit')";

			break;
		case 4:
			$mysql_in = "IN('emer', 'alert', 'crit', 'err')";

			break;
		case 5:
			$mysql_in = "IN('emer', 'alert', 'crit', 'err', 'warn')";

			break;
		case 6:
			$mysql_in = "IN('emer', 'alert', 'crit', 'err', 'warn', 'notice')";

			break;
		case 7:
			$mysql_in = "IN('emer', 'alert', 'crit', 'err', 'warn', 'notice', 'info')";

			break;
		case 8:
			$mysql_in = "IN('debug')";

			break;
		default:
		}

		$sql_where .= (!strlen($sql_where) ? "WHERE ": " AND ") . $syslog_incoming_config["priorityField"] . " " . $mysql_in;
	}

	if (!isset($_REQUEST["export_x"])) {
		$limit = " LIMIT " . ($_REQUEST["rows"]*($_REQUEST["page"]-1)) . "," . $_REQUEST["rows"];
	} else {
		$limit = " LIMIT 10000";
	}

	if ($_REQUEST["sort_column"] == "date") {
		$sort = "ADDTIME(date, time)";
	}else{
		$sort = $_REQUEST["sort_column"];
	}

	if ($_REQUEST["removal"] == "-1") {
		$query_sql = "SELECT *
			FROM syslog " .
			$sql_where . "
			ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
			$limit;
	}else{
		$query_sql = "(SELECT *
			FROM syslog " .
			$sql_where . "
			) UNION (SELECT *
			FROM syslog_removed " .
			$sql_where . ")
			ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
			$limit;
	}

	//echo $query_sql;

	return db_fetch_assoc($query_sql, true, $syslog_cnn);
}

function syslog_filter($sql_where) {
	global $colors, $config, $syslog_cnn, $graph_timespans, $graph_timeshifts;

	include("./plugins/syslog/syslog_timespan_settings.php");

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
	<form style='margin:0px;padding:0px;' id="syslog_form" name="syslog_timespan_selector" method="post" action="syslog.php">
	<table width="100%" cellspacing="1" cellpadding="0">
		<tr>
			<td colspan="2" style="background-color:#EFEFEF;">
				<table width='100%' cellpadding=0 cellspacing=0>
					<tr>
						<td width='100%'>
							<?php
							html_start_box("<strong>Syslog Message Filter</strong>", "100%", $colors["header"], "1", "center", "");?>
							<tr bgcolor="<?php print $colors["panel"];?>" class="noprint">
								<td class="noprint">
									<table cellpadding="0" cellspacing="0">
										<tr>
											<td nowrap style='white-space: nowrap;' width='60'>
												&nbsp;<strong>Presets:</strong>&nbsp;
											</td>
											<td nowrap style='white-space: nowrap;' width='130'>
												<select name='predefined_timespan' onChange="applyTimespanFilterChange(document.syslog_timespan_selector)">
													<?php
													if ($_SESSION["custom"]) {
														$graph_timespans[GT_CUSTOM] = "Custom";
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
															print "<option value='$value'"; if ($_SESSION["sess_current_timespan"] == $value) { print " selected"; } print ">" . title_trim($graph_timespans[$value], 40) . "</option>\n";
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
											<td width='130' nowrap style='white-space: nowrap;'>
												&nbsp;&nbsp;<input style='padding-bottom: 4px;' type='image' name='move_left' src='<?php print $config["url_path"];?>images/move_left.gif' alt='Left' border='0' align='absmiddle' title='Shift Left'>
												<select name='predefined_timeshift' title='Define Shifting Interval' onChange="applyTimespanFilterChange(document.syslog_timespan_selector)">
													<?php
													$start_val = 1;
													$end_val = sizeof($graph_timeshifts)+1;
													if (sizeof($graph_timeshifts) > 0) {
														for ($shift_value=$start_val; $shift_value < $end_val; $shift_value++) {
															print "<option value='$shift_value'"; if ($_SESSION["sess_current_timeshift"] == $shift_value) { print " selected"; } print ">" . title_trim($graph_timeshifts[$shift_value], 40) . "</option>\n";
														}
													}
													?>
												</select>
												<input style='padding-bottom: 4px;' type='image' name='move_right' src='<?php print $config["url_path"];?>images/move_right.gif' alt='Right' border='0' align='absmiddle' title='Shift Right'>
											</td>
										</tr>
									</table>
								</td>
							</tr>
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
											<td style='padding-right:2px;'>
												<select name="efacility" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="0"<?php if ($_REQUEST["efacility"] == "0") {?> selected<?php }?>>All Facilities</option>
													<?php
													$efacilities = db_fetch_assoc("SELECT DISTINCT " . $syslog_incoming_config["facilityField"] . "
														FROM syslog_facilities " . (strlen($hostfilter) ? "WHERE ":"") . $hostfilter . "
														ORDER BY " . $syslog_incoming_config["facilityField"]);

													if (sizeof($efacilities) > 0) {
													foreach ($efacilities as $efacility) {
														print "<option value=" . $efacility[$syslog_incoming_config["facilityField"]]; if ($_REQUEST["efacility"] == $efacility[$syslog_incoming_config["facilityField"]]) { print " selected"; } print ">" . ucfirst($efacility[$syslog_incoming_config["facilityField"]]) . "</option>\n";
													}
													}
													?>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="elevel" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="0"<?php if ($_REQUEST["elevel"] == "0") {?> selected<?php }?>>All Priorities</option>
													<option value="1"<?php if ($_REQUEST["elevel"] == "1") {?> selected<?php }?>>Emergency</option>
													<option value="2"<?php if ($_REQUEST["elevel"] == "2") {?> selected<?php }?>>Alert++</option>
													<option value="3"<?php if ($_REQUEST["elevel"] == "3") {?> selected<?php }?>>Critical++</option>
													<option value="4"<?php if ($_REQUEST["elevel"] == "4") {?> selected<?php }?>>Error++</option>
													<option value="5"<?php if ($_REQUEST["elevel"] == "5") {?> selected<?php }?>>Warning++</option>
													<option value="6"<?php if ($_REQUEST["elevel"] == "6") {?> selected<?php }?>>Notice++</option>
													<option value="7"<?php if ($_REQUEST["elevel"] == "7") {?> selected<?php }?>>Info++</option>
													<option value="8"<?php if ($_REQUEST["elevel"] == "8") {?> selected<?php }?>>Debug</option>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="removal" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="-1"<?php if ($_REQUEST["removal"] == "-1") {?> selected<?php }?>>Exclude Removed</option>
													<option value="1"<?php if ($_REQUEST["removal"] == "1") {?> selected<?php }?>>Include Removed</option>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="rows" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="10"<?php if ($_REQUEST["rows"] == "10") {?> selected<?php }?>>10</option>
													<option value="15"<?php if ($_REQUEST["rows"] == "15") {?> selected<?php }?>>15</option>
													<option value="20"<?php if ($_REQUEST["rows"] == "20") {?> selected<?php }?>>20</option>
													<option value="25"<?php if ($_REQUEST["rows"] == "25") {?> selected<?php }?>>25</option>
													<option value="30"<?php if ($_REQUEST["rows"] == "30") {?> selected<?php }?>>30</option>
													<option value="50"<?php if ($_REQUEST["rows"] == "50") {?> selected<?php }?>>50</option>
													<option value="100"<?php if ($_REQUEST["rows"] == "100") {?> selected<?php }?>>100</option>
													<option value="200"<?php if ($_REQUEST["rows"] == "200") {?> selected<?php }?>>200</option>
													<option value="500"<?php if ($_REQUEST["rows"] == "500") {?> selected<?php }?>>500</option>
												</select>
											</td>
											<td nowrap style='white-space:nowrap;padding-right:2px;'>
												<input type="submit" value='Go' name='button_refresh_x' title="Go">
												<input type='submit' value='Clear' name='button_clear_x' title='Return to the default time span'>
												<input type='submit' value='Export' name='export_x' title='Reset fields to defaults'>
												<input type='hidden' name='action' value='actions'>
												<input type='hidden' name='syslog_pdt_change' value='false'>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<?php html_end_box(false);?>
						</td><?php if (api_plugin_user_realm_auth('syslog_alerts.php')) {?>
						<td valign='top' style='padding-left:5px; background-color:#FFFFFF;'>
							<?php html_start_box("<strong>Rules</strong>", "100%", $colors["header"], "3", "center", "");?>
							<tr bgcolor='#<?php print $colors["panel"];?>'>
								<td class='textHeader'>
									<a href='syslog_alerts.php'>Alerts</a>
									<br>
									<a href='syslog_removal.php'>Removals</a>
									<br>
									<a href='syslog_reports.php'>Reports</a>
								</td>
							</tr>
							<?php html_end_box(false);?>
						</td><?php }?>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
			<td valign="top" style="border-right: #aaaaaa 1px solid;" bgcolor='#efefef'>
				<table align="center" cellpadding=0 cellspacing=0 border=0>
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
									<select id="host_select" name="host[]" multiple size="20" style="width: 150px; overflow: scroll; height: auto;" onChange="javascript:document.getElementById('syslog_form').submit();">
										<option id="host_all" value="0"<?php if (((is_array($_REQUEST["host"])) && ($_REQUEST["host"][0] == "0")) || ($reset_multi)) {?> selected<?php }?>>Show All Hosts&nbsp;&nbsp;</option>
										<?php
										$hosts = db_fetch_assoc("SELECT " . $syslog_incoming_config["hostField"] . " FROM syslog_hosts", true, $syslog_cnn);
										if (sizeof($hosts)) {
											foreach($hosts as $host) {
												$new_hosts[] = $host[$syslog_incoming_config["hostField"]];
											}
											$hosts = natsort($new_hosts);
											foreach ($new_hosts as $host) {
												print "<option value=" . $host;
												if (sizeof($_REQUEST["host"])) {
													foreach ($_REQUEST["host"] as $rh) {
														if (($rh == $host) &&
															(!$reset_multi)) {
															print " selected";
															break;
														}
													}
												}else{
													if (($host == $_REQUEST["host"]) &&
														(!$reset_multi)) {
														print " selected";
													}
												}
												print ">";
												print $host . "</option>\n";
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
							$total_rows = db_fetch_cell("SELECT count(*) from syslog " . $sql_where, '', true, $syslog_cnn);
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

/** function syslog_messages()
 *  This is the main page display function in Syslog.  Displays all the
 *  syslog messages that are relevant to Syslog.
*/
function syslog_messages() {
	global $colors, $sql_where, $syslog_cnn, $hostfilter;
	global $config, $syslog_incoming_config, $reset_multi, $syslog_levels;

	include("./include/global_arrays.php");

	/* create the custom css and javascript for the page */
	generate_syslog_cssjs();

	$url_curr_page = get_browser_query_string();

	$sql_where = "";

	$syslog_messages = get_syslog_messages($sql_where);

	$total_rows = syslog_filter($sql_where);

	$nav = "<tr bgcolor='#" . $colors["header"] . "'>
		<td colspan='9'>
			<table width='100%' cellspacing='0' cellpadding='0' border='0'>
				<tr>
					<td align='left' class='textHeaderDark'>
						<strong>&lt;&lt; ";
						if (isset($_REQUEST["page"]) && $_REQUEST["page"] > 1) {
							$nav .= "<a class='linkOverDark' href='" . get_query_edited_url($url_curr_page, 'page', ($_REQUEST["page"]-1)) . "'>";
						}
						$nav .= "Previous";
						if (isset($_REQUEST["page"]) && $_REQUEST["page"] > 1) {
							$nav .= "</a>";
						}
						$nav .= "</strong>
					</td>\n
					<td align='center' class='textHeaderDark'>
						Showing Rows " . (($_REQUEST["rows"]*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $_REQUEST["rows"]) || ($total_rows < ($_REQUEST["rows"]*$_REQUEST["page"]))) ? $total_rows : ($_REQUEST["rows"]*$_REQUEST["page"])) . " of $total_rows [ " . trim(syslog_page_select($total_rows)) . " ]
					</td>\n
					<td align='right' class='textHeaderDark'><strong>";
						if (isset($_REQUEST["page"]) && ($_REQUEST["page"] * $_REQUEST["rows"]) < $total_rows) {
							$nav .= "<a class='linkOverDark' href='" . get_query_edited_url($url_curr_page, 'page', ($_REQUEST["page"]+1)) . "'>";
						}
						$nav .= "Next";
						if (($_REQUEST["page"] * $_REQUEST["rows"]) < $total_rows) {
							$nav .= "</a>";
						}
						$nav .= " &gt;&gt;</strong>
					</td>\n
				</tr>
			</table>
		</td>
	</tr>\n";
	print $nav;

	$display_text = array(
		$syslog_incoming_config["hostField"] => array("Host", "ASC"),
		"logtime" => array("Date", "ASC"),
		$syslog_incoming_config["textField"] => array("Message", "ASC"),
		$syslog_incoming_config["facilityField"] => array("Facility", "ASC"),
		$syslog_incoming_config["priorityField"] => array("Level", "ASC"),
		"nosortt" => array("Options", "ASC"));

	html_header_sort($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

	$i = 0;
	if (sizeof($syslog_messages) > 0) {
		foreach ($syslog_messages as $syslog_message) {
			$title   = "'" . $syslog_message[$syslog_incoming_config["textField"]] . "'";
			$tip_options = "CLICKCLOSE, 'true', WIDTH, '40', DELAY, '500', FOLLOWMOUSE, 'true', FADEIN, 450, FADEOUT, 450, BGCOLOR, '#F9FDAF', STICKY, 'true', SHADOWCOLOR, '#797C6E', TITLE, 'Message'";

			syslog_row_color($colors["alternate"], $colors["light"], $i, $syslog_message[$syslog_incoming_config["priorityField"]], $title);
			$i++;

			print "<td>" . $syslog_message[$syslog_incoming_config["hostField"]] . "</td>\n";
			print "<td>" . $syslog_message["logtime"] . "</td>\n";
			print "<td>" . eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim($syslog_message[$syslog_incoming_config["textField"]], 70)) . "</td>\n";
			print "<td>" . ucfirst($syslog_message[$syslog_incoming_config["facilityField"]]) . "</td>\n";
			print "<td>" . ucfirst($syslog_message[$syslog_incoming_config["priorityField"]]) . "</td>\n";
			print '<td nowrap valign=top>';
			print "<center><a href='syslog_removal.php?id=" . $syslog_message[$syslog_incoming_config["id"]] . "&action=edit&type=new&type=0'><img src='images/red.gif' border=0></a>&nbsp;<a href='syslog_alerts.php?id=" . $syslog_message[$syslog_incoming_config["id"]] . "&action=edit&type=0'><img src='images/green.gif' border=0></a></center></td></tr>";
		}
	}else{
		print "<tr><td><em>No Messages</em></td></tr>";
	}

		/* put the nav bar on the bottom as well */
		print $nav;
		html_end_box(false);
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
				document.getElementById("host_select").size=size;
			}

			window.onresize = setHostMultiSelect;
			window.onload   = setHostMultiSelect;
			</script>
<?php
}
?>
