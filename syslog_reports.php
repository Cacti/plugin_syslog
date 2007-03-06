<?php

$guest_account = true;

chdir('../../');

include("./include/auth.php");
include_once("./include/config.php");
include('plugins/syslog/config.php');
include_once('plugins/syslog/functions.php');

input_validate_input_number(get_request_var("id"));


$types = array(	'messageb' => 'Message Begins with',
			'messagec' => 'Message Contains',
			'messagee' => 'Message Ends with',
			'host'     => 'Hostname is');

$freqs = array('86400' => 'Last Day',
		'604800' => 'Last Week');


$id = '';
if (isset($_GET['id']))
	$id = $_GET['id'];
if (isset($_PSOT['id']))
	$id = $_POST['id'];

if (!isset($_GET["page"])) {
	$_REQUEST["page"]="1";
}


if (isset($_GET['remove']) && $_GET['remove'] > 0) {
	$remove = (int) $_GET['remove'];
	$remove = sql_sanitize($remove);
	db_connect_real($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, $syslogdb_type);
	db_execute("delete from " . $syslog_config['reportTable'] . " where id = $remove");
	Header("Location:syslog_reports.php\n");
	exit;
}


if (isset($_POST['ename']) && isset($_POST['etext']) && $_POST['ename'] != '' && $_POST['etext'] != '') {
	$username = db_fetch_cell("select username from user_auth where id=" . $_SESSION["sess_user_id"]);
	$save['id'] = '';
	$save['name'] = sql_sanitize($_POST['ename']);
	$save['message'] = sql_sanitize($_POST['etext']);
	$save['type'] = sql_sanitize($_POST['etype']);
	$save['email'] = sql_sanitize($_POST['eemail']);
	$save['timespan'] = sql_sanitize($_POST['freq']);
	$save['hour'] = sql_sanitize($_POST['hour']);
	$save['min'] = sql_sanitize($_POST['min']);
	$save['user'] = strtoupper($username);
	$save['date'] = time();
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);
	sql_save($save, "syslog_reports", "id");
	Header("Location:syslog_reports.php\n");
	exit;
}

	include_once("./include/top_graph_header.php");
	db_connect_real($syslogdb_hostname,$syslogdb_username,$syslogdb_password,$syslogdb_default, $syslogdb_type);

	display_reports();
	print "<br><br><br>";

	$text = '';
	if ($id > 0) {
		$text = db_fetch_cell("SELECT " . $syslog_config["textField"] . " FROM " . $syslog_config["syslogTable"] . " where " . $syslog_config["id"] . "=" . $id);
		$x = strpos($text, ':');
		$y = strpos($text, ' ', $x + 2);
		$text = substr($text, 0, $y);
	}
	disyplay_edit($text);

	include_once("./include/bottom_footer.php");

function disyplay_edit($text) {
	global $colors, $config, $types, $freqs;
	print "<form action=syslog_reports.php method=post><a name=edit></a><center><h3>Add an Report</h3><table cellspacing=0 cellpadding=1 bgcolor='#" . $colors["header"] . "'><tr><td><table bgcolor='#" . $colors["header_panel"] . "'>
		<tr bgcolor='#" . $colors["header_panel"] . "'><td class='textSubHeaderDark'>Name: </td><td><input type=text name=ename size=23></td></tr>
		<tr bgcolor='#" . $colors["header_panel"] . "'><td class='textSubHeaderDark'>Type: </td><td><select name=etype>";

		foreach ($types as $ty => $t) {
			print "<option value='$ty'>$t</option>";
		}

	print "</select>
		</td></tr>
		<tr bgcolor='#" . $colors["header_panel"] . "'><td class='textSubHeaderDark'>Text: </td><td><input type=text name=etext size=23 value='$text'></td></tr>
		<tr bgcolor='#" . $colors["header_panel"] . "'><td class='textSubHeaderDark'>Report on: </td><td><select name=freq>";

		foreach ($freqs as $ty => $t) {
			print "<option value='$ty'>$t</option>";
		}

	print "</select>
		</td></tr>
		<tr bgcolor='#" . $colors["header_panel"] . "'><td class='textSubHeaderDark'>Send at: </td><td><select name=hour>";

		for ($a = 0; $a < 24; $a++) {
			$a2 = $a;
			if ($a2 < 10)
				$a2 = '0' . $a2;
			print "<option value='$a'>$a2</option>";
		}

	print "</select> : <select name=min>";

		for ($a = 0; $a < 60; $a = $a + 5) {
			$a2 = $a;
			if ($a2 < 10)
				$a2 = '0' . $a2;
			print "<option value='$a'>$a2</option>";
		}



	print "</select>
		</td></tr>
		<tr bgcolor='#" . $colors["header_panel"] . "'><td class='textSubHeaderDark'>Email: </td><td><input type=text name=eemail size=23></td></tr>
		<tr bgcolor='#" . $colors["header_panel"] . "'><td class='textSubHeaderDark' colspan=2><center><input type=image name=submit src='" . $config['url_path'] . "images/button_save.gif'></center></td></tr>
		</table></td></tr></table></center>\n";
}

function display_reports () {
	global $colors, $sql_where, $hostfilter, $config, $types, $freqs;
	include("./include/config_arrays.php");
	include('plugins/syslog/config.php');

	$syslog_config["rows_per_page"] = read_config_option("num_rows_syslog");

	$url_curr_page = get_browser_query_string();
	$syslog_reports = db_fetch_assoc("SELECT * FROM " . $syslog_config["reportTable"] . ' LIMIT ' . $syslog_config["rows_per_page"]*($_REQUEST["page"]-1) . ', ' . $syslog_config["rows_per_page"]);
?>

						<center><h1>Syslog Reports</h1><br><table width="50%" cellspacing="0" cellpadding="0">
							<tr>
								<td bgcolor="#ffffff" height="8" style="background-image: url(images/shadow.gif); background-repeat: repeat-x;">
								</td>
							</tr>
							<tr>
								<td width="100%" valign="top" style="padding: 5px;">
									<?php
									$total_rows = db_fetch_cell("SELECT count(*) from " . $syslog_config["reportTable"]);
									html_start_box("", "98%", $colors["header"], "3", "center", "");

										$nav = "<tr bgcolor='#" . $colors["header"] . "'>
											<td colspan='10'>
												<table width='100%' cellspacing='0' cellpadding='0' border='0'>
													<tr>
														<td align='left' class='textHeaderDark'>
															<strong>&lt;&lt; "; if (isset($_REQUEST["page"]) && $_REQUEST["page"] > 1) { $nav .= "<a class='linkOverDark' href='" . get_query_edited_url($url_curr_page, 'page', ($_REQUEST["page"]-1)) . "'>"; } $nav .= "Previous"; if (isset($_REQUEST["page"]) && $_REQUEST["page"] > 1) { $nav .= "</a>"; } $nav .= "</strong>
														</td>\n
														<td align='center' class='textHeaderDark'>
															Showing Rows " . (($syslog_config["rows_per_page"]*($_REQUEST["page"]-1))+1) . " to " . ((($total_rows < $syslog_config["rows_per_page"]) || ($total_rows < ($syslog_config["rows_per_page"]*$_REQUEST["page"]))) ? $total_rows : ($syslog_config["rows_per_page"]*$_REQUEST["page"])) . " of $total_rows [" . syslog_page_select($total_rows) . "]
														</td>\n
														<td align='right' class='textHeaderDark'>
															<strong>"; if (isset($_REQUEST["page"]) && ($_REQUEST["page"] * $syslog_config["rows_per_page"]) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . get_query_edited_url($url_curr_page, 'page', ($_REQUEST["page"]+1)) . "'>"; } $nav .= "Next"; if (($_REQUEST["page"] * $syslog_config["rows_per_page"]) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
														</td>\n
													</tr>
												</table>
											</td>
										</tr>\n";
										print "	$nav
										<tr bgcolor='#" . $colors["header_panel"] . "'>
											<td class='textSubHeaderDark'>Name</td>
											<td class='textSubHeaderDark'>Type</td>
											<td class='textSubHeaderDark'>Text</td>
											<td class='textSubHeaderDark' nowrap>Time Span</td>
											<td class='textSubHeaderDark' nowrap>Send At</td>
											<td class='textSubHeaderDark'>Email</td>
											<td class='textSubHeaderDark'>&nbsp;</td>
											<td class='textSubHeaderDark'>User</td>
											<td class='textSubHeaderDark'>Date</td>
											<td class='textSubHeaderDark'>Options</td>
										</tr>\n";
										$i = 0;
										if (sizeof($syslog_reports) > 0) {
											foreach ($syslog_reports as $syslog_message) {
												syslog_row_color($colors["alternate"],$colors["light"],$i,$colors["alternate"]); $i++;
												?>
												<td nowrap valign=top>
													<?php print $syslog_message['name']; ?>
												</td>
												<td nowrap valign=top>
													<?php print $types[$syslog_message['type']]; ?>
												</td>
												<td nowrap valign=top>
													<?php print $syslog_message['message']; ?>
												</td>
												<td nowrap valign=top>
													<?php print $freqs[$syslog_message['timespan']]; ?>
												</td>
												<td nowrap valign=top>
													<?php
														if ($syslog_message['hour'] < 10)
															print '0';
														print $syslog_message['hour'];
														print ':';
														if ($syslog_message['min'] < 10)
															print '0';
														print $syslog_message['min'];

													?>
												</td>
												<td nowrap valign=top>
													<?php print $syslog_message['email']; ?>
												</td>
												<td nowrap>
													<?php print " ";?>
												</td>
												<td valign=top>
													<?php print $syslog_message['user']; ?>
												</td>
												<td nowrap valign=top>
													<?php print date("F j, Y, g:i a", $syslog_message['date']); ?>
												</td>
												<td nowrap valign=top>
													<center><a href='syslog_reports.php?remove=<?php print $syslog_message['id']; ?>'><img src='images/red.gif' border=0></a></center>
												</td>
											</tr>
											<?php
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
						</table></center>
<?php
}

?>