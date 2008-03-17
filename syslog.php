<?php
/*
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
 | h.aloe: a syslog monitoring addon for Ian Berry's Cacti	               |
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

$guest_account = true;

chdir('../../');

include("./include/auth.php");
include('plugins/syslog/config.php');
include_once('plugins/syslog/functions.php');

if (!syslog_check_dependencies()) {
	include_once("./include/top_graph_header.php");
	cacti_log("SYSLOG: You are missing a required dependency, please install the '<a href='http://cactiusers.org/'>Settings'</a> plugin.", true, "POLLER");
	print "<br><br><center><font color=red>You are missing a dependency for Syslog, please install the '<a href='http://cactiusers.org'>Settings</a>' plugin.</font></color>";
	exit;
}

/* Check to ensure that our settings are setup properly */
$r = read_config_option("syslog_refresh");
if ($r == '' or $r < 1 or $r > 300) {
	db_execute("REPLACE INTO settings VALUES ('syslog_refresh', '300')");
	kill_session_var("sess_config_array");
}

$r = read_config_option("num_rows_syslog");
if ($r == '' or $r < 0 or $r > 100) {
	db_execute("REPLACE INTO settings VALUES ('num_rows_syslog','30')");
	kill_session_var("sess_config_array");
}

if (isset($_REQUEST["rows"])) {
	$config['rows_per_page'] = $_REQUEST["rows"];
} else {
	$config['rows_per_page'] = $r;
	$_REQUEST["rows"]        = $r;
}

$r = read_config_option("syslog_retention");
if ($r == '' or $r < 0 or $r > 365) {
	db_execute("REPLACE INTO settings VALUES ('syslog_retention', '30')");
	kill_session_var("sess_config_array");
}

if ($syslog_config["graphtime"] && read_graph_config_option("timespan_sel") != "on") {
	$syslog_config["graphtime"]    = false;
	$syslog_config["timespan_sel"] = false;
}

if ((isset($_REQUEST["syslog_pdt_change"])) &&
	($_REQUEST["syslog_pdt_change"] == 'true')) {
	/*  predefined_timespan changed  */
	$_SERVER["REQUEST_URI"] = get_query_edited_url($_SERVER["REQUEST_URI"], 'predefined_timespan', $_REQUEST["predefined_timespan"]);

	if ($syslog_config["graphtime"]) {
		unset($_SESSION["sess_current_date1"], $_REQUEST["date1"]);
	} else {
		unset($_SESSION["sess_syslog_array"]["sess_current_date1"], $_REQUEST["date1"]);
	}

	$_GET["predefined_timespan"] = $_REQUEST["predefined_timespan"];
} elseif (isset($_REQUEST["button_clear_x"]) && $_REQUEST["button_clear_x"]) {
	/*  pressed reset, so clear out values  */
	$_REQUEST["filter"]    = "";
	$_REQUEST["efacility"] = "0";
	$_REQUEST["elevel"]    = "0";
	$_REQUEST["output"]    = "screen";
	unset($_REQUEST["predefined_timespan"]);
	unset($_REQUEST["host"]);
	$_REQUEST["host"][0]   = "0";
}

include($syslog_config["graphtime"] ? "./include/html/inc_timespan_settings.php" : "plugins/syslog/html/syslog_timespan_settings.php");

input_validate_input_number(get_request_var("predefined_timespan"));

/* set default action */

/* remember these search fields in session vars so we don't have to keep passing them around */
if (!isset($_GET["page"])) {
	$_REQUEST["page"]="1";
}

if (isset($_REQUEST["filter"])) {
	$_REQUEST["filter"] = sanitize_search_string(get_request_var_request("filter"));
	$_SESSION["sess_syslog_array"]["sess_filter"] = $_REQUEST["filter"];
} else if (isset($_SESSION["sess_syslog_array"]["sess_filter"])) {
	$_REQUEST["filter"] = $_SESSION["sess_syslog_array"]["sess_filter"];
	$_REQUEST["filter"] = sanitize_search_string(get_request_var_request("filter"));
} else {
	$_REQUEST["filter"] = ""; /* default value */
}

if (isset($_REQUEST["host"])) {
	$_SESSION["sess_syslog_array"]["sess_host"] = $_REQUEST["host"];
} else if (isset($_SESSION["sess_syslog_array"]["sess_host"])) {
	$_REQUEST["host"] = $_SESSION["sess_syslog_array"]["sess_host"];
} else {
	$_REQUEST["host"][0] = "0"; /* default value */
}

if ($syslog_config["graphtime"]) {
	if (isset($_SESSION["sess_current_date1"])) {
		$_REQUEST["date1"] = $_SESSION["sess_current_date1"];
	}
	if (isset($_SESSION["sess_current_date2"])) {
		$_REQUEST["date2"] = $_SESSION["sess_current_date2"];
	}
} else if ($syslog_config["timespan_sel"]) {
	if (isset($_SESSION["sess_syslog_array"]["sess_current_date1"])) {
		$_REQUEST["date1"] = $_SESSION["sess_syslog_array"]["sess_current_date1"];
	}
	if (isset($_SESSION["sess_syslog_array"]["sess_current_date2"])) {
		$_REQUEST["date2"] = $_SESSION["sess_syslog_array"]["sess_current_date2"];
	}
}

if (isset($_REQUEST["efacility"])) {
	$_SESSION["sess_syslog_array"]["sess_efacility"] = $_REQUEST["efacility"];
} else if (isset($_SESSION["sess_syslog_array"]["sess_efacility"])) {
	$_REQUEST["efacility"] = $_SESSION["sess_syslog_array"]["sess_efacility"];
} else {
	$_REQUEST["efacility"] = ""; /* default value */
}

if (isset($_REQUEST["elevel"])) {
	$_SESSION["sess_syslog_array"]["sess_elevel"] = $_REQUEST["elevel"];
} else if (isset($_SESSION["sess_syslog_array"]["sess_elevel"])) {
	$_REQUEST["elevel"] = $_SESSION["sess_syslog_array"]["sess_elevel"];
} else {
	$_REQUEST["elevel"] = ""; /* default value */
}

if (!isset($_REQUEST["output"])) {
	$_REQUEST["output"] = "screen"; /* default value */
}

switch ($_REQUEST["output"]) {
case 'file' :

	syslog_export();

	unset($_REQUEST["output"]); /* clear output so reloads wont re-download */

	break;
default:
	include_once("./include/top_graph_header.php");

	syslog_messages();

	/* strip timespan to update graph page times */
	if ($syslog_config["graphtime"]) { strip_timespan(); }

	include_once("./include/bottom_footer.php");

	break;
}

function syslog_messages() {
	global $colors, $sql_where, $hostfilter, $config;

	if (file_exists("./include/global_arrays.php")) {
		include("./include/global_arrays.php");
	} else {
		include("./include/config_arrays.php");
	}

	include('plugins/syslog/config.php');

	if (isset($_REQUEST["rows"])) {		$syslog_config["rows_per_page"] = $_REQUEST["rows"];
	}else{
		$syslog_config["rows_per_page"] = read_config_option("num_rows_syslog");
	}

	print "<style type='text/css'>\n";
	foreach ($syslog_colors as $type => $color) {
		print ".syslog_$type, .syslog_$type td, .syslog_$type tr {\n";
		if ($color != '') {
			print "	background-color:	#$color\n";
		}
		if (isset($syslog_text_colors[$type]) && $syslog_text_colors[$type] != '') {
			print "	color:		#" . $syslog_text_colors[$type] . "\n";
		}
		print "}\n";
	}
	print '</style>';

	$url_curr_page = get_browser_query_string();

	if (!empty($hostfilter)) {
		$where_hostfilter = " WHERE". $hostfilter;
	} else {
		$where_hostfilter = '';
	}

	if (!($syslog_config["graphtime"]) && !($syslog_config["timespan_sel"])) {
		unset($_REQUEST["date1"], $_REQUEST["date2"]);
	}

	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

	$syslog_messages = get_syslog_messages();

	?>
	<tr>
		<td valign="top">
			<form id="syslog_form" name="syslog_timespan_selector" method="post" action="syslog.php">
			<table width="100%" height="100%" cellspacing="0" cellpadding="0">
				<tr height="40">
					<td colspan="2" style="background-color: #EFEFEF; padding: 0px 5px 5px 0px;">
						<?php
						print "<table width='100%' cellpadding=0 cellspacing=0><tr><td width='100%'>";
						html_start_box("<strong>Syslog Message Filters:</strong>&nbsp;&nbsp;[<font size=1> " . preg_replace("/concat.*BETWEEN /", 'Date/Time BETWEEN ', $sql_where) . " </font>]", "99%", $colors["header"], "3", "center", "");
						?>
						<tr bgcolor="#<?php print $colors["panel"];?>">
							<td class='textEditTitle'>
						<?php
							if ($syslog_config["graphtime"] || $syslog_config["timespan_sel"]) {
								include("plugins/syslog/html/syslog_timespan_selector.php");
							}
							include("plugins/syslog/html/syslog_filter_selector.php");
						?>
							</td>
						</tr>
						<?php
						html_end_box(false);

						print "</td><td>";
						html_start_box("<strong>Rules</strong>", "100%", $colors["header"], "3", "center", "");
						print "<tr bgcolor='#" . $colors["panel"] . "'><td class='textHeader'>";
						print "<a href='syslog_alert.php'>Alerts</a><br><a href='syslog_remove.php'>Removals</a><br>";
						print "<a href='syslog_reports.php'>Reports</a>";
						print "</td></tr>";
						html_end_box(false);
						print "</td></tr></table>";
						?>
					</td>
				</tr>
				<tr>
					<td valign="top" style="padding: 0px 5px 0px 5px; border-right: #aaaaaa 1px solid;" bgcolor='#efefef' width='200'>
						<table align="center" width="200" cellpadding=1 cellspacing=0 border=0>
							<tr>
								<td>
									<?php
									html_start_box("", "100%", $colors["header"], "3", "center", "");
										?>
										<tr>
											<td class="textHeader" nowrap>
												Select Host(s):&nbsp;
											</td>
										</tr>
										<tr>
											<td>
												<select name="host[]" multiple size="25" width="100%" style="width: 100%" onDblClick="javascript:document.getElementById('syslog_form').submit();">
													<option value="0"<?php if ((is_array($_REQUEST["host"])) && ($_REQUEST["host"][0] == "0")) {?> selected<?php }?>>Show All Hosts&nbsp;&nbsp;</option>
													<?php
													$query = mysql_query("SELECT DISTINCT " . $syslog_config["hostField"] . " FROM " . $syslog_config["syslogTable"] . " ORDER BY " . $syslog_config["hostField"] . " ASC");

													while ($hosts[] = mysql_fetch_assoc($query));
													array_pop($hosts);
													if (sizeof($hosts) > 0) {
														foreach ($hosts as $host) {
															print "<option value=" . $host[$syslog_config["hostField"]];
															if (is_array($_REQUEST["host"])) {
																foreach ($_REQUEST["host"] as $rh) {
																	if ($rh == $host[$syslog_config["hostField"]]) {
																		print " selected ";
																		break;
																	}
																}
															}else{																if ($host[$syslog_config["hostField"]] == $_REQUEST["host"]) {																	print " selected ";
																}															}
															print ">";
															print $host[$syslog_config["hostField"]] . "</option>\n";
														}
													}
													?>
												</select>
											</td>
										</tr>
									<?php
									html_end_box(false);
									?>
								</td>
							</tr>
						</table>
					</td>
					<td width="100%" valign="top" style="padding: 0px;">
						<table width="100%" cellspacing="0" cellpadding="0">
							<tr>
								<td bgcolor="#ffffff" height="8" style="background-image: url(<?php echo $config['url_path']; ?>images/shadow.gif); background-repeat: repeat-x;">
								</td>
							</tr>
							<tr>
								<td width="100%" valign="top" style="padding: 0px 5px 0px 5px;"><?php display_output_messages();?>
									<?php
									$total_rows = mysql_fetch_array(mysql_query("SELECT count(*) from " . $syslog_config["syslogTable"] . " " . $sql_where));
									$total_rows = $total_rows[0];

									html_start_box("", "100%", $colors["header"], "3", "center", "");
										$hostarray = "";
										if (is_array($_REQUEST["host"])) {
											foreach ($_REQUEST["host"] as $h) {
												$hostarray .= "host[]=$h&";
											}
										}else{											$hostarray .= "host[]=" . $_REQUEST["host"] . "&";
										}

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
															Showing Rows " . (($syslog_config["rows_per_page"]*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $syslog_config["rows_per_page"]) || ($total_rows < ($syslog_config["rows_per_page"]*$_REQUEST["page"]))) ? $total_rows : ($syslog_config["rows_per_page"]*$_REQUEST["page"])) . " of $total_rows [ " . trim(syslog_page_select($total_rows)) . " ]
														</td>\n
														<td align='right' class='textHeaderDark'><strong>";
															if (isset($_REQUEST["page"]) && ($_REQUEST["page"] * $syslog_config["rows_per_page"]) < $total_rows) {
																$nav .= "<a class='linkOverDark' href='" . get_query_edited_url($url_curr_page, 'page', ($_REQUEST["page"]+1)) . "'>";
															}
															$nav .= "Next";
															if (($_REQUEST["page"] * $syslog_config["rows_per_page"]) < $total_rows) {
																$nav .= "</a>";
															}
															$nav .= " &gt;&gt;</strong>
														</td>\n
													</tr>
												</table>
											</td>
										</tr>\n";
										print $nav;
										print "<tr bgcolor='#" . $colors["header_panel"] . "'>";
										print "	<td class='textSubHeaderDark'>Host</td>";
										print "	<td class='textSubHeaderDark'>&nbsp;</td>";
										print "	<td class='textSubHeaderDark'>Date</td>";
										print "	<td class='textSubHeaderDark'>Time</td>";
										print "	<td class='textSubHeaderDark'>&nbsp;</td>";
										print "	<td class='textSubHeaderDark'>Message</td>";
										print " <td class='textSubHeaderDark'>Facility</td>";
										print "	<td class='textSubHeaderDark'>Level</td>";
										print "	<td class='textSubHeaderDark'>Options</td>";
										print "</tr>\n";
										$i = 0;
										if (sizeof($syslog_messages) > 0) {
											foreach ($syslog_messages as $syslog_message) {
												syslog_row_color($colors["alternate"], $colors["light"], $i, $syslog_message[$syslog_config["priorityField"]]);
												$i++;

												print '<td nowrap valign=top>' . $syslog_message[$syslog_config["hostField"]] . "</td>\n";
												print "<td nowrap> </td>\n";
												print '<td nowrap valign=top>' . $syslog_message[$syslog_config["dateField"]] . "</td>\n";
												print '<td nowrap valign=top>' . $syslog_message[$syslog_config["timeField"]] . "</td>\n";
												print "<td nowrap> </td>\n";
												print '<td valign=top>' . htmlspecialchars($syslog_message[$syslog_config["textField"]]) . "</td>\n";
												print '<td nowrap valign=top>' . ucfirst($syslog_message[$syslog_config["facilityField"]]) . "</td>\n";
												print '<td nowrap valign=top>' . ucfirst($syslog_message[$syslog_config["priorityField"]]) . "</td>\n";
												print '<td nowrap valign=top>';
												print "<center><a href='syslog_remove.php?id=" . $syslog_message[$syslog_config["id"]] . "#edit'><img src='images/red.gif' border=0></a>&nbsp;<a href='syslog_alert.php?id=" . $syslog_message[$syslog_config["id"]] . "#edit'><img src='images/green.gif' border=0></a></center></td></tr>";
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
?>