<?php
/*
 ex: set tabstop=4 shiftwidth=4 autoindent:
 +-------------------------------------------------------------------------+
 | Copyright (C) 2006 Platform Computing, Inc.                             |
 | Portions Copyright (C) 2004-2006 The Cacti Group                        |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU Lesser General Public              |
 | License as published by the Free Software Foundation; either            |
 | version 2.1 of the License, or (at your option) any later version.      |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU Lesser General Public License for more details.                     |
 |                                                                         |
 | You should have received a copy of the GNU Lesser General Public        |
 | License along with this library; if not, write to the Free Software     |
 | Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA           |
 | 02110-1301, USA                                                         |
 +-------------------------------------------------------------------------+
 | - Platform - http://www.platform.com/                                   |
 | - Cacti - http://www.cacti.net/                                         |
 +-------------------------------------------------------------------------+
*/

global $colors, $config;

$using_guest_account = false;
$show_console_tab = true;

$oper_mode = api_plugin_hook_function('general_header', OPER_MODE_NATIVE);
if ($oper_mode != OPER_MODE_RESKIN) {

if (read_config_option("auth_method") != 0) {
	global $colors, $config;

	/* at this point this user is good to go... so get some setting about this
	user and put them into variables to save excess SQL in the future */
	$current_user = db_fetch_row("select * from user_auth where id=" . $_SESSION["sess_user_id"]);

	/* find out if we are logged in as a 'guest user' or not */
	if (db_fetch_cell("select id from user_auth where username='" . read_config_option("guest_user") . "'") == $_SESSION["sess_user_id"]) {
		$using_guest_account = true;
	}

	/* find out if we should show the "console" tab or not, based on this user's permissions */
	if (sizeof(db_fetch_assoc("select realm_id from user_auth_realm where realm_id=8 and user_id=" . $_SESSION["sess_user_id"])) == 0) {
		$show_console_tab = false;
	}
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
	<title><?php print (isset($title) ? $title : "Syslog Viewer");?></title>
	<link href="<?php echo $config['url_path'];?>include/main.css" rel="stylesheet">
	<link href="<?php echo $config['url_path'];?>plugins/syslog/images/favicon.ico" rel="shortcut icon">
	<?php if (isset($_REQUEST["refresh"])) {
	print "<meta http-equiv=refresh content=\"" . $_REQUEST["refresh"] . "; url='" . $config["url_path"] . "plugins/syslog/syslog.php'\">";
	}?>
	<script type="text/javascript" src="<?php echo $config['url_path']; ?>include/layout.js"></script>
	<script type="text/javascript" src="<?php echo $config['url_path']; ?>include/jscalendar/calendar.js"></script>
	<script type="text/javascript" src="<?php echo $config['url_path']; ?>include/jscalendar/lang/calendar-en.js"></script>
	<script type="text/javascript" src="<?php echo $config['url_path']; ?>include/jscalendar/calendar-setup.js"></script>
	<?php api_plugin_hook('page_head'); ?>
</head>

<?php if ($oper_mode == OPER_MODE_NATIVE) {?>
<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0" <?php print api_plugin_hook_function("body_style", "");?>>
<script language="JavaScript" type="text/javascript" src="<?php print $config['url_path'] . 'plugins/syslog/wz_tooltip.js';?>"></script>
<a name='page_top'></a>
<?php }else{?>
<body leftmargin="15" topmargin="15" marginwidth="15" marginheight="15" <?php print api_plugin_hook_function("body_style", "");?>>
<script language="JavaScript" type="text/javascript" src="<?php print $config['url_path'] . 'plugins/grid/wz_tooltip.js';?>"></script>
<a name='page_top'></a>
<?php }?>

<table width="100%" cellspacing="0" cellpadding="0">
	<?php if ($oper_mode == OPER_MODE_NATIVE) { ;?>
	<tr height="37" bgcolor="#a9a9a9" class="noprint">
		<td valign="bottom" colspan="3" nowrap>
			<table width="100%" cellspacing="0" cellpadding="0">
				<tr style="background: transparent url('<?php print $config['url_path'];?>images/cacti_backdrop2.gif') no-repeat center right;">
					<td id="tabs" valign="bottom" nowrap>
						&nbsp;<?php if ($show_console_tab == true) {?><a href="<?php echo $config['url_path']; ?>index.php"><img src="<?php echo $config['url_path']; ?>images/tab_console.gif" alt="Console" align="absmiddle" border="0"></a><?php
							 }?><a href="<?php echo $config['url_path']; ?>graph_view.php"><img src="<?php echo $config['url_path']; ?>images/tab_graphs.gif" alt="Graphs" align="absmiddle" border="0"></a><?php
					api_plugin_hook("top_graph_header_tabs");
					?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
	<tr height="2" bgcolor="#183c8f" class="noprint">
		<td colspan="3">
			<img src="<?php echo $config['url_path']; ?>images/transparent_line.gif" width="170" height="2" border="0"><br>
		</td>
	</tr>
	<tr height="5" bgcolor="#e9e9e9" class="noprint">
		<td colspan="3">
			<table width="100%">
				<tr>
					<td>
						<?php echo draw_navigation_text();?>
					</td>
					<td align="right">
						<?php if ((isset($_SESSION["sess_user_id"])) && ($using_guest_account == false)) { ?>
						Logged in as <strong><?php print db_fetch_cell("select username from user_auth where id=" . $_SESSION["sess_user_id"]);?></strong> (<a href="<?php echo $config['url_path']; ?>logout.php">Logout</a>)&nbsp;
						<?php } ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
	<tr class="noprint">
		<td colspan="2" height="8" style="background-image: url(<?php echo $config['url_path']; ?>images/shadow.gif); background-repeat: repeat-x;" bgcolor="#ffffff">
		</td>
	</tr>
	<tr>
		<td width="100%" colspan="2" valign="top" style="padding: 5px; border-right: #aaaaaa 1px solid;"><div style='position:relative;' id='main'><?php display_output_messages();?>
<?php }else{ ?>
	<tr>
		<td width="100%" valign="top"><div style='position:relative;' id='main'><?php display_output_messages();?>
<?php } } ?>
