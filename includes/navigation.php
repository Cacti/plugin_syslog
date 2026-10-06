<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Navigation callbacks. */

/**
 * Draw the Syslog tab in the top header.
 *
 * @return void
 */
function syslog_show_tab(): void {
	global $config;

	if (!syslog_config_safe()) {
		return;
	}

	if (api_user_realm_auth('syslog.php')) {
		if (substr_count($_SERVER['REQUEST_URI'], 'syslog.php')) {
			print '<a href="' . $config['url_path'] . 'plugins/syslog/syslog.php"><img src="' . $config['url_path'] . 'plugins/syslog/images/tab_syslog_down.gif" alt="' . __('Syslog', 'syslog') . '"></a>';
		} else {
			print '<a href="' . $config['url_path'] . 'plugins/syslog/syslog.php"><img src="' . $config['url_path'] . 'plugins/syslog/images/tab_syslog.gif" alt="' . __('Syslog', 'syslog') . '"></a>';
		}
	}
}


/**
 * Add the Syslog pages to the Cacti navigation structure.
 *
 * @param array<string, array<string, mixed>> $nav The navigation array provided by Cacti.
 *
 * @return array<string, array<string, mixed>> The navigation array with the Syslog pages added.
 */
function syslog_draw_navigation_text($nav) {
	global $config;

	$nav['syslog.php:']                = ['title' => __('Syslog', 'syslog'), 'mapping' => '', 'url' => $config['url_path'] . 'plugins/syslog/syslog.php', 'level' => '1'];
	$nav['syslog_removal.php:']        = ['title' => __('Syslog Removals', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_removal.php', 'level' => '1'];
	$nav['syslog_removal.php:edit']    = ['title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_removal.php:', 'url' => 'syslog_removal.php', 'level' => '2'];
	$nav['syslog_removal.php:newedit'] = ['title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_removal.php:', 'url' => 'syslog_removal.php', 'level' => '2'];
	$nav['syslog_removal.php:actions'] = ['title' => __('(Actions)', 'syslog'), 'mapping' => 'index.php:,syslog_removal.php:', 'url' => 'syslog_removal.php', 'level' => '2'];

	$nav['syslog_alerts.php:']         = ['title' => __('Syslog Alerts', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_alerts.php', 'level' => '1'];
	$nav['syslog_alerts.php:edit']     = ['title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_alerts.php:', 'url' => 'syslog_alerts.php', 'level' => '2'];
	$nav['syslog_alerts.php:newedit']  = ['title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_alerts.php:', 'url' => 'syslog_alerts.php', 'level' => '2'];
	$nav['syslog_alerts.php:actions']  = ['title' => __('(Actions)', 'syslog'), 'mapping' => 'index.php:,syslog_alerts.php:', 'url' => 'syslog_alerts.php', 'level' => '2'];

	$nav['syslog_reports.php:']        = ['title' => __('Syslog Reports', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_reports.php', 'level' => '1'];
	$nav['syslog_reports.php:edit']    = ['title' => __('(Edit)', 'syslog'), 'mapping' => 'index.php:,syslog_reports.php:', 'url' => 'syslog_reports.php', 'level' => '2'];
	$nav['syslog_reports.php:actions'] = ['title' => __('(Actions)', 'syslog'), 'mapping' => 'index.php:,syslog_reports.php:', 'url' => 'syslog_reports.php', 'level' => '2'];
	$nav['syslog_saved_searches.php:'] = ['title' => __('Saved Search Templates', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_saved_searches.php', 'level' => '1'];
	$nav['syslog_dashboards.php:']     = ['title' => __('Dashboards', 'syslog'), 'mapping' => 'index.php:', 'url' => $config['url_path'] . 'plugins/syslog/syslog_dashboards.php', 'level' => '1'];
	$nav['syslog.php:actions']         = ['title' => __('Syslog', 'syslog'), 'mapping' => '', 'url' => $config['url_path'] . 'plugins/syslog/syslog.php', 'level' => '1'];

	return $nav;
}


/**
 * Display a link to the Syslog page filtered for the graphed device.
 *
 * @param array<int, array<string, mixed>> $graph_elements The graph elements provided by Cacti.
 *
 * @return void
 */
function syslog_graph_buttons($graph_elements = []): void {
	global $config, $timespan, $graph_timeshifts;

	if (!syslog_config_safe()) {
		return;
	}

	require_once(dirname(__DIR__) . '/setup.php');
	syslog_connect();

	if (get_nfilter_request_var('action') == 'view') {
		return;
	}

	if (get_current_page() == 'graph_view.php') {
		if (isset_request_var('graph_end') && strlen(get_filter_request_var('graph_end'))) {
			$date1 = date('Y-m-d H:i:s', get_filter_request_var('graph_start'));
			$date2 = date('Y-m-d H:i:s', get_filter_request_var('graph_end'));
		} else {
			$date1 = $timespan['current_value_date1'];
			$date2 = $timespan['current_value_date2'];
		}
	} else {
		return;
	}

	if (isset($graph_elements[1]['local_graph_id'])) {
		$host_id = db_fetch_cell_prepared('SELECT host_id
			FROM graph_local
			WHERE id = ?',
			[$graph_elements[1]['local_graph_id']]);

		$sql_where   = '';
		$sql_params  = [];

		if (!empty($host_id)) {
			$host  = db_fetch_row_prepared('SELECT id, description, hostname
				FROM host WHERE id = ?',
				[$host_id]);

			if (is_array($host) && cacti_sizeof($host)) {
				if (!is_ipaddress($host['description'])) {
					$parts     = explode('.', $host['description']);

					$sql_where = 'WHERE host LIKE ? OR host = ?';

					$sql_params[] = $parts[0] . '.%';
					$sql_params[] = $host['description'];
				} else {
					$sql_where = 'WHERE host = ?';

					$sql_params[] = $host['description'];
				}

				if (!is_ipaddress($host['hostname'])) {
					$parts = explode('.', $host['hostname']);

					$sql_where .= ($sql_where != '' ? ' OR ' : 'WHERE ') . 'host LIKE ? OR host = ?';

					$sql_params[] = $parts[0] . '.%';
					$sql_params[] = $host['hostname'];
				} else {
					$sql_where .= ($sql_where != '' ? ' OR ' : 'WHERE ') . 'host = ?';

					$sql_params[] = $host['hostname'];
				}

				if ($sql_where != '') {
					$host_id = syslog_db_fetch_cell_prepared('SELECT host_id FROM syslog_hosts ' . $sql_where, $sql_params);

					if ($host_id) {
						$url = $config['url_path'] . 'plugins/syslog/syslog.php?tab=syslog&reset=1&host=' . $host_id . '&date1=' . $date1 . '&date2=' . $date2;

						print "<a class='iconLink' href='" . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "' title='" . htmlspecialchars(__('Display Syslog in Range', 'syslog'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "'><i class='deviceRecovering fas fa-exclamation-triangle'></i></a><br>";
					}
				}
			}
		}
	}
}
