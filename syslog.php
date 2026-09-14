<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group								 |
 |																		 |
 | This program is free software; you can redistribute it and/or		   |
 | modify it under the terms of the GNU General Public License			 |
 | as published by the Free Software Foundation; either version 2		  |
 | of the License, or (at your option) any later version.				  |
 |																		 |
 | This program is distributed in the hope that it will be useful,		 |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of		  |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the		   |
 | GNU General Public License for more details.							|
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution					 |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | Originally released as aloe by: sidewinder at shitworks.com			 |
 | Modified by: Harlequin <harlequin@cyberonic.com>						|
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/												   |
 +-------------------------------------------------------------------------+
*/

// allow guest account to see this page
$guest_account = true;

// initialize cacti environment
chdir('../../');
include('./include/auth.php');
include_once('./lib/html_tree.php');
include_once('./plugins/syslog/functions.php');
include_once('./plugins/syslog/database.php');

syslog_connect();

set_default_action();

if (get_request_var('action') === 'ajax_search_values') {
	header('Content-Type: application/json; charset=UTF-8');
	print json_encode(syslog_search_suggestions(
		(string) get_nfilter_request_var('field'), (string) get_nfilter_request_var('term'),
		get_nfilter_request_var('tab') === 'alerts' ? 'alerts' : 'syslog',
		(string) get_nfilter_request_var('removal')
	));
	exit;
}

if (get_request_var('action') == 'ajax_programs') {
	return get_ajax_programs(true);
}

if (get_request_var('action') == 'ajax_programs_wnone') {
	return get_ajax_programs(true, true);
}

if (get_request_var('action') == 'ajax_hosts') {
	print get_ajax_hosts();
	exit;
}

if (get_request_var('action') == 'save') {
	save_settings();
	exit;
}

if (get_request_var('action') == 'saved_search_save') {
	header('Content-Type: application/json; charset=UTF-8');
	print saved_search_save();
	exit;
}

if (get_request_var('action') == 'saved_search_delete') {
	header('Content-Type: application/json; charset=UTF-8');
	print saved_search_delete();
	exit;
}

if (get_request_var('action') == 'saved_search_global') {
	header('Content-Type: application/json; charset=UTF-8');
	print saved_search_global();
	exit;
}

$title = __('Syslog Viewer', 'syslog');

// set the default tab
get_filter_request_var('tab', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^([a-zA-Z]+)$/']]);

load_current_session_value('tab', 'sess_syslog_tab', 'syslog');
$current_tab = get_request_var('tab');

// validate the syslog post/get/request information
if ($current_tab != 'stats') {
	syslog_request_validation($current_tab);
}

if (isset_request_var('refresh')) {
	$refresh['seconds'] = get_request_var('refresh');
	$refresh['page']	   = $config['url_path'] . 'plugins/syslog/syslog.php?header=false&tab=' . $current_tab;
	$refresh['logout']  = 'false';

	set_page_refresh($refresh);
}

// draw the tabs
// display the main page
if (isset_request_var('export')) {
	syslog_export($current_tab);

	// clear output so reloads wont re-download
	unset_request_var('output');
} else {
	general_header();

	syslog_include_js();

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

$_SESSION['sess_nav_level_cache'] = [];

function get_ajax_hosts() {
	global $syslogdb_default;

	$ac_rows = read_config_option('autocomplete_rows');

	if ($ac_rows <= 0) {
		$ac_rows = 100;
	}

	$term = '%' . get_nfilter_request_var('term') . '%';

	if (syslog_db_table_exists('host', false)) {
		$hosts = syslog_db_fetch_assoc_prepared("SELECT DISTINCT sh.host_id, sh.host, h.id
			FROM `$syslogdb_default`.`syslog_hosts` AS sh
			LEFT JOIN host AS h
			ON sh.host = h.hostname
			OR sh.host = h.description
			OR sh.host LIKE substring_index(h.hostname, '.', 1)
			OR sh.host LIKE substring_index(h.description, '.', 1)
			WHERE sh.host LIKE ?
			OR h.description LIKE ?
			ORDER BY host
			LIMIT $ac_rows",
			[$term, $term]);
	} else {
		$hosts = syslog_db_fetch_assoc_prepared("SELECT DISTINCT sh.host_id, sh.host, '0' AS id
			FROM `$syslogdb_default`.`syslog_hosts` AS sh
			WHERE sh.host LIKE ?
			ORDER BY host
			LIMIT $ac_rows",
			[$term]);
	}

	if (cacti_sizeof($hosts)) {
		foreach ($hosts as $host) {
			if (!empty($host['id'])) {
				$class = get_device_leaf_class($host['id']);
			} else {
				$class = 'deviceUp';
			}

			$rhosts[$host['host_id']] = [
				'host'	   => $host['host'],
				'host_id' => $host['id'],
				'class'   => $class
			];
		}

		return json_encode($rhosts);
	} else {
		return json_encode([]);
	}
}

function syslog_display_tabs($current_tab) {
	global $config;

	// present a tabbed interface
	$tabs_syslog['syslog'] = __('System Logs', 'syslog');

	if (read_config_option('syslog_statistics') == 'on') {
		$tabs_syslog['stats']  = __('Statistics', 'syslog');
	}
	$tabs_syslog['alerts'] = __('Alert Logs', 'syslog');

	// if they were redirected to the page, let's set that up
	if (!isempty_request_var('id') || $current_tab == 'current') {
		$current_tab = 'current';
	}

	load_current_session_value('id', 'sess_syslog_id', '0');

	if (!isempty_request_var('id') || $current_tab == 'current') {
		$tabs_syslog['current'] = __('Selected Alert', 'syslog');
	}

	// draw the tabs
	print "<div class='tabs'><nav><ul>";

	if (cacti_sizeof($tabs_syslog)) {
		foreach (array_keys($tabs_syslog) as $tab_short_name) {
			print '<li><a class="tab ' . (($tab_short_name == $current_tab) ? 'selected"' : '"') . " href='" . html_escape($config['url_path'] .
				'plugins/syslog/syslog.php?' .
				'tab=' . $tab_short_name) .
				"'>" . $tabs_syslog[$tab_short_name] . '</a></li>';
		}
	}

	print '</ul></nav></div>';
}

function syslog_view_alarm() {
	global $config;
	global $syslogdb_default;

	print "<table class='cactiTable'>";
	print "<tr class='tableHeader'><td class='textHeaderDark'>" . __('Syslog Alert View', 'syslog') . '</td></tr>';
	print "<tr><td class='odd'>";

	$html = syslog_db_fetch_cell_prepared("SELECT html
		FROM `$syslogdb_default`.`syslog_logs`
		WHERE seq = ?",
		[get_request_var('id')]);

	print trim($html, "' ");

	print '</td></tr></table>';

	exit;
}

/**
 * function syslog_statistics()
 * This function paints a table of summary statistics for syslog
 * messages by host, facility, priority, and time range.
 */
function syslog_statistics() {
	global $title, $rows, $config;
	global $syslogdb_default;

	// ================= input validation and session storage =================
	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'refresh' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => read_config_option('syslog_refresh'),
		],
		'timespan' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '300',
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'rfilter' => [
			'filter'  => FILTER_VALIDATE_IS_REGEX,
			'pageset' => true,
			'default' => ''
		],
		'host' => [
			'filter'  => FILTER_VALIDATE_IS_NUMERIC_LIST,
			'pageset' => true,
			'default' => '',
		],
		'facility' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '',
		],
		'priority' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '',
		],
		'program' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'host',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	validate_store_request_vars($filters, 'sess_syslogs');
	// ================= input validation =================

	html_start_box(__('Syslog Statistics Filter', 'syslog'), '100%', '', '3', 'center', '');

	syslog_stats_filter();

	html_end_box();

	$sql_where   = '';
	$sql_params  = [];
	$sql_groupby = '';

	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$rows = 999999;
	} else {
		$rows = get_request_var('rows');
	}

	$records = get_stats_records($sql_where, $sql_params, $sql_groupby, $rows);

	$rows_query_string = "SELECT COUNT(*)
		FROM `$syslogdb_default`.`syslog_statistics` AS ss
		$sql_where
		$sql_groupby";

	$total_rows = syslog_db_fetch_cell_prepared('SELECT COUNT(*) FROM (' . $rows_query_string . ') as temp', $sql_params);

	$nav = html_nav_bar('syslog.php?tab=stats', MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, 4, __('Messages', 'syslog'), 'page', 'main');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = [
		'host' => [
			'display' => __('Device Name', 'syslog'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'facility' => [
			'display' => __('Facility', 'syslog'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'priority' => [
			'display' => __('Priority', 'syslog'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'program' => [
			'display' => __('Program', 'syslog'),
			'sort'    => 'ASC',
			'align'   => 'left'
		],
		'insert_time' => [
			'display' => __('Date', 'syslog'),
			'sort'    => 'DESC',
			'align'   => 'right'
		],
		'records' => [
			'display' => __('Records', 'syslog'),
			'sort'    => 'DESC',
			'align'   => 'right'
		]
	];

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

			print '<td>' . (get_request_var('host') != '-2' ? $r['host'] : '-') . '</td>';
			print '<td>' . (get_request_var('facility') != '-2' ? ucfirst($r['facility']) : '-') . '</td>';
			print '<td>' . (get_request_var('priority') != '-2' ? ucfirst($r['priority']) : '-') . '</td>';
			print '<td>' . (get_request_var('program') != '-2' ? ucfirst($r['program']) : '-') . '</td>';
			// print '<td class="right">' . $r['insert_time'] . '</td>';
			print '<td class="right">' . $time . '</td>';
			print '<td class="right">' . number_format_i18n($r['records'], -1) . '</td>';

			form_end_row();

			$i++;
		}
	} else {
		print "<tr><td colspan='4'><em>" . __('No Syslog Statistics Found', 'syslog') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($records)) {
		print $nav;
	}
}

function get_stats_records(&$sql_where, &$sql_params, &$sql_groupby, $rows) {
	global $syslogdb_default;

	// form the 'where' clause for our main sql query
	if (!isempty_request_var('rfilter')) {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . '(sh.host RLIKE ? OR spr.program RLIKE ?)';

		$sql_params[] = get_request_var('rfilter');
		$sql_params[] = get_request_var('rfilter');
	}

	if (get_request_var('host') == '-2') {
		// Do nothing
	} elseif (get_request_var('host') != '-1' && get_request_var('host') != '') {
		$sql_where   .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'ss.host_id = ?';
		$sql_params[] = get_request_var('host');
		$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'host_id';
	} else {
		$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'host_id';
	}

	if (get_request_var('facility') == '-2') {
		// Do nothing
	} elseif (get_request_var('facility') != '-1' && get_request_var('facility') != '') {
		$sql_where   .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'ss.facility_id = ?';
		$sql_params[] = get_request_var('facility');
		$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'facility_id';
	} else {
		$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'facility_id';
	}

	if (get_request_var('priority') == '-2') {
		// Do nothing
	} elseif (get_request_var('priority') != '-1' && get_request_var('priority') != '') {
		$sql_where   .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'ss.priority_id = ?';
		$sql_params[] = get_request_var('priority');
		$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'priority_id';
	} else {
		$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'priority_id';
	}

	if (get_request_var('program') != '-2') {
		if (get_request_var('program') != '-1' && get_request_var('program') != '') {
			$sql_where   .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'ss.program_id = ?';
			$sql_params[] = get_request_var('program');
			$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'program_id';
		} else {
			$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . 'program_id';
		}
	}

	if (get_request_var('timespan') != '-1') {
		$sql_groupby .= ($sql_groupby != '' ? ', ' : '') . ' UNIX_TIMESTAMP(insert_time) DIV ' . get_request_var('timespan');
	}

	$sql_order = get_order_string();

	if (!isset_request_var('export')) {
		$sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;
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
			FROM `$syslogdb_default`.`syslog_statistics` AS ss
			$sql_where
			$sql_groupby
		) AS ss
		LEFT JOIN `$syslogdb_default`.`syslog_facilities` AS sf
		ON ss.facility_id=sf.facility_id
		LEFT JOIN `$syslogdb_default`.`syslog_priorities` AS sp
		ON ss.priority_id=sp.priority_id
		LEFT JOIN `$syslogdb_default`.`syslog_programs` AS spr
		ON ss.program_id=spr.program_id
		LEFT JOIN `$syslogdb_default`.`syslog_hosts` AS sh
		ON ss.host_id=sh.host_id
		$sql_order
		$sql_limit";

	// cacti_log(str_replace("\n", "", $query_sql));

	return syslog_db_fetch_assoc_prepared($query_sql, $sql_params);
}

function syslog_stats_filter() {
	global $config, $item_rows;
	global $syslogdb_default;

	?>
	<tr class='even'>
		<td>
		<form id='stats_form' action='syslog.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Device', 'syslog'); ?>
					</td>
					<td>
						<select id='host' onChange='applyFilterStats()'>
							<option value='-1'<?php if (get_request_var('host') == '-1') { ?> selected<?php } ?>><?php print __('All', 'syslog'); ?></option>
							<option value='-2'<?php if (get_request_var('host') == '-2') { ?> selected<?php } ?>><?php print __('None', 'syslog'); ?></option>
							<?php
							$ac_rows = read_config_option('autocomplete_rows');

							if ($ac_rows <= 0) {
								$ac_rows = 100;
							}

							if (syslog_db_table_exists('host', false)) {
								$hosts = syslog_db_fetch_assoc("SELECT DISTINCT sh.host_id, sh.host, h.id
									FROM `$syslogdb_default`.`syslog_hosts` AS sh
									LEFT JOIN host AS h
									ON sh.host = h.hostname
									OR sh.host = h.description
									OR sh.host LIKE substring_index(h.hostname, '.', 1)
									OR sh.host LIKE substring_index(h.description, '.', 1)
									ORDER BY host
									LIMIT $ac_rows");
							} else {
								$hosts = syslog_db_fetch_assoc("SELECT DISTINCT sh.host_id, sh.host, '0' AS id
									FROM `$syslogdb_default`.`syslog_hosts` AS sh
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

									print '<option class="' . $class . '" value="' . $host['host_id'] . '"';

									if (get_request_var('host') == $host['host_id']) {
										print ' selected';
									}

									print '>' . html_escape($host['host']) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Facility', 'syslog'); ?>
					</td>
					<td>
						<select id='facility' onChange='applyFilterStats()'>
							<option value='-1'<?php if (get_request_var('facility') == '-1') { ?> selected<?php } ?>><?php print __('All', 'syslog'); ?></option>
							<option value='-2'<?php if (get_request_var('facility') == '-2') { ?> selected<?php } ?>><?php print __('None', 'syslog'); ?></option>
							<?php
							$facilities = syslog_db_fetch_assoc("SELECT DISTINCT facility_id, facility
								FROM `$syslogdb_default`.`syslog_facilities` AS sf
								ORDER BY facility");

							if (cacti_sizeof($facilities)) {
								foreach ($facilities as $r) {
									print '<option value="' . $r['facility_id'] . '"';

									if (get_request_var('facility') == $r['facility_id']) {
										print ' selected';
									}

									print '>' . html_escape(ucfirst($r['facility'])) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Priority', 'syslog'); ?>
					</td>
					<td>
						<select id='priority' onChange='applyFilterStats()'>
							<option value='-1'<?php if (get_request_var('priority') == '-1') { ?> selected<?php } ?>><?php print __('All', 'syslog'); ?></option>
							<option value='-2'<?php if (get_request_var('priority') == '-2') { ?> selected<?php } ?>><?php print __('None', 'syslog'); ?></option>
							<?php
							$priorities = syslog_db_fetch_assoc("SELECT DISTINCT priority_id, priority
								FROM `$syslogdb_default`.`syslog_priorities` AS sp
								ORDER BY priority");

							if (cacti_sizeof($priorities)) {
								foreach ($priorities as $r) {
									print '<option value="' . $r['priority_id'] . '"';

									if (get_request_var('priority') == $r['priority_id']) {
										print ' selected';
									}

									print '>' . html_escape(ucfirst($r['priority'])) . '</option>';
								}
							}
							?>
						</select>
					</td>
					<?php print html_program_filter(get_request_var('program'), true, 'ajax_programs_wnone', 'applyFilterStats'); ?>
					<td>
						<span>
							<input id='go' type='button' value='<?php print __esc('Go', 'syslog'); ?>'>
							<input id='clear' type='button' value='<?php print __esc('Clear', 'syslog'); ?>'>
						</span>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'syslog'); ?>
					</td>
					<td>
						<input type='text' id='rfilter' size='30' value='<?php print html_escape_request_var('rfilter'); ?>' onChange='applyFilterStats()'>
					</td>
					<td>
						<?php print __('Time Range', 'syslog'); ?>
					</td>
					<td>
						<select id='timespan' onChange='applyFilterStats()'>
							<?php
							$timespans = [
								60	   => __('%d Minute', 1, 'syslog'),
								120   => __('%d Minutes', 2, 'syslog'),
								300   => __('%d Minutes', 5, 'syslog'),
								600   => __('%d Minutes', 10, 'syslog'),
								1800  => __('%d Minutes', 30, 'syslog'),
								3600  => __('%d Hour', 1, 'syslog'),
								7200  => __('%d Hours', 2, 'syslog'),
								14400 => __('%d Hours', 4, 'syslog'),
								28880 => __('%d Hours', 8, 'syslog'),
								86400 => __('%d Day', 1, 'syslog')
							];

							foreach ($timespans as $time => $span) {
								print '<option value="' . $time . '"' . (get_request_var('timespan') == $time ? ' selected' : '') . '>' . $span . '</option>';
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Entries', 'syslog'); ?>
					</td>
					<td>
						<select id='rows' onChange='applyFilterStats()'>
						<option value='-1'<?php if (get_request_var('rows') == '-1') { ?> selected<?php } ?>><?php print __('Default', 'syslog'); ?></option>
						<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print '<option value="' . $key . '"';

									if (get_request_var('rows') == $key) {
										print ' selected';
									}

									print '>' . $value . '</option>';
								}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' value='<?php print get_filter_request_var('page'); ?>'>
		</form>
		</td>
		<script type='text/javascript'>
		initSyslogStats();
		</script>
	</tr>
	<?php
}

/**
 * function syslog_request_validation()
 * This is a generic function for this page that makes sure that
 * we have a good request.  We want to protect against people who
 * like to create issues with Cacti.
 *
 * @param mixed $current_tab
 * @param mixed $force
 */
function syslog_request_validation($current_tab, $force = false) {
	global $title, $rows, $config, $reset_multi;

	// Cacti validation populates $_POST even for values restored from the session.
	// Capture the original submission before any request helpers mutate it.
	$filter_submitted = isset($_POST['rfilter']);

	include_once($config['base_path'] . '/lib/time.php');

	if ($current_tab != 'alerts' && isset_request_var('host') && get_nfilter_request_var('host') == -1) {
		kill_session_var('sess_syslog_' . $current_tab . '_hosts');
		unset_request_var('host');
	}

	$shift_span = false;

	if (isset_request_var('predefined_timeshift')) {
		$shift_span = 'shift';
	} elseif (isset_request_var('predefined_timespan') && get_filter_request_var('predefined_timespan') > 0) {
		$shift_span = 'span';
	} elseif (isset_request_var('date1') && isset_request_var('date2')) {
		$shift_span = 'custom';
	}

	// ================= input validation and session storage =================
	$search_mode = isset_request_var('clear') || isset_request_var('reset') ? 'logical' :
		(isset_request_var('search_mode') ? get_nfilter_request_var('search_mode') : ($_SESSION['sess_sl_' . $current_tab . '_search_mode'] ?? (isset($_SESSION['sess_sl_' . $current_tab . '_rfilter']) || isset_request_var('rfilter') ? 'regex' : 'logical')));
	$search_mode = $search_mode === 'logical' ? 'logical' : 'regex';
	set_request_var('search_mode', $search_mode);

	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_user_setting('syslog_rows', '-1', $force)
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		],
		'id' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => ''
		],
		'removal' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => read_user_setting('syslog_removal', '1', $force)
		],
		'predefined_timespan' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_user_setting('default_timespan', GT_LAST_DAY, $force)
		],
		'predefined_timeshift' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_user_setting('default_timeshift', GTS_1_DAY, $force)
		],
		'refresh' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => read_user_setting('syslog_refresh', read_config_option('syslog_refresh'), $force)
		],
		'enabled' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		],
		'host' => [
			'filter'  => FILTER_VALIDATE_IS_NUMERIC_LIST,
			'pageset' => true,
			'default' => '',
		],
		'efacility' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => read_user_setting('syslog_efacility', '-1', $force),
			'options' => ['options' => 'sanitize_search_string']
		],
		'epriority' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => read_user_setting('syslog_epriority', '-1', $force),
			'options' => ['options' => 'sanitize_search_string']
		],
		'eprogram' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_user_setting('syslog_eprogram', '-1', $force),
		],
		'grouping' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => read_user_setting('syslog_grouping', '0', $force),
		],
		'search_mode' => [
			'filter' => FILTER_CALLBACK,
			'options' => ['options' => function ($value) { return $value === 'logical' ? 'logical' : 'regex'; }],
			'pageset' => true,
			'default' => 'logical'
		],
		'rfilter' => [
			'filter'  => $search_mode === 'logical' ? FILTER_UNSAFE_RAW : FILTER_VALIDATE_IS_REGEX,
			'pageset' => true,
			'default' => ''
		],
		'date1' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'date2' => [
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'logtime',
			'options' => ['options' => 'sanitize_search_string']
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'DESC',
			'options' => ['options' => 'sanitize_search_string']
		]
	];

	$logical_input = $search_mode === 'logical' && isset_request_var('rfilter') && !isset_request_var('clear') ? get_nfilter_request_var('rfilter') : null;
	validate_store_request_vars($filters, 'sess_sl_' . $current_tab);
	// Preserve literal text, including Cacti's special 'undefined' sentinel.
	if (is_string($logical_input)) {
		set_request_var('rfilter', $logical_input);
		$_SESSION['sess_sl_' . $current_tab . '_rfilter'] = $logical_input;
	}

	// ================= saved searches =================
	$saved_id = get_filter_request_var('saved', FILTER_VALIDATE_INT);

	if ($saved_id > 0 && !$filter_submitted) {
		// Applying a saved search restores its expression and standard filters.
		saved_search_apply($current_tab, $saved_id);
	} elseif ($filter_submitted || isset_request_var('clear') || isset_request_var('reset')) {
		// Manual edits detach the active saved search.
		kill_session_var('sess_sl_' . $current_tab . '_saved');
	}

	if (get_request_var('search_mode') !== 'logical') {
		$legacy = get_request_var('rfilter');
		set_request_var('rfilter', $legacy === '' ? '' : 'message contains ' . '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $legacy) . '"');
		set_request_var('search_mode', 'logical');
	}
	// New submissions contain the entire query. Convert old saved dropdown state only on entry.
	if (!$filter_submitted && !isset_request_var('clear')) {
		$conditions = [];
		foreach (['eprogram' => 'program_id', 'efacility' => 'facility_id', 'epriority' => 'priority_id'] as $old => $field) {
			$value = (string) get_request_var($old);
			if ($value !== '-1' && $value !== '') {
				$operator = $old === 'epriority' && substr($value, -1) !== 'o' ? '<=' : '=';
				$conditions[] = $field . ' ' . $operator . ' "' . (int) $value . '"';
			}
		}
		$hosts = (string) get_request_var('host');
		if ($hosts !== '' && $hosts !== '0') {
			$host_conditions = [];
			foreach (explode(',', $hosts) as $host) {
				if (ctype_digit($host)) {
					if ($current_tab === 'syslog') {
						$host_conditions[] = 'host_id = "' . $host . '"';
					} else {
						global $syslogdb_default;
						$hostname = syslog_db_fetch_cell_prepared("SELECT host FROM `$syslogdb_default`.`syslog_hosts` WHERE host_id = ?", [$host]);
						if ($hostname !== false && $hostname !== '') {
							$host_conditions[] = 'host = "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $hostname) . '"';
						}
					}
				}
			}
			if ($host_conditions) { $conditions[] = '(' . implode(' OR ', $host_conditions) . ')'; }
		}
		if ($conditions) {
			$query = get_request_var('rfilter');
			set_request_var('rfilter', ($query === '' ? '' : '(' . $query . ') AND ') . implode(' AND ', $conditions));
		}
	}
	foreach (['host' => '0', 'eprogram' => '-1', 'efacility' => '-1', 'epriority' => '-1'] as $key => $value) {
		set_request_var($key, $value);
		$_SESSION['sess_sl_' . $current_tab . '_' . $key] = $value;
	}
	foreach (['rfilter', 'search_mode'] as $key) {
		$_SESSION['sess_sl_' . $current_tab . '_' . $key] = get_request_var($key);
	}

	set_shift_span($shift_span, 'sess_sl_' . $current_tab);
	if (get_request_var('search_mode') === 'logical') {
		$query = get_request_var('rfilter');
		// Keep authored date conditions intact; the default last-day limit is
		// reapplied whenever the search carries no date condition of its own.
		try {
			$has_dates = syslog_search_has_time(syslog_parse_logical_search($query));
		} catch (InvalidArgumentException $error) {
			$has_dates = true; // Leave invalid input intact for the validation error below.
		}
		if (!$has_dates) {
			$dates = $shift_span === false ? 'logtime last "86400"' :
				'logtime >= "' . get_request_var('date1') . '" AND logtime <= "' . get_request_var('date2') . '"';
			set_request_var('rfilter', ($query === '' ? '' : '(' . $query . ') AND ') . $dates);
		}
		$_SESSION['sess_sl_' . $current_tab . '_rfilter'] = get_request_var('rfilter');
	}

	$GLOBALS['syslog_search_tree'] = null;
	$GLOBALS['syslog_search_error'] = '';
	if (get_request_var('search_mode') === 'logical') {
		try {
			$GLOBALS['syslog_search_tree'] = syslog_parse_logical_search(get_request_var('rfilter'));
			syslog_logical_search_sql($GLOBALS['syslog_search_tree'], $current_tab === 'syslog' ? 'message' : 'logmsg');
		} catch (InvalidArgumentException $error) {
			$GLOBALS['syslog_search_error'] = __('Invalid logical search: %s', $error->getMessage(), 'syslog');
		}
	}

	// ================= input validation =================


	api_plugin_hook_function('syslog_request_val');

	if (isset_request_var('host')) {
		$_SESSION['sess_syslog_' . $current_tab . '_hosts'] = get_nfilter_request_var('host');
	} elseif (isset($_SESSION['sess_syslog_' . $current_tab . '_hosts'])) {
		set_request_var('host', $_SESSION['sess_syslog_' . $current_tab . '_hosts']);
	} else {
		set_request_var('host', '-1');
	}
}

/**
 * Apply a saved search: restore its expression and standard filters, and
 * record it as active for the tab. Authored date filters are retained;
 * searches without dates receive the default relative range.
 */
function saved_search_apply($tab, $saved_id) {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);

	$row = syslog_db_fetch_row_prepared("SELECT id, search, removal, grouping
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?
		AND (`user` = ? OR is_global = 'on')",
		[$saved_id, $username]);

	if ($row === false) {
		kill_session_var('sess_sl_' . $tab . '_saved');

		return;
	}

	set_request_var('rfilter', $row['search']);
	set_request_var('removal', $row['removal']);
	set_request_var('grouping', $row['grouping']);
	set_request_var('page', '1');

	$_SESSION['sess_sl_' . $tab . '_rfilter']  = $row['search'];
	$_SESSION['sess_sl_' . $tab . '_removal']  = $row['removal'];
	$_SESSION['sess_sl_' . $tab . '_grouping'] = $row['grouping'];
	$_SESSION['sess_sl_' . $tab . '_page']     = '1';
	$_SESSION['sess_sl_' . $tab . '_saved']    = (int) $saved_id;

	// Page entry reapplies the default range when the saved expression has no dates.
}

function saved_search_save() {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);
	$name     = trim((string) get_nfilter_request_var('name'));
	$search   = (string) get_nfilter_request_var('rfilter');
	$removal  = get_filter_request_var('removal', FILTER_VALIDATE_INT);
	$grouping = get_filter_request_var('grouping', FILTER_VALIDATE_INT);

	if ($removal === false || $removal === null) {
		$removal = 1;
	}

	if ($grouping === false || $grouping === null) {
		$grouping = 0;
	}

	if ($name === '' || strlen($name) > 128) {
		return json_encode(['error' => __('A name of up to 128 characters is required.', 'syslog')]);
	}

	try {
		$tree = syslog_parse_logical_search($search);
		syslog_logical_search_sql($tree, get_request_var('tab') === 'alerts' ? 'logmsg' : 'message');
	} catch (InvalidArgumentException $error) {
		return json_encode(['error' => __('Invalid logical search: %s', $error->getMessage(), 'syslog')]);
	}

	// Upsert by owner and name, preserving the global flag of an existing row.
	$existing_id = syslog_db_fetch_cell_prepared("SELECT id
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE `user` = ? AND name = ?",
		[$username, $name]);

	if ($existing_id) {
		syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_saved_searches`
			SET search = ?, removal = ?, grouping = ?, `date` = ?
			WHERE id = ?",
			[$search, $removal, $grouping, time(), $existing_id]);

		return json_encode(['id' => (int) $existing_id]);
	}

	syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_saved_searches`
		(name, search, removal, grouping, `user`, is_global, `date`)
		VALUES (?, ?, ?, ?, ?, '', ?)",
		[$name, $search, $removal, $grouping, $username, time()]);

	return json_encode(['id' => (int) syslog_db_fetch_insert_id()]);
}

function saved_search_delete() {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);
	$id       = get_filter_request_var('id', FILTER_VALIDATE_INT);

	if ($id === false || $id === null || $id <= 0) {
		return json_encode(['error' => __('A valid saved search is required.', 'syslog')]);
	}

	$row = syslog_db_fetch_row_prepared("SELECT `user`, is_global
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?",
		[$id]);

	if ($row === false) {
		return json_encode(['error' => __('Saved search not found.', 'syslog')]);
	}

	if ($row['user'] !== $username && !($row['is_global'] === 'on' && syslog_saved_search_admin())) {
		return json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?",
		[$id]);

	if ((int) ($_SESSION['sess_sl_' . get_request_var('tab') . '_saved'] ?? 0) === (int) $id) {
		kill_session_var('sess_sl_' . get_request_var('tab') . '_saved');
	}

	return json_encode(['id' => (int) $id]);
}

function saved_search_global() {
	global $syslogdb_default;

	if (!syslog_saved_search_share()) {
		return json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	$username = get_username($_SESSION['sess_user_id']);

	if ($id === false || $id === null || $id <= 0) {
		return json_encode(['error' => __('A valid saved search is required.', 'syslog')]);
	}

	$row = syslog_db_fetch_row_prepared("SELECT `user`, is_global
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?",
		[$id]);

	if ($row === false) {
		return json_encode(['error' => __('Saved search not found.', 'syslog')]);
	}
	if ($row['user'] !== $username && !syslog_saved_search_admin()) {
		return json_encode(['error' => __('You may only share your own saved searches.', 'syslog')]);
	}

	$is_global = $row['is_global'] === 'on' ? '' : 'on';

	syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_saved_searches`
		SET is_global = ?
		WHERE id = ?",
		[$is_global, $id]);

	return json_encode(['id' => (int) $id, 'is_global' => $is_global]);
}

function set_shift_span($shift_span, $session_prefix) {
	global $graph_timeshifts;

	if ($shift_span === 'span') {
		$span = [];

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
		$span = [];

		$span['current_value_date1'] = get_request_var('date1');
		$span['current_value_date2'] = get_request_var('date2');
		$span['begin_now']           = strtotime(get_request_var('date1'));
		$span['end_now']             = strtotime(get_request_var('date2'));

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
		// Persist custom dates so page navigation preserves the filter.
		$_SESSION[$session_prefix . '_date1'] = get_request_var('date1');
		$_SESSION[$session_prefix . '_date2'] = get_request_var('date2');
		set_request_var('custom', true);
	} else {
		// Page navigation: custom dates were set earlier; restore from session.
		if (get_request_var('predefined_timespan') == 0) {
			set_request_var('date1', $_SESSION[$session_prefix . '_date1']);
			set_request_var('date2', $_SESSION[$session_prefix . '_date2']);
			set_request_var('custom', true);
		} else {
			// Session keys missing; fall back to a fresh span calculation.
			$first_weekdayid = read_user_setting('first_weekdayid');
			$default         = get_request_var('predefined_timespan') ?? 7;
			$span            = [];
			get_timespan($span, time(), $default, $first_weekdayid);
			set_request_var('date1', date('Y-m-d H:i:s', $span['begin_now']));
			set_request_var('date2', date('Y-m-d H:i:s', $span['end_now']));
			set_request_var('custom', false);
		}
	}
}

function get_syslog_messages(&$sql_where, $rows, $tab) {
	global $sql_where, $hostfilter, $hostfilter_log, $current_tab, $syslog_incoming_config;
	global $syslogdb_default;

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

				if ($hostfilter_log != '') {
					$sql_where .= 'WHERE ' . $hostfilter_log;
				}
			}

			if ($thold_pos !== false) {
				$ids = array_rekey(
					syslog_db_fetch_assoc("SELECT id
						FROM `$syslogdb_default`.`syslog_alert`
						WHERE method = 1"),
					'id', 'id'
				);

				if (cacti_sizeof($ids)) {
					$sql_where .= ($sql_where == '' ? 'WHERE ' : ' OR ') . 'alert_id IN (' . implode(', ', $ids) . ')';
				} elseif ($sql_where == '') {
					$sql_where .= 'WHERE 0 = 1';
				}
			}
		}
	} elseif ($tab == 'syslog') {
		if (!isempty_request_var('host')) {
			sql_hosts_where($tab);

			if ($hostfilter != '') {
				$sql_where .= 'WHERE ' . $hostfilter;
			}
		}
	}

	// Keep host alternatives inside the restrictions applied below.
	if ($sql_where !== '') {
		$sql_where = 'WHERE (' . substr($sql_where, 6) . ')';
	}

	if (get_request_var('search_mode') !== 'logical') {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') .
			"logtime BETWEEN '" . get_request_var('date1') . "'
				AND '" . get_request_var('date2') . "'";
	}


	if (isset_request_var('id') && $current_tab == 'current') {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') .
			'sa.id=' . get_request_var('id');
	}

	if (get_request_var('search_mode') === 'logical') {
		$predicate = !empty($GLOBALS['syslog_search_error']) ? '(1 = 0)' :
			syslog_logical_search_sql($GLOBALS['syslog_search_tree'] ?? null, $tab == 'syslog' ? 'message' : 'logmsg');
		if ($predicate !== '') {
			$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . $predicate;
		}
	} elseif (!isempty_request_var('rfilter')) {
		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') .
			($tab == 'syslog' ? 'message' : 'logmsg') . ' RLIKE ' . db_qstr(get_request_var('rfilter'));
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

		$sql_where .= ($sql_where == '' ? 'WHERE ' : ' AND ') . 'syslog.priority_id ' . $priorities;
	}

	$sql_where = api_plugin_hook_function('syslog_sqlwhere', $sql_where);

	$sql_order = get_order_string();

	if (!isset_request_var('export')) {
		$sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;
	} else {
		$sql_limit = ' LIMIT 10000';
	}

	if ($tab == 'syslog') {
		// Check if grouping is enabled
		$grouping_enabled = isset_request_var('grouping') && get_request_var('grouping') == '1';

		if ($grouping_enabled) {
			if (get_request_var('removal') == '-1') {
				$query_sql = "SELECT syslog.host_id, syslog.message, syslog.program_id,
					syslog.facility_id, syslog.priority_id, syslog_programs.program,
					'main' AS mtype, COUNT(*) AS occurrence_count, MIN(syslog.logtime) AS first_logtime,
					MAX(syslog.logtime) AS logtime, MIN(syslog.seq) AS seq,
					GROUP_CONCAT(syslog.seq ORDER BY syslog.logtime DESC SEPARATOR ',') AS seq_list
					FROM `$syslogdb_default`.`syslog`
					LEFT JOIN `$syslogdb_default`.`syslog_programs`
					ON syslog.program_id=syslog_programs.program_id
					$sql_where
					GROUP BY syslog.host_id, syslog.message, syslog.program_id, syslog.facility_id, syslog.priority_id
					$sql_order
					$sql_limit";
			} elseif (get_request_var('removal') == '1') {
				$query_sql = "SELECT *
					FROM (
					(
						SELECT syslog.host_id, syslog.message, syslog.program_id,
						syslog.facility_id, syslog.priority_id, syslog_programs.program,
						'main' AS mtype, COUNT(*) AS occurrence_count, MIN(syslog.logtime) AS first_logtime,
						MAX(syslog.logtime) AS logtime, MIN(syslog.seq) AS seq,
						GROUP_CONCAT(syslog.seq ORDER BY syslog.logtime DESC SEPARATOR ',') AS seq_list
						FROM `$syslogdb_default`.`syslog` AS syslog
						LEFT JOIN `$syslogdb_default`.`syslog_programs`
						ON syslog.program_id=syslog_programs.program_id
						$sql_where
						GROUP BY syslog.host_id, syslog.message, syslog.program_id, syslog.facility_id, syslog.priority_id
					) UNION (
						SELECT syslog.host_id, syslog.message, syslog.program_id,
						syslog.facility_id, syslog.priority_id, syslog_programs.program,
						'remove' AS mtype, COUNT(*) AS occurrence_count, MIN(syslog.logtime) AS first_logtime,
						MAX(syslog.logtime) AS logtime, MIN(syslog.seq) AS seq,
						GROUP_CONCAT(syslog.seq ORDER BY syslog.logtime DESC SEPARATOR ',') AS seq_list
						FROM `$syslogdb_default`.`syslog_removed` AS syslog
						LEFT JOIN `$syslogdb_default`.`syslog_programs`
						ON syslog.program_id=syslog_programs.program_id
						$sql_where
						GROUP BY syslog.host_id, syslog.message, syslog.program_id, syslog.facility_id, syslog.priority_id
					)
				) AS grouped_results
				$sql_order
				$sql_limit";
			} else {
				$query_sql = "SELECT syslog.host_id, syslog.message, syslog.program_id,
					syslog.facility_id, syslog.priority_id, syslog_programs.program,
					'remove' AS mtype, COUNT(*) AS occurrence_count, MIN(syslog.logtime) AS first_logtime,
					MAX(syslog.logtime) AS logtime, MIN(syslog.seq) AS seq,
					GROUP_CONCAT(syslog.seq ORDER BY syslog.logtime DESC SEPARATOR ',') AS seq_list
					FROM `$syslogdb_default`.`syslog_removed` AS syslog
					LEFT JOIN `$syslogdb_default`.`syslog_programs` AS syslog_programs
					ON syslog.program_id = syslog_programs.program_id
					$sql_where
					GROUP BY syslog.host_id, syslog.message, syslog.program_id, syslog.facility_id, syslog.priority_id
					$sql_order
					$sql_limit";
			}
		} else {
			// Original non-grouped queries
			if (get_request_var('removal') == '-1') {
				$query_sql = "SELECT `syslog`.*, `syslog_programs`.`program`, 'main' AS mtype
					FROM `$syslogdb_default`.`syslog`
					LEFT JOIN `$syslogdb_default`.`syslog_programs`
					ON syslog.program_id = syslog_programs.program_id
					$sql_where
					$sql_order
					$sql_limit";
			} elseif (get_request_var('removal') == '1') {
				$query_sql = "(
						SELECT `syslog`.*, `syslog_programs`.`program`, 'main' AS mtype
						FROM `$syslogdb_default`.`syslog` AS syslog
						LEFT JOIN `$syslogdb_default`.`syslog_programs`
						ON syslog.program_id=syslog_programs.program_id
						$sql_where
					) UNION (
						SELECT `syslog`.*, `syslog_programs`.`program`, 'remove' AS mtype
						FROM `$syslogdb_default`.`syslog_removed` AS syslog
						LEFT JOIN `$syslogdb_default`.`syslog_programs`
						ON syslog.program_id = syslog_programs.program_id
						$sql_where
					)
					$sql_order
					$sql_limit";
			} else {
				$query_sql = "SELECT `syslog`.*, `syslog_programs`.`program`, 'remove' AS mtype
					FROM `$syslogdb_default`.`syslog_removed` AS syslog
					LEFT JOIN `$syslogdb_default`.`syslog_programs` AS syslog_programs
					ON syslog.program_id = syslog_programs.program_id
					$sql_where
					$sql_order
					$sql_limit";
			}
		}
	} else {
		$query_sql = "SELECT `syslog`.*, `sf`.`facility`, `sp`.`priority`, `spr`.`program`, `sa`.`name`, `sa`.`severity`
			FROM `$syslogdb_default`.`syslog_logs` AS syslog
			LEFT JOIN `$syslogdb_default`.`syslog_facilities` AS sf
			ON syslog.facility_id=sf.facility_id
			LEFT JOIN `$syslogdb_default`.`syslog_priorities` AS sp
			ON syslog.priority_id=sp.priority_id
			LEFT JOIN `$syslogdb_default`.`syslog_alert` AS sa
			ON syslog.alert_id=sa.id
			LEFT JOIN `$syslogdb_default`.`syslog_programs` AS spr
			ON syslog.program_id=spr.program_id
			$sql_where
			$sql_order
			$sql_limit";
	}

	//print $query_sql;

	return syslog_db_fetch_assoc($query_sql);
}

function syslog_filter($sql_where, $tab) {
	global $config, $page_refresh_interval, $item_rows;
	global $syslogdb_default;

	$unprocessed = syslog_db_fetch_cell("SELECT COUNT(*) FROM `$syslogdb_default`.`syslog_incoming`");

	$filter_text = __esc('[ Unprocessed Messages: %s ]', $unprocessed, 'syslog');

	// Shared search builder data for the main panel and the saved search dialog.
	$saved_fields = syslog_search_fields();

	if ($tab != 'syslog') {
		unset($saved_fields['host_id']);
	}

	$saved_choices_json = html_escape(json_encode(syslog_search_choices()));
	$saved_fields_json  = html_escape(json_encode($saved_fields));
	$saved_tree_json    = html_escape(json_encode($GLOBALS['syslog_search_tree'] ?? null));

	$username = get_username($_SESSION['sess_user_id']);

	$saved_searches = syslog_db_fetch_assoc_prepared("SELECT id, name, `user`, is_global
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE `user` = ? OR is_global = 'on'
		ORDER BY is_global, name",
		[$username]);

	$saved_active = (int) ($_SESSION['sess_sl_' . $tab . '_saved'] ?? 0);
	$saved_share  = syslog_saved_search_share();

	?>
	<script type='text/javascript'>
	initSyslogMain({
		pageTab: '<?php print get_request_var('tab'); ?>'
	});
	</script>
	<?php

	html_start_box(__('Syslog Message Filter %s', $filter_text, 'syslog'), '100%', '', '3', 'center', ''); ?>
		<tr class='even noprint syslogFilterRow'>
			<td class='noprint'>
			<form id='syslog_form' data-theme='<?php print html_escape(get_selected_theme()); ?>' action='syslog.php' method='post'>
				<section class='syslogSearchPanel' aria-labelledby='syslog_search_title'>
					<div class='syslogSearchHeader'>
						<div class='syslogSearchHeading'>
							<span class='syslogSearchIcon' aria-hidden='true'><i class='fa fa-search'></i></span>
							<h3 id='syslog_search_title'><?php print __esc('Search messages', 'syslog'); ?></h3>
						</div>
						<button type='button' id='syslog_search_toggle' class='syslogSearchToggle'
							aria-expanded='true' aria-controls='syslog_search_content'
							aria-label='<?php print __esc('Collapse filters', 'syslog'); ?>'
							title='<?php print __esc('Collapse filters', 'syslog'); ?>'
							data-hide='<?php print __esc('Collapse filters', 'syslog'); ?>'
							data-show='<?php print __esc('Edit filters', 'syslog'); ?>'
							onclick='toggleSyslogSearch()'><span><?php print __esc('Collapse filters', 'syslog'); ?></span><i class='fa fa-chevron-up' aria-hidden='true'></i></button>
						<input type='hidden' id='search_mode' value='logical'>
					</div>
					<div class='syslogSearchSavedBar'>
						<label for='saved_search'><?php print __('Saved Searches', 'syslog'); ?></label>
						<select id='saved_search'>
							<?php
							$saved_groups = [
								__('My Searches', 'syslog')   => [],
								__('Global Searches', 'syslog') => []
							];

							foreach ($saved_searches as $saved) {
								$saved_groups[$saved['is_global'] === 'on' ? __('Global Searches', 'syslog') : __('My Searches', 'syslog')][] = $saved;
							}

							foreach ($saved_groups as $saved_label => $saved_group) {
								if (cacti_sizeof($saved_group)) {
									print "<optgroup label='" . html_escape($saved_label) . "'>";

									foreach ($saved_group as $saved) {
										print "<option value='" . $saved['id'] . "'" . ($saved_active === (int) $saved['id'] ? ' selected' : '') . '>' .
											html_escape($saved['name']) . '</option>';
									}

									print '</optgroup>';
								}
							}
							?>
						</select>
						<details class='syslogMenu'><summary aria-label='<?php print __esc('Manage saved searches', 'syslog'); ?>' title='<?php print __esc('Manage saved searches', 'syslog'); ?>'><?php print __esc('Manage', 'syslog'); ?></summary><div class='syslogMenuBody'>
							<input id='saved_saveas' type='button' value='<?php print __esc('Save search', 'syslog'); ?>'>
							<input id='saved_new' type='button' value='<?php print __esc('New', 'syslog'); ?>'>
							<input id='saved_edit' type='button' value='<?php print __esc('Edit', 'syslog'); ?>'>
							<input id='saved_delete' type='button' value='<?php print __esc('Delete', 'syslog'); ?>'>
							<?php if ($saved_share) { ?>
							<input id='saved_global' type='button' value='<?php print $saved_active ? __esc('Make Private', 'syslog') : __esc('Save for all', 'syslog'); ?>'>
							<?php } ?>
						</div></details>
					</div>
					<div id='syslog_search_summary' hidden></div>
					<div id='syslog_search_content'>
					<input type='hidden' id='rfilter' size='40' aria-label='<?php print __esc('Search messages', 'syslog'); ?>' value='<?php print html_escape_request_var('rfilter'); ?>'>
					<div id='syslog_search_builder' class='syslogSearchBuilder'
						data-choices='<?php print $saved_choices_json; ?>'
						data-fields='<?php print $saved_fields_json; ?>'
						data-tree='<?php print $saved_tree_json; ?>'
						data-message='<?php print __esc('Message', 'syslog'); ?>'
						data-placeholder='<?php print __esc('Enter message text…', 'syslog'); ?>'
						data-contains='<?php print __esc('contains', 'syslog'); ?>'
						data-not-contains='<?php print __esc('does not contain', 'syslog'); ?>'
						data-remove='<?php print __esc('Remove condition', 'syslog'); ?>'
						data-match='<?php print __esc('Match group', 'syslog'); ?>'
						data-exclude='<?php print __esc('Exclude group', 'syslog'); ?>'>
					</div>
					<div class='syslogSearchOption syslogResultsLimit'>
						<label for='rows'><?php print __('Results limit', 'syslog'); ?></label>
						<select id='rows' onChange='applyFilter()' title='<?php print __esc('Display Rows', 'syslog'); ?>'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') { ?> selected<?php } ?>><?php print __('Default', 'syslog'); ?></option>
							<?php
							foreach ($item_rows as $rows => $display_text) {
								print "<option value='" . $rows . "'";

								if (get_request_var('rows') == $rows) {
									print ' selected';
								}

								print '>' . $display_text . '</option>';
							}
							?>
						</select>
					</div>
					<div id='logical_search_error' role='alert'><?php print html_escape($GLOBALS['syslog_search_error'] ?? ''); ?></div>
					<details id='syslog_view_options' class='syslogMenu'><summary><?php print __esc('View options', 'syslog'); ?></summary><div class='syslogMenuBody syslogSearchOptions'>
						<div class='syslogSearchOption'>
							<label for='refresh'><?php print __('Refresh', 'syslog'); ?></label>
							<select id='refresh' onChange='applyFilter()'>
								<?php
								foreach ($page_refresh_interval as $seconds => $display_text) {
									print "<option value='" . $seconds . "'";

									if (get_request_var('refresh') == $seconds) {
										print ' selected';
									}

									print '>' . $display_text . '</option>';
								}
								?>
							</select>
						</div>
						<?php if (get_nfilter_request_var('tab') == 'syslog') { ?>
						<div class='syslogSearchOption'>
							<label for='removal'><?php print __('Record Type', 'syslog'); ?></label>
							<select id='removal' onChange='applyFilter()' title='<?php print __esc('Removal Handling', 'syslog'); ?>'>
								<option value='1'<?php if (get_request_var('removal') == '1') { ?> selected<?php } ?>><?php print __('All Records', 'syslog'); ?></option>
								<option value='-1'<?php if (get_request_var('removal') == '-1') { ?> selected<?php } ?>><?php print __('Main Records', 'syslog'); ?></option>
								<option value='2'<?php if (get_request_var('removal') == '2') { ?> selected<?php } ?>><?php print __('Removed Records', 'syslog'); ?></option>
							</select>
						</div>
						<?php } else { ?>
						<input type='hidden' id='removal' value='<?php print html_escape_request_var('removal'); ?>'>
						<?php } ?>
						<?php if (get_nfilter_request_var('tab') == 'syslog') { ?>
						<div class='syslogSearchOption'>
							<label for='grouping'><?php print __('Display', 'syslog'); ?></label>
							<select id='grouping' onChange='applyFilter()' title='<?php print __esc('Group Duplicate Messages', 'syslog'); ?>'>
								<option value='0'<?php if (get_request_var('grouping') == '0') { ?> selected<?php } ?>><?php print __('Individual Messages', 'syslog'); ?></option>
								<option value='1'<?php if (get_request_var('grouping') == '1') { ?> selected<?php } ?>><?php print __('Grouped Messages', 'syslog'); ?></option>
							</select>
						</div>
						<?php } else { ?>
						<input type='hidden' id='grouping' value='0'>
						<?php } ?>
						<div class='syslogSearchOptionHook'><?php api_plugin_hook('syslog_extend_filter'); ?></div>
					</div>
					</details>
					<div class='syslogSearchFooter'>
						<details id='logical_search_help'><summary><?php print __esc('Search help', 'syslog'); ?></summary><?php print __esc('Choose a field, operator, and value. AND takes precedence over OR; NOT excludes a condition. LIKE uses % for any number of characters and _ for one character. Dates use YYYY-MM-DD HH:MM:SS. IDs use nonnegative integers. By default, the last day of logs is shown unless the date range is adjusted.', 'syslog'); ?></details>
					</div>
					</div>
				</section>
				<div id='syslog_saved_dialog' class='syslogSavedDialog' style='display:none'
					data-new-title='<?php print __esc('New Saved Search', 'syslog'); ?>'
					data-edit-title='<?php print __esc('Edit Saved Search', 'syslog'); ?>'
					data-saveas='<?php print __esc('Save As', 'syslog'); ?>'
					data-apply='<?php print __esc('Apply', 'syslog'); ?>'
					data-cancel='<?php print __esc('Cancel', 'syslog'); ?>'
					data-save='<?php print __esc('Save', 'syslog'); ?>'
					data-delete-confirm='<?php print __esc('Delete the selected saved search?', 'syslog'); ?>'>
					<div id='syslog_saved_builder' class='syslogSearchBuilder'
						data-choices='<?php print $saved_choices_json; ?>'
						data-fields='<?php print $saved_fields_json; ?>'
						data-message='<?php print __esc('Message', 'syslog'); ?>'
						data-placeholder='<?php print __esc('Enter message text…', 'syslog'); ?>'
						data-contains='<?php print __esc('contains', 'syslog'); ?>'
						data-not-contains='<?php print __esc('does not contain', 'syslog'); ?>'
						data-remove='<?php print __esc('Remove condition', 'syslog'); ?>'
						data-match='<?php print __esc('Match group', 'syslog'); ?>'
						data-exclude='<?php print __esc('Exclude group', 'syslog'); ?>'>
					</div>
				</div>
				<div id='syslog_saved_prompt' class='syslogSavedPrompt' title='<?php print __esc('Save Search As', 'syslog'); ?>' style='display:none'>
					<div class='syslogSavedNameRow'>
						<label for='syslog_saved_prompt_name'><?php print __('Name', 'syslog'); ?></label>
						<input type='text' id='syslog_saved_prompt_name' size='40' maxlength='128'>
					</div>
				</div>
				<table class='filterTable syslogSearchButtons'>
					<tr>
						<td>
							<span>
								<input id='go' type='button' value='<?php print __esc('Search', 'syslog'); ?>'>
								<input id='clear' type='button' value='<?php print __esc('Clear', 'syslog'); ?>' title='<?php print __esc('Return filter values to their user defined defaults', 'syslog'); ?>'>
								<input id='refresh_results' type='button' value='<?php print __esc('Refresh', 'syslog'); ?>' title='<?php print __esc('Refresh Results', 'syslog'); ?>'>
								<input id='export' type='button' value='<?php print __esc('Export', 'syslog'); ?>' title='<?php print __esc('Export Records to CSV', 'syslog'); ?>'>
								<input id='save' type='button' value='<?php print __esc('Save view defaults', 'syslog'); ?>' title='<?php print __esc('Save Default Settings', 'syslog'); ?>'>
							</span>
						</td>
						<td>
							<span id='text'></span>
							<input type='hidden' name='action' value='actions'>
							<input type='hidden' name='syslog_pdt_change' value='false'>
						</td>
					</tr>
				</table>
				<input type='hidden' id='page' value='<?php print get_filter_request_var('page'); ?>'>
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
 * @param mixed $hostname
 */
function syslog_strip_domain($hostname) {
	if (strpos($hostname, '.') === false) {
		return $hostname;
	}

	if (filter_var($hostname, FILTER_VALIDATE_IP)) {
		return $hostname;
	} else {
		$parts = explode('.', $hostname);

		foreach ($parts as $part) {
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
	print "<td width='10%' class='logCritical'>" . __('Critical', 'syslog') . '</td>';
	print "<td width='10%' class='logAlert'>" . __('Alert', 'syslog') . '</td>';
	print "<td width='10%' class='logError'>" . __('Error', 'syslog') . '</td>';
	print "<td width='10%' class='logWarning'>" . __('Warning', 'syslog') . '</td>';
	print "<td width='10%' class='logNotice'>" . __('Notice', 'syslog') . '</td>';
	print "<td width='10%' class='logInfo'>" . __('Info', 'syslog') . '</td>';
	print "<td width='10%' class='logDebug'>" . __('Debug', 'syslog') . '</td>';
	print '</tr>';
	html_end_box(false);
}

/**
 * function syslog_log_legend()
 * This function displays the foreground and background colors for the syslog log legend
 */
function syslog_log_legend() {
	global $disabled_color, $notmon_color, $database_default;

	html_start_box('', '100%', '', '3', 'center', '');
	print '<tr class="">';
	print "<td width='10%' class='logCritical'>" . __('Critical', 'syslog') . '</td>';
	print "<td width='10%' class='logWarning'>" . __('Warning', 'syslog') . '</td>';
	print "<td width='10%' class='logNotice'>" . __('Notice', 'syslog') . '</td>';
	print "<td width='10%' class='logInfo'>" . __('Informational', 'syslog') . '</td>';
	print '</tr>';
	html_end_box(false);
}

/**
 * function syslog_messages()
 * This is the main page display function in Syslog.  Displays all the
 * syslog messages that are relevant to Syslog.
 *
 * @param mixed $tab
 */
function syslog_messages($tab = 'syslog') {
	global $sql_where, $hostfilter, $severities;
	global $config, $syslog_incoming_config, $reset_multi, $syslog_levels;
	global $syslogdb_default;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	include('./include/global_arrays.php');

	// force the initial timespan to be 30 minutes for performance reasons
	if (!isset($_SESSION['sess_syslog_init'])) {
		$_SESSION['sess_current_timespan'] = 1;
		$_SESSION['sess_syslog_init']      = 1;
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
	print "<div id='syslog_workspace' data-theme='" . html_escape(get_selected_theme()) . "'><div class='syslogResultsMain'>";

	if ($tab == 'syslog') {
		// Check if grouping is enabled for row count
		$grouping_enabled = isset_request_var('grouping') && get_request_var('grouping') == '1';

		if ($grouping_enabled) {
			// When grouping, count distinct groups instead of individual rows
			if (get_request_var('removal') == 1) {
				$total_rows = syslog_db_fetch_cell("SELECT SUM(totals)
					FROM (
						SELECT COUNT(DISTINCT CONCAT(host_id, '|', message, '|', program_id, '|', facility_id, '|', priority_id)) AS totals
						FROM `$syslogdb_default`.`syslog` AS syslog
						$sql_where
						UNION
						SELECT COUNT(DISTINCT CONCAT(host_id, '|', message, '|', program_id, '|', facility_id, '|', priority_id)) AS totals
						FROM `$syslogdb_default`.`syslog_removed` AS syslog
						$sql_where
					) AS rowcount");
			} elseif (get_request_var('removal') == -1) {
				$total_rows = syslog_db_fetch_cell("SELECT COUNT(DISTINCT CONCAT(host_id, '|', message, '|', program_id, '|', facility_id, '|', priority_id))
					FROM `$syslogdb_default`.`syslog` AS syslog
					$sql_where");
			} else {
				$total_rows = syslog_db_fetch_cell("SELECT COUNT(DISTINCT CONCAT(host_id, '|', message, '|', program_id, '|', facility_id, '|', priority_id))
					FROM `$syslogdb_default`.`syslog_removed` AS syslog
					$sql_where");
			}
		} else {
			// Original non-grouped row counting
			if (get_request_var('removal') == 1) {
				$total_rows = syslog_db_fetch_cell("SELECT SUM(totals)
					FROM (
						SELECT COUNT(*) AS totals
						FROM `$syslogdb_default`.`syslog` AS syslog
						$sql_where
						UNION
						SELECT COUNT(*) AS totals
						FROM `$syslogdb_default`.`syslog_removed` AS syslog
						$sql_where
					) AS rowcount");
			} elseif (get_request_var('removal') == -1) {
				$total_rows = syslog_db_fetch_cell("SELECT COUNT(*)
					FROM `$syslogdb_default`.`syslog` AS syslog
					$sql_where");
			} else {
				$total_rows = syslog_db_fetch_cell("SELECT COUNT(*)
					FROM `$syslogdb_default`.`syslog_removed` AS syslog
					$sql_where");
			}
		}
	} else {
		$total_rows = syslog_db_fetch_cell("SELECT COUNT(*)
			FROM `$syslogdb_default`.`syslog_logs` AS syslog
			LEFT JOIN `$syslogdb_default`.`syslog_facilities` AS sf
			ON syslog.facility_id=sf.facility_id
			LEFT JOIN `$syslogdb_default`.`syslog_priorities` AS sp
			ON syslog.priority_id=sp.priority_id
			LEFT JOIN `$syslogdb_default`.`syslog_alert` AS sa
			ON syslog.alert_id=sa.id
			LEFT JOIN `$syslogdb_default`.`syslog_programs` AS spr
			ON syslog.program_id=spr.program_id
			$sql_where");
	}

	if ($tab == 'syslog') {
		// Check if grouping is enabled for display
		$grouping_enabled = isset_request_var('grouping') && get_request_var('grouping') == '1';

		$display_text = [
			'logtime'     => [__('Date', 'syslog'), 'ASC'],
			'host_id'     => [__('Device', 'syslog'), 'ASC'],
			'program'     => [__('Program', 'syslog'), 'ASC'],
			'message'     => [__('Message', 'syslog'), 'ASC'],
			'facility_id' => [__('Facility', 'syslog'), 'ASC'],
			'priority_id' => [__('Priority', 'syslog'), 'ASC']];

		// Add count column if grouping is enabled
		if ($grouping_enabled) {
			$display_text['occurrence_count'] = [__('Count', 'syslog'), 'DESC'];
		}
		$nav = html_nav_bar("syslog.php?tab=$tab", MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, cacti_sizeof($display_text), __('Messages', 'syslog'), 'page', 'main');

		print $nav;

		html_start_box('', '100%', '', '3', 'center', '');

		html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

		$hosts = array_rekey(
			syslog_db_fetch_assoc("SELECT host_id, host
				FROM `$syslogdb_default`.`syslog_hosts`"),
			'host_id', 'host'
		);

		$facilities = array_rekey(
			syslog_db_fetch_assoc("SELECT facility_id, facility
				FROM `$syslogdb_default`.`syslog_facilities`"),
			'facility_id', 'facility'
		);

		$priorities = array_rekey(
			syslog_db_fetch_assoc("SELECT priority_id, priority
				FROM `$syslogdb_default`.`syslog_priorities`"),
			'priority_id', 'priority'
		);

		if (cacti_sizeof($syslog_messages)) {
			foreach ($syslog_messages as $sm) {
				if (is_null($sm[$syslog_incoming_config['textField']])) {
					$sm[$syslog_incoming_config['textField']] = __('Empty message', 'syslog');
				}

				$title = html_escape($sm['message']);

				syslog_row_color($sm['priority_id'], $sm['message']);


				// Display grouped or individual messages
				if ($grouping_enabled && isset($sm['occurrence_count']) && $sm['occurrence_count'] > 1) {
					// Grouped message display with expand/collapse
					$expand_icon = "<i class='fas fa-chevron-down syslog-group-toggle' data-seq='" . html_escape($sm['seq']) . "' style='cursor:pointer; margin-right:5px;'></i>";
					form_selectable_cell($expand_icon . $sm['logtime'], $sm['seq'], '', 'left');
				} else {
					form_selectable_cell($sm['logtime'], $sm['seq'], '', 'left');
				}

				print "<td class='nowrap left syslogMeta'>" . syslog_value_filter_button($hosts[$sm['host_id']] ?? __('Unknown', 'syslog'), 'host') . '</td>';
				print "<td class='nowrap left syslogMeta'>" . syslog_value_filter_button($sm['program'], 'program') . '</td>';
				// Group summaries show the latest timestamp; use its matching sequence ID.
				$rule_id = $grouping_enabled && !empty($sm['seq_list']) ? explode(',', $sm['seq_list'])[0] : $sm[$syslog_incoming_config['id']];
				form_selectable_cell(syslog_message_button($sm['message'], $hosts[$sm['host_id']] ?? '', $sm['program'], $facilities[$sm['facility_id']] ?? '', $priorities[$sm['priority_id']] ?? '', $sm['logtime'], $rule_id, $sm['mtype']), $sm['seq'], '', 'left syslogMessage');
				form_selectable_cell(syslog_metadata_label(isset($facilities[$sm['facility_id']]) ? $facilities[$sm['facility_id']] : __('Unknown', 'syslog'), 'facility'), $sm['seq'], '', 'left');
				form_selectable_cell(syslog_value_filter_button($priorities[$sm['priority_id']] ?? __('Unknown', 'syslog'), 'priority'), $sm['seq'], '', 'left');

				// Add occurrence count if grouping is enabled
				if ($grouping_enabled) {
					form_selectable_cell(isset($sm['occurrence_count']) ? $sm['occurrence_count'] : 1, $sm['seq'], '', 'right');
				}

				form_end_row();

				// If grouping is enabled and there are multiple occurrences, add hidden detail rows
				if ($grouping_enabled && isset($sm['occurrence_count']) && $sm['occurrence_count'] > 1 && isset($sm['seq_list'])) {
					$seq_array = explode(',', $sm['seq_list']);

					// Get individual messages for this group
					$detail_messages = syslog_db_fetch_assoc("SELECT `syslog`.*, `syslog_programs`.`program`
						FROM `$syslogdb_default`.`" . (($sm['mtype'] == 'main') ? 'syslog' : 'syslog_removed') . "` AS syslog
						LEFT JOIN `$syslogdb_default`.`syslog_programs`
						ON syslog.program_id = syslog_programs.program_id
						WHERE syslog.seq IN (" . implode(',', array_map('intval', $seq_array)) . ')
						ORDER BY syslog.logtime DESC');

					if (cacti_sizeof($detail_messages)) {
						foreach ($detail_messages as $dm) {
							print "<tr class='tableRow syslogRow syslog-detail-row syslog-detail-" . html_escape($sm['seq']) . "' style='display:none;' data-parent='" . html_escape($sm['seq']) . "'>";


							print "<td class='left' style='padding-left:30px;'>" . html_escape($dm['logtime'], $dm[$syslog_incoming_config['id']], $sm['mtype']) . '</td>';
							print "<td class='nowrap left'>" . syslog_value_filter_button($hosts[$dm['host_id']] ?? __('Unknown', 'syslog'), 'host') . '</td>';
							print "<td class='nowrap left'>" . syslog_value_filter_button($dm['program'], 'program') . '</td>';
							print "<td class='left syslogMessage'>" . syslog_message_button($dm['message'], $hosts[$dm['host_id']] ?? '', $dm['program'], $facilities[$dm['facility_id']] ?? '', $priorities[$dm['priority_id']] ?? '', $dm['logtime'], $dm[$syslog_incoming_config['id']], $sm['mtype']) . '</td>';
							print "<td class='left'>" . syslog_metadata_label(isset($facilities[$dm['facility_id']]) ? $facilities[$dm['facility_id']] : __('Unknown', 'syslog'), 'facility') . '</td>';
							print "<td class='left'>" . syslog_value_filter_button($priorities[$dm['priority_id']] ?? __('Unknown', 'syslog'), 'priority') . '</td>';

							if ($grouping_enabled) {
								print "<td class='right'></td>";
							}

							print '</tr>';
						}
					}
				}
			}
		} else {
			print "<tr><td class='center' colspan='" . (cacti_sizeof($display_text)) . "'><em>" . __('No Syslog Messages', 'syslog') . '</em></td></tr>';
		}

		html_end_box(false);

		if (cacti_sizeof($syslog_messages)) {
			print $nav;
		}


		syslog_syslog_legend();
		?>
		<script type='text/javascript'>
		initSyslogMessagesDisplay();
		initSyslogValueFilters();
		</script>
		<?php
	} else {
		$display_text = [
			'name'        => ['display' => __('Alert Name', 'syslog'), 'sort' => 'ASC', 'align' => 'left'],
			'severity'    => ['display' => __('Severity', 'syslog'),   'sort' => 'ASC', 'align' => 'left'],
			'logtime'     => ['display' => __('Date', 'syslog'),	   'sort' => 'ASC', 'align' => 'left'],
			'logmsg'      => ['display' => __('Message', 'syslog'),	'sort' => 'ASC', 'align' => 'left'],
			'count'       => ['display' => __('Count', 'syslog'),	  'sort' => 'ASC', 'align' => 'right'],
			'host'        => ['display' => __('Device', 'syslog'),	 'sort' => 'ASC', 'align' => 'right'],
			'facility_id' => ['display' => __('Facility', 'syslog'),   'sort' => 'ASC', 'align' => 'right'],
			'priority_id' => ['display' => __('Priority', 'syslog'),   'sort' => 'ASC', 'align' => 'right']
		];

		$nav = html_nav_bar("syslog.php?tab=$tab", MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, cacti_sizeof($display_text), __('Alert Log Rows', 'syslog'), 'page', 'main');

		print $nav;

		html_start_box('', '100%', '', '3', 'center', '');

		html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

		if (cacti_sizeof($syslog_messages)) {
			foreach ($syslog_messages as $log) {
				$title   = html_escape($log['logmsg']);

				syslog_log_row_color($log['severity'], $title);

				form_selectable_cell(filter_value($log['name'] != '' ? $log['name'] : __('Alert Removed', 'syslog'), get_request_var('rfilter'), $config['url_path'] . 'plugins/syslog/syslog.php?id=' . $log['seq'] . '&tab=current'), $log['seq'], '', 'left');

				form_selectable_cell(isset($severities[$log['severity']]) ? $severities[$log['severity']] : __('Unknown', 'syslog'), $log['seq'], '', 'left');
				form_selectable_cell($log['logtime'], $log['seq'], '', 'left');
				form_selectable_cell(syslog_message_button($log['logmsg'], $log['host'], $log['program'] ?? '', $log['facility'], $log['priority'], $log['logtime']), $log['seq'], '', 'syslogMessage left');

				form_selectable_cell($log['count'], $log['seq'], '', 'right');
				print "<td class='nowrap right'>" . syslog_value_filter_button($log['host'], 'host') . '</td>';
				form_selectable_cell(ucfirst($log['facility']), $log['seq'], '', 'right');
				print "<td class='nowrap right'>" . syslog_value_filter_button(ucfirst($log['priority']), 'priority') . '</td>';

				form_end_row();
			}
		} else {
			print "<tr><td colspan='" . (cacti_sizeof($display_text)) . "'><em>" . __('No Alert Log Messages', 'syslog') . '</em></td></tr>';
		}

		html_end_box(false);

		if (cacti_sizeof($syslog_messages)) {
			print $nav;
		}

		syslog_log_legend();
	}
	print '</div>';
	?>
	<aside id='syslog_message_details' hidden aria-labelledby='syslog_details_title' tabindex='-1'>
		<header class='ui-widget-header'><h3 id='syslog_details_title'><?php print __esc('Message details', 'syslog'); ?></h3><button type='button' class='ui-state-default' id='syslog_details_close' aria-label='<?php print __esc('Close message details', 'syslog'); ?>'>×</button></header>
		<dl><?php foreach (['received' => __('Date', 'syslog'), 'device' => __('Device', 'syslog'), 'program' => __('Program', 'syslog'), 'facility' => __('Facility', 'syslog'), 'severity' => __('Priority', 'syslog')] as $key => $label) { print '<dt>' . html_escape($label) . "</dt><dd data-detail='" . $key . "'></dd>"; } ?></dl>
		<h4><?php print __esc('Message', 'syslog'); ?></h4><pre id='syslog_details_raw'></pre>
		<div class='syslogDetailsActions'><button type='button' id='syslog_details_copy'><?php print __esc('Copy message', 'syslog'); ?></button><span id='syslog_copy_status' role='status' data-success='<?php print __esc('Copied', 'syslog'); ?>' data-error='<?php print __esc('Copy unavailable. Select and copy the message text.', 'syslog'); ?>'></span></div>
		<div class='syslogDetailsRules'><a data-rule='alarm' class='syslogRuleButton' hidden><?php print __esc('Create Alarm Rule', 'syslog'); ?></a><a data-rule='removal' class='syslogRuleButton' hidden><?php print __esc('Create Removal Rule', 'syslog'); ?></a></div>
		<div class='syslogDetailsFilters'><button type='button' data-filter-detail='host'><?php print __esc('Filter by device', 'syslog'); ?></button><button type='button' data-filter-detail='program'><?php print __esc('Filter by program', 'syslog'); ?></button></div>
	</aside></div>
	<script>initSyslogWorkspace();</script>
	<?php
}

function save_settings() {
	global $current_tab;

//	syslog_request_validation($current_tab);

	$variables = [
		'rows',
		'refresh',
		'removal',
		'efacility',
		'priority',
		'eprogram',
		'grouping',
		'predefined_timespan',
		'predefined_timeshift',
	];

	foreach ($variables as $v) {
		if (isset_request_var($v)) {
			// Accommodate predefined
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
		$program = syslog_db_fetch_cell_prepared('SELECT program
			FROM syslog_programs
			WHERE program_id = ?',
			[$program_id]);
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
	$return	    = [];
	$sql_params = [];

	$term = get_filter_request_var('term', FILTER_CALLBACK, ['options' => 'sanitize_search_string']);

	if ($term != '') {
		$sql_where .= ($sql_where != '' ? ' AND ' : 'WHERE ') . 'program LIKE ?';
		$sql_params[] = '%' . $term . '%';
	}

	if (get_request_var('term') == '') {
		if ($include_any) {
			$return[] = [
				'label' => __('All Programs', 'syslog'),
				'value' => __('All Programs', 'syslog'),
				'id'    => '-1'
			];
		}

		if ($include_none) {
			$return[] = [
				'label' => __('None', 'syslog'),
				'value' => __('None', 'syslog'),
				'id'    => '-2'
			];
		}
	}

	$programs = syslog_db_fetch_assoc_prepared("SELECT program_id, program
		FROM syslog_programs
		$sql_where
		ORDER BY program
		LIMIT 20", $sql_params);

	if (cacti_sizeof($programs)) {
		foreach ($programs as $program) {
			$return[] = [
				'label' => $program['program'],
				'value' => $program['program'],
				'id'    => $program['program_id']
			];
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
			$class .= ($class != '' ? ' ' : '') . 'txtErrorTextBox';
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
		print "<select id='" . html_escape($form_name) . "' name='" . html_escape($form_name) . "'" . $class . ($on_change != '' ? "onChange='$on_change'" : '') . '>';

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
		print '</span>';

		if (!empty($none_entry) && empty($previous_value)) {
			$previous_value = $none_entry;
		}

		print '</span>';
		print "<input type='hidden' id='" . $form_name . "' name='" . $form_name . "' value='" . html_escape($previous_id) . "'>";
		?>
		<style type='text/css'>
		.syslogMessage {
			white-space: normal !important;
		}
		</style>
		<script type='text/javascript'>
		initSyslogAutocomplete('<?php print $form_name; ?>', '<?php print $callback; ?>', '<?php print $on_change; ?>');
		</script>
		<?php
	}
}
