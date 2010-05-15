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
	global $colors, $syslog_colors, $syslog_incoming_config, $syslog_text_colors, $config, $syslog_levels;

	/* legacy css for syslog backgrounds */
	print "\n\t\t\t<style type='text/css'>\n";
	if (sizeof($syslog_colors)) {
	foreach ($syslog_colors as $type => $color) {
		if (($color != "") ||
			(isset($syslog_text_colors[$type]) && $syslog_text_colors[$type] != '')) {

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

	/* new css for syslog backgrounds */
	if (sizeof($syslog_levels)) {
	foreach ($syslog_levels as $key => $level) {
		print "\t\t\t.syslog_n_$level {\n";

		$clrs = syslog_get_colors($level);

		print "\t\t\t\tbackground-color:#" . $clrs["bg"] . ";\n";
		print "\t\t\t\tcolor:#" . $clrs["fg"] . ";\n";
		print "\t\t\t}\n";
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

	include("./plugins/syslog/config.php");
	include("./plugins/syslog/html/syslog_timespan_settings.php");

	if (isset($_REQUEST["host"])) {
		$_SESSION["sess_syslog_hosts"] = $_REQUEST["host"];
	} else if (isset($_SESSION["sess_syslog_hosts"])) {
		$_REQUEST["host"] = $_SESSION["sess_syslog_hosts"];
	} else {
		$_REQUEST["host"][0] = "0"; /* default value */
	}
}

/** function syslog_messages()
 *  This is the main page display function in Syslog.  Displays all the
 *  syslog messages that are relevant to Syslog.
*/
function syslog_messages() {
	global $colors, $sql_where, $hostfilter;
	global $config, $syslog_incoming_config, $reset_multi, $syslog_levels;

	/* cacti 0.8.7/0.8.6 compatibility */
	if (file_exists("./include/global_arrays.php")) {
		include("./include/global_arrays.php");
	} else {
		include("./include/config_arrays.php");
	}

	/* database connectivity information and legacy color arrays */
	include('./plugins/syslog/config.php');

	/* create the custom css and javascript for the page */
	generate_syslog_cssjs();

	$url_curr_page = get_browser_query_string();

	/* beyond this point no more cacti database calls */
	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);

	$syslog_messages = get_syslog_messages();

	include(dirname(__FILE__) . "/html/syslog_filter_selector.php");

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
<?php
}
?>
