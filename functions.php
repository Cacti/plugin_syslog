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
 | h.aloe: a syslog monitoring addon for Ian Berry's Cacti	           |
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

function syslog_check_changed($request, $session) {
	if ((isset($_REQUEST[$request])) && (isset($_SESSION[$session]))) {
		if ($_REQUEST[$request] != $_SESSION[$session]) {
			return 1;
		}
	}
}

function syslog_sendemail($to, $from, $subject, $message) {
	if (syslog_check_dependencies()) {
		syslog_debug("Sending Alert email to '" . $to . "'");

		send_mail($to, $from, $subject, $message);
	} else {
		syslog_debug("Could not send alert, you are missing the Settings plugin");
	}
}

function syslog_remove_items($table, $rule = '') {
	global $config;

	include($config["base_path"] . '/plugins/syslog/config.php');

	/* REMOVE ALL THE THINGS WE DONT WANT TO SEE */
	$query = mysql_query("SELECT * FROM " . $syslog_config["removeTable"]);

	syslog_debug("Found " . mysql_affected_rows() .
		" Removal Rule" . (mysql_affected_rows() == 1 ? "" : "s" ) .
		" to process");

	$sql  = "";
	$sql1 = "";

	while ($remove = mysql_fetch_array($query, MYSQL_ASSOC)) {
		$sql = '';
		if ($remove['type'] == 'facility') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO " . $syslog_config["syslogRemovedTable"] . "
					SELECT *
					FROM " . $table . "
					WHERE " . $syslog_config["facilityField"] . "='" . $remove['message'] . "'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE " . $syslog_config["facilityField"] . "='" . $remove['message'] . "'";
		}else if ($remove['type'] == 'host') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO " . $syslog_config["syslogRemovedTable"] . "
					SELECT *
					FROM " . $table . "
					WHERE host='" . $remove['message'] . "'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE host='" . $remove['message'] . "'";
		} else if ($remove['type'] == 'messageb') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO " . $syslog_config["syslogRemovedTable"] . "
					SELECT *
					FROM " . $table . "
					WHERE message LIKE '" . $remove['message'] . "%'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE message LIKE '" . $remove['message'] . "%'";
		} else if ($remove['type'] == 'messagec') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO " . $syslog_config["syslogRemovedTable"] . "
					SELECT *
					FROM " . $table . "
					WHERE message LIKE '%" . $remove['message'] . "%'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE message LIKE '%" . $remove['message'] . "%'";
		} else if ($remove['type'] == 'messagee') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO " . $syslog_config["syslogRemovedTable"] . "
					SELECT *
					FROM " . $table . "
					WHERE message LIKE '%" . $remove['message'] . "'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE message LIKE '%" . $remove['message'] . "'";
		}

		if (($sql != '' || $sql1 != '') && ($rule == '' || $remove['id'] == $rule)) {
			/* move rows first */
			mysql_query($sql1);

			$messages_moved = mysql_affected_rows();

			/* now delete the remainder that match */
			mysql_query($sql);

			syslog_debug("Moved " . $messages_moved . ", Deleted " . mysql_affected_rows() .
					" Message" . ((mysql_affected_rows() + $messages_moved) == 1 ? "" : "s" ) .
					" for removal rule '" . $remove['name'] . "'");
		}
	}
}

function syslog_get_colors($level) {
	global $syslog_level_colors;

	if (!is_array($syslog_level_colors)) {
		$syslog_level_colors = array();
	}

	if (!isset($syslog_level_colors[$level])) {
		$bg = db_fetch_cell("SELECT hex FROM colors WHERE id='" . read_config_option("syslog_" . $level . "_bg", TRUE) . "'");
		$fg = db_fetch_cell("SELECT hex FROM colors WHERE id='" . read_config_option("syslog_" . $level . "_fg", TRUE) . "'");

		$syslog_level_colors[$level] = array("bg" => $bg, "fg" => $fg);
	}

	return $syslog_level_colors[$level];
}

/** function syslog_row_color()
 *  This function set's the CSS for each row of the syslog table as it is displayed
 *  it supports both the legacy as well as the new approach to controlling these
 *  colors.
*/
function syslog_row_color($row_color1, $row_color2, $row_value, $level, $tip_title) {
	global $config, $syslog_colors, $syslog_text_colors;

	$legacy = false;

	if (isset($syslog_colors[$level]) && preg_match("/[a-fA-F0-9]{6}/",$syslog_colors[$level])) {
		$current_color = $syslog_colors[$level];
		$legacy = true;
	}else{
		$bglevel = strtolower($level);

		if (substr_count($bglevel, "emer")) {
			$current_color = read_config_option("syslog_emer_bg");
		}else if (substr_count($bglevel, "alert")) {
			$current_color = read_config_option("syslog_alert_bg");
		}else if (substr_count($bglevel, "crit")) {
			$current_color = read_config_option("syslog_crit_bg");
		}else if (substr_count($bglevel, "err")) {
			$current_color = read_config_option("syslog_err_bg");
		}else if (substr_count($bglevel, "warn")) {
			$current_color = read_config_option("syslog_warn_bg");
		}else if (substr_count($bglevel, "notice")) {
			$current_color = read_config_option("syslog_notice_bg");
		}else if (substr_count($bglevel, "info")) {
			$current_color = read_config_option("syslog_info_bg");
		}else if (substr_count($bglevel, "debug")) {
			$current_color = read_config_option("syslog_debug_bg");
		}else{
			$legacy = true;

			if (($row_value % 2) == 1) {
				$current_color = $row_color1;
			}else{
				$current_color = $row_color2;
			}
		}
	}

	if (isset($syslog_text_colors[$level]) && preg_match("/[a-fA-F0-9]{6}/", $syslog_text_colors[$level])) {
		$current_text_color = $syslog_text_colors[$level];

		break;
	}else{
		$fglevel = strtolower($level);

		if (substr_count($fglevel, "emer")) {
			$current_color = read_config_option("syslog_emer_bg");
		}else if (substr_count($fglevel, "alert")) {
			$current_color = read_config_option("syslog_alert_bg");
		}else if (substr_count($fglevel, "crit")) {
			$current_color = read_config_option("syslog_crit_bg");
		}else if (substr_count($fglevel, "err")) {
			$current_color = read_config_option("syslog_err_bg");
		}else if (substr_count($fglevel, "warn")) {
			$current_color = read_config_option("syslog_warn_bg");
		}else if (substr_count($fglevel, "notice")) {
			$current_color = read_config_option("syslog_notice_bg");
		}else if (substr_count($fglevel, "info")) {
			$current_color = read_config_option("syslog_info_bg");
		}else if (substr_count($fglevel, "debug")) {
			$current_color = read_config_option("syslog_debug_bg");
		}else{
			$current_text_color = 'ffffff';
		}
	}

	$tip_options = "CLICKCLOSE, 'true', WIDTH, '40', DELAY, '300', FOLLOWMOUSE, 'true', FADEIN, 250, FADEOUT, 250, BGCOLOR, '#FEFEFE', STICKY, 'true', SHADOWCOLOR, '#797C6E'";

	if ($legacy) {
		if (isset($syslog_colors[$level]) && $syslog_colors[$level] != '') {
			print "<tr onmouseout=\"UnTip()\" onmouseover=\"Tip(" . $tip_title . ", " . $tip_options . ")\" class='syslog_$level'>\n";
		} else {
			print "<tr onmouseout=\"UnTip()\" onmouseover=\"Tip(" . $tip_title . ", " . $tip_options . ")\" bgcolor='#$current_color' class='syslog_$level'>\n";
		}
	}else{
		print "<tr onmouseout=\"UnTip()\" onmouseover=\"Tip(" . $tip_title . ", " . $tip_options . ")\" class='syslog_n_$bglevel'>\n";
	}
}

function sql_hosts_where() {
	global $hostfilter, $syslog_config;

	if (!empty($_REQUEST["host"])) {
		if (is_array($_REQUEST["host"])) {
			$hostfilter  = "";
			$x=0;
			if ($_REQUEST["host"][$x] != "0") {
				while ($x < count($_REQUEST["host"])) {
					if (!empty($hostfilter)) {
						$hostfilter .= ", '" . $_REQUEST["host"][$x] . "'";
					}else{
						if (!empty($sql_where)) {
							$hostfilter .= " AND " . $syslog_config["hostField"] . " IN('" . $_REQUEST["host"][$x] . "'";
						} else {
							$hostfilter .= " " . $syslog_config["hostField"] . " IN('" . $_REQUEST["host"][$x] . "'";
						}
					}

					$x++;
				}

				$hostfilter .= ")";
			}
		}else{
			if (!empty($sql_where)) {
				$hostfilter .= " AND " . $syslog_config["hostField"] . " IN('" . $_REQUEST["host"] . "')";
			} else {
				$hostfilter .= " " . $syslog_config["hostField"] . " IN('" . $_REQUEST["host"] . "')";
			}
		}
	}
}

function get_syslog_messages() {
	global $sql_where, $hostfilter, $syslog_config;

	$sql_where = "";
	/* form the 'where' clause for our main sql query */
	if (!empty($_REQUEST["host"])) {
		sql_hosts_where();
		if (strlen($hostfilter)) {
			$sql_where .=  "WHERE " . $hostfilter;
		}
	}

	$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . "CONCAT(DATE_FORMAT("  . $syslog_config["dateField"] . ",'%Y-%m-%d'),' ',TIME_FORMAT(" . $syslog_config["timeField"] . ",'%H:%i:%s')) BETWEEN '". $_SESSION["sess_current_date1"] . "' AND '" . $_SESSION["sess_current_date2"] . "'";

	if (!empty($_REQUEST["filter"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . $syslog_config["textField"] . " LIKE '%%" . $_REQUEST["filter"] . "%%'";
	}

	if (!empty($_REQUEST["efacility"])) {
		$sql_where .= (!strlen($sql_where) ? "WHERE " : " AND ") . $syslog_config["facilityField"] . "='" . $_REQUEST["efacility"] . "'";
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

		$sql_where .= (!strlen($sql_where) ? "WHERE ": " AND ") . $syslog_config["priorityField"] . " " . $mysql_in;
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
			FROM " . $syslog_config["syslogTable"] . " " .
			$sql_where . "
			ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
			$limit;
	}else{
		$query_sql = "(SELECT *
			FROM " . $syslog_config["syslogTable"] . " " .
			$sql_where . "
			) UNION (SELECT *
			FROM " . $syslog_config["syslogRemovedTable"] . " " .
			$sql_where . ")
			ORDER BY " . $sort . " " . $_REQUEST["sort_direction"] .
			$limit;
	}

	//echo $query_sql;

	$query = mysql_query($query_sql);

	$syslog_messages = array();
	if (is_resource($query)) {
		while ($syslog_messages[] = mysql_fetch_assoc($query));
		array_pop($syslog_messages);
	}

	return($syslog_messages);
}

function syslog_export () {
	global $syslog_config;

	header("Content-type: text/plain");
	header("Content-Disposition: attachment; filename=log_view-" . date("Y-m-d",time()) . ".log");
	include('plugins/syslog/config.php');

	/* no more cacti calls beyond this point */
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

	$syslog_messages = get_syslog_messages();

	if (sizeof($syslog_messages) > 0) {
		foreach ($syslog_messages as $syslog_message) {
			print
				$syslog_message[$syslog_config["hostField"]]     . "," .
				$syslog_message[$syslog_config["facilityField"]] . "," .
				$syslog_message[$syslog_config["priorityField"]] . "," .
				$syslog_message[$syslog_config["dateField"]]     . "," .
				$syslog_message[$syslog_config["timeField"]]     . "," .
				$syslog_message[$syslog_config["textField"]]     . "\r\n";
		}
	}
}

/* strip timespan to update graph page times */
function strip_timespan () {
	if (isset($_SESSION["sess_graph_view_url_cache"])) {
		$timespan_sel_pos = strpos($_SESSION["sess_graph_view_url_cache"],"&predefined_timespan");

		if ($timespan_sel_pos) {
			$_SESSION["sess_graph_view_url_cache"] = substr($_SESSION["sess_graph_view_url_cache"],0,$timespan_sel_pos);
		}
	}
}

function syslog_page_select($total_rows) {
	global $config, $syslog_config;

	$url_page_select = "";

	if ($total_rows > $_REQUEST["rows"]) {
		$total_pages      = ceil($total_rows / $_REQUEST["rows"]);
		$url_curr_page    = get_browser_query_string();
		$url_page_select .= "";
		$url_curr_page    = RemoveArgFromURL($url_curr_page, 'predefined_timespan');

		if ( $total_pages > 10 ) {
			$init_page_max = ( $total_pages > 3 ) ? 3 : $total_pages;
			for($i = 1; $i < $init_page_max + 1; $i++) {
				$url_page_select .= ( $i == $_REQUEST["page"] ) ? '<u>' . $i . '</u>' : ' <a class="linkOverDark" href="' . get_query_edited_url($url_curr_page, 'page', $i) . '"><b>' . $i . '</b></a>';
				if ( $i <  $init_page_max ) {
					$url_page_select .= ", ";
				}
			}
			if ( $total_pages > 3 ) {
				if ( $_REQUEST["page"] > 1  && $_REQUEST["page"] < $total_pages ) {
					$url_page_select .= ( $_REQUEST["page"] > 5 ) ? ' ... ' : ', ';
					$init_page_min = ( $_REQUEST["page"] > 4 ) ? $_REQUEST["page"] : 5;
					$init_page_max = ( $_REQUEST["page"] < $total_pages - 4 ) ? $_REQUEST["page"] : $total_pages - 4;
					for($i = $init_page_min - 1; $i < $init_page_max + 2; $i++) {
						$url_page_select .= ($i == $_REQUEST["page"]) ? '<u>' . $i . '</u>' : ' <a class="linkOverDark" href="' . get_query_edited_url($url_curr_page, 'page', $i) . '"><b>' . $i . '</b></a>';
						if ( $i <  $init_page_max + 1 ) {
							$url_page_select .= ', ';
						}
					}
					$url_page_select .= ( $_REQUEST["page"] < $total_pages - 4 ) ? ' ... ' : ', ';
				} else {
					$url_page_select .= ' ... ';
				}
				for($i = $total_pages - 2; $i < $total_pages + 1; $i++) {
					$url_page_select .= ( $i == $_REQUEST["page"] ) ? '<u>' . $i . '</u>'  : ' <a class="linkOverDark" href="' . get_query_edited_url($url_curr_page, 'page', $i) . '"><b>' . $i . '</b></a>';
					if( $i <  $total_pages ) {
						$url_page_select .= ", ";
					}
				}
			}
		} else {
			for($i = 1; $i < $total_pages + 1; $i++) {
				$url_page_select .= ( $i == $_REQUEST["page"] ) ? '<u>' . $i . '</u>' : ' <a class="linkOverDark" href="' . get_query_edited_url($url_curr_page, 'page', $i) . '"><b>' . $i . '</b></a>';
				if ( $i <  $total_pages ) {
					$url_page_select .= ', ';
				}
			}
		}

		return $url_page_select;
	}
}


/* Functions to edit a url query key and its value.
You can also add a new query key and its value.
Test & Output:
$url = "http://user:pass@host/path?arg1=value&arg2=myval2#anchor";
print  "<br>0:".$url;
print  "<br>1:".get_query_edited_url($url,'arg1','CHANGED'); //Arg1
print  "<br>2:".get_query_edited_url($url,'arg2','CHANGED'); //Arg2
print  "<br>3:".get_query_edited_url($url,'arg3','NEW'); //New Argument
Output
0:http://user:pass@host/path?arg1=value&arg2=myval2#anchor
1:http://user:pass@host/path?arg1=CHANGED&arg2=myval2#anchor
2:http://user:pass@host/path?arg1=value&arg2=CHANGED#anchor
3:http://user:pass@host/path?arg1=value&arg2=myval2&arg3=NEW#anchor
*/

function syslog_debug($message) {
	global $syslog_debug;

	if ($syslog_debug) {
		echo "SYSLOG: " . $message . "\n";
	}
}

function get_query_edited_url($url, $arg, $val) {
	$parsed_url = parse_url($url);
	if (!isset($parsed_url['query']))
		$parsed_url['query'] = 'page=1';

	parse_str($parsed_url['query'],$url_query);
	$url_query[$arg] = $val;
	$parsed_url['query'] = http_implode($url_query);
	$url = glue_url($parsed_url);

	return $url;
}

function http_implode($arrayInput) {
	if (!is_array($arrayInput)) {
		return false;
	}

	$url_query = "";
	foreach ($arrayInput as $key => $value) {
		$url_query .= (strlen($url_query)>1)?'&':"";

		if (!is_array($value)) {
			$url_query .= urlencode($key) . '=' . urlencode($value);
		}else{
			foreach ($value as $item) {
				$url_query .= urlencode($key) . '=' . urlencode($item);
			}
		}
	}
	return $url_query;
}

function glue_url($parsed) {
	if (! is_array($parsed))
		return false;
	if (!isset($parsed['scheme']))
		$parsed['scheme'] = null;
	if (!isset($parsed['user']))
		$parsed['user'] = null;
	if (!isset($parsed['port']))
		$parsed['port'] = null;
	if (!isset($parsed['fragment']))
		$parsed['fragment'] = null;
	if (!isset($parsed['host']))
		$parsed['host'] = null;

	$url = $parsed['scheme'] ? $parsed['scheme'].':'.((strtolower($parsed['scheme']) == 'mailto') ? '':'//'): '';
	$url .= $parsed['user'] ? $parsed['user'].($parsed['pass']? ':'.$parsed['pass']:'').'@':'';
	$url .= $parsed['host'] ? $parsed['host'] : '';
	$url .= $parsed['port'] ? ':'.$parsed['port'] : '';
	$url .= $parsed['path'] ? $parsed['path'] : '';
	$url .= $parsed['query'] ? '?'.$parsed['query'] : '';
	$url .= $parsed['fragment'] ? '#'.$parsed['fragment'] : '';
	return $url;
}

function RemoveArgFromURL($URL,$Arg) {
	while($Pos = strpos($URL,"$Arg=")) {
		if ($Pos) {
			if ($URL[$Pos-1] == "&") { $Pos--; }
			$nMax = strlen($URL);
			$nEndPos = strpos($URL,"&",$Pos+1);
			if ($nEndPos === false) {
				$URL = substr($URL,0,$Pos);
			} else {
				$URL = str_replace(substr($URL,$Pos,$nEndPos-$Pos),'',$URL);
			}
		}
	}
	return $URL;
}


