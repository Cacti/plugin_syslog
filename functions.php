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

function syslog_sendemail($to, $from, $subject, $message) {
	global $debug;
	if (syslog_check_dependencies()) {
		if ($debug)
			print "      Sending Alert email to '" . $to . "'\n";
		send_mail($to, $from, $subject, $message);
	} else {
		if ($debug)
			print "      Could not send alert, you are missing the Settings plugin\n";
	}
}

function syslog_remove_items($table, $rule = '') {
	global $config, $debug;
	include($config["base_path"] . '/plugins/syslog/config.php');
	/* REMOVE ALL THE THINGS WE DONT WANT TO SEE */
	$query = mysql_query("SELECT * FROM " . $syslog_config["removeTable"]);

	if ($debug)
		print "Found " . mysql_affected_rows() . " Removal Rule" . (mysql_affected_rows() == 1 ? "" : "s" ) . " to process\n";

	while ($remove = mysql_fetch_array($query, MYSQL_ASSOC)) {
		$sql = '';
		if ($remove['type'] == 'facility') {
			$sql = 'delete from ' . $table . " where " . $syslog_config["facilityField"] . " = '" . $remove['message'] . "'";
		} else if ($remove['type'] == 'host') {
			$sql = 'delete from ' . $table . " where host = '" . $remove['message'] . "'";
		} else if ($remove['type'] == 'messageb') {
			$sql = 'delete from ' . $table . " where message like '" . $remove['message'] . "%'";
		} else if ($remove['type'] == 'messagec') {
			$sql = 'delete from ' . $table . " where message like '%" . $remove['message'] . "%'";
		} else if ($remove['type'] == 'messagee') {
			$sql = 'delete from ' . $table . " where message like '%" . $remove['message'] . "'";
		}
		if ($sql != '' && ($rule == '' || $remove['id'] == $rule)) {
			mysql_query($sql);
			if ($debug)
				print "  Deleted " . mysql_affected_rows() . " Message" . (mysql_affected_rows() == 1 ? "" : "s" ) . " for removal rule '" . $remove['name'] . "'\n";
		}
	}
}

function syslog_row_color($row_color1, $row_color2, $row_value, $level) {
	global $syslog_colors, $syslog_text_colors;

	switch ($level) {
		case (isset($syslog_colors[$level]) && preg_match("/[a-fA-F0-9]{6}/",$syslog_colors[$level])) :
			$current_color = $syslog_colors[$level];
			break;
		default :
			if (($row_value % 2) == 1) {
				$current_color = $row_color1;
			}else{
				$current_color = $row_color2;
			}
	}

	switch ($level) {
		case (isset($syslog_text_colors[$level]) && preg_match("/[a-fA-F0-9]{6}/", $syslog_text_colors[$level])) :
			$current_text_color = $syslog_text_colors[$level];
			break;
		default :
			$current_text_color = 'ffffff';
	}

	if (isset($syslog_colors[$level]) && $syslog_colors[$level] != '') {
		print "<tr class='syslog_$level'>\n";
	} else {
		print "<tr bgcolor='#$current_color' class='syslog_$level'>\n";
	}

}

function sql_hosts_where() {
	global $hostfilter, $syslog_config;

	if (!empty($_REQUEST["host"])) {
		$hostfilter  = "";
		$x=0;
		if ($_REQUEST["host"][$x] != "0") {
			while ($x < count($_REQUEST["host"])) {
				if (!empty($hostfilter)) {
					$hostfilter .= " or " . $syslog_config["hostField"] . "='" . $_REQUEST["host"][$x] . "'";
				} else {
					if (!empty($sql_where)) {
						$hostfilter .= " and " . $syslog_config["hostField"] . "='" . $_REQUEST["host"][$x] . "'";
					} else {
						$hostfilter .= "  (" . $syslog_config["hostField"] . "='" . $_REQUEST["host"][$x] . "'";
					}
				}
				$x++;
			}
			$hostfilter .= ")";
		}
	}
}

function get_syslog_messages() {
	global $sql_where, $hostfilter, $syslog_config;

	$syslog_config["rows_per_page"] = read_config_option("num_rows_syslog");

	$sql_where = "";
	/* form the 'where' clause for our main sql query */
	if (!empty($_REQUEST["host"])) {
		sql_hosts_where();
		if (!empty($hostfilter)) {
			$sql_where .=  "where " . $hostfilter;
		}
	}

	if (!empty($_REQUEST["date1"])) {
		if (!empty($sql_where)) {
			$sql_where .= " and concat(DATE_FORMAT(" . $syslog_config["dateField"] . ",'%Y-%m-%d'),' ',TIME_FORMAT(" . $syslog_config["timeField"] . ",'%H:%i:%s')) BETWEEN '". $_REQUEST["date1"] . "' AND '" . $_REQUEST["date2"] . "'";
		} else {
			$sql_where .= " where concat(DATE_FORMAT(" . $syslog_config["dateField"] . ",'%Y-%m-%d'),' ',TIME_FORMAT(" . $syslog_config["timeField"] . ",'%H:%i:%s')) BETWEEN '". $_REQUEST["date1"] . "' AND '" . $_REQUEST["date2"] . "'";
		}
	}

	if (!empty($_REQUEST["filter"])) {
		if (!empty($sql_where)) {
			$sql_where .= " and " . $syslog_config["textField"] . " like '%%" . $_REQUEST["filter"] . "%%'";
		} else {
			$sql_where .= " where " . $syslog_config["textField"] . " like '%%" . $_REQUEST["filter"] . "%%'";
		}
	}

	if (!empty($_REQUEST["efacility"])) {
		if (!empty($sql_where)) {
			$sql_where .= " and " . $syslog_config["facilityField"] . " ='" . $_REQUEST["efacility"] . "'";
		} else {
			$sql_where .= " where " . $syslog_config["facilityField"] . " ='" . $_REQUEST["efacility"] . "'";
		}
	}

	if (!empty($_REQUEST["elevel"])) {
		if (!empty($sql_where)) {
			$sql_where .= " and " . $syslog_config["priorityField"] . " ='" . $_REQUEST["elevel"] . "'";
		} else {
			$sql_where .= " where " . $syslog_config["priorityField"] . " ='" . $_REQUEST["elevel"] . "'";
		}
	}

	if ($_REQUEST["output"] != "file") {
		$limit = " LIMIT " . ($syslog_config["rows_per_page"]*($_REQUEST["page"]-1)) . "," . $syslog_config["rows_per_page"];
	} else {
		$limit = "";
	}

	$query = mysql_query("SELECT " .
				$syslog_config["hostField"] . "," .
				$syslog_config["priorityField"] . "," .
				$syslog_config["facilityField"] . "," .
				$syslog_config["dateField"] . "," .
				$syslog_config["timeField"] . "," .
				$syslog_config["id"] . "," .
				$syslog_config["textField"] .
				" FROM " . $syslog_config["syslogTable"] . " " .
				$sql_where . " " .
				" ORDER BY " . $syslog_config["dateField"] . " DESC," . $syslog_config["timeField"] . " DESC," . $syslog_config["hostField"] . " DESC" . $limit);

	while ($syslog_messages[] = mysql_fetch_assoc($query));
	array_pop($syslog_messages);

	return($syslog_messages);
}

function syslog_export () {
	global $syslog_config;

	header("Content-type: text/plain");
	header("Content-Disposition: attachment; filename=log_view-" . date("Y-m-d",time()) . ".log");
	include('plugins/syslog/config.php');
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

	$syslog_messages = get_syslog_messages();

	if (sizeof($syslog_messages) > 0) {
		foreach ($syslog_messages as $syslog_message) {
			print $syslog_message[$syslog_config["hostField"]] . "," . $syslog_message[$syslog_config["facilityField"]] . "," . $syslog_message[$syslog_config["priorityField"]] . "," . $syslog_message[$syslog_config["dateField"]] . "," . $syslog_message[$syslog_config["timeField"]] . "," . $syslog_message[$syslog_config["textField"]] . "\r\n";
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

function syslog_page_select($total_rows) {  /* list of each page #, so the user can jump straight to it */
	global $syslog_config;
	$url_page_select = "";

	$syslog_config["rows_per_page"] = read_config_option("num_rows_syslog");

	if ($total_rows > $syslog_config["rows_per_page"]) {
		$total_pages = ceil($total_rows / $syslog_config["rows_per_page"]);
		$url_curr_page = get_browser_query_string();
		$url_page_select .= "";
		$url_curr_page = RemoveArgFromURL($url_curr_page, 'predefined_timespan');

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
		$url_page_select = "page " . $url_page_select;

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
	if (! is_array($arrayInput))
		return false;

	$url_query="";
	foreach ($arrayInput as $key=>$value) {
		$url_query .=(strlen($url_query)>1)?'&':"";
		$url_query .= urlencode($key).'='.urlencode($value);
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


