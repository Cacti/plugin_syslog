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
include_once(__DIR__ . '/setup.php');
include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/database.php');
include_once(__DIR__ . '/lib/syslog_dashboard.php');

global $config;

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
	get_ajax_programs(true);

	exit;
}

if (get_request_var('action') == 'ajax_programs_wnone') {
	get_ajax_programs(true, true);

	exit;
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

if (get_request_var('action') == 'dashboard_chart') {
	header('Content-Type: application/json; charset=UTF-8');
	print syslog_dashboard_chart_data();
	exit;
}

if (get_request_var('action') == 'dashboard_save') {
	header('Content-Type: application/json; charset=UTF-8');
	print syslog_dashboard_save();
	exit;
}

if (get_request_var('action') == 'dashboard_panel_save') {
	header('Content-Type: application/json; charset=UTF-8');
	print syslog_dashboard_panel_save();
	exit;
}

if (get_request_var('action') == 'dashboard_global') {
	header('Content-Type: application/json; charset=UTF-8');
	print syslog_dashboard_global();
	exit;
}

if (get_request_var('action') == 'dashboard_copy') {
	header('Content-Type: application/json; charset=UTF-8');
	print syslog_dashboard_copy();
	exit;
}

$title = __('Syslog Viewer', 'syslog');

// set the default tab
get_filter_request_var('tab', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^([a-zA-Z]+)$/']]);

load_current_session_value('tab', 'sess_syslog_tab', 'syslog');
$current_tab = get_request_var('tab');

if (!in_array($current_tab, ['syslog', 'alerts', 'current', 'status', 'dashboard'], true)) {
	$current_tab = 'syslog';
	set_request_var('tab', $current_tab);
	$_SESSION['sess_syslog_tab'] = $current_tab;
}

// validate the syslog post/get/request information
syslog_request_validation($current_tab);

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
	} elseif ($current_tab == 'status') {
		syslog_status();
	} elseif ($current_tab == 'dashboard') {
		syslog_dashboard();
	} else {
		syslog_messages($current_tab);
	}

	bottom_footer();
}

$_SESSION['sess_nav_level_cache'] = [];

/**
 * Return the host list for the autocomplete endpoint as a JSON document.
 *
 * @return string JSON document mapping host ids to host data.
 */
function get_ajax_hosts(): string {
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
		$rhosts = [];

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

		return (string) json_encode($rhosts);
	} else {
		return (string) json_encode([]);
	}
}

/**
 * Draw the tab navigation for the syslog page.
 *
 * @param string $current_tab The currently selected tab.
 *
 * @return void
 */
function syslog_display_tabs(string $current_tab): void {
	global $config;

	// present a tabbed interface
	$tabs_syslog['syslog'] = __('System Logs', 'syslog');

	$tabs_syslog['alerts'] = __('Alert Logs', 'syslog');

	$tabs_syslog['dashboard'] = __('Dashboard', 'syslog');

	$tabs_syslog['status'] = __('Syslog Status', 'syslog');

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

/**
 * Format a unix timestamp for the status tab, or 'Never' when it is unset.
 *
 * @param string $value The raw status value.
 *
 * @return string The formatted timestamp.
 */
function syslog_status_format_time(string $value): string {
	if ($value === '' || !is_numeric($value) || (int) $value <= 0) {
		return __('Never', 'syslog');
	}

	return date('Y-m-d H:i:s', (int) $value);
}

/**
 * Format a runtime in seconds for the status tab, or 'Never' when it is unset.
 *
 * @param string $value The raw status value.
 *
 * @return string The formatted runtime.
 */
function syslog_status_format_seconds(string $value): string {
	if ($value === '' || !is_numeric($value)) {
		return __('Never', 'syslog');
	}

	return number_format((float) $value, 3) . ' ' . __('seconds', 'syslog');
}

/**
 * Summarize the last polling runtime together with its min/avg/max values.
 *
 * @param array<string, string> $status The status values.
 *
 * @return string The formatted runtime statistics.
 */
function syslog_status_format_runtime_stats(array $status): string {
	if ($status['polling_runtime_last'] === '' || !is_numeric($status['polling_runtime_last'])) {
		return __('Never', 'syslog');
	}

	return sprintf(
		'%s (%s / %s / %s %s)',
		syslog_status_format_seconds($status['polling_runtime_last']),
		number_format((float) $status['polling_runtime_min'], 3),
		number_format((float) $status['polling_runtime_avg'], 3),
		number_format((float) $status['polling_runtime_max'], 3),
		__('min/avg/max', 'syslog')
	);
}

/**
 * Format a counter for the status tab, or '0' when it is unset.
 *
 * @param string $value The raw status value.
 *
 * @return string The formatted counter.
 */
function syslog_status_format_count(string $value): string {
	return $value === '' || !is_numeric($value) ? '0' : number_format((int) $value);
}

/**
 * Summarize the rules that fired during the last poller run.
 *
 * @param string $value JSON document of rule activity, or an empty string.
 *
 * @return string Comma separated list of rule names with their counts.
 */
function syslog_status_format_rule_activity(string $value): string {
	$rules = json_decode((string) $value, true);

	if (!is_array($rules) || !cacti_sizeof($rules)) {
		return __('None', 'syslog');
	}

	$items = [];

	foreach ($rules as $rule) {
		if (!is_array($rule) || empty($rule['name'])) {
			continue;
		}

		$count   = isset($rule['count']) && is_numeric($rule['count']) ? (int) $rule['count'] : 0;
		$items[] = sprintf('%s (%s)', $rule['name'], number_format($count));
	}

	return cacti_sizeof($items) ? implode(', ', $items) : __('None', 'syslog');
}

/**
 * Default incoming-backlog age after which the collector looks stale.
 *
 * Matches the poller interval the Status tab refresh defaults to: one
 * missed processing run is worth a warning.
 */
define('SYSLOG_COLLECTOR_STALE_SECONDS', 300);

/**
 * Default incoming-backlog count after which the collector looks backed up.
 *
 * A few thousand unprocessed messages is normal for a busy collector
 * between poller runs; far more than that suggests the processing path
 * has stopped keeping up.
 */
define('SYSLOG_COLLECTOR_BACKLOG_THRESHOLD', 10000);

/**
 * Read live collector health metrics for the Syslog Status tab.
 *
 * Every metric is queried against the real Syslog database connection
 * with the configured incoming-table field mappings.  When a query
 * cannot be answered, the metric reads 'Unavailable' rather than an
 * invented value.
 *
 * @return array{
 *   last_received: string,
 *   oldest_age: string,
 *   backlog: string,
 *   processed: string,
 *   warning: bool,
 *   warning_text: string
 * } Collector health metrics with formatted values and a warning flag.
 */
function syslog_status_collector_health(): array {
	global $syslogdb_default, $syslog_incoming_config;

	if (!isset($syslog_incoming_config['timeField'])) {
		$syslog_incoming_config['timeField'] = 'logtime';
	}

	$time_field   = $syslog_incoming_config['timeField'];
	$id_field     = isset($syslog_incoming_config['id']) ? $syslog_incoming_config['id'] : 'seq';

	// Column identifiers come from the plugin config file, not user input,
	// but they are interpolated into SQL so they are strictly validated.
	foreach ([$time_field, $id_field] as $column) {
		if (!is_string($column) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
			return syslog_status_collector_unavailable();
		}
	}

	$warning     = false;
	$warn_parts  = [];

	// Last received log timestamp: the newest incoming row by time field.
	$last_received_raw = syslog_db_fetch_cell("SELECT MAX(`$time_field`)
		FROM `$syslogdb_default`.`syslog_incoming`");

	$last_received = __('Unavailable', 'syslog');
	$seconds_since = null;

	if (is_string($last_received_raw) && $last_received_raw !== '' && $last_received_raw !== 'NULL') {
		$epoch = strtotime($last_received_raw);

		if ($epoch !== false) {
			$last_received = date('Y-m-d H:i:s', $epoch);
			$seconds_since = time() - $epoch;
		}
	}

	// Oldest unprocessed incoming log age, and the incoming backlog count.
	$oldest_raw = syslog_db_fetch_cell("SELECT MIN(`$time_field`)
		FROM `$syslogdb_default`.`syslog_incoming`");

	$oldest_age = __('Unavailable', 'syslog');

	if (is_string($oldest_raw) && $oldest_raw !== '' && $oldest_raw !== 'NULL') {
		$oldest_epoch = strtotime($oldest_raw);

		if ($oldest_epoch !== false) {
			$age = time() - $oldest_epoch;

			if ($age >= 0) {
				$oldest_age = syslog_status_format_age($age);
			}
		}
	}

	$backlog_raw = syslog_db_fetch_cell("SELECT COUNT(*)
		FROM `$syslogdb_default`.`syslog_incoming`");

	$backlog = __('Unavailable', 'syslog');

	if (is_numeric($backlog_raw)) {
		$backlog = number_format((int) $backlog_raw);
	}

	// Records processed in the latest processing run.
	$status      = syslog_status_get();
	$processed   = syslog_status_format_count($status['last_record_count']);

	// Warning state: stale data or an oversized backlog.
	$stale_threshold     = (int) read_config_option('syslog_stale_threshold');
	$backlog_threshold   = (int) read_config_option('syslog_backlog_threshold');

	if ($stale_threshold <= 0) {
		$stale_threshold = SYSLOG_COLLECTOR_STALE_SECONDS;
	}

	if ($backlog_threshold <= 0) {
		$backlog_threshold = SYSLOG_COLLECTOR_BACKLOG_THRESHOLD;
	}

	if ($seconds_since !== null && $seconds_since > $stale_threshold) {
		$warning    = true;
		$warn_parts[] = sprintf(__('no message received for %s', 'syslog'), syslog_status_format_age($seconds_since));
	}

	if (is_numeric($backlog_raw) && (int) $backlog_raw > $backlog_threshold) {
		$warning      = true;
		$warn_parts[] = sprintf(__('incoming backlog of %s messages', 'syslog'), number_format((int) $backlog_raw));
	}

	if (is_numeric($backlog_raw) && (int) $backlog_raw === 0 && $seconds_since === null) {
		$warning    = true;
		$warn_parts[] = __('no incoming data is available to evaluate', 'syslog');
	}

	return [
		'last_received' => $last_received,
		'oldest_age'    => $oldest_age,
		'backlog'       => $backlog,
		'processed'     => $processed,
		'warning'       => $warning,
		'warning_text'  => cacti_sizeof($warn_parts) ? implode('; ', $warn_parts) : ''
	];
}

/**
 * Provide the all-unavailable collector health shape for broken configs.
 *
 * @return array The collector health array with every metric unavailable.
 */
function syslog_status_collector_unavailable(): array {
	$unavailable = __('Unavailable', 'syslog');

	return [
		'last_received' => $unavailable,
		'oldest_age'    => $unavailable,
		'backlog'       => $unavailable,
		'processed'     => '0',
		'warning'       => false,
		'warning_text'  => ''
	];
}

/**
 * Format a duration in seconds as a compact human-readable age.
 *
 * @param int $seconds The age in seconds.
 *
 * @return string The formatted age, for example '3h 12m'.
 */
function syslog_status_format_age(int $seconds): string {
	if ($seconds < 60) {
		return $seconds . ' ' . __('seconds', 'syslog');
	}

	$parts = [];

	$days = intdiv($seconds, 86400);
	$rest = $seconds % 86400;

	if ($days > 0) {
		$parts[] = $days . ' ' . ($days === 1 ? __('day', 'syslog') : __('days', 'syslog'));
	}

	$hours = intdiv($rest, 3600);
	$rest %= 3600;

	if ($hours > 0) {
		$parts[] = $hours . ' ' . ($hours === 1 ? __('hour', 'syslog') : __('hours', 'syslog'));
	}

	$minutes = intdiv($rest, 60);

	if ($minutes > 0 || !cacti_sizeof($parts)) {
		$parts[] = $minutes . ' ' . ($minutes === 1 ? __('minute', 'syslog') : __('minutes', 'syslog'));
	}

	return implode(' ', array_slice($parts, 0, 2));
}

/**
 * Format an age value for the collector health display.
 *
 * @param int $seconds The age in seconds.
 *
 * @return string The formatted age.
 */
function syslog_status_format_age_value(int $seconds): string {
	return syslog_status_format_age($seconds);
}

/**
 * Read live storage metrics only when rendering the status tab.
 *
 * @return array<string, string> Map of storage labels to formatted values.
 */
function syslog_status_storage(): array {
	global $syslogdb_default, $syslog_retentions, $syslog_alert_retentions;

	$incoming = syslog_db_fetch_cell("SELECT COUNT(*) FROM `$syslogdb_default`.`syslog_incoming`");
	$bytes = syslog_db_fetch_cell_prepared('SELECT DATA_LENGTH + INDEX_LENGTH
		FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
		[$syslogdb_default, 'syslog']);

	$size = syslog_status_format_bytes($bytes);

	$retention = read_config_option('syslog_retention');
	$alert_retention = read_config_option('syslog_alert_retention');

	return [
		__('Rows in syslog_incoming', 'syslog') => is_numeric($incoming) ? number_format((int) $incoming) : __('Unavailable', 'syslog'),
		__('Syslog table size (data + indexes)', 'syslog') => $size,
		__('Syslog retention', 'syslog') => $syslog_retentions[$retention] ?? __('Unavailable', 'syslog'),
		__('Alert retention', 'syslog') => $syslog_alert_retentions[$alert_retention] ?? __('Unavailable', 'syslog')
	];
}

/** Format a byte count for the Status tab, or Unavailable when unknown. */
function syslog_status_format_bytes($bytes): string {
	if (!is_numeric($bytes) || $bytes < 0) {
		return __('Unavailable', 'syslog');
	}

	$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
	$unit  = 0;

	while ($bytes >= 1024 && $unit < count($units) - 1) {
		$bytes /= 1024;
		$unit++;
	}

	return number_format((float) $bytes, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}

/** Format a dYYYYMMDD partition label as an ISO date. */
function syslog_status_format_partition_date(string $date): string {
	if (preg_match('/^\d{8}$/', $date) !== 1) {
		return __('Unavailable', 'syslog');
	}

	return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
}

/** Return a readable summary of the persisted per-table recovery state. */
function syslog_status_format_partition_progress(string $value): string {
	$progress = json_decode($value, true);

	if (!is_array($progress)) {
		return __('No recovery activity recorded yet.', 'syslog');
	}

	$parts = [];

	foreach (['syslog' => 'syslog', 'syslog_removed' => 'syslog_removed'] as $key => $label) {
		$state = $progress[$key] ?? null;
		if (!is_array($state)) {
			continue;
		}

		$parts[] = sprintf(
			'%s: %d %s, %d %s%s',
			$label,
			(int) ($state['created'] ?? 0),
			__('created', 'syslog'),
			(int) ($state['missing'] ?? 0),
			__('remaining', 'syslog'),
			empty($state['deferred']) ? '' : ' — ' . __('retention deferred', 'syslog')
		);
	}

	return cacti_sizeof($parts) ? implode('; ', $parts) : __('No recovery activity recorded yet.', 'syslog');
}

/**
 * Display the syslog processing status tab.
 *
 * @return void
 */
function syslog_status(): void {
	global $config;

	$status = syslog_status_get();

	$worker_stats = syslog_worker_stats_get();

	html_start_box(__('Syslog Status', 'syslog'), '100%', false, '3', 'center', '');
	?>
	<tr><td>
		<div class="syslogStatus">
			<section class="syslogStatusRun" aria-labelledby="syslog_status_run">
				<h2 id="syslog_status_run" class="syslogStatusHeading ui-widget-header"><?php print __esc('Latest processing run', 'syslog'); ?></h2>
				<dl class="syslogStatusMetrics">
					<div>
						<dt><?php print __esc('Records processed', 'syslog'); ?></dt>
						<dd class="syslogStatusValue"><?php print html_escape(syslog_status_format_count($status['last_record_count'])); ?></dd>
					</div>
					<div>
						<dt><?php print __esc('Polling runtime', 'syslog'); ?></dt>
						<dd class="syslogStatusValue"><?php print html_escape(syslog_status_format_seconds($status['polling_runtime_last'])); ?></dd>
					</div>
				</dl>
				<dl class="syslogStatusTimings">
					<?php
					$timings = [
						__('Started', 'syslog') => syslog_status_format_time($status['last_start_time']),
						__('Finished', 'syslog') => syslog_status_format_time($status['last_end_time']),
						__('Minimum runtime', 'syslog') => syslog_status_format_seconds($status['polling_runtime_min']),
						__('Average runtime', 'syslog') => syslog_status_format_seconds($status['polling_runtime_avg']),
						__('Maximum runtime', 'syslog') => syslog_status_format_seconds($status['polling_runtime_max'])
					];
					foreach ($timings as $label => $value) {
						print '<div><dt>' . html_escape($label) . '</dt><dd>' . html_escape($value) . '</dd></div>';
					}
					?>
				</dl>
			</section>
			<?php $replication = syslog_replication_operational_status(); ?>
			<?php if (!empty($replication['enabled'])) { ?>
			<section class="syslogStatusRun" aria-labelledby="syslog_status_replication">
				<h2 id="syslog_status_replication" class="syslogStatusHeading ui-widget-header"><?php print __esc('Distributed synchronization', 'syslog'); ?></h2>
				<dl class="syslogStatusTimings">
				<?php foreach ([
					__('State', 'syslog') => strtoupper((string) $replication['state']),
					__('Queued Recovery Records', 'syslog') => $replication['pending'] === null ? __('Unavailable', 'syslog') : number_format((int) $replication['pending']),
					__('Oldest pending', 'syslog') => $replication['oldest_pending'] !== '' ? (string) $replication['oldest_pending'] : __('None', 'syslog'),
					__('Last successful synchronization', 'syslog') => syslog_status_format_time((string) $replication['last_success']),
					__('Recovery worker', 'syslog') => !empty($replication['recovery_active']) ? __('Active', 'syslog') : __('Inactive', 'syslog'),
					__('Last synchronization error', 'syslog') => $replication['last_error'] !== '' ? $replication['last_error'] : __('None', 'syslog')
				] as $label => $value) { print '<div><dt>' . html_escape($label) . '</dt><dd>' . html_escape((string) $value) . '</dd></div>'; } ?>
				</dl>
			</section>
			<?php } ?>
			<?php if (isset($config['poller_id']) && (int) $config['poller_id'] === 1) { ?>
			<?php $remote_collectors = syslog_replication_collector_status(); ?>
			<section class="syslogStatusRun" aria-labelledby="syslog_status_remote_collectors">
				<h2 id="syslog_status_remote_collectors" class="syslogStatusHeading ui-widget-header"><?php print __esc('Remote collector receipts', 'syslog'); ?></h2>
				<table class="syslogStatusWorkers" aria-labelledby="syslog_status_remote_collectors">
					<thead><tr>
						<th scope="col"><?php print __esc('Remote Poller', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Last Batch Count', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Last Record Received', 'syslog'); ?></th>
					</tr></thead>
					<tbody>
					<?php if (cacti_sizeof($remote_collectors)) { foreach ($remote_collectors as $collector) { ?>
						<tr>
							<th scope="row"><?php print html_escape(sprintf(__('Poller #%d', 'syslog'), (int) $collector['source_poller_id'])); ?></th>
							<td><?php print html_escape(number_format((int) $collector['last_batch_count'])); ?></td>
							<td><?php print html_escape(syslog_status_format_time((string) $collector['last_received'])); ?></td>
						</tr>
					<?php } } else { ?>
						<tr><td colspan="3" class="syslogStatusWorkersEmpty"><?php print __esc('No remote collector records have been received yet.', 'syslog'); ?></td></tr>
					<?php } ?>
					</tbody>
				</table>
			</section>
			<?php } ?>
			<section class="syslogStatusRun" aria-labelledby="syslog_status_storage">
				<h2 id="syslog_status_storage" class="syslogStatusHeading ui-widget-header"><?php print __esc('Storage and retention', 'syslog'); ?></h2>
				<dl class="syslogStatusTimings syslogStatusStorage">
					<?php foreach (syslog_status_storage() as $label => $value) {
						print '<div><dt>' . html_escape($label) . '</dt><dd>' . html_escape($value) . '</dd></div>';
					} ?>
				</dl>
				<?php $partition_block = syslog_partition_blocked_state(); ?>
				<?php if ($partition_block['blocked']) { ?>
				<p class="syslogStatusPartitionBlocked">
					<span class="syslogStatusWarningLabel"><?php print __esc('Partition maintenance blocked', 'syslog'); ?>:</span>
					<?php print html_escape($partition_block['reason'] !== '' ? $partition_block['reason'] : __('Partition maintenance is stopped; writes continue into the dMaxValue safety partition.', 'syslog')); ?>
				</p>
				<?php } ?>
			</section>
			<section class="syslogStatusRun" aria-labelledby="syslog_status_partitions">
				<h2 id="syslog_status_partitions" class="syslogStatusHeading ui-widget-header"><?php print __esc('Partition health', 'syslog'); ?></h2>
				<?php $partition_health = syslog_partition_observability(); ?>
				<table class="syslogStatusPartitions" aria-labelledby="syslog_status_partitions">
					<thead><tr>
						<th scope="col"><?php print __esc('Table', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Date coverage', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('dMaxValue rows (estimated)', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('dMaxValue size', 'syslog'); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach (['syslog', 'syslog_removed'] as $table) { ?>
						<?php $health = $partition_health[$table] ?? []; ?>
						<?php $coverage = !empty($health['coverage_start']) && !empty($health['coverage_end']) ? syslog_status_format_partition_date((string) $health['coverage_start']) . ' — ' . syslog_status_format_partition_date((string) $health['coverage_end']) . ' (' . number_format((int) $health['partitions']) . ')' : __('Unavailable', 'syslog'); ?>
						<tr>
							<th scope="row"><?php print html_escape($table); ?></th>
							<td><?php print html_escape($coverage); ?></td>
							<td><?php print html_escape(isset($health['dmax_rows']) && $health['dmax_rows'] !== null ? number_format((float) $health['dmax_rows']) : __('Unavailable', 'syslog')); ?></td>
							<td><?php print html_escape(syslog_status_format_bytes($health['dmax_bytes'] ?? null)); ?></td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
			</section>
			<section class="syslogStatusRun" aria-labelledby="syslog_status_maintenance">
				<h2 id="syslog_status_maintenance" class="syslogStatusHeading ui-widget-header"><?php print __esc('Partition maintenance activity', 'syslog'); ?></h2>
				<dl class="syslogStatusTimings">
					<?php
					$maintenance = [
						__('Last attempt', 'syslog') => syslog_status_format_time($status['partition_maintenance_last_attempt']),
						__('Last successful maintenance', 'syslog') => syslog_status_format_time($status['partition_maintenance_last_success']),
						__('Latest outcome', 'syslog') => $status['partition_maintenance_outcome'] === 'success' ? __('Complete', 'syslog') : ($status['partition_maintenance_outcome'] === 'deferred' ? __('Deferred', 'syslog') : __('Never', 'syslog')),
						__('Recovery progress', 'syslog') => syslog_status_format_partition_progress($status['partition_recovery_progress'])
					];
					foreach ($maintenance as $label => $value) {
						print '<div><dt>' . html_escape($label) . '</dt><dd>' . html_escape($value) . '</dd></div>';
					}
					?>
				</dl>
				<?php $history = json_decode($status['partition_maintenance_history'], true); ?>
				<table class="syslogStatusPartitions" aria-label="<?php print __esc('Recent partition maintenance activity', 'syslog'); ?>">
					<thead><tr>
						<th scope="col"><?php print __esc('When', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Outcome', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Recovery', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Details', 'syslog'); ?></th>
					</tr></thead>
					<tbody>
					<?php if (!is_array($history) || !cacti_sizeof($history)) { ?>
						<tr><td colspan="4" class="syslogStatusPhasesEmpty"><?php print __esc('No partition maintenance activity recorded yet. Activity appears after the next poller run.', 'syslog'); ?></td></tr>
					<?php } else { ?>
						<?php foreach (array_reverse($history) as $event) { ?>
							<tr>
								<td><?php print html_escape(syslog_status_format_time((string) ($event['time'] ?? ''))); ?></td>
								<td><?php print html_escape(!empty($event['successful']) ? __('Complete', 'syslog') : __('Deferred', 'syslog')); ?></td>
								<td><?php print html_escape(__('%d created, %d remaining, %d pruned', (int) ($event['created'] ?? 0), (int) ($event['missing'] ?? 0), (int) ($event['pruned'] ?? 0), 'syslog')); ?></td>
								<td><?php print html_escape((string) ($event['reason'] ?? '') !== '' ? (string) $event['reason'] : __('No action required.', 'syslog')); ?></td>
							</tr>
						<?php } ?>
					<?php } ?>
					</tbody>
				</table>
			</section>
			<section class="syslogStatusRun" aria-labelledby="syslog_status_collector">
				<h2 id="syslog_status_collector" class="syslogStatusHeading ui-widget-header"><?php print __esc('Collector health', 'syslog'); ?></h2>
				<?php $health = syslog_status_collector_health(); ?>
				<dl class="syslogStatusTimings syslogStatusCollector">
					<?php
					$health_metrics = [
						__('Last received log', 'syslog') => $health['last_received'],
						__('Oldest unprocessed message', 'syslog') => $health['oldest_age'],
						__('Incoming backlog', 'syslog') => $health['backlog'],
						__('Records processed in latest run', 'syslog') => $health['processed']
					];
					foreach ($health_metrics as $label => $value) {
						print '<div><dt>' . html_escape($label) . '</dt><dd>' . html_escape($value) . '</dd></div>';
					}
					?>
				</dl>
				<?php if ($health['warning']) { ?>
				<p class="syslogStatusHealthWarning">
					<span class="syslogStatusWarningLabel"><?php print __esc('Collector warning', 'syslog'); ?>:</span>
					<?php print html_escape($health['warning_text']); ?>
				</p>
				<?php } ?>
			</section>
			<section class="syslogStatusRun" aria-labelledby="syslog_status_workers">
				<h2 id="syslog_status_workers" class="syslogStatusHeading ui-widget-header"><?php print __esc('Parallel workers', 'syslog'); ?></h2>
				<dl class="syslogStatusTimings syslogStatusWorkerSummary">
					<?php
					$workers_summary = [
						__('Worker processes running', 'syslog') => syslog_status_format_count($worker_stats['running']),
						__('Configured worker processes', 'syslog') => syslog_status_format_count($worker_stats['workers'])
					];
					foreach ($workers_summary as $label => $value) {
						print '<div><dt>' . html_escape($label) . '</dt><dd>' . html_escape($value) . '</dd></div>';
					}
					?>
				</dl>
				<?php if (cacti_sizeof($worker_stats['children'])) { ?>
				<table class="syslogStatusWorkers" aria-labelledby="syslog_status_workers">
					<thead><tr>
						<th scope="col"><?php print __esc('Process', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Records handled', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Hosts resolved', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Runtime', 'syslog'); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($worker_stats['children'] as $child) { ?>
						<tr>
							<th scope="row"><?php print html_escape(syslog_status_format_count($child['child'])); ?></th>
							<td><?php print html_escape(syslog_status_format_count($child['moved'])); ?></td>
							<td><?php print html_escape(syslog_status_format_count($child['resolved'])); ?></td>
							<td><?php print html_escape(syslog_status_format_seconds($child['runtime'])); ?></td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
				<?php } else { ?>
				<p class="syslogStatusWorkersEmpty"><?php print __esc('No per process statistics recorded yet.  Worker statistics appear after the first parallel run.', 'syslog'); ?></p>
				<?php } ?>
			</section>
			<section aria-labelledby="syslog_status_phases">
				<h2 id="syslog_status_phases" class="syslogStatusHeading ui-widget-header"><?php print __esc('Processing phases', 'syslog'); ?></h2>
				<?php
				$phase_telemetry = syslog_status_phase_telemetry();
				$phase_labels = [
					'partition'  => __('Partition maintenance', 'syslog'),
					'references' => __('Reference updates', 'syslog'),
					'removal'    => __('Removal rules', 'syslog'),
					'alerts'     => __('Alert evaluation', 'syslog'),
					'transfer'   => __('Incoming transfer', 'syslog'),
					'reports'    => __('Report processing', 'syslog')
				];

				$slowest_phase = '';
				$slowest_seconds = -1.0;

				foreach ($phase_telemetry as $phase => $telemetry) {
					if ($telemetry === null) {
						continue;
					}

					if ($telemetry['seconds'] > $slowest_seconds) {
						$slowest_seconds = $telemetry['seconds'];
						$slowest_phase   = $phase;
					}
				}
				?>
				<table class="syslogStatusPhases" aria-labelledby="syslog_status_phases">
					<thead><tr>
						<th scope="col"><?php print __esc('Phase', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Started', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Duration', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Records', 'syslog'); ?></th>
					</tr></thead>
					<tbody>
					<?php $any_phase = false; ?>
					<?php foreach ($phase_telemetry as $phase => $telemetry) { ?>
						<?php if ($telemetry === null) { continue; } $any_phase = true; ?>
						<tr<?php if ($phase === $slowest_phase) { print ' class="syslogStatusPhaseSlowest"'; } ?>>
							<th scope="row"><?php print html_escape($phase_labels[$phase] ?? $phase); ?></th>
							<td><?php print html_escape(syslog_status_format_time((string) $telemetry['start'])); ?></td>
							<td><?php print html_escape(syslog_status_format_seconds((string) $telemetry['seconds'])); ?></td>
							<td><?php print html_escape(syslog_status_format_count((string) $telemetry['count'])); ?></td>
						</tr>
					<?php } ?>
					<?php if (!$any_phase) { ?>
					<tr><td colspan="4" class="syslogStatusPhasesEmpty"><?php print __esc('No phase timings recorded yet.  Phase timings appear after the next poller run.', 'syslog'); ?></td></tr>
					<?php } ?>
					</tbody>
				</table>
				<?php if ($slowest_phase !== '') { ?>
				<p class="syslogStatusPhaseSlowestSummary">
					<span class="syslogStatusDetailLabel"><?php print __esc('Slowest phase', 'syslog'); ?>:</span>
					<?php print html_escape($phase_labels[$slowest_phase] ?? $slowest_phase); ?>
					(<?php print html_escape(syslog_status_format_seconds((string) $slowest_seconds)); ?>)
				</p>
				<?php } ?>
			</section>
			<section aria-labelledby="syslog_status_rules">
				<h2 id="syslog_status_rules" class="syslogStatusHeading ui-widget-header"><?php print __esc('Rule activity', 'syslog'); ?></h2>
				<table class="syslogStatusRules" aria-labelledby="syslog_status_rules">
					<thead><tr>
						<th scope="col"><?php print __esc('Rules processed', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Last run', 'syslog'); ?></th>
						<th scope="col"><?php print __esc('Total', 'syslog'); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach (['alert' => __('Alert rules', 'syslog'), 'delete' => __('Delete rules', 'syslog')] as $type => $label) { ?>
						<tr class="syslogStatusRuleCounts">
							<th scope="row"><?php print html_escape($label); ?></th>
							<td><?php print html_escape(syslog_status_format_count($status['last_' . $type . '_rules_processed'])); ?></td>
							<td><?php print html_escape(syslog_status_format_count($status['total_' . $type . '_rules_processed'])); ?></td>
						</tr>
						<tr class="syslogStatusRuleDetails"><td colspan="3">
							<span class="syslogStatusDetailLabel"><?php print __esc('Fired last run', 'syslog'); ?>:</span>
							<?php print html_escape(syslog_status_format_rule_activity($status['last_' . $type . '_rules_fired'])); ?>
						</td></tr>
					<?php } ?>
					</tbody>
				</table>
			</section>
		</div>
	</td></tr>
	<?php
	html_end_box(false);
}

/**
 * Display the HTML body of the selected alert, then stop.
 *
 * @return void
 */
function syslog_view_alarm(): void {
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
 * Validate the request for the syslog page and store the filter state.
 *
 * This is a generic function for this page that makes sure that
 * we have a good request.  We want to protect against people who
 * like to create issues with Cacti.
 *
 * @param string $current_tab The currently selected tab.
 * @param bool   $force       Whether to bypass cached user settings.
 *
 * @return void
 */
function syslog_request_validation(string $current_tab, bool $force = false): void {
	global $title, $rows, $config, $reset_multi;

	// Cacti validation populates $_POST even for values restored from the session.
	// Capture the original submission before any request helpers mutate it.
	$filter_submitted = isset($_POST['rfilter']);

	include_once($config['base_path'] . '/lib/time.php');

	// The dashboard tab charts panels through its own endpoint and does not
	// use the log viewer filters; only validate its shared controls here.
	if ($current_tab == 'dashboard') {
		$dashboard_filters = [
			'dashboard_id' => [
				'filter'  => FILTER_VALIDATE_INT,
				'pageset' => true,
				'default' => '0'
			],
			'dashboard_timespan' => [
				'filter'  => FILTER_CALLBACK,
				'pageset' => true,
				'default' => '86400',
				'options' => ['options' => 'sanitize_search_string']
			],
			'refresh' => [
				'filter'  => FILTER_VALIDATE_INT,
				'pageset' => true,
				'default' => read_user_setting('syslog_refresh', read_config_option('syslog_refresh'), $force)
			]
		];

		validate_store_request_vars($dashboard_filters, 'sess_sl_dashboard');

		return;
	}

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
 *
 * @param string $tab      The tab the saved search belongs to.
 * @param int    $saved_id The saved search id.
 *
 * @return void
 */
function saved_search_apply(string $tab, int $saved_id): void {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);

	$sql_where = "`user` = ? OR is_global = 'on'";
	$shared = syslog_shared_item_ids('saved_search');

	if (cacti_sizeof($shared)) {
		$sql_where .= ' OR id IN (' . implode(',', $shared) . ')';
	}

	$row = syslog_db_fetch_row_prepared("SELECT id, search, removal, grouping
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?
		AND ($sql_where)",
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

/**
 * Create or update a saved search from an AJAX submission.
 *
 * @return string JSON document with the saved search id, or an error.
 */
function saved_search_save(): string {
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
		return (string) json_encode(['error' => __('A name of up to 128 characters is required.', 'syslog')]);
	}

	try {
		$tree = syslog_parse_logical_search($search);
		syslog_logical_search_sql($tree, get_request_var('tab') === 'alerts' ? 'logmsg' : 'message');
	} catch (InvalidArgumentException $error) {
		return (string) json_encode(['error' => __('Invalid logical search: %s', $error->getMessage(), 'syslog')]);
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

		return (string) json_encode(['id' => (int) $existing_id]);
	}

	syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_saved_searches`
		(name, search, removal, grouping, `user`, is_global, `date`)
		VALUES (?, ?, ?, ?, ?, '', ?)",
		[$name, $search, $removal, $grouping, $username, time()]);

		return (string) json_encode(['id' => (int) syslog_db_fetch_insert_id()]);
}

/**
 * Delete a saved search that the current user is allowed to manage.
 *
 * @return string JSON document with the deleted id, or an error.
 */
function saved_search_delete(): string {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);
	$id       = get_filter_request_var('id', FILTER_VALIDATE_INT);

	if ($id === false || $id === null || $id <= 0) {
		return (string) json_encode(['error' => __('A valid saved search is required.', 'syslog')]);
	}

	$row = syslog_db_fetch_row_prepared("SELECT `user`, is_global
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?",
		[$id]);

	if ($row === false) {
		return (string) json_encode(['error' => __('Saved search not found.', 'syslog')]);
	}

	if ($row['user'] !== $username && !($row['is_global'] === 'on' && syslog_saved_search_admin())) {
		return (string) json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?",
		[$id]);
	syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_saved_searches_perm`
		WHERE search_id = ?",
		[$id]);

	if ((int) ($_SESSION['sess_sl_' . get_request_var('tab') . '_saved'] ?? 0) === (int) $id) {
		kill_session_var('sess_sl_' . get_request_var('tab') . '_saved');
	}

	return (string) json_encode(['id' => (int) $id]);
}

/**
 * Toggle the global sharing flag of an owned saved search.
 *
 * @return string JSON document with the id and new flag, or an error.
 */
function saved_search_global(): string {
	global $syslogdb_default;

	if (!syslog_saved_search_share()) {
		return (string) json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	$username = get_username($_SESSION['sess_user_id']);

	if ($id === false || $id === null || $id <= 0) {
		return (string) json_encode(['error' => __('A valid saved search is required.', 'syslog')]);
	}

	$row = syslog_db_fetch_row_prepared("SELECT `user`, is_global
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE id = ?",
		[$id]);

	if ($row === false) {
		return (string) json_encode(['error' => __('Saved search not found.', 'syslog')]);
	}
	if ($row['user'] !== $username && !syslog_saved_search_admin()) {
		return (string) json_encode(['error' => __('You may only share your own saved searches.', 'syslog')]);
	}

	$is_global = $row['is_global'] === 'on' ? '' : 'on';

	syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_saved_searches`
		SET is_global = ?
		WHERE id = ?",
		[$is_global, $id]);

	return (string) json_encode(['id' => (int) $id, 'is_global' => $is_global]);
}

/**
 * Apply a timespan or timeshift selection and store the resulting dates.
 *
 * @param bool|string $shift_span       The shift mode: 'span', 'shift', 'custom', or false for page navigation.
 * @param string      $session_prefix The session key prefix of the tab.
 *
 * @return void
 */
function set_shift_span(bool|string $shift_span, string $session_prefix): void {
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
			$span            = [];
			get_timespan($span, time(), get_request_var('predefined_timespan'), $first_weekdayid);
			set_request_var('date1', date('Y-m-d H:i:s', $span['begin_now']));
			set_request_var('date2', date('Y-m-d H:i:s', $span['end_now']));
			set_request_var('custom', false);
		}
	}
}

/**
 * Build the log query and the shared WHERE clause for the log viewer.
 *
 * @param string     $sql_where The built WHERE clause, updated in place.
 * @param int|string $rows      The number of rows to fetch per page.
 * @param string     $tab       The current tab: 'syslog' or 'alerts'.
 *
 * @return list<array<string, mixed>> The matching log records.
 */
function get_syslog_messages(string &$sql_where, int|string $rows, string $tab): array {
	global $sql_where, $hostfilter, $hostfilter_log, $current_tab, $syslog_incoming_config;
	global $syslogdb_default;
	// syslog and syslog_removed can legitimately gain independent operational
	// columns. Keep the combined viewer query on its stable display contract
	// instead of using SELECT *, which would make UNION depend on identical DDL.
	$message_columns = 'syslog.facility_id, syslog.priority_id, syslog.program_id, syslog.host_id, syslog.logtime, syslog.message, syslog.seq';

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
		$sql_limit = ' LIMIT ' . ((int) $rows * (get_request_var('page') - 1)) . ',' . $rows;
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
				$query_sql = "SELECT $message_columns, `syslog_programs`.`program`, 'main' AS mtype
					FROM `$syslogdb_default`.`syslog`
					LEFT JOIN `$syslogdb_default`.`syslog_programs`
					ON syslog.program_id = syslog_programs.program_id
					$sql_where
					$sql_order
					$sql_limit";
			} elseif (get_request_var('removal') == '1') {
				$query_sql = "(
						SELECT $message_columns, `syslog_programs`.`program`, 'main' AS mtype
						FROM `$syslogdb_default`.`syslog` AS syslog
						LEFT JOIN `$syslogdb_default`.`syslog_programs`
						ON syslog.program_id=syslog_programs.program_id
						$sql_where
					) UNION (
						SELECT $message_columns, `syslog_programs`.`program`, 'remove' AS mtype
						FROM `$syslogdb_default`.`syslog_removed` AS syslog
						LEFT JOIN `$syslogdb_default`.`syslog_programs`
						ON syslog.program_id = syslog_programs.program_id
						$sql_where
					)
					$sql_order
					$sql_limit";
			} else {
				$query_sql = "SELECT $message_columns, `syslog_programs`.`program`, 'remove' AS mtype
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

/**
 * Draw the filter panel above the log results.
 *
 * @param string $sql_where The message filter clause built by the query builder.
 * @param string $tab       The current tab: 'syslog' or 'alerts'.
 *
 * @return void
 */
function syslog_filter(string $sql_where, string $tab): void {
	global $config, $page_refresh_interval, $item_rows;
	global $syslogdb_default;

	$unprocessed = syslog_db_fetch_cell("SELECT COUNT(*) FROM `$syslogdb_default`.`syslog_incoming`");

	$filter_text = __esc('[ Unprocessed Messages: %s ]', $unprocessed, 'syslog');

	// Shared search builder data for the main panel and the saved search dialog.
	$saved_fields = syslog_search_fields();

	if ($tab != 'syslog') {
		unset($saved_fields['host_id']);
	}

	$saved_choices_json = html_escape((string) json_encode(syslog_search_choices()));
	$saved_fields_json  = html_escape((string) json_encode($saved_fields));
	$saved_tree_json    = html_escape((string) json_encode($GLOBALS['syslog_search_tree'] ?? null));

	$username = get_username($_SESSION['sess_user_id']);

	// Note: $sql_where is this function's message filter; the saved search
	// visibility clause is separate.
	$saved_where = "`user` = ? OR is_global = 'on'";
	$shared_searches = syslog_shared_item_ids('saved_search');

	if (cacti_sizeof($shared_searches)) {
		$saved_where .= ' OR id IN (' . implode(',', $shared_searches) . ')';
	}

	$saved_searches = syslog_db_fetch_assoc_prepared("SELECT id, name, `user`, is_global
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE $saved_where
		ORDER BY is_global, name",
		[$username]);

	$saved_active = (int) ($_SESSION['sess_sl_' . $tab . '_saved'] ?? 0);
	$saved_share  = syslog_saved_search_share();
	$saved_admin  = syslog_saved_search_admin();

	?>
	<script type='text/javascript'>
	initSyslogMain({
		pageTab: <?php print syslog_json_safe(get_request_var('tab')); ?>,
		placeHolder: <?php print syslog_json_safe(__('Enter a search term', 'syslog')); ?>,
		noneSelectedText: <?php print syslog_json_safe(__('Select Device(s)', 'syslog')); ?>,
		devicesSelectedText: <?php print syslog_json_safe(__('Devices Selected', 'syslog')); ?>,
		allDevicesText: <?php print syslog_json_safe(__('All Devices Selected', 'syslog')); ?>
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
						<select id='saved_search' data-user='<?php print html_escape($username); ?>' data-admin='<?php print $saved_admin ? '1' : '0'; ?>'>
							<?php
							$saved_groups = [
								__('My Searches', 'syslog')   => [],
								__('Global Searches', 'syslog') => [],
								__('Shared With Me', 'syslog')  => []
							];

							foreach ($saved_searches as $saved) {
								if ($saved['is_global'] === 'on') {
									$label = __('Global Searches', 'syslog');
								} elseif ($saved['user'] === $username) {
									$label = __('My Searches', 'syslog');
								} else {
									$label = __('Shared With Me', 'syslog');
								}

								$saved_groups[$label][] = $saved;
							}

							foreach ($saved_groups as $saved_label => $saved_group) {
								if (cacti_sizeof($saved_group)) {
									print "<optgroup label='" . html_escape($saved_label) . "'>";

									foreach ($saved_group as $saved) {
										// Match the server-side delete permission in saved_search_delete().
										$can_manage = $saved['user'] === $username || ($saved['is_global'] === 'on' && $saved_admin);

										print "<option value='" . $saved['id'] . "' data-owner='" . html_escape($saved['user']) . "' data-global='" . ($saved['is_global'] === 'on' ? '1' : '0') . "' data-manage='" . ($can_manage ? '1' : '0') . "'" . ($saved_active === (int) $saved['id'] ? ' selected' : '') . '>' .
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
 * Strip the domain from a hostname for rule matching.
 *
 * @param string $hostname The hostname or IP address.
 *
 * @return string The bare hostname, or the original address.
 */
function syslog_strip_domain(string $hostname): string {
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
 * Display the foreground and background colors for the syslog legend.
 *
 * @return void
 */
function syslog_syslog_legend(): void {
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
 * Display the foreground and background colors for the alert log legend.
 *
 * @return void
 */
function syslog_log_legend(): void {
	global $disabled_color, $notmon_color, $database_default;

	html_start_box('', '100%', '', '3', 'center', '');
	print '<tr class="">';
	print "<td width='10%' class='logAlert'>" . __('Alert', 'syslog') . '</td>';
	print "<td width='10%' class='logWarning'>" . __('Warning', 'syslog') . '</td>';
	print "<td width='10%' class='logInfo'>" . __('Informational', 'syslog') . '</td>';
	print '</tr>';
	html_end_box(false);
}

/**
 * Display the main log results table for syslog or alert messages.
 *
 * This is the main page display function in Syslog.  Displays all the
 * syslog messages that are relevant to Syslog.
 *
 * @param string $tab The current tab: 'syslog' or 'alerts'.
 *
 * @return void
 */
function syslog_messages(string $tab = 'syslog'): void {
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


							print "<td class='left' style='padding-left:30px;'>" . html_escape($dm['logtime']) . '</td>';
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
			'logtime'     => ['display' => __('Date', 'syslog'),	   'sort' => 'ASC', 'align' => 'left'],
			'host'        => ['display' => __('Device', 'syslog'),	 'sort' => 'ASC', 'align' => 'left'],
			'severity'    => ['display' => __('Severity', 'syslog'),   'sort' => 'ASC', 'align' => 'left'],
			'name'        => ['display' => __('Alert Name', 'syslog'), 'sort' => 'ASC', 'align' => 'left'],
			'logmsg'      => ['display' => __('Message', 'syslog'),	'sort' => 'ASC', 'align' => 'left'],
			'count'       => ['display' => __('Count', 'syslog'),	  'sort' => 'ASC', 'align' => 'right'],
			'facility_id' => ['display' => __('Facility', 'syslog'),   'sort' => 'ASC', 'align' => 'left'],
			'priority_id' => ['display' => __('Priority', 'syslog'),   'sort' => 'ASC', 'align' => 'left']
		];

		$nav = html_nav_bar("syslog.php?tab=$tab", MAX_DISPLAY_PAGES, get_request_var_request('page'), $rows, $total_rows, cacti_sizeof($display_text), __('Alert Log Rows', 'syslog'), 'page', 'main');

		print $nav;

		html_start_box('', '100%', '', '3', 'center', '');

		html_header_sort($display_text, get_request_var('sort_column'), get_request_var('sort_direction'));

		if (cacti_sizeof($syslog_messages)) {
			foreach ($syslog_messages as $log) {
				$title   = html_escape($log['logmsg']);

				syslog_log_row_color($log['severity'], $title);

				form_selectable_cell($log['logtime'], $log['seq'], '', 'left');
				print "<td class='nowrap left'>" . syslog_value_filter_button($log['host'], 'host') . '</td>';
				form_selectable_cell(isset($severities[$log['severity']]) ? $severities[$log['severity']] : __('Unknown', 'syslog'), $log['seq'], '', 'left');
				form_selectable_cell(filter_value($log['name'] != '' ? $log['name'] : __('Alert Removed', 'syslog'), get_request_var('rfilter'), $config['url_path'] . 'plugins/syslog/syslog.php?id=' . $log['seq'] . '&tab=current'), $log['seq'], '', 'left');
				form_selectable_cell(syslog_message_button($log['logmsg'], $log['host'], $log['program'] ?? '', $log['facility'], $log['priority'], $log['logtime']), $log['seq'], '', 'syslogMessage left');

				form_selectable_cell($log['count'], $log['seq'], '', 'right');
				form_selectable_cell(ucfirst($log['facility']), $log['seq'], '', 'left');
				print "<td class='nowrap left'>" . syslog_value_filter_button(ucfirst($log['priority']), 'priority') . '</td>';

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
		?>
		<script type='text/javascript'>
		initSyslogValueFilters();
		</script>
		<?php
	}
	print '</div>';
	?>
	<aside id='syslog_message_details' hidden aria-labelledby='syslog_details_title' tabindex='-1'>
		<header class='ui-widget-header'><h3 id='syslog_details_title'><?php print __esc('Message details', 'syslog'); ?></h3><button type='button' class='ui-state-default' id='syslog_details_close' aria-label='<?php print __esc('Close message details', 'syslog'); ?>'>×</button></header>
		<dl><?php foreach (['received' => __('Date', 'syslog'), 'device' => __('Device', 'syslog'), 'program' => __('Program', 'syslog'), 'facility' => __('Facility', 'syslog'), 'severity' => __('Priority', 'syslog')] as $key => $label) { print '<dt>' . html_escape($label) . "</dt><dd data-detail='" . $key . "'></dd>"; } ?></dl>
		<h4><?php print __esc('Message', 'syslog'); ?></h4><pre id='syslog_details_raw'></pre>
		<div class='syslogDetailsActions'><button type='button' id='syslog_details_copy'><?php print __esc('Copy message', 'syslog'); ?></button><span id='syslog_copy_status' role='status' data-success='<?php print __esc('Copied', 'syslog'); ?>' data-error='<?php print __esc('Copy unavailable. Select and copy the message text.', 'syslog'); ?>'></span></div>
		<div class='syslogDetailsRules'><a data-rule='alarm' class='syslogRuleButton' hidden><?php print __esc('Create Alarm Rule', 'syslog'); ?></a><a data-rule='device' class='syslogRuleButton' hidden><?php print __esc('Create Device Alert Rule', 'syslog'); ?></a><a data-rule='removal' class='syslogRuleButton' hidden><?php print __esc('Create Removal Rule', 'syslog'); ?></a></div>
		<div class='syslogDetailsFilters'><button type='button' data-filter-detail='host'><?php print __esc('Filter by device', 'syslog'); ?></button><button type='button' data-filter-detail='program'><?php print __esc('Filter by program', 'syslog'); ?></button></div>
	</aside></div>
	<script>initSyslogWorkspace();</script>
	<?php
}

/**
 * Save the current filter values as the user's defaults.
 *
 * @return void
 */
function save_settings(): void {
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

/**
 * Draw the program filter dropdown.
 *
 * @param int|string $program_id The selected program id.
 * @param string     $none_entry The entry to show when nothing is selected.
 * @param string     $action     The AJAX action for the dropdown.
 * @param string     $call_back  The JavaScript callback on selection.
 * @param string     $sql_where  An optional WHERE clause for the query.
 *
 * @return void
 */
function html_program_filter(int|string $program_id = '-1', string $none_entry = '', string $action = 'ajax_programs', string $call_back = 'applyFilter', string $sql_where = ''): void {
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

/**
 * Return the program list for the autocomplete endpoint as JSON.
 *
 * @param bool   $include_any  Whether to include the 'All Programs' entry.
 * @param bool   $include_none Whether to include the 'None' entry.
 * @param string $sql_where    An optional WHERE clause for the query.
 *
 * @return void
 */
function get_ajax_programs(bool $include_any = true, bool $include_none = false, string $sql_where = ''): void {
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

/**
 * Draw a program selection control, either a classic list or an autocomplete box.
 *
 * @param string     $form_name      The name of the form field.
 * @param string     $classic_sql    The query for the classic dropdown.
 * @param string     $column_display The display column of the query.
 * @param string     $column_id      The id column of the query.
 * @param string     $callback       The AJAX action for the autocomplete.
 * @param int|string $previous_id    The previously selected id.
 * @param string     $previous_value The previously selected value.
 * @param string     $none_entry     The entry to show when nothing is selected.
 * @param string     $default_value  The value to show when no previous value exists.
 * @param string     $class          An extra CSS class.
 * @param string     $on_change      The JavaScript callback on change.
 *
 * @return void
 */
function syslog_form_callback(string $form_name, string $classic_sql, string $column_display, string $column_id, string $callback, int|string $previous_id, string $previous_value, string $none_entry, string $default_value, string $class = '', string $on_change = ''): void {
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

		html_create_list($form_data, $column_display, $column_id, html_escape((string) $previous_id));

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
		print "<input type='hidden' id='" . $form_name . "' name='" . $form_name . "' value='" . html_escape((string) $previous_id) . "'>";
		?>
		<style type='text/css'>
		.syslogMessage {
			white-space: normal !important;
		}
		</style>
		<?php
		// JSON_HEX_TAG escapes </script>; the other flags block HTML context
		// escapes if the script block ever runs under unusual content types.
		$js_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
		?>
		<script type='text/javascript'>
		initSyslogAutocomplete(<?php print json_encode($form_name, $js_flags); ?>, <?php print json_encode($callback, $js_flags); ?>, <?php print json_encode($on_change, $js_flags); ?>);
		</script>
		<?php
	}
}
