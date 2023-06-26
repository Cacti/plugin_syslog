<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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
include_once('./lib/html_tree.php');
include_once('./plugins/syslog/functions.php');
include_once('./plugins/syslog/database.php');

syslog_determine_config();

include(SYSLOG_CONFIG);

syslog_connect();

set_default_action();

if (get_request_var('action') == 'ajax_programs') {
	return get_ajax_programs(true);
} elseif (get_request_var('action') == 'ajax_programs_wnone') {
	return get_ajax_programs(true, true);
} elseif (get_request_var('action') == 'ajax_hosts') {
	print get_ajax_hosts();
	exit;
} elseif (get_request_var('action') == 'save') {
	save_settings();
	exit;
}

$title = __('Syslog Viewer', 'syslog');

$trimvals = array(
	'1024' => __('All Text', 'syslog'),
	'30'   => __('%d Chars', 30, 'syslog'),
	'50'   => __('%d Chars', 50, 'syslog'),
	'75'   => __('%d Chars', 75, 'syslog'),
	'100'  => __('%d Chars', 100, 'syslog'),
	'150'  => __('%d Chars', 150, 'syslog'),
	'300'  => __('%d Chars', 300, 'syslog')
);

/* set the default tab */
get_filter_request_var('tab', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z]+)$/')));

load_current_session_value('tab', 'sess_syslog_tab', 'syslog');
$current_tab = get_request_var('tab');

/* validate the syslog post/get/request information */;
if ($current_tab != 'stats') {
	syslog_request_validation($current_tab);
}

if (isset_request_var('refresh')) {
	$refresh['seconds'] = get_request_var('refresh');
	$refresh['page']    = $config['url_path'] . 'plugins/syslog/syslog.php?header=false&tab=' . $current_tab;
	$refresh['logout']  = 'false';

	set_page_refresh($refresh);
}

/* draw the tabs */
/* display the main page */
if (isset_request_var('export')) {
	syslog_export($current_tab);

	/* clear output so reloads wont re-download */
	unset_request_var('output');
} else {
	general_header();

	syslog_display_tabs($current_tab);

	if ($current_tab == 'current') {
		syslog_view_alarm();
	} elseif ($current_tab == 'stats') {
		syslog_statistics();
	} else {
		syslog_messages($current_tab);
	}

	bottom_footer();
}

$_SESSION['sess_nav_level_cache'] = array();

function get_ajax_hosts() {
	include(SYSLOG_CONFIG);

	$ac_rows = read_config_option('autocomplete_rows');
	if ($ac_rows <= 0) {
		$ac_rows = 100;
	}

	$term = '%' . get_nfilter_request_var('term') . '%';

	if (syslog_db_table_exists('host', false)) {
		$hosts = syslog_db_fetch_assoc_prepared("SELECT DISTINCT sh.host_id, sh.host, h.id
			FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
			LEFT JOIN host AS h
			ON sh.host = h.hostname
			OR sh.host = h.description
			OR sh.host LIKE substring_index(h.hostname, '.', 1)
			OR sh.host LIKE substring_index(h.description, '.', 1)
			WHERE sh.host LIKE ?
			OR h.description LIKE ?
			ORDER BY host
			LIMIT $ac_rows",
			array($term, $term));
	} else {
		$hosts = syslog_db_fetch_assoc_prepared("SELECT DISTINCT sh.host_id, sh.host, '0' AS id
			FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
			WHERE sh.host LIKE ?
			ORDER BY host
			LIMIT $ac_rows",
			array($term));
	}

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $host) {
			if (!empty($host['id'])) {
				$class = get_device_leaf_class($host['id']);
			} else {
				$class = 'deviceUp';
			}

			$rhosts[$host['host_id']] = array(
				'host'    => $host['host'],
				'host_id' => $host['id'],
				'class'   => $class
			);
		}

		return json_encode($rhosts);
	} else {
		return json_encode(array());
	}
}

function syslog_display_tabs($current_tab) {
	global $config;

	/* present a tabbed interface */
	$tabs_syslog['syslog'] = __('System Logs', 'syslog');
	if (read_config_option('syslog_statistics') == 'on') {
		$tabs_syslog['stats']  = __('Statistics', 'syslog');
	}
	$tabs_syslog['alerts'] = __('Alert Logs', 'syslog');

	/* if they were redirected to the page, let's set that up */
	if (!isempty_request_var('id') || $current_tab == 'current') {
		$current_tab = 'current';
	}

	load_current_session_value('id', 'sess_syslog_id', '0');
	if (!isempty_request_var('id') || $current_tab == 'current') {
		$tabs_syslog['current'] = __('Selected Alert', 'syslog');
	}

	/* draw the tabs */
	print "<div class='tabs'><nav><ul>\n";

	if (cacti_sizeof($tabs_syslog)) {
		foreach (array_keys($tabs_syslog) as $tab_short_name) {
			print '<li><a class="tab ' . (($tab_short_name == $current_tab) ? 'selected"':'"') . " href='" . html_escape($config['url_path'] .
				'plugins/syslog/syslog.php?' .
				'tab=' . $tab_short_name) .
				"'>" . $tabs_syslog[$tab_short_name] . "</a></li>\n";
		}
	}
	print "</ul></nav></div>\n";
}

function syslog_view_alarm() {
	global $config;

	include(SYSLOG_CONFIG);

	print "<table class='cactiTable'>";
	print "<tr class='tableHeader'><td class='textHeaderDark'>" . __('Syslog Alert View', 'syslog') . "</td></tr>";
	print "<tr><td class='odd'>\n";

	$html = syslog_db_fetch_cell('SELECT html FROM `' . $syslogdb_default . '`.`syslog_logs` WHERE seq=' . get_request_var('id'));
	print trim($html, "' ");

	print '</td></tr></table>';

	exit;
}

/** function syslog_statistics()
 *  This function paints a table of summary statistics for syslog
 *  messages by host, facility, priority, and time range.
*/
function syslog_statistics() {
	global $title, $rows, $config;

	include(SYSLOG_CONFIG);

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
            'default' => '300',
            ),
        'page' => array(
            'filter' => FILTER_VALIDATE_INT,
            'default' => '1'
            ),
        'rfilter' => array(
            'filter' => FILTER_VALIDATE_IS_REGEX,
            'pageset' => true,
            'default' => ''
            ),
        'host' => array(
            'filter' => FILTER_VALIDATE_IS_NUMERIC_LIST,
            'pageset' => true,
            'default' => '',
            ),
        'facility' => array(
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => '',
            ),
        'priority' => array(
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => '',
            ),
        'program' => array(
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => '',
            'options' => array('options' => 'sanitize_search_string')
            ),
        'sort_column' => array(
            'filter' => FILTER_CALLBACK,
            'default' => 'host',
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

	html_start_box(__('Syslog Statistics Filter', 'syslog'), '100%', '', '3', 'center', '');

	syslog_stats_filter();

	html_end_box();

	$sql_where   = '';
	$sql_groupby = '';

	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = get_request_var('rows');
	}

	$records = get_stats_records($sql_where, $sql_groupby, $rows);

	$rows_query_string = "SELECT COUNT(*)
		FROM `" . $syslogdb_default . "`.`syslog_statistics` AS ss
		$sql_where
		$sql_groupby";

	$total_rows = syslog_db_fetch_cell('SELECT COUNT(*) FROM ('. $rows_query_string  . ') as temp');

	$nav = html_nav_bar('syslog.php?tab=stats', MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, 4, __('Messages', 'syslog'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'host' => array(
			'display' => __('Device Name', 'syslog'),
			'sort' => 'ASC',
			'align' => 'left'
		),
		'facility' => array(
			'display' => __('Facility', 'syslog'),
			'sort' => 'ASC',
			'align' => 'left'
		),
		'priority' => array(
			'display' => __('Priority', 'syslog'),
			'sort' => 'ASC',
			'align' => 'left'
		),
		'program' => array(
			'display' => __('Program', 'syslog'),
			'sort' => 'ASC',
			'align' => 'left'
		),
		'insert_time' => array(
			'display' => __('Date', 'syslog'),
			'sort' => 'DESC',
			'align' => 'right'
		),
		'records' => array(
			'display' => __('Records', 'syslog'),
			'sort' => 'DESC',
			'align' => 'right'
		)
	);

	html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

	if (get_request_var('timespan') < 3600) {
		$date_format = 'Y-m-d H:i';
	} elseif (get_request_var('timespan') < 86400) {
		$date_format = 'Y-m-d H:00';
	} else {
		$date_format = 'Y-m-d 00:00';
	}

	if (cacti_sizeof($records)) {
		$i = 0;

		foreach ($records as $r) {
			$time = date($date_format, strtotime($r['insert_time']));

			form_alternate_row('line' . $i);

			print '<td>' . (get_request_var('host') != '-2' ? $r['host']:'-') . '</td>';
			print '<td>' . (get_request_var('facility') != '-2' ? ucfirst($r['facility']):'-') . '</td>';
			print '<td>' . (get_request_var('priority') != '-2' ? ucfirst($r['priority']):'-') . '</td>';
			print '<td>' . (get_request_var('program') != '-2' ? ucfirst($r['program']):'-') . '</td>';
			//print '<td class="right">' . $r['insert_time'] . '</td>';
			print '<td class="right">' . $time . '</td>';
			print '<td class="right">' . number_format_i18n($r['records'], -1)     . '</td>';

			form_end_row();

			$i++;
		}
	} else {
		print "<tr><td colspan='4'><em>" . __('No Syslog Statistics Found', 'syslog') . "</em></td></tr>";
	}

	html_end_box(false);

	if (cacti_sizeof($records)) {
		print $nav;
	}
}

function get_stats_records(&$sql_where, &$sql_groupby, $rows) {
	include(SYSLOG_CONFIG);

	/* form the 'where' clause for our main sql query */
	if (!isempty_request_var('rfilter')) {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') .
			'sh.host RLIKE "'       . get_request_var('rfilter') . '"
			OR spr.program RLIKE "' . get_request_var('rfilter') . '"';
	}

	if (get_request_var('host') == '-2') {
		// Do nothing
	} elseif (get_request_var('host') != '-1' && get_request_var('host') != '') {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'ss.host_id=' . get_request_var('host');
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'host_id';
	} else {
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'host_id';
	}

	if (get_request_var('facility') == '-2') {
		// Do nothing
	} elseif (get_request_var('facility') != '-1' && get_request_var('facility') != '') {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'ss.facility_id=' . get_request_var('facility');
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'facility_id';
	} else {
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'facility_id';
	}

	if (get_request_var('priority') == '-2') {
		// Do nothing
	} elseif (get_request_var('priority') != '-1' && get_request_var('priority') != '') {
		$sql_where .= ($sql_where == '' ? 'WHERE ': ' AND ') . 'ss.priority_id=' . get_request_var('priority');
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'priority_id';
	} else {
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'priority_id';
	}

	if (get_request_var('program') == '-2') {
		// Do nothing
	} elseif (get_request_var('program') != '-1' && get_request_var('program') != '') {
		$sql_where .= ($sql_where == '' ? 'WHERE ': ' AND ') . 'ss.program_id=' . get_request_var('program');
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'program_id';
	} else {
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . 'program_id';
	}

	if (get_request_var('timespan') != '-1') {
		$sql_groupby .= ($sql_groupby != '' ? ', ':'') . ' UNIX_TIMESTAMP(insert_time) DIV ' . get_request_var('timespan');
	}

	$sql_order = get_order_string();
	if (!isset_request_var('export')) {
		$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;
	} else {
		$sql_limit = ' LIMIT 10000';
	}

	if ($sql_groupby != '') {
		$sql_groupby = 'GROUP BY ' . $sql_groupby;
	}

	$time = 'FROM_UNIXTIME(TRUNCATE(UNIX_TIMESTAMP(insert_time)/' . get_request_var('timespan') . ',0)*' . get_request_var('timespan') . ') AS insert_time';

	$query_sql = "SELECT sh.host, sf.facility, sp.priority, spr.program, records, insert_time
		FROM (
			SELECT host_id, facility_id, priority_id, program_id, sum(records) AS records, $time
			FROM `" . $syslogdb_default . "`.`syslog_statistics` AS ss
			$sql_where
			$sql_groupby
		) AS ss
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
		ON ss.facility_id=sf.facility_id
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
		ON ss.priority_id=sp.priority_id
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs` AS spr
		ON ss.program_id=spr.program_id
		LEFT JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
		ON ss.host_id=sh.host_id
		$sql_order
		$sql_limit";

	//cacti_log(str_replace("\n", "", $query_sql));

	return syslog_db_fetch_assoc($query_sql);
}

function syslog_stats_filter() {
	global $config, $item_rows;

	include(SYSLOG_CONFIG);

	?>
	<tr class='even'>
		<td>
		<form id='stats_form' action='syslog.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Device', 'syslog');?>
					</td>
					<td>
						<select id='host' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('host') == '-1') { ?> selected<?php } ?>><?php print __('All', 'syslog');?></option>
							<option value='-2'<?php if (get_request_var('host') == '-2') { ?> selected<?php } ?>><?php print __('None', 'syslog');?></option>
							<?php
							$ac_rows = read_config_option('autocomplete_rows');
							if ($ac_rows <= 0) {
								$ac_rows = 100;
							}

							if (syslog_db_table_exists('host', false)) {
								$hosts = syslog_db_fetch_assoc("SELECT DISTINCT sh.host_id, sh.host, h.id
									FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
									LEFT JOIN host AS h
									ON sh.host = h.hostname
									OR sh.host = h.description
									OR sh.host LIKE substring_index(h.hostname, '.', 1)
									OR sh.host LIKE substring_index(h.description, '.', 1)
									ORDER BY host
									LIMIT $ac_rows");
							} else {
								$hosts = syslog_db_fetch_assoc("SELECT DISTINCT sh.host_id, sh.host, '0' AS id
									FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
									ORDER BY host
									LIMIT $ac_rows");
							}

							if (cacti_sizeof($hosts)) {
								foreach ($hosts as $host) {
									if (!empty($host['id'])) {
										$class = get_device_leaf_class($host['id']);
									} else {
										$class = 'deviceUp';
									}

									print '<option class="' . $class . '" value="' . $host['host_id'] . '"'; if (get_request_var('host') == $host['host_id']) { print ' selected'; } print '>' . $host['host'] . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Facility', 'syslog');?>
					</td>
					<td>
						<select id='facility' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('facility') == '-1') { ?> selected<?php } ?>><?php print __('All', 'syslog');?></option>
							<option value='-2'<?php if (get_request_var('facility') == '-2') { ?> selected<?php } ?>><?php print __('None', 'syslog');?></option>
							<?php
							$facilities = syslog_db_fetch_assoc('SELECT DISTINCT facility_id, facility
								FROM `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ORDER BY facility');

							if (cacti_sizeof($facilities)) {
								foreach ($facilities as $r) {
									print '<option value="' . $r['facility_id'] . '"'; if (get_request_var('facility') == $r['facility_id']) { print ' selected'; } print '>' . ucfirst($r['facility']) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Priority', 'syslog');?>
					</td>
					<td>
						<select id='priority' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('priority') == '-1') { ?> selected<?php } ?>><?php print __('All', 'syslog');?></option>
							<option value='-2'<?php if (get_request_var('priority') == '-2') { ?> selected<?php } ?>><?php print __('None', 'syslog');?></option>
							<?php
							$priorities = syslog_db_fetch_assoc('SELECT DISTINCT priority_id, priority
								FROM `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ORDER BY priority');

							if (cacti_sizeof($priorities)) {
								foreach ($priorities as $r) {
									print '<option value="' . $r['priority_id'] . '"'; if (get_request_var('priority') == $r['priority_id']) { print ' selected'; } print '>' . ucfirst($r['priority']) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<?php print html_program_filter(get_request_var('program'), true, 'ajax_programs_wnone');?>
					<td>
						<span>
							<input id='go' type='button' value='<?php print __esc('Go', 'syslog');?>'>
							<input id='clear' type='button' value='<?php print __esc('Clear', 'syslog');?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'syslog');?>
					</td>
					<td>
						<input type='text' id='rfilter' size='30' value='<?php print html_escape_request_var('rfilter');?>' onChange='applyFilter()'>
					</td>
					<td>
						<?php print __('Time Range', 'syslog');?>
					</td>
					<td>
						<select id='timespan' onChange='applyFilter()'>
							<?php
							$timespans = array(
								60    => __('%d Minute', 1, 'syslog'),
								120   => __('%d Minutes', 2, 'syslog'),
								300   => __('%d Minutes', 5, 'syslog'),
								600   => __('%d Minutes', 10, 'syslog'),
								1800  => __('%d Minutes', 30, 'syslog'),
								3600  => __('%d Hour', 1, 'syslog'),
								7200  => __('%d Hours', 2, 'syslog'),
								14400 => __('%d Hours', 4, 'syslog'),
								28880 => __('%d Hours', 8, 'syslog'),
								86400 => __('%d Day', 1, 'syslog')
							);

							foreach($timespans as $time => $span) {
								print '<option value="'. $time . '"' . (get_request_var('timespan') == $time ? ' selected':'') . '>' . $span . '</option>';
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Entries', 'syslog');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
						<option value='-1'<?php if (get_request_var('rows') == '-1') { ?> selected<?php } ?>><?php print __('Default', 'syslog');?></option>
						<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print '<option value="' . $key . '"'; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . "</option>\n";
								}
							}
						?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_filter_request_var('page');?>'>
		</form>
		</td>
		<script type='text/javascript'>

		function clearFilter() {
			strURL = 'syslog.php?tab=stats&clear=1&header=false';
			loadPageNoHeader(strURL);
		}

		$(function() {
			$('#go').click(function() {
				applyFilter();
			});

			$('#clear').click(function() {
				clearFilter();
			});

			$('#host').selectmenu({
				open: function() {
					$('div.ui-selectmenu-menu li.ui-menu-item').each(function(idx){
						$(this).addClass( $('#host option').eq(idx).attr('class') )
					})
				}
			});
		});

		function applyFilter() {
			strURL  = 'syslog.php?header=false';
			strURL += '&none=true';
			strURL += '&facility=' + $('#facility').val();
			strURL += '&host=' + $('#host').val();
			strURL += '&priority=' + $('#priority').val();
			strURL += '&program=' + $('#eprogram').val();
			strURL += '&timespan=' + $('#timespan').val();
			strURL += '&rfilter=' + base64_encode($('#rfilter').val());
			strURL += '&rows=' + $('#rows').val();
			loadPageNoHeader(strURL);
		}

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

	include_once($config['base_path'] . '/lib/time.php');

	if ($current_tab != 'alerts' && isset_request_var('host') && get_nfilter_request_var('host') == -1) {
		kill_session_var('sess_syslog_' . $current_tab . '_hosts');
		unset_request_var('host');
	}

	$shift_span = false;
	if (isset_request_var('predefined_timespan')) {
		$shift_span = 'span';
	} elseif (isset_request_var('predefined_timeshift')) {
		$shift_span = 'shift';
	} elseif (isset_request_var('date1') && isset_request_var('date2')) {
		$shift_span = 'custom';
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
        'predefined_timespan' => array(
            'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
            'default' => read_user_setting('default_timespan', GT_LAST_DAY, $force)
            ),
        'predefined_timeshift' => array(
            'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
            'default' => read_user_setting('default_timeshift', GTS_1_DAY, $force)
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
            'filter' => FILTER_VALIDATE_IS_NUMERIC_LIST,
            'pageset' => true,
            'default' => '',
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
            'filter' => FILTER_VALIDATE_INT,
            'pageset' => true,
            'default' => read_user_setting('syslog_eprogram', '-1', $force),
            ),
        'rfilter' => array(
            'filter' => FILTER_VALIDATE_IS_REGEX,
            'pageset' => true,
            'default' => ''
            ),
        'date1' => array(
            'filter' => FILTER_CALLBACK,
            'pageset' => true,
            'default' => '',
            'options' => array('options' => 'sanitize_search_string')
			),
        'date2' => array(
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

    validate_store_request_vars($filters, 'sess_sl_' . $current_tab);
    /* ================= input validation ================= */

	// Modify session and request variables based upon span/shift/settings
	set_shift_span($shift_span, 'sess_sl_' . $current_tab);

	api_plugin_hook_function('syslog_request_val');

	if (isset_request_var('host')) {
		$_SESSION['sess_syslog_' . $current_tab . '_hosts'] = get_nfilter_request_var('host');
	} elseif (isset($_SESSION['sess_syslog_' . $current_tab . '_hosts'])) {
		set_request_var('host', $_SESSION['sess_syslog_' . $current_tab . '_hosts']);
	} else {
		set_request_var('host', '-1');
	}
}

function set_shift_span($shift_span, $session_prefix) {
	global $graph_timeshifts;

	if ($shift_span == 'span' || $shift_span === false) {
		$span = array();

		// Calculate the timespan
		$first_weekdayid = read_user_setting('first_weekdayid');
		get_timespan($span, time(), get_request_var('predefined_timespan'), $first_weekdayid);

		// Save the settings for next page refresh
		set_request_var('date1', date('Y-m-d H:i:s', $span['begin_now']));
		set_request_var('date2', date('Y-m-d H:i:s', $span['end_now']));

		// We don't want any date saved in the session
		kill_session_var($session_prefix . '_date1');
		kill_session_var($session_prefix . '_date2');

		set_request_var('custom', false);
	} elseif ($shift_span == 'shift') {
		$span = array();

		$span['current_value_date1'] = get_request_var('date1');
		$span['current_value_date2'] = get_request_var('date2');
		$span['begin_now'] = strtotime(get_request_var('date1'));
		$span['end_now']   = strtotime(get_request_var('date2'));

		if (isset_request_var('shift_right')) {
			$direction = '+';
		} elseif (isset_request_var('shift_left')) {
			$direction = '-';
		} else {
			$direction = '+';
		}

		$timeshift = $graph_timeshifts[get_request_var('predefined_timeshift')];

		// Calculate the new date1 and date2
		shift_time($span, $direction, $timeshift);

		// Save the settings for next page refresh
		set_request_var('date1', date('Y-m-d H:i:s', $span['begin_now']));
		set_request_var('date2', date('Y-m-d H:i:s', $span['end_now']));

		// Save the dates in the session variable for page refresh
		$_SESSION[$session_prefix . '_date1'] = get_request_var('date1');
		$_SESSION[$session_prefix . '_date2'] = get_request_var('date2');

		set_request_var('custom', true);
	} elseif ($shift_span == 'custom') {
		set_request_var('custom', true);
	}
}

function get_syslog_messages(&$sql_where, $rows, $tab) {
	global $sql_where, $hostfilter, $hostfilter_log, $current_tab, $syslog_incoming_config;

	include(SYSLOG_CONFIG);

	$sql_where = '';

	if ($tab == 'alerts') {
		if (get_request_var('host') == 0) {
			// Show all hosts
		} else {
			$hosts = explode(',', get_request_var('host'));

			$thold_pos = array_search('-1', $hosts, true);

			if ($thold_pos !== false) {
				unset($hosts[$thold_pos]);
			}

			if (sizeof($hosts)) {
				sql_hosts_where($tab);

				if (strlen($hostfilter_log)) {
					$sql_where .= 'WHERE ' . $hostfilter_log;
				}
			}

			if ($thold_pos !== false) {
				$ids = array_rekey(
					syslog_db_fetch_assoc('SELECT id
						FROM `' . $syslogdb_default . '`.`syslog_alert`
						WHERE method = 1'),
					'id', 'id'
				);

				if (cacti_sizeof($ids)) {
					$sql_where .= ($sql_where == '' ? 'WHERE ':' OR ') . 'alert_id IN (' . implode(', ', $ids) . ')';
				} elseif ($sql_where == '') {
					$sql_where .= 'WHERE 0 = 1';
				}
			}
		}
	} elseif ($tab == 'syslog') {
		if (!isempty_request_var('host')) {
			sql_hosts_where($tab);

			if (strlen($hostfilter)) {
				$sql_where .= 'WHERE ' . $hostfilter;
			}
		}
	}

	$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') .
		"logtime BETWEEN '" . get_request_var('date1') . "'
			AND '" . get_request_var('date2') . "'";

	if (isset_request_var('id') && $current_tab == 'current') {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') .
			'sa.id=' . get_request_var('id');
	}

	if (!isempty_request_var('rfilter')) {
		if ($tab == 'syslog') {
			$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'message RLIKE "' . get_request_var('rfilter') . '"';
		} else {
			$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'logmsg RLIKE "' . get_request_var('rfilter') . '"';
		}
	}

	if (get_request_var('eprogram') != '-1') {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'syslog.program_id = ' . db_qstr(get_request_var('eprogram'));
	}

	if (get_request_var('efacility') != '-1') {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'syslog.facility_id = ' . db_qstr(get_request_var('efacility'));
	}

	if (isset_request_var('epriority') && get_request_var('epriority') != '-1') {
		$priorities = '';

		switch(get_request_var('epriority')) {
		case '0':
			$priorities = ' = 0';
			break;
		case '1o':
			$priorities = ' = 1';
			break;
		case '1':
			$priorities = ' <= 1';
			break;
		case '2o':
			$priorities = ' = 2';
			break;
		case '2':
			$priorities = ' <= 2';
			break;
		case '3o':
			$priorities = ' = 3';
			break;
		case '3':
			$priorities = ' <= 3';
			break;
		case '4o':
			$priorities = ' = 4';
			break;
		case '4':
			$priorities = ' <= 4';
			break;
		case '5o':
			$priorities = ' = 5';
			break;
		case '5':
			$priorities = ' <= 5';
			break;
		case '6o':
			$priorities = ' = 6';
			break;
		case '6':
			$priorities = ' <= 6';
			break;
		case '7':
			$priorities = ' = 7';
			break;
		}

		$sql_where .= ($sql_where == '' ? 'WHERE ': ' AND ') . 'syslog.priority_id ' . $priorities;
	}

	$sql_where = api_plugin_hook_function('syslog_sqlwhere', $sql_where);

	$sql_order = get_order_string();

	if (!isset_request_var('export')) {
		$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;
	} else {
		$sql_limit = ' LIMIT 10000';
	}

	if ($tab == 'syslog') {
		if (get_request_var('removal') == '-1') {
			$query_sql = "SELECT syslog.*, syslog_programs.program, 'main' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog`
				LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs`
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . "
				$sql_order
				$sql_limit";
		} elseif (get_request_var('removal') == '1') {
			$query_sql = "(SELECT syslog.*, syslog_programs.program, 'main' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog` AS syslog
				LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs`
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . "
				) UNION (SELECT syslog.*, syslog_programs.program, 'remove' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog_removed` AS syslog
				LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs`
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . ")
				$sql_order
				$sql_limit";
		} else {
			$query_sql = "SELECT syslog.*, syslog_programs.program, 'remove' AS mtype
				FROM `" . $syslogdb_default . "`.`syslog_removed` AS syslog
				LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs` AS syslog_programs
				ON syslog.program_id=syslog_programs.program_id " .
				$sql_where . "
				$sql_order
				$sql_limit";
		}
	} else {
		$query_sql = "SELECT syslog.*, sf.facility, sp.priority, spr.program, sa.name, sa.severity
			FROM `" . $syslogdb_default . "`.`syslog_logs` AS syslog
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
			ON syslog.facility_id=sf.facility_id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
			ON syslog.priority_id=sp.priority_id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_alert` AS sa
			ON syslog.alert_id=sa.id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs` AS spr
			ON syslog.program_id=spr.program_id " .
			$sql_where . "
			$sql_order
			$sql_limit";
	}

	//print $query_sql;

	return syslog_db_fetch_assoc($query_sql);
}

function syslog_filter($sql_where, $tab) {
	global $config, $graph_timespans, $graph_timeshifts, $reset_multi, $page_refresh_interval, $item_rows, $trimvals;

	include(SYSLOG_CONFIG);

	$unprocessed = syslog_db_fetch_cell("SELECT COUNT(*) FROM `" . $syslogdb_default . "`.`syslog_incoming`");

	if (isset_request_var('date1')) {
		$filter_text = __esc(' [ Start: \'%s\' to End: \'%s\', Unprocessed Messages: %s ]', get_request_var('date1'), get_request_var('date2'), $unprocessed, 'syslog');
	} else {
		$filter_text = __esc('[ Unprocessed Messages: %s ]', $unprocessed, 'syslog');
	}

	?>
	<script type='text/javascript'>

	var date1Open = false;
	var date2Open = false;
	var pageTab   = '<?php print get_request_var('tab');?>';
	var hostTerm  = '';
	var placeHolder = '<?php print __esc('Enter a search term', 'syslog');?>';

	$(function() {
		$('#syslog_form').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});

		$('#host').multiselect({
			menuHeight: $(window).height()*.7,
			menuWidth: '220',
			linkInfo: faIcons,
			noneSelectedText: '<?php print __('Select Device(s)', 'syslog');?>',
			selectedText: function(numChecked, numTotal, checkedItems) {
				myReturn = numChecked + ' <?php print __('Devices Selected', 'syslog');?>';
				$.each(checkedItems, function(index, value) {
					if (value.value == '0') {
						myReturn='<?php print __('All Devices Selected', 'syslog');?>';
						return false;
					}
				});
				return myReturn;
			},
			uncheckAll: function() {
				$(this).multiselect('widget').find(':checkbox:first').each(function() {
					$(this).prop('checked', true);
				});
				$('#test').trigger('keyup');
			},
			checkAll: function() {
				$(this).multiselect('widget').find(':checkbox').not(':first').each(function() {
					$(this).prop('checked', true);
				});
				$(this).multiselect('widget').find(':checkbox:first').each(function() {
					$(this).prop('checked', false);
				});
			},
			open: function(event, ui) {
				if ($('#term').length == 0) {
					var width = parseInt($(this).multiselect('widget').find('.ui-multiselect-header').width() - 5);
					$(this).multiselect('widget').find('.ui-multiselect-header').after('<input id="term" placeholder="'+placeHolder+'" class="ui-state-default ui-corner-all" style="width:'+width+'px" type="text" value="'+hostTerm+'">');
					$('#term').on('keyup', function() {
						$.getJSON('syslog.php?action=ajax_hosts&term='+$('#term').val(), function(data) {
							$('#host').find('option').not(':selected').each(function() {
								if ($(this).attr('id') != 'host_all') {
									$(this).remove();
								}
							});

							$.each(data, function(index, hostData) {
								if ($('#host option[value="'+index+'"]').length == 0) {
									$('#host').append('<option class="'+hostData.class+'" value="'+index+'">'+hostData.host+'</option>');
								}
							});

							$('#host').multiselect('refresh');
						});
					});
				}

				$('#term').focus();
			},
			click: function(event, ui) {
				checked=$(this).multiselect('widget').find('input:checked').length;

				if (ui.value == '0') {
					if (ui.checked == true) {
						$('#host').multiselect('uncheckAll');
						$(this).multiselect('widget').find(':checkbox:first').each(function() {
							$(this).prop('checked', true);
						});
					}
				} else if (checked == 0) {
					$(this).multiselect('widget').find(':checkbox:first').each(function() {
						$(this).click();
					});
				} else if ($(this).multiselect('widget').find('input:checked:first').val() == '0') {
					if (checked > 0) {
						$(this).multiselect('widget').find(':checkbox:first').each(function() {
							$(this).click();
							$(this).prop('disable', true);
						});
					}
				}
			}
		});

		$('#save').click(function() {
			saveSettings();
		});

		$('#go').click(function() {
			applyFilter();
		});

		$('#clear').click(function() {
			clearFilter();
		});

		$('#export').click(function() {
			exportRecords();
		});

		$('#balerts').click(function() {
			loadTopTab(urlPath+'plugins/syslog/syslog_alerts.php?header=false');
			$('.maintabs').find('a').removeClass('selected');
			$('#tab-console').addClass('selected');
		});

		$('#bremoval').click(function() {
			loadTopTab(urlPath+'plugins/syslog/syslog_removal.php?header=false');
			$('.maintabs').find('a').removeClass('selected');
			$('#tab-console').addClass('selected');
		});

		$('#breports').click(function() {
			loadTopTab(urlPath+'plugins/syslog/syslog_reports.php?header=false');
			$('.maintabs').find('a').removeClass('selected');
			$('#tab-console').addClass('selected');
		});

		$('#startDate').click(function() {
			if (date1Open) {
				date1Open = false;
				$('#date1').datetimepicker('hide');
			} else {
				date1Open = true;
				$('#date1').datetimepicker('show');
			}
		});

		$('#endDate').click(function() {
			if (date2Open) {
				date2Open = false;
				$('#date2').datetimepicker('hide');
			} else {
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
	});

	function applyTimespan() {
		var strURL  = urlPath+'plugins/syslog/syslog.php?header=false';

		strURL += '&predefined_timespan=' + $('#predefined_timespan').val();

		loadPageNoHeader(strURL);
	}

	function applyFilter() {
		var strURL  = 'syslog.php?tab='+pageTab;

		strURL += '&header=false';
		strURL += '&date1='+$('#date1').val();
		strURL += '&date2='+$('#date2').val();
		strURL += '&host='+$('#host').val();
		strURL += '&rfilter='+base64_encode($('#rfilter').val());
		strURL += '&efacility='+$('#efacility').val();
		strURL += '&epriority='+$('#epriority').val();
		strURL += '&eprogram='+$('#eprogram').val();
		strURL += '&rows='+$('#rows').val();
		strURL += '&trimval='+$('#trimval').val();
		strURL += '&removal='+$('#removal').val();
		strURL += '&refresh='+$('#refresh').val();
		loadPageNoHeader(strURL);
	}

	function exportRecords() {
		document.location = 'syslog.php?export=true';

		Pace.stop();
	}

	function clearFilter() {
		var strURL  = 'syslog.php?tab=' + pageTab;

		strURL += '&header=false&clear=true';

		loadPageNoHeader(strURL);
	}

	function saveSettings() {
		var strURL  = 'syslog.php?action=save&tab=' + pageTab;
		var data    = {};

		data.trimval      = $('#trimval').val();
		data.rows         = $('#rows').val();
		data.removal      = $('#removal').val();
		data.refresh      = $('#refresh').val();
		data.efacility    = $('#efacility').val();
		data.epriority    = $('#epriority').val();
		data.eprogram     = $('#eprogram').val();
		data.__csrf_magic = csrfMagicToken;

		if ($('#predefined_timespan').val() > 0) {
			data.predefined_timespan = $('#predefined_timespan').val();
		}

		data.predefined_timeshift = $('#predefined_timeshift').val();

		$.post(strURL, data).done(function() {
			$('#text').show().text('Filter Settings Saved').fadeOut(2000);
		});
	}

	function timeshiftFilterLeft() {
		var strURL  = 'syslog.php?tab='+pageTab+'&header=false';

		strURL += '&shift_left=true';
		strURL += '&date1='+$('#date1').val();
		strURL += '&date2='+$('#date2').val();
		strURL += '&predefined_timeshift='+$('#predefined_timeshift').val();

		loadPageNoHeader(strURL);
	}

	function timeshiftFilterRight() {
		var strURL  = 'syslog.php?tab='+pageTab+'&header=false';

		strURL += '&shift_right=true';
		strURL += '&date1='+$('#date1').val();
		strURL += '&date2='+$('#date2').val();
		strURL += '&predefined_timeshift='+$('#predefined_timeshift').val();

		loadPageNoHeader(strURL);
	}

	</script>
	<?php

	html_start_box(__('Syslog Message Filter %s', $filter_text, 'syslog'), '100%', '', '3', 'center', '');?>
		<tr class='even noprint'>
			<td class='noprint'>
			<form id='syslog_form' action='syslog.php'>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Timespan', 'syslog');?>
						</td>
						<td>
							<select id='predefined_timespan' onChange='applyTimespan()'>
								<?php
								if (isset_request_var('custom') && get_request_var('custom') == true) {
									$graph_timespans[GT_CUSTOM] = __('Custom', 'syslog');
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

								if (cacti_sizeof($graph_timespans)) {
									for ($value=$start_val; $value < $end_val; $value++) {
										print "<option value='$value'"; if (get_request_var('predefined_timespan') == $value) { print ' selected'; } print '>' . title_trim($graph_timespans[$value], 40) . "</option>\n";
									}
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('From', 'syslog');?>
						</td>
						<td>
							<input type='text' id='date1' size='18' value='<?php print get_request_var('date1');?>'>
						</td>
						<td>
							<i title='<?php print __esc('Start Date Selector', 'syslog');?>' class='calendar fa fa-calendar-alt' id='startDate'></i>
						</td>
						<td>
							<?php print __('To', 'syslog');?>
						</td>
						<td>
							<input type='text' id='date2' size='18' value='<?php print get_request_var('date2');?>'>
						</td>
						<td>
							<i title='<?php print __esc('End Date Selector', 'syslog');?>' class='calendar fa fa-calendar-alt' id='endDate'></i>
						</td>
						<td>
							<i title='<?php print __esc('Shift Time Backward', 'syslog');?>' onclick='timeshiftFilterLeft()' class='shiftArrow fa fa-backward'></i>
						</td>
						<td>
							<select id='predefined_timeshift' title='<?php print __esc('Define Shifting Interval', 'syslog');?>'>
								<?php
								$start_val = 1;
								$end_val = sizeof($graph_timeshifts) + 1;
								if (cacti_sizeof($graph_timeshifts)) {
									for ($shift_value=$start_val; $shift_value < $end_val; $shift_value++) {
										print "<option value='$shift_value'"; if (get_request_var('predefined_timeshift') == $shift_value) { print ' selected'; } print '>' . title_trim($graph_timeshifts[$shift_value], 40) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<i title='<?php print __esc('Shift Time Forward', 'syslog');?>' onclick='timeshiftFilterRight()' class='shiftArrow fa fa-forward'></i>
						</td>
						<td>
							<span>
								<input id='go' type='button' value='<?php print __esc('Go', 'syslog');?>'>
								<input id='clear' type='button' value='<?php print __esc('Clear', 'syslog');?>' title='<?php print __esc('Return filter values to their user defined defaults', 'syslog');?>'>
								<input id='export' type='button' value='<?php print __esc('Export', 'syslog');?>' title='<?php print __esc('Export Records to CSV', 'syslog');?>'>
								<input id='save' type='button' value='<?php print __esc('Save', 'syslog');?>' title='<?php print __esc('Save Default Settings', 'syslog');?>'>
							</span>
						</td>
						<?php if (api_plugin_user_realm_auth('syslog_alerts.php')) { ?>
						<td>
							<span>
								<input id='balerts' type='button' value='<?php print __esc('Alerts', 'syslog');?>' title='<?php print __esc('View Syslog Alert Rules', 'syslog');?>'>
								<input id='bremoval' type='button' value='<?php print __esc('Removals', 'syslog');?>' title='<?php print __esc('View Syslog Removal Rules', 'syslog');?>'>
								<input id='breports' type='button' value='<?php print __esc('Reports', 'syslog');?>' title='<?php print __esc('View Syslog Reports', 'syslog');?>'>
							</span>
						</td>
						<?php } ?>
						<td>
							<span id='text'></span>
							<input type='hidden' name='action' value='actions'>
							<input type='hidden' name='syslog_pdt_change' value='false'>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<td>
							<?php print __('Search', 'syslog');?>
						</td>
						<td>
							<input type='text' id='rfilter' size='30' value='<?php print html_escape_request_var('rfilter');?>' onChange='applyFilter()'>
						</td>
						<td>
							<?php print __('Devices', 'syslog');?>
						</td>
						<td>
							<select id='host' multiple style='display:none; width: 150px; overflow: scroll;'>
								<?php
								$hfilter = get_request_var('host');

								if ($tab == 'syslog') {
									print "<option id='host_all' value='0'" . (($hfilter == 'null' || $hfilter == '0' || $reset_multi) ? 'selected':'') . '>' .  __('Show All Devices', 'syslog') . '</option>';
								} else {
									print "<option id='host_all' value='0'" . (($hfilter == 'null' || $hfilter == 0 || $reset_multi) ? 'selected':'') . '>' . __('Show All Logs', 'syslog') . '</option>';
									print "<option id='host_none' value='-1'" . ($hfilter == '-1' ? 'selected':'') . '>' . __('Threshold Logs', 'syslog') . '</option>';
								}

								$hosts_where = '';
								$hosts_where = api_plugin_hook_function('syslog_hosts_where', $hosts_where);

								if ($hosts_where != '') {
									$hosts_where = 'WHERE ' . $hosts_where;
								}

								if ($hfilter != '0' && $hfilter != '' && $hfilter != '-1') {
									$mhosts_where  = ($hosts_where != '' ? ' AND ':'WHERE ') . ' host_id IN (' . $hfilter . ')';
									$mhosts_nwhere = ($hosts_where != '' ? $hosts_where . ' AND ':'WHERE ') . ' host_id NOT IN (' . $hfilter . ')';
								}

								$ac_rows = read_config_option('autocomplete_rows');
								if ($ac_rows <= 0) {
									$ac_rows = 100;
								}

								if (syslog_db_table_exists('host', false)) {
									if ($hfilter != '0' && $hfilter != '' && $hfilter != '-1') {
										$hosts = syslog_db_fetch_assoc("SELECT *
											FROM (
												SELECT DISTINCT sh.host_id, sh.host, h.id, '1' AS selected
												FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
												LEFT JOIN host AS h
												ON sh.host = h.hostname
												OR sh.host = h.description
												OR sh.host LIKE substring_index(h.hostname, '.', 1)
												OR sh.host LIKE substring_index(h.description, '.', 1)
												$mhosts_where
												UNION
												SELECT DISTINCT sh.host_id, sh.host, h.id, '0' AS selected
												FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
												LEFT JOIN host AS h
												ON sh.host = h.hostname
												OR sh.host = h.description
												OR sh.host LIKE substring_index(h.hostname, '.', 1)
												OR sh.host LIKE substring_index(h.description, '.', 1)
												$mhosts_nwhere
											) AS rs
											ORDER BY selected DESC, host
											LIMIT $ac_rows");
									} else {
										$hosts = syslog_db_fetch_assoc("SELECT DISTINCT sh.host_id, sh.host, h.id
											FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
											LEFT JOIN host AS h
											ON sh.host = h.hostname
											OR sh.host = h.description
											OR sh.host LIKE substring_index(h.hostname, '.', 1)
											OR sh.host LIKE substring_index(h.description, '.', 1)
											$hosts_where
											ORDER BY host
											LIMIT $ac_rows");
									}
								} else {
									if ($hfilter != '0' && $hfilter != '' && $hfilter != '-1') {
										$hosts = syslog_db_fetch_assoc("SELECT *
											FROM (
												SELECT DISTINCT sh.host_id, sh.host, '0' AS id, '1' AS selected
												FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
												$mhosts_where
												UNION
												SELECT DISTINCT sh.host_id, sh.host, '0' AS id, '0' AS selected
												FROM `" . $syslogdb_default . "`.`syslog_hosts` AS sh
												$mhosts_nwhere
											) AS rs
											ORDER BY selected DESC, host
											LIMIT $ac_rows");
									} else {
										$hosts = syslog_db_fetch_assoc('SELECT DISTINCT sh.host_id, sh.host, "0" AS id
											FROM `' . $syslogdb_default . "`.`syslog_hosts` AS sh
											$hosts_where
											ORDER BY host
											LIMIT $ac_rows");
									}
								}

								$selected = explode(',', $hfilter);

								if (cacti_sizeof($hosts)) {
									foreach ($hosts as $host) {
										$host['host'] = syslog_strip_domain($host['host']);

										if (!empty($host['id'])) {
											$class = get_device_leaf_class($host['id']);
										} else {
											$class = 'deviceUp';
										}

										print "<option class='$class' value='" . $host['host_id'] . "'";

										if (cacti_sizeof($selected)) {
											if (in_array($host['host_id'], $selected)) {
												print ' selected';
											}
										}
										print '>';
										print $host['host'] . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Messages', 'syslog');?>
						</td>
						<td>
							<select id='rows' onChange='applyFilter()' title='<?php print __esc('Display Rows', 'syslog');?>'>
								<option value='-1'<?php if (get_request_var('rows') == '-1') { ?> selected<?php } ?>><?php print __('Default', 'syslog');?></option>
								<?php
								foreach($item_rows AS $rows => $display_text) {
									print "<option value='" . $rows . "'"; if (get_request_var('rows') == $rows) { print ' selected'; } print '>' . $display_text . '</option>';
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Trim', 'syslog');?>
						</td>
						<td>
							<select id='trimval' onChange='applyFilter()' title='<?php print __esc('Message Trim', 'syslog');?>'>
								<?php
								foreach($trimvals AS $seconds => $display_text) {
									print "<option value='" . $seconds . "'"; if (get_request_var('trimval') == $seconds) { print ' selected'; } print '>' . $display_text . "</option>\n";
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Refresh', 'syslog');?>
						</td>
						<td>
							<select id='refresh' onChange='applyFilter()'>
								<?php
								foreach($page_refresh_interval AS $seconds => $display_text) {
									print "<option value='" . $seconds . "'"; if (get_request_var('refresh') == $seconds) { print ' selected'; } print '>' . $display_text . '</option>';
								}
								?>
							</select>
						</td>
					</tr>
				</table>
				<table class='filterTable'>
					<tr>
						<?php api_plugin_hook('syslog_extend_filter');?>
						<?php html_program_filter(get_request_var('eprogram'), false);?>
						<td>
							<?php print __('Facility', 'syslog');?>
						</td>
						<td>
							<select id='efacility' onChange='applyFilter()' title='<?php print __esc('Facilities to filter on', 'syslog');?>'>
								<option value='-1'<?php if (get_request_var('efacility') == '0') { ?> selected<?php } ?>><?php print __('All Facilities', 'syslog');?></option>
								<?php
								if (!isset($hostfilter)) $hostfilter = '';
								$efacilities = syslog_db_fetch_assoc('SELECT DISTINCT f.facility_id, f.facility
									FROM `' . $syslogdb_default . '`.`syslog_host_facilities` AS fh
									INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS f
									ON f.facility_id=fh.facility_id ' . (strlen($hostfilter) ? 'WHERE ':'') . $hostfilter . '
									ORDER BY facility');

								if (cacti_sizeof($efacilities)) {
									foreach ($efacilities as $efacility) {
										print "<option value='" . $efacility['facility_id'] . "'"; if (get_request_var('efacility') == $efacility['facility_id']) { print ' selected'; } print '>' . ucfirst($efacility['facility']) . '</option>';
									}
								}
								?>
							</select>
						</td>
						<td>
							<?php print __('Priority', 'syslog');?>
						</td>
						<td>
							<select id='epriority' onChange='applyFilter()' title='<?php print __('Priority Levels', 'syslog');?>'>
								<option value='-1'<?php if (get_request_var('epriority') == '-1') { ?> selected<?php } ?>><?php print __('All Priorities', 'syslog');?></option>
								<option value='0'<?php if (get_request_var('epriority') == '0') { ?> selected<?php } ?>><?php print __('Emergency', 'syslog');?></option>
								<option value='1'<?php if (get_request_var('epriority') == '1') { ?> selected<?php } ?>><?php print __('Alert++', 'syslog');?></option>
								<option value='1o'<?php if (get_request_var('epriority') == '1o') { ?> selected<?php } ?>><?php print __('Alert', 'syslog');?></option>
								<option value='2'<?php if (get_request_var('epriority') == '2') { ?> selected<?php } ?>><?php print __('Critical++', 'syslog');?></option>
								<option value='2o'<?php if (get_request_var('epriority') == '2o') { ?> selected<?php } ?>><?php print __('Critical', 'syslog');?></option>
								<option value='3'<?php if (get_request_var('epriority') == '3') { ?> selected<?php } ?>><?php print __('Error++', 'syslog');?></option>
								<option value='3o'<?php if (get_request_var('epriority') == '3o') { ?> selected<?php } ?>><?php print __('Error', 'syslog');?></option>
								<option value='4'<?php if (get_request_var('epriority') == '4') { ?> selected<?php } ?>><?php print __('Warning++', 'syslog');?></option>
								<option value='4o'<?php if (get_request_var('epriority') == '4o') { ?> selected<?php } ?>><?php print __('Warning', 'syslog');?></option>
								<option value='5'<?php if (get_request_var('epriority') == '5') { ?> selected<?php } ?>><?php print __('Notice++', 'syslog');?></option>
								<option value='5o'<?php if (get_request_var('epriority') == '5o') { ?> selected<?php } ?>><?php print __('Notice', 'syslog');?></option>
								<option value='6'<?php if (get_request_var('epriority') == '6') { ?> selected<?php } ?>><?php print __('Info++', 'syslog');?></option>
								<option value='6o'<?php if (get_request_var('epriority') == '6o') { ?> selected<?php } ?>><?php print __('Info', 'syslog');?></option>
								<option value='7'<?php if (get_request_var('epriority') == '7') { ?> selected<?php } ?>><?php print __('Debug', 'syslog');?></option>
							</select>
						</td>
						<?php if (get_nfilter_request_var('tab') == 'syslog') { ?>
						<td>
							<?php print __('Record Type', 'syslog');?>
						</td>
						<td>
							<select id='removal' onChange='applyFilter()' title='<?php print __esc('Removal Handling', 'syslog');?>'>
								<option value='1'<?php if (get_request_var('removal') == '1') { ?> selected<?php } ?>><?php print __('All Records', 'syslog');?></option>
								<option value='-1'<?php if (get_request_var('removal') == '-1') { ?> selected<?php } ?>><?php print __('Main Records', 'syslog');?></option>
								<option value='2'<?php if (get_request_var('removal') == '2') { ?> selected<?php } ?>><?php print __('Removed Records', 'syslog');?></option>
							</select>
						</td>
						<?php } else { ?>
						<input type='hidden' id='removal' value='<?php print get_request_var('removal');?>'>
						<?php } ?>
					</tr>
				</table>
			</form>
			</td>
		</tr>
	<?php html_end_box(false);
}

/**
 * function syslog_strip_domain()
 *
 * Simple function to strip the domain for a hostname
 *
 * @param string hostname
 */
function syslog_strip_domain($hostname) {
	if (strpos($hostname, '.') === false) {
		return $hostname;
	} elseif (filter_var($hostnam, FILTER_VALIDATE_IP)) {
		return $hostname;
	} else {
		$parts = explode('.', $hostname);
		foreach($parts as $part) {
			if (is_numeric($part)) {
				return $hostname;
			}
		}

		return $parts[0];
	}
}

/**
 * function syslog_syslog_legend()
 *
 * This function displays the foreground and background colors for the syslog syslog legend
*/
function syslog_syslog_legend() {
	global $disabled_color, $notmon_color, $database_default;

	html_start_box('', '100%', '', '3', 'center', '');
	print '<tr class="">';
	print "<td width='10%' class='logEmergency'>" . __('Emergency', 'syslog') . '</td>';
	print "<td width='10%' class='logCritical'>"  . __('Critical', 'syslog')  . '</td>';
	print "<td width='10%' class='logAlert'>"     . __('Alert', 'syslog')     . '</td>';
	print "<td width='10%' class='logError'>"     . __('Error', 'syslog')     . '</td>';
	print "<td width='10%' class='logWarning'>"   . __('Warning', 'syslog')   . '</td>';
	print "<td width='10%' class='logNotice'>"    . __('Notice', 'syslog')    . '</td>';
	print "<td width='10%' class='logInfo'>"      . __('Info', 'syslog')      . '</td>';
	print "<td width='10%' class='logDebug'>"     . __('Debug', 'syslog')     . '</td>';
	print '</tr>';
	html_end_box(false);
}

/** function syslog_log_legend()
 *  This function displays the foreground and background colors for the syslog log legend
*/
function syslog_log_legend() {
	global $disabled_color, $notmon_color, $database_default;

	html_start_box('', '100%', '', '3', 'center', '');
	print '<tr class="">';
	print "<td width='10%' class='logCritical'>" . __('Critical', 'syslog')      . '</td>';
	print "<td width='10%' class='logWarning'>"  . __('Warning', 'syslog')       . '</td>';
	print "<td width='10%' class='logNotice'>"   . __('Notice', 'syslog')        . '</td>';
	print "<td width='10%' class='logInfo'>"     . __('Informational', 'syslog') . '</td>';
	print '</tr>';
	html_end_box(false);
}

/** function syslog_messages()
 *  This is the main page display function in Syslog.  Displays all the
 *  syslog messages that are relevant to Syslog.
*/
function syslog_messages($tab = 'syslog') {
	global $sql_where, $hostfilter, $severities;
	global $config, $syslog_incoming_config, $reset_multi, $syslog_levels;

	include(SYSLOG_CONFIG);
	include('./include/global_arrays.php');

	/* force the initial timespan to be 30 minutes for performance reasons */
	if (!isset($_SESSION['sess_syslog_init'])) {
		$_SESSION['sess_current_timespan'] = 1;
		$_SESSION['sess_syslog_init'] = 1;
	}

	$url_curr_page = get_browser_query_string();

	$sql_where = '';

	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = get_request_var('rows');
	}

	$syslog_messages = get_syslog_messages($sql_where, $rows, $tab);

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
		} elseif (get_request_var('removal') == -1) {
			$total_rows = syslog_db_fetch_cell("SELECT count(*)
				FROM `" . $syslogdb_default . "`.`syslog` AS syslog
				$sql_where");
		} else {
			$total_rows = syslog_db_fetch_cell("SELECT count(*)
				FROM `" . $syslogdb_default . "`.`syslog_removed` AS syslog
				$sql_where");
		}
	} else {
		$total_rows = syslog_db_fetch_cell("SELECT count(*)
			FROM `" . $syslogdb_default . "`.`syslog_logs` AS syslog
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
			ON syslog.facility_id=sf.facility_id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_priorities` AS sp
			ON syslog.priority_id=sp.priority_id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_alert` AS sa
			ON syslog.alert_id=sa.id
			LEFT JOIN `" . $syslogdb_default . "`.`syslog_programs` AS spr
			ON syslog.program_id=spr.program_id " .
			$sql_where);
	}

	if ($tab == 'syslog') {
		if (api_plugin_user_realm_auth('syslog_alerts.php')) {
			$display_text = array(
				'nosortt'     => array(__('Actions', 'syslog'), 'ASC'),
				'logtime'     => array(__('Date', 'syslog'), 'ASC'),
				'host_id'     => array(__('Device', 'syslog'), 'ASC'),
				'program'     => array(__('Program', 'syslog'), 'ASC'),
				'message'     => array(__('Message', 'syslog'), 'ASC'),
				'facility_id' => array(__('Facility', 'syslog'), 'ASC'),
				'priority_id' => array(__('Priority', 'syslog'), 'ASC'));
		} else {
			$display_text = array(
				'logtime'     => array(__('Date', 'syslog'), 'ASC'),
				'host_id'     => array(__('Device', 'syslog'), 'ASC'),
				'program'     => array(__('Program', 'syslog'), 'ASC'),
				'message'     => array(__('Message', 'syslog'), 'ASC'),
				'facility_id' => array(__('Facility', 'syslog'), 'ASC'),
				'priority_id' => array(__('Priority', 'syslog'), 'ASC'));
		}

		$nav = html_nav_bar("syslog.php?tab=$tab", MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, cacti_sizeof($display_text), __('Messages', 'syslog'), 'page', 'main');

		print $nav;

		html_start_box('', '100%', '', '3', 'center', '');

		html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

		$hosts = array_rekey(
			syslog_db_fetch_assoc('SELECT host_id, host
				FROM `' . $syslogdb_default . '`.`syslog_hosts`'),
			'host_id', 'host'
		);

		$facilities = array_rekey(
			syslog_db_fetch_assoc('SELECT facility_id, facility
				FROM `' . $syslogdb_default . '`.`syslog_facilities`'),
			'facility_id', 'facility'
		);

		$priorities = array_rekey(
			syslog_db_fetch_assoc('SELECT priority_id, priority
				FROM `' . $syslogdb_default . '`.`syslog_priorities`'),
			'priority_id', 'priority'
		);

		if (cacti_sizeof($syslog_messages)) {
			foreach ($syslog_messages as $sm) {
				$title = html_escape($sm['message']);

				syslog_row_color($sm['priority_id'], $sm['message']);

				if (api_plugin_user_realm_auth('syslog_alerts.php')) {
					$url = '';
					if ($sm['mtype'] == 'main') {
						$url .= "<a style='padding:1px' href='" . html_escape('syslog_alerts.php?id=' . $sm[$syslog_incoming_config['id']] . '&action=newedit&type=0') . "'><i class='deviceUp fas fa-plus-circle'></i>";
						$url .= "<a style='padding:1px' href='" . html_escape('syslog_removal.php?id=' . $sm[$syslog_incoming_config['id']] . '&action=newedit&type=new&type=0') . "'><i class='deviceDown fas fa-minus-circle'></i>";
					}

					form_selectable_cell($url, $sm['seq'], '', 'left');
				}

				form_selectable_cell($sm['logtime'], $sm['seq'], '', 'left');
				form_selectable_cell(isset($hosts[$sm['host_id']]) ? $hosts[$sm['host_id']]:__('Unknown', 'syslog'), $sm['seq'], '', 'left');
				form_selectable_cell($sm['program'], $sm['seq'], '', 'left');
				form_selectable_cell(filter_value(title_trim($sm[$syslog_incoming_config['textField']], get_request_var_request('trimval')), get_request_var('rfilter')), $sm['seq'], '', 'left syslogMessage');
				form_selectable_cell(isset($facilities[$sm['facility_id']]) ? $facilities[$sm['facility_id']]:__('Unknown', 'syslog'), $sm['seq'], '', 'left');
				form_selectable_cell(isset($priorities[$sm['priority_id']]) ? $priorities[$sm['priority_id']]:__('Unknown', 'syslog'), $sm['seq'], '', 'left');

				form_end_row();
			}
		} else {
			print "<tr><td class='center' colspan='" . (cacti_sizeof($display_text)) . "'><em>" . __('No Syslog Messages', 'syslog') . "</em></td></tr>";
		}

		html_end_box(false);

		if (cacti_sizeof($syslog_messages)) {
			print $nav;
		}

		syslog_syslog_legend();

		?>
		<script type='text/javascript'>
		$(function() {
			$('.syslogRow').tooltip({
				track: true,
				show: {
					effect: 'fade',
					duration: 250,
					delay: 125
				},
				position: { my: 'left+15 center', at: 'right center' }
			});

			$('button').tooltip({
				closed: true
			}).on('focus', function() {
				$('#filter').tooltip('close')
			}).on('click', function() {
				$(this).tooltip('close');
			});
		});
		</script>
		<?php
	} else {
		$display_text = array(
			'name'        => array('display' => __('Alert Name', 'syslog'), 'sort' => 'ASC', 'align' => 'left'),
			'severity'    => array('display' => __('Severity', 'syslog'),   'sort' => 'ASC', 'align' => 'left'),
			'logtime'     => array('display' => __('Date', 'syslog'),       'sort' => 'ASC', 'align' => 'left'),
			'logmsg'      => array('display' => __('Message', 'syslog'),    'sort' => 'ASC', 'align' => 'left'),
			'count'       => array('display' => __('Count', 'syslog'),      'sort' => 'ASC', 'align' => 'right'),
			'host'        => array('display' => __('Device', 'syslog'),     'sort' => 'ASC', 'align' => 'right'),
			'facility_id' => array('display' => __('Facility', 'syslog'),   'sort' => 'ASC', 'align' => 'right'),
			'priority_id' => array('display' => __('Priority', 'syslog'),   'sort' => 'ASC', 'align' => 'right')
		);

		$nav = html_nav_bar("syslog.php?tab=$tab", MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, cacti_sizeof($display_text), __('Alert Log Rows', 'syslog'), 'page', 'main');

		print $nav;

		html_start_box('', '100%', '', '3', 'center', '');

		html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

		if (cacti_sizeof($syslog_messages)) {
			foreach ($syslog_messages as $log) {
				$title   = html_escape($log['logmsg']);

				syslog_log_row_color($log['severity'], $title);

				form_selectable_cell(filter_value(strlen($log['name']) ? $log['name']:__('Alert Removed', 'syslog'), get_request_var('rfilter'), $config['url_path'] . 'plugins/syslog/syslog.php?id=' . $log['seq'] . '&tab=current'), $log['seq'], '', 'left');

				form_selectable_cell(isset($severities[$log['severity']]) ? $severities[$log['severity']]:__('Unknown', 'syslog'), $log['seq'], '', 'left');
				form_selectable_cell($log['logtime'], $log['seq'], '', 'left');
				form_selectable_cell(filter_value(title_trim($log['logmsg'], get_request_var_request('trimval')), get_request_var('rfilter')), $log['seq'], '', 'syslogMessage left');

				form_selectable_cell($log['count'], $log['seq'], '', 'right');
				form_selectable_cell($log['host'], $log['seq'], '', 'right');
				form_selectable_cell(ucfirst($log['facility']), $log['seq'], '', 'right');
				form_selectable_cell(ucfirst($log['priority']), $log['seq'], '', 'right');

				form_end_row();
			}
		} else {
			print "<tr><td colspan='" . (cacti_sizeof($display_text)) . "'><em>" . __('No Alert Log Messages', 'syslog') . "</em></td></tr>";
		}

		html_end_box(false);

		if (cacti_sizeof($syslog_messages)) {
			print $nav;
		}

		syslog_log_legend();
	}
}

function save_settings() {
	global $current_tab;

//	syslog_request_validation($current_tab);

	$variables = array(
		'rows',
		'refresh',
		'removal',
		'trimval',
		'efacility',
		'priority',
		'eprogram',
		'predefined_timespan',
		'predefined_timeshift',
	);

	foreach($variables as $v) {
		if (isset_request_var($v)) {
			// Accomdate predefined
			if (strpos($v, 'predefined') !== false) {
				$v = str_replace('predefined_', 'default_', $v);
				set_user_setting($v, get_request_var($v));
			} else {
				set_user_setting('syslog_' . $v, get_request_var($v));
			}
		}
	}

	syslog_request_validation($current_tab, true);
}

function html_program_filter($program_id = '-1', $none_entry = '', $action = 'ajax_programs', $call_back = 'applyFilter', $sql_where = '') {
	if (strpos($call_back, '()') === false) {
		$call_back .= '()';
	}

	if ($program_id > 0) {
		$program = syslog_db_fetch_cell("SELECT program
			FROM syslog_programs
			WHERE program_id = $program_id");
	} elseif ($program_id == -2) {
		$program = __('None', 'syslog');
	} else {
		$program = __('All Programs', 'syslog');
	}

	print '<td>';
	print __('Program', 'syslog');
	print '</td>';
	print '<td>';

	if ($none_entry) {
		$none_entry = __('None', 'syslog');
	} else {
		$none_entry = '';
	}

	syslog_form_callback(
		'eprogram',
		'SELECT DISTINCT program_id, program FROM syslog_programs AS spr ORDER BY program',
		'program',
		'program_id',
		$action,
		$program_id,
		$program,
		$none_entry,
		__('All Programs', 'syslog'),
		'',
		$call_back
	);

	print '</td>';
}

function get_ajax_programs($include_any = true, $include_none = false, $sql_where = '') {
	$return    = array();

	$term = get_filter_request_var('term', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));
	if ($term != '') {
		$sql_where .= ($sql_where != '' ? ' AND ' : 'WHERE ') . "program LIKE '%$term%'";
	}

	if (get_request_var('term') == '') {
		if ($include_any) {
			$return[] = array(
				'label' => __('All Programs', 'syslog'),
				'value' => __('All Programs', 'syslog'),
				'id' => '-1'
			);
		}

		if ($include_none) {
			$return[] = array(
				'label' => __('None', 'syslog'),
				'value' => __('None', 'syslog'),
				'id' => '-2'
			);
		}
	}

	$programs = syslog_db_fetch_assoc("SELECT program_id, program
		FROM syslog_programs
		$sql_where
		ORDER BY program
		LIMIT 20");

	if (cacti_sizeof($programs)) {
		foreach($programs as $program) {
			$return[] = array(
				'label' => $program['program'],
				'value' => $program['program'],
				'id' => $program['program_id']
			);
		}
	}

	print json_encode($return);
}

function syslog_form_callback($form_name, $classic_sql, $column_display, $column_id, $callback, $previous_id, $previous_value, $none_entry, $default_value, $class = '', $on_change = '') {
	if ($previous_value == '') {
		$previous_value = $default_value;
	}

	if (isset($_SESSION['sess_error_fields'])) {
		if (!empty($_SESSION['sess_error_fields'][$form_name])) {
			$class .= ($class != '' ? ' ':'') . 'txtErrorTextBox';
			unset($_SESSION['sess_error_fields'][$form_name]);
		}
	}

	if (isset($_SESSION['sess_field_values'])) {
		if (!empty($_SESSION['sess_field_values'][$form_name])) {
			$previous_value = $_SESSION['sess_field_values'][$form_name];
		}
	}

	if ($class != '') {
		$class = " class='$class' ";
	}

	$theme = get_selected_theme();
	if ($theme == 'classic' || read_config_option('autocomplete') > 0) {
		print "<select id='" . html_escape($form_name) . "' name='" . html_escape($form_name) . "'" . $class . ($on_change != '' ? "onChange='$on_change'":'') . '>';

		if (!empty($none_entry)) {
			print "<option value='-2'" . (empty($previous_value) ? ' selected' : '') . ">$none_entry</option>";
		}

		$form_data = syslog_db_fetch_assoc($classic_sql);

		html_create_list($form_data, $column_display, $column_id, html_escape($previous_id));

		print '</select>';
	} else {
		if (empty($previous_id) && $previous_value == '') {
			$previous_value = $none_entry;
		}

		print "<span id='$form_name" . "_wrap' class='autodrop ui-selectmenu-button ui-selectmenu-button-closed ui-corner-all ui-corner-all ui-button ui-widget'>";
		print "<span id='$form_name" . "_click' style='z-index:4' class='ui-selectmenu-icon ui-icon ui-icon-triangle-1-s'></span>";
		print "<span class='ui-select-text'>";
		print "<input type='text' class='ui-state-default ui-corner-all' id='$form_name" . "_input' value='" . html_escape($previous_value) . "'>";
		print "</span>";

		if (!empty($none_entry) && empty($previous_value)) {
			$previous_value = $none_entry;
		}

		print "</span>";
		print "<input type='hidden' id='" . $form_name . "' name='" . $form_name . "' value='" . html_escape($previous_id) . "'>";
		?>
		<style type='text/css'>
		.syslogMessage {
			white-space: normal !important;
		}
		</style>
		<script type='text/javascript'>
		var <?php print $form_name;?>Timer;
		var <?php print $form_name;?>ClickTimer;
		var <?php print $form_name;?>Open = false;

		$(function() {
		    $('#<?php print $form_name;?>_input').autocomplete({
		        source: '<?php print get_current_page();?>?action=<?php print $callback;?>',
				autoFocus: true,
				minLength: 0,
				select: function(event,ui) {
					$('#<?php print $form_name;?>_input').val(ui.item.label);
					if (ui.item.id) {
						$('#<?php print $form_name;?>').val(ui.item.id);
					} else {
						$('#<?php print $form_name;?>').val(ui.item.value);
					}
					<?php print $on_change;?>;
				}
			}).css('border', 'none').css('background-color', 'transparent');

			$('#<?php print $form_name;?>_wrap').on('dblclick', function() {
				<?php print $form_name;?>Open = false;
				clearTimeout(<?php print $form_name;?>Timer);
				clearTimeout(<?php print $form_name;?>ClickTimer);
				$('#<?php print $form_name;?>_input').autocomplete('close');
			}).on('click', function() {
				if (<?php print $form_name;?>Open) {
					$('#<?php print $form_name;?>_input').autocomplete('close');
					clearTimeout(<?php print $form_name;?>Timer);
					<?php print $form_name;?>Open = false;
				} else {
					<?php print $form_name;?>ClickTimer = setTimeout(function() {
						$('#<?php print $form_name;?>_input').autocomplete('search', '');
						clearTimeout(<?php print $form_name;?>Timer);
						<?php print $form_name;?>Open = true;
					}, 200);
				}
			}).on('mouseleave', function() {
				<?php print $form_name;?>Timer = setTimeout(function() { $('#<?php print $form_name;?>_input').autocomplete('close'); }, 800);
			});

			width = $('#<?php print $form_name;?>_input').textBoxWidth();
			if (width < 100) {
				width = 100;
			}

			$('#<?php print $form_name;?>_wrap').css('width', width+20);
			$('#<?php print $form_name;?>_input').css('width', width);

			$('ul[id^="ui-id"]').on('mouseenter', function() {
				clearTimeout(<?php print $form_name;?>Timer);
			}).on('mouseleave', function() {
				<?php print $form_name;?>Timer = setTimeout(function() { $('#<?php print $form_name;?>_input').autocomplete('close'); }, 800);
			});

			$('ul[id^="ui-id"] > li').each().on('mouseenter', function() {
				$(this).addClass('ui-state-hover');
			}).on('mouseleave', function() {
				$(this).removeClass('ui-state-hover');
			});

			$('#<?php print $form_name;?>_wrap').on('mouseenter', function() {
				$(this).addClass('ui-state-hover');
				$('input#<?php print $form_name;?>_input').addClass('ui-state-hover');
			}).on('mouseleave', function() {
				$(this).removeClass('ui-state-hover');
				$('input#<?php print $form_name;?>_input').removeClass('ui-state-hover');
			});
		});
		</script>
		<?php
	}
}

