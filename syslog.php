<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2016 The Cacti Group                                 |
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

/* allow guest account to see this page */
$guest_account = true;

/* initialize cacti environment */
chdir('../../');
include('./include/auth.php');

/* syslog specific database setup and functions */
include('./plugins/syslog/config.php');
include_once('./plugins/syslog/functions.php');

$title = 'Syslog Viewer';

$trimvals = array(
	'1024' => 'All Text',
	'30'   => '30 Chars',
	'50'   => '50 Chars',
	'75'   => '75 Chars',
	'100'  => '100 Chars',
	'150'  => '150 Chars',
	'300'  => '300 Chars'
);

/* set the default tab */
get_filter_request_var('tab', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z]+)$/')));

load_current_session_value('tab', 'sess_syslog_tab', 'syslog');
$current_tab = get_request_var('tab');

/* validate the syslog post/get/request information */;
if ($current_tab != 'stats') {
	syslog_request_validation($current_tab);
}

/* draw the tabs */
/* display the main page */
if (isset_request_var('export')) {
	syslog_export($current_tab);

	/* clear output so reloads wont re-download */
	unset_request_var('output');
}else{
	general_header();

	syslog_display_tabs($current_tab);

	if ($current_tab == 'current') {
		syslog_view_alarm();
	}elseif ($current_tab == 'stats') {
		syslog_statistics();
	}else{
		syslog_messages($current_tab);
	}

	bottom_footer();
}
	
$_SESSION['sess_nav_level_cache'] = '';

function syslog_display_tabs($current_tab) {
	global $config;

	/* present a tabbed interface */
	$tabs_syslog['syslog'] = 'Syslogs';
	if (read_config_option('syslog_statistics') == 'on') {
		$tabs_syslog['stats']  = 'Statistics';
	}
	$tabs_syslog['alerts'] = 'Alert Log';

	/* if they were redirected to the page, let's set that up */
	if (!isempty_request_var('id') || $current_tab == 'current') {
		$current_tab = 'current';
	}

	load_current_session_value('id', 'sess_syslog_id', '0');
	if (!isempty_request_var('id') || $current_tab == 'current') {
		$tabs_syslog['current'] = 'Selected Alert';
	}

	/* draw the tabs */
	print "<div class='tabs'><nav><ul>\n";

	if (sizeof($tabs_syslog)) {
		foreach (array_keys($tabs_syslog) as $tab_short_name) {
			print '<li><a ' . (($tab_short_name == $current_tab) ? "class='selected'":'') . " href='" . htmlspecialchars($config['url_path'] .
				'plugins/syslog/syslog.php?' .
				'tab=' . $tab_short_name) .
				"'>" . $tabs_syslog[$tab_short_name] . "</a></li>\n";
		}
	}
	print "</ul></nav></div>\n";
}

function syslog_view_alarm() {
	global $config;

	include(dirname(__FILE__) . '/config.php');

	echo "<table class='cactiTableTitle' cellpadding='3' cellspacing='0' align='center' width='100%'>";
	echo "<tr class='tableHeader'><td class='textHeaderDark'>Syslog Alert View</td></tr>";
	echo "<tr><td class='odd'>\n";

	$html = syslog_db_fetch_cell('SELECT html FROM `' . $syslogdb_default . '`.`syslog_logs` WHERE seq=' . get_request_var('id'));
	echo $html;

	echo '</td></tr></table>';

	exit;
}

/** function syslog_statistics()
 *  This function paints a table of summary statistics for syslog
 *  messages by host, facility, priority, and time range.
*/
function syslog_statistics() {
	global $title, $rows, $config;

	include(dirname(__FILE__) . '/config.php');

    /* ================= input validation and session storage ================= */
    $filters = array(
        'rows' => array(
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => '-1',
            ),
        'refresh' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => read_config_option('syslog_refresh'),
            ),
        'timespan' => array(
            'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
            'default' => '-1',
            ),
        'page' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => '1'
            ),
        'filter' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => '',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'efacility' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => '',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'epriority' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => '',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'sort_column' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'name',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'sort_direction' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'ASC',
            'options' => array('options' => 'sanitize_search_string')
            )
    );

    validate_store_request_vars($filters, 'sess_syslogs');
    /* ================= input validation ================= */

	html_start_box('Syslog Statistics Filter', '100%', '', '3', 'center', '');

	syslog_stats_filter();

	html_end_box();

	html_start_box('', '100%', '', '3', 'center', '');

	$sql_where   = '';
	$sql_groupby = '';

	if (get_request_var('rows') == -1) {
		$row_limit = read_config_option('num_rows_table');
	}elseif (get_request_var('rows') == -2) {
		$row_limit = 999999;
	}else{
		$row_limit = get_request_var('rows');
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

	$total_rows = syslog_db_fetch_cell('SELECT COUNT(*) FROM ('. $rows_query_string  . ') as temp');

	?>
	<script type='text/javascript'>

	function applyFilter() {
		strURL  = 'syslog.php?header=false&facility=' + $('#facility').val();
		strURL += '&priority=' + $('#priority').val();
		strURL += '&filter=' + $('#filter').val();
		strURL += '&rows=' + $('#rows').val();
		loadPageNoHeader(strURL);
	}

	function forceReturn(evt) {
		var evt  = (evt) ? evt : ((event) ? event : null);
		var node = (evt.target) ? evt.target : ((evt.srcElement) ? evt.srcElement : null);

		if ((evt.keyCode == 13) && (node.type=='text')) {
			document.getElementById('syslog_form').submit();
			return false;
		}
	}
	document.onkeypress = forceReturn;

	</script>
	<?php

	$nav = html_nav_bar('syslog.php?tab=stats&filter=' . get_request_var_request('filter'), MAX_DISPLAY_PAGES, get_request_var_request('page'), $row_limit, $total_rows, 4, 'Messages', 'page', 'main');

	print $nav;

	$display_text = array(
		'host'     => array('Host Name', 'ASC'),
		'facility' => array('Facility', 'ASC'),
		'priority' => array('Priority', 'ASC'),
		'records'  => array('Records', 'DESC'));

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

	if (sizeof($records)) {
		foreach ($records as $r) {
			form_alternate_row();
			echo '<td>' . $r['host'] . '</td>';
			echo '<td>' . (get_request_var('facility') != '-2' ? ucfirst($r['facility']):'-') . '</td>';
			echo '<td>' . (get_request_var('priority') != '-2' ? ucfirst($r['priority']):'-') . '</td>';
			echo '<td>' . $r['records'] . '</td>';
			form_end_row();
		}
	}else{
		print "<tr><td colspan='4'><em>No Syslog Statistics Found</em></td></tr>";
	}

	html_end_box(false);
}

function get_stats_records(&$sql_where, &$sql_groupby, $row_limit) {
	include(dirname(__FILE__) . '/config.php');

	$sql_where   = '';
	$sql_groupby = 'GROUP BY sh.host';

	/* form the 'where' clause for our main sql query */
	if (!isempty_request_var('filter')) {
		$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') . "sh.host LIKE '%" . get_request_var('filter') . "%'";
	}

	if (get_request_var('facility') == '-2') {
		// Do nothing
	}elseif (get_request_var('facility') != '-1') {
		$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') . 'ss.facility_id=' . get_request_var('facility');
		$sql_groupby .= ', sf.facility';
	}else{
		$sql_groupby .= ', sf.facility';
	}

	if (get_request_var('priority') == '-2') {
		// Do nothing
	}elseif (get_request_var('priority') != '-1') {
		$sql_where .= (!strlen($sql_where) ? 'WHERE ': ' AND ') . 'ss.priority_id=' . get_request_var('priority');
		$sql_groupby .= ', sp.priority';
	}else{
		$sql_groupby .= ', sp.priority';
	}

	if (!isset_request_var('export')) {
		$limit = ' LIMIT ' . ($row_limit*(get_request_var('page')-1)) . ',' . $row_limit;
	} else {
		$limit = ' LIMIT 10000';
	}

	$sort = get_request_var('sort_column');

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
		ORDER BY " . $sort . " " . get_request_var('sort_direction') .
		$limit;

	return syslog_db_fetch_assoc($query_sql);
}

function syslog_stats_filter() {
	global $config, $item_rows;
	?>
	<tr class='even'>
		<td>
		<form name='stats'>
			<table class='filterTable'>
				<tr>
					<td>
						Facility
					</td>
					<td>
						<select id='facility' onChange='applyChange(document.stats)'>
							<option value='-1'<?php if (get_request_var('facility') == '-1') { ?> selected<?php } ?>>All</option>
							<option value='-2'<?php if (get_request_var('facility') == '-2') { ?> selected<?php } ?>>None</option>
							<?php
							$facilities = syslog_db_fetch_assoc('SELECT DISTINCT facility_id, facility 
								FROM syslog_facilities AS sf
								WHERE facility_id IN (SELECT DISTINCT facility_id FROM syslog_statistics)
								ORDER BY facility');

							if (sizeof($facilities)) {
							foreach ($facilities as $r) {
								print '<option value="' . $r['facility_id'] . '"'; if (get_request_var('facility') == $r['facility_id']) { print ' selected'; } print '>' . ucfirst($r['facility']) . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td>
						Priority
					</td>
					<td>
						<select id='priority' onChange='applyChange()'>
							<option value='-1'<?php if (get_request_var('priority') == '-1') { ?> selected<?php } ?>>All</option>
							<option value='-2'<?php if (get_request_var('priority') == '-2') { ?> selected<?php } ?>>None</option>
							<?php
							$priorities = syslog_db_fetch_assoc('SELECT DISTINCT priority_id, priority 
								FROM syslog_priorities AS sp
								WHERE priority_id IN (SELECT DISTINCT priority_id FROM syslog_statistics)
								ORDER BY priority');

							if (sizeof($priorities)) {
							foreach ($priorities as $r) {
								print '<option value="' . $r['priority_id'] . '"'; if (get_request_var('priority') == $r['priority_id']) { print ' selected'; } print '>' . ucfirst($r['priority']) . "</option>\n";
							}
							}
							?>
						</select>
					</td>
					<td>
						Entries
					</td>
					<td>
						<select id='rows' onChange='applyChange()'>
						<option value='-1'<?php if (get_request_var('rows') == '-1') { ?> selected<?php } ?>>Default</option>
						<?php
							if (sizeof($item_rows) > 0) {
							foreach ($item_rows as $key => $value) {
								print '<option value="' . $key . '"'; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . "</option>\n";
							}
							}
						?>
						</select>
					</td>
					<td>
						<input id='go' type='button' value='Go'>
					</td>
					<td>
						<input id='clear' type='button' value='Clear'>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						Search
					</td>
					<td>
						<input type='text' id='filter' size='30' value='<?php print get_request_var('filter');?>'>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_filter_request_var('page');?>'>
		</form>
		</td>
		<script type='text/javascript'>

		function applyFilter() {
			strURL = 'syslog_reports.php?filter='+$('#filter').val()+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
			loadPageNoHeader(strURL);
		}

		function clearFilter() {
			strURL = 'syslog_reports.php?clear=1&header=false';
			loadPageNoHeader(strURL);
		}

		$(function() {
			$('#go').click(function() {
				applyFilter();
			});

			$('#clear').click(function() {
				clearFilter();
			});

			$('#removal').submit(function(event) {
				event.preventDefault();
				applyFilter();
			});
		});

		function forceReturn(evt) {
			var evt  = (evt) ? evt : ((event) ? event : null);
			var node = (evt.target) ? evt.target : ((evt.srcElement) ? evt.srcElement : null);

			if ((evt.keyCode == 13) && (node.type=='text')) {
				document.getElementById('syslog_form').submit();
				return false;
			}
		}
		document.onkeypress = forceReturn;

		</script>
	</tr>
	<?php
}

/** function syslog_request_validation()
 *  This is a generic funtion for this page that makes sure that
 *  we have a good request.  We want to protect against people who
 *  like to create issues with Cacti.
*/
function syslog_request_validation($current_tab, $force = false) {
	global $title, $rows, $config, $reset_multi;

	include_once('./lib/timespan_settings.php');

	if ($current_tab != 'alerts' && isset_request_var('host') && get_nfilter_request_var('host') == -1) {
		kill_session_var('sess_syslog_' . $current_tab . '_hosts');
		unset_request_var('host');
	}

    /* ================= input validation and session storage ================= */
    $filters = array(
        'rows' => array(
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => read_user_setting('syslog_rows', '-1', $force)
            ),
        'page' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => '1'
            ),
        'id' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => ''
            ),
        'removal' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => read_user_setting('syslog_removal', '1', $force)
            ),
        'refresh' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => read_user_setting('syslog_refresh', read_config_option('syslog_refresh'), $force) 
            ),
        'trimval' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => read_user_setting('syslog_trimval', '75', $force)
            ),
        'enabled' => array(
            'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
            'default' => '-1'
			),
        'host' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => '',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'efacility' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => read_user_setting('syslog_efacility', '-1', $force),
            'options' => array('options' => 'sanitize_search_string')
            ),
        'epriority' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => read_user_setting('syslog_epriority', '-1', $force),
            'options' => array('options' => 'sanitize_search_string')
            ),
        'eprogram' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => read_user_setting('syslog_eprogram', '-1', $force),
            'options' => array('options' => 'sanitize_search_string')
            ),
        'filter' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => '',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'sort_column' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'logtime',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'sort_direction' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'DESC',
            'options' => array('options' => 'sanitize_search_string')
            )
    );

    validate_store_request_vars($filters, 'sess_syslogs_' . $current_tab);
    /* ================= input validation ================= */

	api_plugin_hook_function('syslog_request_val');

	if (isset_request_var('host')) {
		$_SESSION['sess_syslog_' . $current_tab . '_hosts'] = get_nfilter_request_var('host');
	} else if (isset($_SESSION['sess_syslog_' . $current_tab . '_hosts'])) {
		set_request_var('host', $_SESSION['sess_syslog_' . $current_tab . '_hosts']);
	} else {
		set_request_var('host', '-1');
	}
}

function get_syslog_messages(&$sql_where, $row_limit, $tab) {
	global $sql_where, $hostfilter, $current_tab, $syslog_incoming_config;

	include(dirname(__FILE__) . '/config.php');

	$sql_where = '';
	/* form the 'where' clause for our main sql query */
	if (get_request_var('host') == -1 && $tab != 'syslog') {
		$sql_where .=  "WHERE sl.host='N/A'";
	}else{
		if (!isempty_request_var('host')) {
			sql_hosts_where($tab);
			if (strlen($hostfilter)) {
				$sql_where .=  'WHERE ' . $hostfilter;
			}
		}
	}

	if (isset($_SESSION['sess_current_date1'])) {
		$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') .
			"logtime BETWEEN '" . $_SESSION['sess_current_date1'] . "'
				AND '" . $_SESSION['sess_current_date2'] . "'";
	}

	if (isset_request_var('id') && $current_tab == 'current') {
		$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') .
			'sa.id=' . get_request_var('id');
	}

	if (!isempty_request_var('filter')) {
		if ($tab == 'syslog') {
			$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') . "message LIKE '%" . get_request_var('filter') . "%'";
		}else{
			$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') . "logmsg LIKE '%" . get_request_var('filter') . "%'";
		}
	}

	if (get_request_var('eprogram') != '-1') {
		$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') . "syslog.program_id='" . get_request_var('eprogram') . "'";
	}

	if (get_request_var('efacility') != '-1') {
		$sql_where .= (!strlen($sql_where) ? 'WHERE ' : ' AND ') . "syslog.facility_id='" . get_request_var('efacility') . "'";
	}

	if (isset_request_var('epriority') && get_request_var('epriority') != '-1') {
		$priorities = '';

		switch(get_request_var('epriority')) {
		case '0':
			$priorities = "=0";
			break;
		case '1o':
			$priorities = "=1";
			break;
		case '1':
			$priorities = "<1";
			break;
		case '2o':
			$priorities = "=2";
			break;
		case '2':
			$priorities = "<=2";
			break;
		case '3o':
			$priorities = "=3";
			break;
		case '3':
			$priorities = "<=3";
			break;
		case '4o':
			$priorities = "=4";
			break;
		case '4':
			$priorities = "<=4";
			break;
		case '5o':
			$priorities = "=5";
			break;
		case '5':
			$priorities = "<=5";
			break;
		case '6o':
			$priorities = "=6";
			break;
		case '6':
			$priorities = "<=6";
			break;
		case '7':
			$priorities = "=7";
			break;
		}

		$sql_where .= (!strlen($sql_where) ? 'WHERE ': ' AND ') . 'syslog.priority_id ' . $priorities;
	}

	$sql_where = api_plugin_hook_function('syslog_sqlwhere', $sql_where);

	if (!isset_request_var('export')) {
		$limit = ' LIMIT ' . ($row_limit*(get_request_var('page')-1)) . ',' . $row_limit;
	} else {
		$limit = ' LIMIT 10000';
	}

	$sort = get_request_var('sort_column');

	if ($tab == 'syslog') {
		if (get_request_var('removal') == '-1') {
			$query_sql = "SELECT syslog.*, syslog_programs.program, 'main' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog` 
				LEFT JOIN  `" . $syslogdb_default . "`.`syslog_programs` 
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . "
				ORDER BY " . $sort . " " . get_request_var('sort_direction') .
				$limit;
		}elseif (get_request_var('removal') == '1') {
			$query_sql = "(SELECT syslog.*, syslog_programs.program, 'main' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog` AS syslog
				LEFT JOIN  `" . $syslogdb_default . "`.`syslog_programs` 
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . "
				) UNION (SELECT syslog.*, syslog_programs.program, 'remove' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog_removed` AS syslog
				LEFT JOIN  `" . $syslogdb_default . "`.`syslog_programs` 
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . ")
				ORDER BY " . $sort . " " . get_request_var('sort_direction') .
				$limit;
		}else{
			$query_sql = "SELECT syslog.*, syslog_programs.program, 'remove' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog_removed` AS syslog
				LEFT JOIN  `" . $syslogdb_default . "`.`syslog_programs` AS syslog
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . "
				ORDER BY " . $sort . " " . get_request_var('sort_direction') .
				$limit;
		}
	}else{
		$query_sql = "SELECT sl.*, spr.program, sa.name, sa.severity
			FROM `" . $syslogdb_default . "`.`syslog_logs` AS sl
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
			ON sl.facility=sf.facility
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
			ON sl.priority=sp.priority
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
			ON sl.host=sh.host
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_alert` AS sa
			ON sl.alert_id=sa.id 
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs` AS spr
			ON sl.program_id=spr.program_id " .
			$sql_where . "
			ORDER BY " . $sort . " " . get_request_var('sort_direction') .
			$limit;
	}

	//echo $query_sql;

	return syslog_db_fetch_assoc($query_sql);
}

function syslog_filter($sql_where, $tab) {
	global $config, $graph_timespans, $graph_timeshifts, $reset_multi, $page_refresh_interval, $item_rows, $trimvals;

	include(dirname(__FILE__) . '/config.php');

	$unprocessed = syslog_db_fetch_cell("SELECT COUNT(*) FROM `" . $syslogdb_default . "`.`syslog_incoming`");

	if (isset($_SESSION['sess_current_date1'])) {
		$filter_text = " [ Start: '" . $_SESSION['sess_current_date1'] . "' to End: '" . $_SESSION['sess_current_date2'] . "', Unprocessed Messages: " . $unprocessed . ' ]';
	}else{
		$filter_text = '[ Unprocessed Messages: ' . $unprocessed . ' ]';
	}

	?>
	<script type='text/javascript'>

	var date1Open = false;
	var date2Open = false;

	$(function() {
		$('#host').multiselect().multiselectfilter({label: 'Search', width: '150'});

		$('#go').click(function() {
			applyFilter();
		});

		$('#startDate').click(function() {
			if (date1Open) {
				date1Open = false;
				$('#date1').datetimepicker('hide');
			}else{
				date1Open = true;
				$('#date1').datetimepicker('show');
			}
		});

		$('#endDate').click(function() {
			if (date2Open) {
				date2Open = false;
				$('#date2').datetimepicker('hide');
			}else{
				date2Open = true;
				$('#date2').datetimepicker('show');
			}
		});

		$('#date1').datetimepicker({
			minuteGrid: 10,
			stepMinute: 1,
			showAnim: 'slideDown',
			numberOfMonths: 1,
			timeFormat: 'HH:mm',
			dateFormat: 'yy-mm-dd',
			showButtonPanel: false
		});

		$('#date2').datetimepicker({
			minuteGrid: 10,
			stepMinute: 1,
			showAnim: 'slideDown',
			numberOfMonths: 1,
			timeFormat: 'HH:mm',
			dateFormat: 'yy-mm-dd',
			showButtonPanel: false
		});

		$(window).resize(function() {
			resizeHostSelect();
		});

		resizeHostSelect();
	});

	function resizeHostSelect() {
		position = $('#host').offset();
		$('#host').css('height', ($(window).height()-position.top)+'px');
	}

	function applyTimespan() {
		strURL  = urlPath+'plugins/syslog/syslog.php?header=false&predefined_timespan=' + $('#predefined_timespan').val();
		strURL += '&predefined_timeshift=' + $('#predefined_timeshift').val();
		loadPageNoHeader(strURL);
	}

	function applyFilter() {
		strURL = 'syslog.php?header=false'+
			'&date1='+$('#date1').val()+
			'&date2='+$('#date2').val()+
			'&host='+$('#host').val()+
			'&filter='+$('#filter').val()+
			'&efacility='+$('#efacility').val()+
			'&epriority='+$('#epriority').val()+
			'&eprogram='+$('#eprogram').val()+
			'&rows='+$('#rows').val()+
			'&trimval='+$('#trimval').val()+
			'&removal='+$('#removal').val()+
			'&refresh='+$('#refresh').val();

		loadPageNoHeader(strURL);
	}

	function refreshTimespanFilter() {
		var json = { 
			custom: 1, 
			button_refresh_x: 1, 
			date1: $('#date1').val(), 
			date2: $('#date2').val(), 
			predefined_timespan: $('#predefined_timespan').val(), 
			predefined_timeshift: $('#predefined_timeshift').val(),
			__csrf_magic: csrfMagicToken
		};

		var href = urlPath+'plugins/syslog/syslog.php?action='+pageAction+'&header=false';
		$.post(href, json).done(function(data) {
			$('#main').html(data);
			applySkin();
		});
	}

	function timeshiftFilterLeft() {
		var json = { 
			move_left_x: 1, 
			move_left_y: 1, 
			date1: $('#date1').val(), 
			date2: $('#date2').val(), 
			predefined_timespan: $('#predefined_timespan').val(), 
			predefined_timeshift: $('#predefined_timeshift').val(),
			__csrf_magic: csrfMagicToken
		};
	
		var href = urlPath+'plugins/syslog/syslog.php?action='+pageAction+'&header=false';
		$.post(href, json).done(function(data) {
			$('#main').html(data);
			applySkin();
		});
	}

	function timeshiftFilterRight() {
		var json = { 
			move_right_x: 1, 
			move_right_y: 1, 
			date1: $('#date1').val(), 
			date2: $('#date2').val(), 
			predefined_timespan: $('#predefined_timespan').val(), 
			predefined_timeshift: $('#predefined_timeshift').val(),
			__csrf_magic: csrfMagicToken
		};
	
		var href = urlPath+'plugins/syslog/syslog.php?action='+pageAction+'&header=false';
		$.post(href, json).done(function(data) {
			$('#main').html(data);
			applySkin();
		});
	}

	function clearTimespanFilter() {
		var json = { 
			button_clear: 1, 
			date1: $('#date1').val(), 
			date2: $('#date2').val(), 
			predefined_timespan: $('#predefined_timespan').val(), 
			predefined_timeshift: $('#predefined_timeshift').val(),
			__csrf_magic: csrfMagicToken
		};
	
		var href = urlPath+'plugins/syslog/syslog.php?action='+pageAction+'&header=false';
		$.post(href, json).done(function(data) {
			$('#main').html(data);
			applySkin();
		});
	}

	function forceReturn(evt) {
		var evt  = (evt) ? evt : ((event) ? event : null);
		var node = (evt.target) ? evt.target : ((evt.srcElement) ? evt.srcElement : null);

		if ((evt.keyCode == 13) && (node.type=='text')) {
			document.getElementById('syslog_form').submit();
			return false;
		}
	}
	document.onkeypress = forceReturn;

	</script>
	<?php

	html_start_box('Syslog Message Filter ' . $filter_text, '100%', '', '3', 'center', '');?>
		<tr class='even noprint'>
			<td class='noprint'>
			<form style='margin:0px;padding:0px;' id='syslog_form' method='post' action='syslog.php'>
				<table class='filterTable'>
					<tr>
						<td>
							<select id='predefined_timespan' onChange='applyTimespan()'>
								<?php
								if ($_SESSION['custom']) {
									$graph_timespans[GT_CUSTOM] = 'Custom';
									set_request_var('predefined_timespan', GT_CUSTOM);
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
										print "<option value='$value'"; if (get_request_var('predefined_timespan') == $value) { print ' selected'; } print '>' . title_trim($graph_timespans[$value], 40) . "</option>\n";
									}
								}
								?>
							</select>
						</td>
						<td>
							From
						</td>
						<td>
							<input type='text' id='date1' size='15' value='<?php print (isset($_SESSION['sess_current_date1']) ? $_SESSION['sess_current_date1'] : '');?>'>
						</td>
						<td>
							<i title='Start Date Selector' class='calendar fa fa-calendar' id='startDate'></i>
						</td>
						<td>
							To
						</td>
						<td>
							<input type='text' id='date2' size='15' value='<?php print (isset($_SESSION['sess_current_date2']) ? $_SESSION['sess_current_date2'] : '');?>'>
						</td>
						<td>
							<i title='End Date Selector' class='calendar fa fa-calendar' id='endDate'></i>
						</td>
						<td>
							<i title='Shift Time Backward' onclick='timeshiftFilterLeft()' class='shiftArrow fa fa-backward'></i>
						</td>
						<td>
							<select id='predefined_timeshift' title='Define Shifting Interval' onChange='applyTimespan()'>
								<?php
								$start_val = 1;
								$end_val = sizeof($graph_timeshifts)+1;
								if (sizeof($graph_timeshifts) > 0) {
									for ($shift_value=$start_val; $shift_value < $end_val; $shift_value++) {
										print "<option value='$shift_value'"; if (get_request_var('predefined_timeshift') == $shift_value) { print ' selected'; } print '>' . title_trim($graph_timeshifts[$shift_value], 40) . "</option>\n";
									}
								}
								?>
							</select>
						</td>
						<td>
							<i title="Shift Time Forward" onclick="timeshiftFilterRight()" class="shiftArrow fa fa-forward"></i>
						</td>
						<td>
							<input id='go' type='button' value='Go'>
						</td>
						<td>
							<input id='clear' type='button' value='Clear' title='Return to the default time span'>
						</td>
						<td>
							<input id='export' type='button' value='Export' title='Export Records to CSV'>
						</td>
						<td>
							<input id='save' type='button' value='Save' title='Save Default Settings'>
						</td>
						<?php if (api_plugin_user_realm_auth('syslog_alerts.php')) { ?>
						<td align='right' style='white-space:nowrap;'>
							<input type='button' value='Alerts' title='View Syslog Alert Rules' onClick='javascript:document.location="<?php print $config['url_path'] . "plugins/syslog/syslog_alerts.php";?>"'>
						</td>
						<td>
							<input type='button' value='Removals' title='View Syslog Removal Rules' onClick='javascript:document.location="<?php print $config['url_path'] . "plugins/syslog/syslog_removal.php";?>"'>
						</td>
						<td>
							<input type='button' value='Reports' title='View Syslog Reports' onClick='javascript:document.location="<?php print $config['url_path'] . "plugins/syslog/syslog_reports.php";?>"'>
						</td>
						<?php } ?>
						<td>
							<input type='hidden' name='action' value='actions'>
							<input type='hidden' name='syslog_pdt_change' value='false'>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<input type='text' id='filter' size='30' value='<?php print get_request_var('filter');?>'>
						</td>
						<td class='even'>
							<select id='host' multiple style='width: 150px; overflow: scroll;'>
								<?php if ($tab == 'syslog') { ?><option id='host_all' value='0'<?php if (get_request_var('host') == 'null' || get_request_var('host') == '0' || $reset_multi) { ?> selected<?php } ?>>Show All Hosts</option><?php }else{ ?>
								<option id='host_all' value='0'<?php if (get_request_var('host') == 'null' || get_request_var('host') == 0 || $reset_multi) { ?> selected<?php } ?>>Show All Logs</option>
								<option id='host_none' value='-1'<?php if (get_request_var('host') == '-1') { ?> selected<?php } ?>>Threshold Logs</option><?php } ?>
								<?php
								$hosts_where = '';
								$hosts_where = api_plugin_hook_function('syslog_hosts_where', $hosts_where);
								$hosts       = syslog_db_fetch_assoc("SELECT * FROM `" . $syslogdb_default . "`.`syslog_hosts` $hosts_where ORDER BY host");
								$selected    = explode(',', get_request_var('host'));
								if (sizeof($hosts)) {
									foreach ($hosts as $host) {
										print "<option value='" . $host["host_id"] . "'";
										if (!isempty_request_var('host') && get_request_var('host') != 'null') {
											foreach ($selected as $rh) {
												if (($rh == $host['host_id']) && (!$reset_multi)) {
													print ' selected';
													break;
												}
											}
										}
										print '>';
										print $host['host'] . "</option>\n";
									}
								}
								?>
							</select>
						</td>
						<td>
							<select id='rows' onChange='applyFilter()' title='Display Rows'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') { ?> selected<?php } ?>>Default Messages</option>
								<?php
								foreach($item_rows AS $rows => $display_text) {
									print "<option value='" . $rows . "'"; if (get_request_var('rows') == $rows) { print ' selected'; } print '>' . $display_text . " Messages</option>\n";
								}
								?>
							</select>
						</td>
						<td>
							<select id='trimval' onChange='applyFilter()' title='Message Trim'>
								<?php
								foreach($trimvals AS $seconds => $display_text) {
									print "<option value='" . $seconds . "'"; if (get_request_var('trimval') == $seconds) { print ' selected'; } print '>' . $display_text . "</option>\n";
								}
								?>
							</select>
						</td>
						<td>
							<select id='refresh' onChange='applyFilter()'>
								<?php
								foreach($page_refresh_interval AS $seconds => $display_text) {
									print "<option value='" . $seconds . "'"; if (get_request_var('refresh') == $seconds) { print ' selected'; } print '>' . $display_text . "</option>\n";
								}
								?>
							</select>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<?php api_plugin_hook('syslog_extend_filter');?>
						<td>
							<select id='eprogram' onChange='applyFilter()' title='Programs to filter on'>
								<option value='-1'<?php if (get_request_var('eprogram') == '-1') { ?> selected<?php } ?>>All Programs</option>
								<?php
								$eprograms = syslog_db_fetch_assoc('SELECT program_id, program
									FROM `' . $syslogdb_default . '`.`syslog_programs` AS fh
									ORDER BY program');

								if (sizeof($eprograms)) {
								foreach ($eprograms as $eprogram) {
									if (trim($eprogram['program']) == '') $eprogram['program'] = 'unspecified';
									print "<option value='" . $eprogram['program_id'] . "'"; if (get_request_var('eprogram') == $eprogram['program_id']) { print ' selected'; } print '>' . $eprogram['program'] . "</option>\n";
								}
								}
								?>
							</select>
						</td>
						<td>
							<select id='efacility' onChange='applyFilter()' title='Facilities to filter on'>
								<option value='-1'<?php if (get_request_var('efacility') == '0') { ?> selected<?php } ?>>All Facilities</option>
								<?php
								if (!isset($hostfilter)) $hostfilter = '';
								$efacilities = syslog_db_fetch_assoc('SELECT DISTINCT f.facility_id, f.facility
									FROM `' . $syslogdb_default . '`.`syslog_host_facilities` AS fh
									INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS f
									ON f.facility_id=fh.facility_id ' . (strlen($hostfilter) ? 'WHERE ':'') . $hostfilter . '
									ORDER BY facility');

								if (sizeof($efacilities)) {
								foreach ($efacilities as $efacility) {
									print "<option value='" . $efacility['facility_id'] . "'"; if (get_request_var('efacility') == $efacility['facility_id']) { print ' selected'; } print '>' . ucfirst($efacility['facility']) . "</option>\n";
								}
								}
								?>
							</select>
						</td>
						<td>
							<select id='epriority' onChange='applyFilter()' title='Priority Levels'>
								<option value='-1'<?php if (get_request_var('epriority') == '-1') { ?> selected<?php } ?>>All Priorities</option>
								<option value='0'<?php if (get_request_var('epriority') == '0') { ?> selected<?php } ?>>Emergency</option>
								<option value='1'<?php if (get_request_var('epriority') == '1') { ?> selected<?php } ?>>Critical++</option>
								<option value='1o'<?php if (get_request_var('epriority') == '1o') { ?> selected<?php } ?>>Critical</option>
								<option value='2'<?php if (get_request_var('epriority') == '2') { ?> selected<?php } ?>>Alert++</option>
								<option value='2o'<?php if (get_request_var('epriority') == '2o') { ?> selected<?php } ?>>Alert</option>
								<option value='3'<?php if (get_request_var('epriority') == '3') { ?> selected<?php } ?>>Error++</option>
								<option value='3o'<?php if (get_request_var('epriority') == '3o') { ?> selected<?php } ?>>Error</option>
								<option value='4'<?php if (get_request_var('epriority') == '4') { ?> selected<?php } ?>>Warning++</option>
								<option value='4o'<?php if (get_request_var('epriority') == '4o') { ?> selected<?php } ?>>Warning</option>
								<option value='5'<?php if (get_request_var('epriority') == '5') { ?> selected<?php } ?>>Notice++</option>
								<option value='5o'<?php if (get_request_var('epriority') == '5o') { ?> selected<?php } ?>>Notice</option>
								<option value='6'<?php if (get_request_var('epriority') == '6') { ?> selected<?php } ?>>Info++</option>
								<option value='6o'<?php if (get_request_var('epriority') == '6o') { ?> selected<?php } ?>>Info</option>
								<option value='7'<?php if (get_request_var('epriority') == '7') { ?> selected<?php } ?>>Debug</option>
							</select>
						</td>
						<?php if (get_nfilter_request_var('tab') == 'syslog') { ?>
						<td>
							<select id='removal' onChange='applyFilter()' title='Removal Handling'>
								<option value='1'<?php if (get_request_var('removal') == '1') { ?> selected<?php } ?>>All Records</option>
								<option value='-1'<?php if (get_request_var('removal') == '-1') { ?> selected<?php } ?>>Main Records</option>
								<option value='2'<?php if (get_request_var('removal') == '2') { ?> selected<?php } ?>>Removed Records</option>
							</select>
						</td>
						<?php }else{ ?>
						<input type='hidden' id='removal' value='<?php print get_request_var('removal');?>'>
						<?php } ?>
					</tr>
				</table>
			</form>
			</td>
		</tr>
	<?php html_end_box(false);
}

/** function syslog_syslog_legend()
 *  This function displays the foreground and background colors for the syslog syslog legend
*/
function syslog_syslog_legend() {
	global $disabled_color, $notmon_color, $database_default;

	html_start_box("", "100%", '', "3", "center", "");
	print "<tr>";
	print "<td width='10%' class='logEmergency'>Emergency</td>";
	print "<td width='10%' class='logCritical'>Critical</td>";
	print "<td width='10%' class='logAlert'>Alert</td>";
	print "<td width='10%' class='logError'>Error</td>";
	print "<td width='10%' class='logWarning'>Warning</td>";
	print "<td width='10%' class='logNotice'>Notice</td>";
	print "<td width='10%' class='logInfo'>Info</td>";
	print "<td width='10%' class='logDebug'>Debug</td>";
	print "</tr>";
	html_end_box(false);
}

/** function syslog_log_legend()
 *  This function displays the foreground and background colors for the syslog log legend
*/
function syslog_log_legend() {
	global $disabled_color, $notmon_color, $database_default;

	html_start_box("", "100%", '', "3", "center", "");
	print "<tr>";
	print "<td width='10%' class='logCritical'>Critical</td>";
	print "<td width='10%' class='logWarning'>Warning</td>";
	print "<td width='10%' class='logNotice'>Notice</td>";
	print "<td width='10%' class='logInfo'>Informational</td>";
	print "</tr>";
	html_end_box(false);
}

/** function syslog_messages()
 *  This is the main page display function in Syslog.  Displays all the
 *  syslog messages that are relevant to Syslog.
*/
function syslog_messages($tab="syslog") {
	global $sql_where, $hostfilter, $severities;
	global $config, $syslog_incoming_config, $reset_multi, $syslog_levels;

	include(dirname(__FILE__) . '/config.php');
	include('./include/global_arrays.php');

	/* force the initial timespan to be 30 minutes for performance reasons */
	if (!isset($_SESSION['sess_syslog_init'])) {
		$_SESSION['sess_current_timespan'] = 1;
		$_SESSION['sess_syslog_init'] = 1;
	}

	$url_curr_page = get_browser_query_string();

	$sql_where = '';

	if (get_request_var('rows') == -1) {
		$row_limit = read_config_option('num_rows_syslog');
	}elseif (get_request_var('rows') == -2) {
		$row_limit = 999999;
	}else{
		$row_limit = get_request_var('rows');
	}

	$syslog_messages = get_syslog_messages($sql_where, $row_limit, $tab);

	syslog_filter($sql_where, $tab);

	if ($tab == 'syslog') {
		if (get_request_var('removal') == 1) {
			$total_rows = syslog_db_fetch_cell("SELECT SUM(totals)
				FROM (
					SELECT count(*) AS totals
					FROM `" . $syslogdb_default . "`.`syslog` AS syslog
					$sql_where
					UNION
					SELECT count(*) AS totals
					FROM `" . $syslogdb_default . "`.`syslog_removed` AS syslog
					$sql_where
				) AS rowcount");
		}elseif (get_request_var("removal") == -1){
			$total_rows = syslog_db_fetch_cell("SELECT count(*) 
				FROM `" . $syslogdb_default . "`.`syslog` AS syslog
				$sql_where");
		}else{
			$total_rows = syslog_db_fetch_cell("SELECT count(*) 
				FROM `" . $syslogdb_default . "`.`syslog_removed` AS syslog
				$sql_where");
		}
	}else{
		$total_rows = syslog_db_fetch_cell("SELECT count(*)
			FROM `" . $syslogdb_default . "`.`syslog_logs` AS syslog
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
			ON syslog.facility_id=sf.facility_id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
			ON syslog.priority_id=sp.priority_id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
			ON syslog.host_id=sh.host_id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_alert` AS sa
			ON syslog.alert_id=sa.id 
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs` AS spr
			ON syslog.program_id=spr.program_id " .
			$sql_where);
	}

	html_start_box('', '100%', '', '3', 'center', '');

	if ($tab == 'syslog') {
		$nav = html_nav_bar("syslog.php?tab=$tab", MAX_DISPLAY_PAGES, get_request_var_request('page'), $row_limit, $total_rows, 7, 'Messages', 'page', 'main');

		if (api_plugin_user_realm_auth('syslog_alerts.php')) {
			$display_text = array(
				'nosortt'     => array('Actions', 'ASC'),
				'host_id'     => array('Host', 'ASC'),
				'logtime'     => array('Date', 'ASC'),
				'program'     => array('Program', 'ASC'),
				'message'     => array('Message', 'ASC'),
				'facility_id' => array('Facility', 'ASC'),
				'priority_id' => array('Priority', 'ASC'));
		}else{
			$display_text = array(
				'host_id'     => array('Host', 'ASC'),
				'logtime'     => array('Date', 'ASC'),
				'program'     => array('Program', 'ASC'),
				'message'     => array('Message', 'ASC'),
				'facility_id' => array('Facility', 'ASC'),
				'priority_id' => array('Priority', 'ASC'));
		}

		print $nav;

		html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

		$hosts      = array_rekey(syslog_db_fetch_assoc('SELECT host_id, host FROM `' . $syslogdb_default . '`.`syslog_hosts`'), 'host_id', 'host');
		$facilities = array_rekey(syslog_db_fetch_assoc('SELECT facility_id, facility FROM `' . $syslogdb_default . '`.`syslog_facilities`'), 'facility_id', 'facility');
		$priorities = array_rekey(syslog_db_fetch_assoc('SELECT priority_id, priority FROM `' . $syslogdb_default . '`.`syslog_priorities`'), 'priority_id', 'priority');

		if (sizeof($syslog_messages)) {
			foreach ($syslog_messages as $syslog_message) {
				$title   = htmlspecialchars($syslog_message['message'], ENT_QUOTES);

				syslog_row_color($syslog_message['priority_id'], $title);

				if (api_plugin_user_realm_auth('syslog_alerts.php')) {
					print "<td class='nowrap left' style='width:1%:padding:1px !important;'>";
					if ($syslog_message['mtype'] == 'main') {
						print "<a style='padding:1px' href='" . htmlspecialchars('syslog_alerts.php?id=' . $syslog_message[$syslog_incoming_config['id']] . '&action=newedit&type=0') . "'><img src='images/add.png' border='0'></a>
						<a style='padding:1px' href='" . htmlspecialchars('syslog_removal.php?id=' . $syslog_message[$syslog_incoming_config['id']] . '&action=newedit&type=new&type=0') . "'><img src='images/delete.png' border='0'></a>\n";
					}
					print "</td>\n";
				}
				print '<td class="left nowrap">' . $hosts[$syslog_message['host_id']] . "</td>\n";
				print '<td class="left nowrap">' . $syslog_message['logtime'] . "</td>\n";
				print '<td class="left nowrap">' . $syslog_message['program'] . "</td>\n";
				print '<td class="left syslogMessage" title="' . $title . '">' . (strlen(get_request_var('filter')) ? eregi_replace('(' . preg_quote(get_request_var('filter')) . ')', "<span class='filteredValue'>\\1</span>", title_trim($syslog_message[$syslog_incoming_config['textField']], get_request_var_request('trimval'))):title_trim($syslog_message[$syslog_incoming_config['textField']], get_request_var_request('trimval'))) . "</td>\n";
				print '<td class="left nowrap">' . ucfirst($facilities[$syslog_message['facility_id']]) . "</td>\n";
				print '<td class="left nowrap">' . ucfirst($priorities[$syslog_message['priority_id']]) . "</td>\n";
			}
		}else{
			print "<tr><td class='center' colspan='7'><em>No Syslog Messages</em></td></tr>";
		}

		print $nav;
		html_end_box(false);

		syslog_syslog_legend();

		print "<script type='text/javascript'>$(function() { $('.syslogMessage, button').tooltip({ closed: true }).on('focus', function() { $('#filter').tooltip('close') }).on('click', function() { $(this).tooltip('close'); }); })</script>\n";
	}else{
		$nav = html_nav_bar("syslog.php?tab=$tab", MAX_DISPLAY_PAGES, get_request_var_request('page'), $row_limit, $total_rows, 8, 'Alert Log Rows', 'page', 'main');

		print $nav;

		$display_text = array(
			'name'        => array('Alert Name', 'ASC'),
			'severity'    => array('Severity', 'ASC'),
			'count'       => array('Count', 'ASC'),
			'logtime'     => array('Date', 'ASC'),
			'logmsg'      => array('Message', 'ASC'),
			'host'        => array('Host', 'ASC'),
			'facility_id' => array('Facility', 'ASC'),
			'priority_id' => array('Priority', 'ASC'));

		html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

		if (sizeof($syslog_messages)) {
			foreach ($syslog_messages as $log) {
				$title   = htmlspecialchars($log['logmsg'], ENT_QUOTES);

				syslog_row_color($log['severity'], $title);

				print "<td><a class='linkEditMain' href='" . htmlspecialchars($config['url_path'] . 'plugins/syslog/syslog.php?id=' . $log['seq'] . '&tab=current') . "'>" . (strlen($log['name']) ? $log['name']:'Alert Removed') . "</a></td>\n";
				print '<td class="left nowrap">' . (isset($severities[$log['severity']]) ? $severities[$log['severity']]:'Unknown') . "</td>\n";
				print '<td class="left nowrap">' . $log['count'] . "</td>\n";
				print '<td class="left nowrap">' . $log['logtime'] . "</td>\n";
				print '<td class="syslogMessage" title="' . $title . '">' . (strlen(get_request_var('filter')) ? eregi_replace('(' . preg_quote(get_request_var('filter')) . ')', "<span class='filteredValue'>\\1</span>", title_trim($log['logmsg'], get_request_var_request('trimval'))):title_trim($log['logmsg'], get_request_var_request('trimval'))) . "</td>\n";
				print '<td class="left nowrap">' . $log['host'] . "</td>\n";
				print '<td class="left nowrap">' . ucfirst($log['facility']) . "</td>\n";
				print '<td class="left nowrap">' . ucfirst($log['priority']) . "</td>\n";
				print "</tr>\n";
			}
		}else{
			print "<tr><td colspan='11'><em>No Alert Log Messages</em></td></tr>";
		}

		print $nav;
		html_end_box(false);

		syslog_log_legend();

		print "<script type='text/javascript'>$(function() { $('.syslogMessage').tooltip(); })</script>\n";
	}
}

?>
