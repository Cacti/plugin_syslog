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

function syslog_sendemail($to, $from, $subject, $message) {
	if (syslog_check_dependencies()) {
		syslog_debug("Sending Alert email to '" . $to . "'");

		send_mail($to, $from, $subject, $message);
	} else {
		syslog_debug("Could not send alert, you are missing the Settings plugin");
	}
}

function syslog_remove_items($table, $rule = '') {
	global $config, $syslog_cnn, $syslog_incoming_config;

	include($config["base_path"] . '/plugins/syslog/config.php');

	/* REMOVE ALL THE THINGS WE DONT WANT TO SEE */
	$rows = db_fetch_assoc("SELECT * FROM syslog_remove", true, $syslog_cnn);

	syslog_debug("Found " . sizeof($rows) .
		" Removal Rule" . (sizeof($rows) == 1 ? "" : "s" ) .
		" to process");

	if (sizeof($rows)) {
	foreach($rows as $remove) {
		$sql  = "";
		$sql1 = "";
		if ($remove['type'] == 'facility') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO syslog_removed (logtime, " .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] . ') ' .
					" SELECT TIMESTAMP(`" . $syslog_incoming_config['dateField'] . '`, `' .
						$syslog_incoming_config["timeField"] . ')`, ' .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] .
					" FROM " . $table . "
					WHERE " . $syslog_incoming_config["facilityField"] . "='" . $remove['message'] . "'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE " . $syslog_incoming_config["facilityField"] . "='" . $remove['message'] . "'";
		}else if ($remove['type'] == 'host') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO syslog_removed (logtime, " .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] . ') ' .
					" SELECT TIMESTAMP(`" . $syslog_incoming_config["dateField"] . '`, `' .
						$syslog_incoming_config["timeField"] . '`), ' .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] .
					" FROM " . $table . "
					WHERE host='" . $remove['message'] . "'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE host='" . $remove['message'] . "'";
		} else if ($remove['type'] == 'messageb') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO syslog_removed (logtime, " .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] . ') ' .
					" SELECT TIMESTAMP(`" . $syslog_incoming_config["dateField"] . '`, `' .
						$syslog_incoming_config["timeField"] . '`), ' .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] .
					" FROM " . $table . "
					WHERE message LIKE '" . $remove['message'] . "%'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE message LIKE '" . $remove['message'] . "%'";
		} else if ($remove['type'] == 'messagec') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO syslog_removed (logtime, " .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] . ') ' .
					" SELECT TIMESTAMP(`" . $syslog_incoming_config["dateField"] . '`, `' .
						$syslog_incoming_config["timeField"] . '`), ' .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] .
					" FROM " . $table . "
					WHERE message LIKE '%" . $remove['message'] . "%'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE message LIKE '%" . $remove['message'] . "%'";
		} else if ($remove['type'] == 'messagee') {
			if ($remove['method'] != 'del') {
				$sql1 = "INSERT INTO syslog_removed (logtime, " .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] . ') ' .
					" SELECT TIMESTAMP(`" . $syslog_incoming_config["dateField"] . '`, `' .
						$syslog_incoming_config["timeField"] . '`), ' .
						$syslog_incoming_config["priorityField"] . ', ' .
						$syslog_incoming_config["facilityField"] . ', ' .
						$syslog_incoming_config["hostField"] . ', ' .
						$syslog_incoming_config["textField"] .
					" FROM " . $table . "
					WHERE message LIKE '%" . $remove['message'] . "'";
			}

			$sql = "DELETE
				FROM " . $table . "
				WHERE message LIKE '%" . $remove['message'] . "'";
		}

		if (($sql != '' || $sql1 != '') && ($rule == '' || $remove['id'] == $rule)) {
			$debugm = '';
			if ($sql1 != '') {
				/* move rows first */
				db_execute($sql1, true, $syslog_cnn);
				$messages_moved = $syslog_cnn->Affected_Rows();
				$debugm = "Moved " . $messages_moved . ", ";
			}

			/* now delete the remainder that match */
			db_execute($sql, true, $syslog_cnn);

			syslog_debug($debugm . "Deleted " . $syslog_cnn->Affected_rows() .
					" Message" . ($syslog_cnn->Affected_rows() == 1 ? "" : "s" ) .
					" for removal rule '" . $remove['name'] . "'");
		}
	}
	}
}

/** function syslog_row_color()
 *  This function set's the CSS for each row of the syslog table as it is displayed
 *  it supports both the legacy as well as the new approach to controlling these
 *  colors.
*/
function syslog_row_color($row_color1, $row_color2, $row_value, $level, $tip_title) {
	global $config, $syslog_colors, $syslog_text_colors;

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

	$tip_options = "CLICKCLOSE, 'true', WIDTH, '40', DELAY, '300', FOLLOWMOUSE, 'true', FADEIN, 250, FADEOUT, 250, BGCOLOR, '#FEFEFE', STICKY, 'true', SHADOWCOLOR, '#797C6E'";

	print "<tr onmouseout=\"UnTip()\" onmouseover=\"Tip(" . $tip_title . ", " . $tip_options . ")\" class='syslog_$level'>\n";
}

function sql_hosts_where() {
	global $hostfilter, $syslog_incoming_config;

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
							$hostfilter .= " AND " . $syslog_incoming_config["hostField"] . " IN('" . $_REQUEST["host"][$x] . "'";
						} else {
							$hostfilter .= " " . $syslog_incoming_config["hostField"] . " IN('" . $_REQUEST["host"][$x] . "'";
						}
					}

					$x++;
				}

				$hostfilter .= ")";
			}
		}else{
			if (!empty($sql_where)) {
				$hostfilter .= " AND " . $syslog_incoming_config["hostField"] . " IN('" . $_REQUEST["host"] . "')";
			} else {
				$hostfilter .= " " . $syslog_incoming_config["hostField"] . " IN('" . $_REQUEST["host"] . "')";
			}
		}
	}
}


function syslog_export () {
	global $syslog_incoming_config;

	header("Content-type: application/excel");
	header("Content-Disposition: attachment; filename=log_view-" . date("Y-m-d",time()) . ".csv");

	$sql_where = "";
	$syslog_messages = get_syslog_messages($sql_where, "10000");

	if (sizeof($syslog_messages) > 0) {
		foreach ($syslog_messages as $syslog_message) {
			print
				$syslog_message[$syslog_incoming_config["hostField"]]     . "," .
				$syslog_message[$syslog_incoming_config["facilityField"]] . "," .
				$syslog_message[$syslog_incoming_config["priorityField"]] . "," .
				$syslog_message["logtime"]     . "," .
				$syslog_message[$syslog_incoming_config["textField"]]     . "\r\n";
		}
	}
}

function syslog_debug($message) {
	global $syslog_debug;

	if ($syslog_debug) {
		echo "SYSLOG: " . $message . "\n";
	}
}

