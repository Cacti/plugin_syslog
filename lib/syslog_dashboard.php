<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Syslog Dashboard: personal collections of chart panels rendered with
 * Cacti's bundled billboard.js. Panel filters use the same logical search
 * DSL as the log viewer, so dashboard data is aggregated with exactly the
 * same validation and safety guarantees.
 */

/** Allowlisted log sources for a dashboard panel. */
function syslog_dashboard_sources() {
	return ['syslog', 'alerts'];
}

/** Allowlisted panel kinds. */
function syslog_dashboard_kinds() {
	return ['timeseries', 'breakdown'];
}

/** Allowlisted chart types per panel kind. */
function syslog_dashboard_charts($kind) {
	return $kind === 'breakdown' ? ['donut'] : ['line', 'area', 'bar'];
}

/** Allowlisted breakdown dimensions. */
function syslog_dashboard_fields() {
	return ['host', 'program', 'facility', 'priority'];
}

/** Allowlisted bucket intervals; 'dashboard'/'auto' are resolved at render time. */
function syslog_dashboard_intervals() {
	return ['dashboard', 'auto', 'minute', '10min', 'hour', 'day'];
}

/** Allowlisted panel timespans; 'dashboard' follows the shared time picker. */
function syslog_dashboard_timespans() {
	return ['dashboard', '3600', '21600', '86400', '604800', '1209600', '2592000', '3months', '6months'];
}

/** Allowlisted record-type settings for the System Logs source. */
function syslog_dashboard_removals() {
	return ['1', '-1', '2'];
}

/** Server-side cap on the per-panel top-N. */
function syslog_dashboard_top_n_cap() {
	return 50;
}

/** Maximum grid columns a panel may span. */
function syslog_dashboard_width_cap() {
	return 3;
}

/** Minimum chart height in pixels; 0 follows the default. */
function syslog_dashboard_height_min() {
	return 140;
}

/** Maximum chart height in pixels. */
function syslog_dashboard_height_cap() {
	return 1200;
}

/** Server-side cap on rendered buckets per timeseries. */
function syslog_dashboard_bucket_cap() {
	return 720;
}

/**
 * Map a panel timespan preset to a window in seconds.
 *
 * @param string $preset A syslog_dashboard_timespans() value.
 *
 * @return int Window length in seconds, or 86400 for unknown values.
 */
function syslog_dashboard_timespan_seconds($preset) {
	$months = ['3months' => 3, '6months' => 6];

	if (isset($months[$preset])) {
		// Months vary in length; 30.44-day months keep the bucket math stable.
		return (int) round($months[$preset] * 30.44 * 86400);
	}

	$seconds = (int) $preset;

	return $seconds > 0 ? $seconds : 86400;
}

/**
 * Resolve the bucket size for a timeseries panel.
 *
 * 'dashboard' and 'auto' pick a bucket from the window length; explicit
 * values are coarsened when they would produce too many buckets.
 *
 * @param int    $seconds  Window length in seconds.
 * @param string $interval A syslog_dashboard_intervals() value.
 *
 * @return int Bucket size in seconds.
 */
function syslog_dashboard_bucket_seconds($seconds, $interval) {
	$explicit = ['minute' => 60, '10min' => 600, 'hour' => 3600, 'day' => 86400];

	if (isset($explicit[$interval])) {
		$bucket = $explicit[$interval];
	} elseif ($seconds <= 21600) {
		$bucket = 60;
	} elseif ($seconds <= 259200) {
		$bucket = 600;
	} elseif ($seconds <= 2592000) {
		$bucket = 3600;
	} else {
		$bucket = 86400;
	}

	// Guard render cost: coarsen the bucket through the standard ladder
	// (minute → 10 minutes → hour → day) until the chart stays under the
	// bucket cap.
	$ladder = [60, 600, 3600, 86400];
	$cap = syslog_dashboard_bucket_cap();

	foreach ($ladder as $step) {
		if ($step > $bucket) {
			$bucket = $step;
		}

		if ($seconds / $bucket <= $cap) {
			break;
		}
	}

	return $bucket;
}

/**
 * Format a bucket epoch for chart labels.
 *
 * @param int $epoch   Bucket start as a Unix timestamp.
 * @param int $bucket  Bucket size in seconds.
 *
 * @return string Human-readable local time label.
 */
function syslog_dashboard_bucket_label($epoch, $bucket) {
	return date($bucket >= 86400 ? 'Y-m-d' : 'Y-m-d H:i', (int) $epoch);
}

/**
 * Strip characters that would be unsafe inside a chart label rendered
 * through billboard tooltips (which build HTML client side).
 *
 * @param string $label Raw label from the database.
 *
 * @return string Sanitized label.
 */
function syslog_dashboard_label_safe($label) {
	$label = (string) $label;

	// Remove control characters that can smuggle markup past escaping.
	$label = preg_replace('/[\x00-\x1F\x7F]/u', '', $label);

	if ($label === null) {
		return '';
	}

	$label = trim($label);

	// billboard.js keys legend metrics by target id; an empty (or purely
	// whitespace) label becomes a falsy id and getLegendItemTextBox()
	// returns undefined for it, crashing chart render. Show "Unknown".
	if ($label === '') {
		return __('Unknown', 'syslog');
	}

	// Entities keep the label inert even where a consumer forgets escaping.
	return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Resolve the lookup table and join columns for a breakdown dimension.
 *
 * @param string $field A syslog_dashboard_fields() value.
 * @param string $source Panel source ('syslog' or 'alerts').
 *
 * @return array|null [table, id_column, name_column] or null when the
 *                    dimension reads the log row directly.
 */
function syslog_dashboard_breakdown_lookup($field, $source) {
	if ($source === 'alerts' && $field === 'host') {
		return null;
	}

	$lookups = [
		'host'     => ['syslog_hosts', 'host_id', 'host'],
		'program'  => ['syslog_programs', 'program_id', 'program'],
		'facility' => ['syslog_facilities', 'facility_id', 'facility'],
		'priority' => ['syslog_priorities', 'priority_id', 'priority']
	];

	return $lookups[$field] ?? null;
}

/**
 * Build the SQL that aggregates a timeseries panel.
 *
 * Table names come from fixed allowlists and intervals/buckets are
 * integer literals, so the composed statement contains no request data.
 * The log table is aliased `syslog` so logical search predicates bind
 * exactly as they do in the log viewer queries.
 *
 * @param string $source   'syslog' or 'alerts'.
 * @param int    $removal  Record type for the syslog source (-1, 1, 2).
 * @param string $predicate Validated SQL WHERE predicate (without WHERE).
 * @param int    $bucket   Bucket size in seconds.
 * @param int    $start    Window start as a Unix timestamp.
 *
 * @return string The complete SQL statement.
 */
function syslog_dashboard_timeseries_sql($source, $removal, $predicate, $bucket, $start) {
	global $syslogdb_default;

	$select = 'FLOOR(UNIX_TIMESTAMP(syslog.logtime) / ' . (int) $bucket . ') * ' . (int) $bucket . ' AS bucket, ';

	if ($source === 'alerts') {
		// Alert rows already group identical occurrences per message.
		$select .= 'SUM(syslog.count) AS records';
		$table = "`$syslogdb_default`.`syslog_logs` AS syslog";
	} elseif ($removal == '2') {
		$select .= 'COUNT(*) AS records';
		$table = "`$syslogdb_default`.`syslog_removed` AS syslog";
	} elseif ($removal == '-1') {
		$select .= 'COUNT(*) AS records';
		$table = "`$syslogdb_default`.`syslog` AS syslog";
	} else {
		// All records: UNION over both system log tables, summed per bucket.
		// $select ends with a comma, so each branch appends its aggregate
		// first; adding ', mtype' directly would emit a double comma.
		$select .= 'COUNT(*) AS records';

		$sql = "SELECT bucket, SUM(records) AS records FROM (
			(SELECT $select, 'main' AS mtype
				FROM `$syslogdb_default`.`syslog` AS syslog
				WHERE $predicate)
			UNION ALL
			(SELECT $select, 'remove' AS mtype
				FROM `$syslogdb_default`.`syslog_removed` AS syslog
				WHERE $predicate)
		) AS buckets GROUP BY bucket ORDER BY bucket";

		return $sql;
	}

	return "SELECT $select FROM $table WHERE $predicate GROUP BY bucket ORDER BY bucket";
}

/**
 * Build the SQL that aggregates a breakdown panel.
 *
 * @param string $source   'syslog' or 'alerts'.
 * @param string $field     A syslog_dashboard_fields() value.
 * @param int    $removal  Record type for the syslog source (-1, 1, 2).
 * @param string $predicate Validated SQL WHERE predicate (without WHERE).
 * @param int    $start    Window start as a Unix timestamp.
 *
 * @return string The complete SQL statement.
 */
function syslog_dashboard_breakdown_sql($source, $field, $removal, $predicate, $start) {
	global $syslogdb_default;

	$lookup = syslog_dashboard_breakdown_lookup($field, $source);

	if ($lookup === null) {
		$dimension = 'syslog.host';
	} else {
		[$table, $id_column, $name_column] = $lookup;
		$dimension = "(SELECT lookup.$name_column FROM `$syslogdb_default`.`$table` AS lookup
			WHERE lookup.$id_column = syslog.$id_column)";
	}

	if ($source === 'alerts') {
		$count = 'SUM(syslog.count)';
		$table = "`$syslogdb_default`.`syslog_logs` AS syslog";
	} elseif ($removal == '2') {
		$count = 'COUNT(*)';
		$table = "`$syslogdb_default`.`syslog_removed` AS syslog";
	} elseif ($removal == '-1') {
		$count = 'COUNT(*)';
		$table = "`$syslogdb_default`.`syslog` AS syslog";
	} else {
		return "SELECT label, SUM(records) AS records FROM (
			(SELECT $dimension AS label, COUNT(*) AS records, 'main' AS mtype
				FROM `$syslogdb_default`.`syslog` AS syslog
				WHERE $predicate)
			UNION ALL
			(SELECT $dimension AS label, COUNT(*) AS records, 'remove' AS mtype
				FROM `$syslogdb_default`.`syslog_removed` AS syslog
				WHERE $predicate)
		) AS grouped GROUP BY label ORDER BY records DESC, label ASC";
	}

	return "SELECT $dimension AS label, $count AS records
		FROM $table
		WHERE $predicate
		GROUP BY label ORDER BY records DESC, label ASC";
}

/**
 * Turn one dashboard panel row into a validated, render-ready definition.
 * Returns an error string on the first invalid field.
 *
 * @param array $panel Raw panel row from the database.
 *
 * @return array|string Validated panel or a translated error message.
 */
function syslog_dashboard_panel_settings($panel) {
	$source = (string) ($panel['source'] ?? '');

	if (!in_array($source, syslog_dashboard_sources(), true)) {
		return __('Invalid dashboard panel source.', 'syslog');
	}

	$kind = (string) ($panel['kind'] ?? '');

	if (!in_array($kind, syslog_dashboard_kinds(), true)) {
		return __('Invalid dashboard panel type.', 'syslog');
	}

	$chart = (string) ($panel['chart'] ?? '');

	if (!in_array($chart, syslog_dashboard_charts($kind), true)) {
		return __('Invalid dashboard chart type.', 'syslog');
	}

	$field = (string) ($panel['field'] ?? '');

	if (!in_array($field, syslog_dashboard_fields(), true)) {
		return __('Invalid dashboard breakdown field.', 'syslog');
	}

	$interval = (string) ($panel['interval'] ?? '');

	if (!in_array($interval, syslog_dashboard_intervals(), true)) {
		return __('Invalid dashboard interval.', 'syslog');
	}

	$timespan = (string) ($panel['timespan'] ?? '');

	if (!in_array($timespan, syslog_dashboard_timespans(), true)) {
		return __('Invalid dashboard timespan.', 'syslog');
	}

	$removal = (string) ($panel['removal'] ?? '');

	if ($source === 'syslog' && !in_array($removal, syslog_dashboard_removals(), true)) {
		return __('Invalid dashboard record type.', 'syslog');
	}

	$top_n = (int) ($panel['top_n'] ?? 10);

	if ($top_n < 1 || $top_n > syslog_dashboard_top_n_cap()) {
		return __('Invalid dashboard top count.', 'syslog');
	}

	$width = trim((string) ($panel['width'] ?? ''));

	if ($width === '') {
		$width = 1;
	} else {
		$width = (int) $width;
	}

	if ($width < 1 || $width > syslog_dashboard_width_cap()) {
		return __('Invalid dashboard panel width.', 'syslog');
	}

	$height = trim((string) ($panel['height'] ?? ''));

	if ($height === '') {
		$height = 0;
	} else {
		$height = (int) $height;
	}

	if ($height < 0 || $height > syslog_dashboard_height_cap()) {
		return __('Invalid dashboard panel height.', 'syslog');
	}

	return [
		'source'   => $source,
		'kind'     => $kind,
		'chart'    => $chart,
		'field'    => $field,
		'interval' => $interval,
		'timespan' => $timespan,
		'removal'  => $source === 'alerts' ? '1' : ($removal === '' ? '1' : $removal),
		'top_n'    => $top_n,
		'width'    => $width,
		'height'   => $height
	];
}

/**
 * Fetch and validate a panel owned by the current user.
 *
 * @param int $panel_id Panel id.
 *
 * @return array|null Panel row joined with dashboard ownership, or null.
 */
function syslog_dashboard_load_panel($panel_id) {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);

	$panel = syslog_db_fetch_row_prepared("SELECT p.*
		FROM `$syslogdb_default`.`syslog_dashboard_panels` AS p
		INNER JOIN `$syslogdb_default`.`syslog_dashboards` AS d
		ON p.dashboard_id = d.id
		WHERE p.id = ?
		AND d.`user` = ?",
		[$panel_id, $username]);

	// Some Cacti versions return [] rather than false when no row matches.
	return $panel === false || $panel === null || !cacti_sizeof($panel) ? null : $panel;
}

/**
 * Produce the chart payload for one panel.
 *
 * @param array $panel Valid panel row (from syslog_dashboard_load_panel()).
 * @param int   $dashboard_timespan Seconds for the shared time picker.
 *
 * @return array Chart payload: labels + series for the client renderer.
 */
function syslog_dashboard_panel_data($panel, $dashboard_timespan) {
	$settings = syslog_dashboard_panel_settings($panel);

	if (is_string($settings)) {
		return ['error' => $settings];
	}

	$expression = (string) $panel['expression'];

	try {
		$tree = syslog_parse_logical_search($expression);
		$column = $settings['source'] === 'alerts' ? 'logmsg' : 'message';
		$predicate = syslog_logical_search_sql($tree, $column);
	} catch (InvalidArgumentException $error) {
		return ['error' => __('Invalid logical search: %s', $error->getMessage(), 'syslog')];
	}

	$timespan = $settings['timespan'] === 'dashboard'
		? $dashboard_timespan
		: syslog_dashboard_timespan_seconds($settings['timespan']);

	$start = time() - $timespan;
	$range = 'syslog.logtime BETWEEN FROM_UNIXTIME(' . (int) $start . ') AND FROM_UNIXTIME(' . (int) ($start + $timespan) . ')';
	$where = $predicate === '' ? $range : '(' . $predicate . ') AND ' . $range;

	if ($settings['kind'] === 'timeseries') {
		$bucket = syslog_dashboard_bucket_seconds($timespan, $settings['interval']);

		$sql = syslog_dashboard_timeseries_sql($settings['source'], $settings['removal'], $where, $bucket, $start);

		$rows = syslog_db_fetch_assoc($sql);
		$labels = [];
		$values = [];

		if (cacti_sizeof($rows)) {
			foreach ($rows as $row) {
				$labels[] = syslog_dashboard_bucket_label((int) $row['bucket'], $bucket);
				$values[] = (int) $row['records'];
			}
		}

		return [
			'kind'    => 'timeseries',
			'chart'   => $settings['chart'],
			'bucket'  => $bucket,
			'labels'  => $labels,
			'series'  => [$values],
			'total'   => array_sum($values)
		];
	}

	// Breakdown: honor the requested top-N, capped server side.
	$top_n = min($settings['top_n'], syslog_dashboard_top_n_cap());

	$sql = syslog_dashboard_breakdown_sql($settings['source'], $settings['field'], $settings['removal'], $where, $start);

	$rows = syslog_db_fetch_assoc($sql);

	$labels = [];
	$values = [];
	$total = 0;

	if (cacti_sizeof($rows)) {
		$rank = 0;

		foreach ($rows as $row) {
			$count = (int) $row['records'];
			$total += $count;

			if ($rank < $top_n) {
				$labels[] = syslog_dashboard_label_safe($row['label']);
				$values[] = $count;
				$rank++;
			}
		}

		// Fold everything beyond the top-N into one "Other" slice.
		$other = $total - array_sum($values);

		if ($other > 0) {
			$labels[] = __('Other', 'syslog');
			$values[] = $other;
		}
	}

	// billboard.js uses the label string as the series id; duplicate labels
	// (two rows sharing a sanitized name) collide on one legend entry, so
	// their counts are merged into a single slice instead.
	$merged = [];

	if (cacti_sizeof($labels)) {
		foreach ($labels as $index => $label) {
			$merged[$label] = ($merged[$label] ?? 0) + $values[$index];
		}
	}

	$labels = array_keys($merged);
	$values = array_values($merged);

	return [
		'kind'   => 'breakdown',
		'chart'  => $settings['chart'],
		'labels' => $labels,
		'series' => [$values],
		'total'  => $total
	];
}

/**
 * JSON endpoint: chart data for one panel.
 *
 * @return string JSON response.
 */
function syslog_dashboard_chart_data() {
	global $syslogdb_default;

	if (!isset($_SESSION['sess_user_id'])) {
		return json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	$panel_id = get_filter_request_var('panel_id', FILTER_VALIDATE_INT);

	if ($panel_id === false || $panel_id === null || $panel_id <= 0) {
		return json_encode(['error' => __('A valid dashboard panel is required.', 'syslog')]);
	}

	$panel = syslog_dashboard_load_panel($panel_id);

	if ($panel === null) {
		return json_encode(['error' => __('Dashboard panel not found.', 'syslog')]);
	}

	$dashboard_timespan = syslog_dashboard_request_timespan();

	return json_encode(syslog_dashboard_panel_data($panel, $dashboard_timespan));
}

/** Allowlist the shared dashboard timespan request value. */
function syslog_dashboard_request_timespan() {
	$timespan = (string) get_nfilter_request_var('dashboard_timespan');

	if (!in_array($timespan, syslog_dashboard_timespans(), true)) {
		$timespan = '86400';
	}

	return $timespan === 'dashboard' ? 86400 : syslog_dashboard_timespan_seconds($timespan);
}

/** Owned dashboard row for the current user, or null. */
function syslog_dashboard_load($dashboard_id) {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);

	$dashboard = syslog_db_fetch_row_prepared("SELECT *
		FROM `$syslogdb_default`.`syslog_dashboards`
		WHERE id = ?
		AND `user` = ?",
		[$dashboard_id, $username]);

	// Some Cacti versions return [] rather than false when no row matches.
	return $dashboard === false || $dashboard === null || !cacti_sizeof($dashboard) ? null : $dashboard;
}

/** All dashboards owned by the current user. */
function syslog_dashboard_list() {
	global $syslogdb_default;

	$username = get_username($_SESSION['sess_user_id']);

	return syslog_db_fetch_assoc_prepared("SELECT id, name
		FROM `$syslogdb_default`.`syslog_dashboards`
		WHERE `user` = ?
		ORDER BY name",
		[$username]);
}

/** Panels of one dashboard ordered by their position. */
function syslog_dashboard_panels($dashboard_id) {
	global $syslogdb_default;

	return syslog_db_fetch_assoc_prepared("SELECT *
		FROM `$syslogdb_default`.`syslog_dashboard_panels`
		WHERE dashboard_id = ?
		ORDER BY position, id",
		[$dashboard_id]);
}

/** Next free position for panels of a dashboard. */
function syslog_dashboard_next_position($dashboard_id) {
	global $syslogdb_default;

	$position = syslog_db_fetch_cell_prepared("SELECT MAX(position) + 1
		FROM `$syslogdb_default`.`syslog_dashboard_panels`
		WHERE dashboard_id = ?",
		[$dashboard_id]);

	return $position === null || $position === false ? 1 : (int) $position;
}

/**
 * JSON endpoint: create or rename a dashboard (upsert by name), or delete
 * one (with its panels). All actions are scoped to the current user.
 *
 * @return string JSON response.
 */
function syslog_dashboard_save() {
	global $syslogdb_default;

	if (!isset($_SESSION['sess_user_id'])) {
		return json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	$username = get_username($_SESSION['sess_user_id']);
	$name     = trim((string) get_nfilter_request_var('name'));

	if ($name === '' || strlen($name) > 128) {
		return json_encode(['error' => __('A name of up to 128 characters is required.', 'syslog')]);
	}

	$id = get_filter_request_var('id', FILTER_VALIDATE_INT);
	$delete = get_nfilter_request_var('dashboard_delete') === '1';

	if ($delete) {
		if ($id === false || $id === null || $id <= 0) {
			return json_encode(['error' => __('A valid dashboard is required.', 'syslog')]);
		}

		if (syslog_dashboard_load($id) === null) {
			return json_encode(['error' => __('Dashboard not found.', 'syslog')]);
		}

		syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_dashboard_panels`
			WHERE dashboard_id = ?",
			[$id]);
		syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_dashboards`
			WHERE id = ?",
			[$id]);

		return json_encode(['id' => (int) $id, 'deleted' => true]);
	}

	if ($id > 0) {
		if (syslog_dashboard_load($id) === null) {
			return json_encode(['error' => __('Dashboard not found.', 'syslog')]);
		}

		syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_dashboards`
			SET name = ?, updated = ?
			WHERE id = ?",
			[$name, time(), $id]);

		return json_encode(['id' => (int) $id]);
	}

	syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_dashboards`
		(name, `user`, `date`, updated)
		VALUES (?, ?, ?, ?)",
		[$name, $username, time(), time()]);

	// Upsert semantics: resolve the row by owner and name when the
	// connection's insert id is unavailable on some Cacti builds.
	$id = (int) syslog_db_fetch_insert_id();

	if ($id <= 0) {
		$id = (int) syslog_db_fetch_cell_prepared("SELECT id
			FROM `$syslogdb_default`.`syslog_dashboards`
			WHERE `user` = ? AND name = ?
			ORDER BY id DESC",
			[$username, $name]);
	}

	return json_encode(['id' => $id]);
}

/**
 * JSON endpoint: create or update a panel, or delete/reposition one.
 * Every write re-verifies dashboard ownership.
 *
 * @return string JSON response.
 */
function syslog_dashboard_panel_save() {
	global $syslogdb_default;

	if (!isset($_SESSION['sess_user_id'])) {
		return json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	$dashboard_id = get_filter_request_var('dashboard_id', FILTER_VALIDATE_INT);
	$panel_id     = get_filter_request_var('panel_id', FILTER_VALIDATE_INT);
	$move         = (string) get_nfilter_request_var('panel_move');
	$delete       = get_nfilter_request_var('panel_delete') === '1';
	$resize       = get_nfilter_request_var('panel_resize') === '1';
	$reposition   = get_nfilter_request_var('panel_position') === '1';
	$position     = get_filter_request_var('position', FILTER_VALIDATE_INT);

	if ($dashboard_id === false || $dashboard_id === null || $dashboard_id <= 0) {
		return json_encode(['error' => __('A valid dashboard is required.', 'syslog')]);
	}

	if (syslog_dashboard_load($dashboard_id) === null) {
		return json_encode(['error' => __('Dashboard not found.', 'syslog')]);
	}

	// Repositioning, resizing, and deletion address existing panels only.
	if ($delete || $resize || $reposition || in_array($move, ['up', 'down'], true)) {
		if ($panel_id === false || $panel_id === null || $panel_id <= 0) {
			return json_encode(['error' => __('A valid dashboard panel is required.', 'syslog')]);
		}

		$panel = syslog_dashboard_load_panel($panel_id);

		if ($panel === null || (int) $panel['dashboard_id'] !== (int) $dashboard_id) {
			return json_encode(['error' => __('Dashboard panel not found.', 'syslog')]);
		}

		if ($delete) {
			syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_dashboard_panels`
				WHERE id = ?",
				[$panel_id]);

			return json_encode(['id' => (int) $panel_id, 'deleted' => true]);
		}

		if ($reposition) {
			if ($position === false || $position === null || $position < 1) {
				return json_encode(['error' => __('A valid panel position is required.', 'syslog')]);
			}

			syslog_dashboard_panel_reposition($panel, $dashboard_id, (int) $position);

			return json_encode(['id' => (int) $panel_id, 'position' => (int) $position]);
		}

		if ($resize) {
			$size = syslog_dashboard_panel_settings([
				'source' => $panel['source'], 'kind' => $panel['kind'], 'chart' => $panel['chart'],
				'field' => $panel['field'], 'interval' => $panel['interval'], 'timespan' => $panel['timespan'],
				'removal' => $panel['removal'], 'top_n' => $panel['top_n'],
				'width'  => (string) get_nfilter_request_var('width'),
				'height' => (string) get_nfilter_request_var('height')
			]);

			if (is_string($size)) {
				return json_encode(['error' => $size]);
			}

			syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_dashboard_panels`
				SET width = ?, height = ?
				WHERE id = ?",
				[$size['width'], $size['height'], $panel_id]);

			return json_encode(['id' => (int) $panel_id, 'width' => $size['width'], 'height' => $size['height']]);
		}

		syslog_dashboard_panel_move($panel, $dashboard_id, $move === 'up' ? -1 : 1);

		return json_encode(['id' => (int) $panel_id, 'moved' => $move]);
	}

	// Create or update the definition itself.
	$panel = [
		'source'   => (string) get_nfilter_request_var('source'),
		'kind'     => (string) get_nfilter_request_var('kind'),
		'chart'    => (string) get_nfilter_request_var('chart'),
		'field'    => (string) get_nfilter_request_var('field'),
		'interval' => (string) get_nfilter_request_var('interval'),
		'timespan' => (string) get_nfilter_request_var('timespan'),
		'removal'  => (string) get_nfilter_request_var('removal'),
		'top_n'    => (string) get_nfilter_request_var('top_n'),
		'width'    => (string) get_nfilter_request_var('width'),
		'height'   => (string) get_nfilter_request_var('height')
	];

	$settings = syslog_dashboard_panel_settings($panel);

	if (is_string($settings)) {
		return json_encode(['error' => $settings]);
	}

	$title = trim((string) get_nfilter_request_var('title'));

	if ($title === '' || strlen($title) > 128) {
		return json_encode(['error' => __('A title of up to 128 characters is required.', 'syslog')]);
	}

	$expression = (string) get_nfilter_request_var('expression');

	try {
		$tree = syslog_parse_logical_search($expression);
		syslog_logical_search_sql($tree, $settings['source'] === 'alerts' ? 'logmsg' : 'message');
	} catch (InvalidArgumentException $error) {
		return json_encode(['error' => __('Invalid logical search: %s', $error->getMessage(), 'syslog')]);
	}

	if ($panel_id > 0) {
		$existing = syslog_dashboard_load_panel($panel_id);

		if ($existing === null || (int) $existing['dashboard_id'] !== (int) $dashboard_id) {
			return json_encode(['error' => __('Dashboard panel not found.', 'syslog')]);
		}

		// The editor dialog does not manage size; keep the persisted values
		// when the request did not supply them so dialog saves cannot reset
		// a panel the user has resized.
		if ((string) get_nfilter_request_var('width') === '') {
			$settings['width'] = (int) $existing['width'];
		}

		if ((string) get_nfilter_request_var('height') === '') {
			$settings['height'] = (int) $existing['height'];
		}

		syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_dashboard_panels`
			SET title = ?, expression = ?, source = ?, removal = ?, kind = ?,
			chart = ?, field = ?, `interval` = ?, timespan = ?, top_n = ?,
			width = ?, height = ?
			WHERE id = ?",
			[$title, $expression, $settings['source'], (int) $settings['removal'],
				$settings['kind'], $settings['chart'], $settings['field'],
				$settings['interval'], $settings['timespan'], $settings['top_n'],
				$settings['width'], $settings['height'], $panel_id]);

		return json_encode(['id' => (int) $panel_id]);
	}

	$position = syslog_dashboard_next_position($dashboard_id);

	syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_dashboard_panels`
		(dashboard_id, title, expression, source, removal, kind, chart, field, `interval`, timespan, top_n, width, height, position, `date`)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
		[$dashboard_id, $title, $expression, $settings['source'], (int) $settings['removal'],
			$settings['kind'], $settings['chart'], $settings['field'], $settings['interval'],
			$settings['timespan'], $settings['top_n'], $settings['width'], $settings['height'],
			$position, time()]);

	// Fall back to a positional lookup when the connection's insert id is
	// unavailable on some Cacti builds.
	$id = (int) syslog_db_fetch_insert_id();

	if ($id <= 0) {
		$id = (int) syslog_db_fetch_cell_prepared("SELECT id
			FROM `$syslogdb_default`.`syslog_dashboard_panels`
			WHERE dashboard_id = ?
			ORDER BY id DESC",
			[$dashboard_id]);
	}

	return json_encode(['id' => $id]);
}

/**
 * Swap a panel with its neighbor in the requested direction and renumber
 * the positions so the order stays gapless.
 *
 * @param array $panel        Loaded panel row.
 * @param int   $dashboard_id Owning dashboard id.
 * @param int   $direction    -1 for up, 1 for down.
 */
function syslog_dashboard_panel_move($panel, $dashboard_id, $direction) {
	global $syslogdb_default;

	$panels = syslog_dashboard_panels($dashboard_id);
	$ids = [];

	foreach ($panels as $row) {
		$ids[] = (int) $row['id'];
	}

	$index = array_search((int) $panel['id'], $ids, true);
	$swap  = $index + $direction;

	if ($index === false || $swap < 0 || $swap >= cacti_sizeof($ids)) {
		return;
	}

	[$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];

	foreach ($ids as $position => $id) {
		syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_dashboard_panels`
			SET position = ?
			WHERE id = ?",
			[$position + 1, $id]);
	}
}

/**
 * Move a panel to an absolute 1-based position (drag-and-drop) and
 * renumber the remaining panels so the order stays gapless.
 *
 * @param array $panel        Loaded panel row.
 * @param int   $dashboard_id Owning dashboard id.
 * @param int   $position     Requested 1-based position.
 */
function syslog_dashboard_panel_reposition($panel, $dashboard_id, $position) {
	global $syslogdb_default;

	$panels = syslog_dashboard_panels($dashboard_id);
	$ids = [];

	foreach ($panels as $row) {
		if ((int) $row['id'] !== (int) $panel['id']) {
			$ids[] = (int) $row['id'];
		}
	}

	// Clamp into the valid range, then splice the panel in.
	$position = min(max($position, 1), cacti_sizeof($ids) + 1);
	array_splice($ids, $position - 1, 0, [(int) $panel['id']]);

	foreach ($ids as $index => $id) {
		syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_dashboard_panels`
			SET position = ?
			WHERE id = ?",
			[$index + 1, $id]);
	}
}

/**
 * Render the Dashboard tab: toolbar, grid skeleton, and the client-side
 * bootstrap data (panels and labels) for js/dashboard.js.
 */
function syslog_dashboard() {
	global $config, $syslogdb_default;

	$dashboards    = syslog_dashboard_list();
	$dashboard_id  = get_filter_request_var('dashboard_id', FILTER_VALIDATE_INT);

	// Keep only an owned dashboard selected; fall back to the first one so
	// the tab opens ready instead of the empty state while one exists.
	if ($dashboard_id === false || $dashboard_id === null || syslog_dashboard_load($dashboard_id) === null) {
		$dashboard_id = 0;
	}

	if ($dashboard_id === 0 && cacti_sizeof($dashboards)) {
		$dashboard_id = (int) reset($dashboards)['id'];
	}

	$panels = $dashboard_id > 0 ? syslog_dashboard_panels($dashboard_id) : [];

	$panel_json = [];

	if (cacti_sizeof($panels)) {
		foreach ($panels as $panel) {
			// Editing rehydrates the builder from the server-parsed tree, so
			// a legacy or hand-edited expression can never corrupt the dialog.
			try {
				$tree = syslog_parse_logical_search((string) $panel['expression']);
			} catch (InvalidArgumentException $error) {
				$tree = null;
			}

			$panel_json[] = [
				'id'       => (int) $panel['id'],
				'title'    => (string) $panel['title'],
				'source'   => (string) $panel['source'],
				'kind'     => (string) $panel['kind'],
				'chart'    => (string) $panel['chart'],
				'field'    => (string) $panel['field'],
				'interval' => (string) $panel['interval'],
				'timespan' => (string) $panel['timespan'],
				'removal'  => (string) $panel['removal'],
				'top_n'    => (int) $panel['top_n'],
				'width'    => (int) $panel['width'],
				'height'   => (int) $panel['height'],
				'tree'     => $tree
			];
		}
	}

	$username = isset($_SESSION['sess_user_id']) ? get_username($_SESSION['sess_user_id']) : '';

	$saved_searches = $username === '' ? [] : syslog_db_fetch_assoc_prepared("SELECT id, name, search, removal
		FROM `$syslogdb_default`.`syslog_saved_searches`
		WHERE `user` = ? OR is_global = 'on'
		ORDER BY is_global, name",
		[$username]);

	// Pre-parse each saved search server side; the dialog imports the tree
	// directly instead of re-parsing DSL text in the browser.
	$saved_trees = [];

	foreach ($saved_searches as $saved) {
		try {
			$saved_trees[(int) $saved['id']] = syslog_parse_logical_search((string) $saved['search']);
		} catch (InvalidArgumentException $error) {
			$saved_trees[(int) $saved['id']] = null;
		}
	}
	?>
	<script type='text/javascript'>
	var syslogDashboard = {
		panels: <?php print syslog_json_safe($panel_json); ?>,
		dashboardId: <?php print (int) $dashboard_id; ?>,
		timespan: <?php print syslog_json_safe((string) get_nfilter_request_var('dashboard_timespan') ?: '86400'); ?>,
		refresh: <?php print (int) get_request_var('refresh'); ?>,
		text: {
			libraryUnavailable: <?php print syslog_json_safe(__('Chart library unavailable on this Cacti installation.', 'syslog')); ?>,
			editPanel: <?php print syslog_json_safe(__('Edit Panel', 'syslog')); ?>,
			newPanel: <?php print syslog_json_safe(__('New Panel', 'syslog')); ?>,
			save: <?php print syslog_json_safe(__('Save', 'syslog')); ?>,
			cancel: <?php print syslog_json_safe(__('Cancel', 'syslog')); ?>,
			apply: <?php print syslog_json_safe(__('Apply', 'syslog')); ?>,
			delete: <?php print syslog_json_safe(__('Delete', 'syslog')); ?>,
			deletePanelConfirm: <?php print syslog_json_safe(__('Delete this dashboard panel?', 'syslog')); ?>,
			deleteDashboardConfirm: <?php print syslog_json_safe(__('Delete this dashboard and all of its panels?', 'syslog')); ?>,
			emptyTitle: <?php print syslog_json_safe(__('Name your dashboard', 'syslog')); ?>
		}
	};
	</script>
	<?php

	html_start_box(__('Syslog Dashboards', 'syslog'), '100%', '', '3', 'center', '');
	?>
	<tr class='even noprint syslogFilterRow'>
		<td class='noprint'>
			<form id='syslog_dashboard_form' data-theme='<?php print html_escape(get_selected_theme()); ?>' action='syslog.php' method='post'>
				<input type='hidden' name='tab' value='dashboard'>
				<input type='hidden' id='syslog_dashboard_selected' name='dashboard_id' value='<?php print (int) $dashboard_id; ?>'>
				<section class='syslogSearchPanel syslogDashboardBar' aria-labelledby='syslog_dashboard_title'>
					<div class='syslogSearchHeader'>
						<div class='syslogSearchHeading'>
							<span class='syslogSearchIcon' aria-hidden='true'><i class='fa fa-area-chart'></i></span>
							<h3 id='syslog_dashboard_title'><?php print __esc('Dashboard', 'syslog'); ?></h3>
						</div>
						<div class='syslogDashboardTimespan'>
							<label for='syslog_dashboard_timespan'><?php print __esc('Time Range', 'syslog'); ?></label>
							<select id='syslog_dashboard_timespan'>
								<?php
								$selected_timespan = (string) get_nfilter_request_var('dashboard_timespan') ?: '86400';
								$timespans = [
									'3600'     => __('Last Hour', 'syslog'),
									'21600'    => __('Last %d Hours', 6, 'syslog'),
									'86400'    => __('Last Day', 'syslog'),
									'604800'   => __('Last Week', 'syslog'),
									'1209600'  => __('Last %d Weeks', 2, 'syslog'),
									'2592000'  => __('Last Month', 'syslog'),
									'3months'  => __('Last %d Months', 3, 'syslog'),
									'6months'  => __('Last %d Months', 6, 'syslog')
								];

								foreach ($timespans as $value => $label) {
									print "<option value='" . html_escape($value) . "'" . ($selected_timespan === $value ? ' selected' : '') . '>' . html_escape($label) . '</option>';
								}
								?>
							</select>
						</div>
					</div>
					<div class='syslogDashboardBarRow'>
						<label for='syslog_dashboard_select'><?php print __('Dashboards', 'syslog'); ?></label>
						<select id='syslog_dashboard_select'>
							<?php
							if (cacti_sizeof($dashboards)) {
								foreach ($dashboards as $dashboard) {
									print "<option value='" . (int) $dashboard['id'] . "'" . ((int) $dashboard['id'] === (int) $dashboard_id ? ' selected' : '') . '>' . html_escape($dashboard['name']) . '</option>';
								}
							}
							?>
						</select>
						<input type='button' id='syslog_dashboard_new' value='<?php print __esc('New', 'syslog'); ?>'>
						<input type='button' id='syslog_dashboard_rename' value='<?php print __esc('Rename', 'syslog'); ?>'>
						<input type='button' id='syslog_dashboard_delete' value='<?php print __esc('Delete', 'syslog'); ?>'>
						<span class='syslogDashboardPanelActions'>
							<input type='button' id='syslog_panel_new' value='<?php print __esc('Add Panel', 'syslog'); ?>'>
						</span>
					</div>
				</section>
			</form>
		</td>
	</tr>
	<?php
	html_end_box(false);
	?>
	<div id='syslog_dashboard_grid' class='syslogDashboardGrid' data-dashboard='<?php print (int) $dashboard_id; ?>'></div>
	<div id='syslog_dashboard_empty' class='syslogDashboardEmpty'<?php print $dashboard_id > 0 ? ' hidden' : ''; ?>>
		<p><?php print __('Create your first dashboard to visualize syslog trends.', 'syslog'); ?></p>
	</div>
	<?php
	if ($dashboard_id > 0 && !cacti_sizeof($panels)) {
		?>
		<div id='syslog_dashboard_nopanels' class='syslogDashboardEmpty'>
			<p><?php print __('Add a panel to start charting.  Panels can use your saved searches.', 'syslog'); ?></p>
		</div>
		<?php
	}

	// Panel editor dialog scaffold (SyslogFilterBuilder attaches here).
	$fields = syslog_search_fields();
	$choices = syslog_search_choices();
	?>
	<div id='syslog_panel_dialog' class='syslogPanelDialog' style='display:none'
		data-save='<?php print __esc('Save', 'syslog'); ?>'
		data-cancel='<?php print __esc('Cancel', 'syslog'); ?>'
		data-title='<?php print __esc('Dashboard Panel', 'syslog'); ?>'>
		<div class='syslogPanelDialogRows'>
			<div>
				<label for='syslog_panel_title'><?php print __('Title', 'syslog'); ?></label>
				<input type='text' id='syslog_panel_title' maxlength='128' size='40'>
			</div>
			<div>
				<label for='syslog_panel_source'><?php print __('Log Source', 'syslog'); ?></label>
				<select id='syslog_panel_source'>
					<option value='syslog'><?php print __esc('System Logs', 'syslog'); ?></option>
					<option value='alerts'><?php print __esc('Alert Logs', 'syslog'); ?></option>
				</select>
			</div>
			<div>
				<label for='syslog_panel_kind'><?php print __('Panel Type', 'syslog'); ?></label>
				<select id='syslog_panel_kind'>
					<option value='timeseries'><?php print __esc('Time Series', 'syslog'); ?></option>
					<option value='breakdown'><?php print __esc('Breakdown', 'syslog'); ?></option>
				</select>
			</div>
			<div class='syslogPanelOnlyTimeseries'>
				<label for='syslog_panel_chart'><?php print __('Chart', 'syslog'); ?></label>
				<select id='syslog_panel_chart'>
					<option value='line'><?php print __esc('Line', 'syslog'); ?></option>
					<option value='area'><?php print __esc('Area', 'syslog'); ?></option>
					<option value='bar'><?php print __esc('Bar', 'syslog'); ?></option>
				</select>
			</div>
			<div class='syslogPanelOnlyTimeseries'>
				<label for='syslog_panel_interval'><?php print __('Interval', 'syslog'); ?></label>
				<select id='syslog_panel_interval'>
					<option value='dashboard'><?php print __esc('Dashboard', 'syslog'); ?></option>
					<option value='auto'><?php print __esc('Auto', 'syslog'); ?></option>
					<option value='minute'><?php print __esc('Minute', 'syslog'); ?></option>
					<option value='10min'><?php print __esc('10 Minutes', 'syslog'); ?></option>
					<option value='hour'><?php print __esc('Hour', 'syslog'); ?></option>
					<option value='day'><?php print __esc('Day', 'syslog'); ?></option>
				</select>
			</div>
			<div class='syslogPanelOnlyBreakdown'>
				<label for='syslog_panel_field'><?php print __('Field', 'syslog'); ?></label>
				<select id='syslog_panel_field'>
					<option value='host'><?php print __esc('Host', 'syslog'); ?></option>
					<option value='program'><?php print __esc('Program', 'syslog'); ?></option>
					<option value='facility'><?php print __esc('Facility', 'syslog'); ?></option>
					<option value='priority'><?php print __esc('Priority', 'syslog'); ?></option>
				</select>
			</div>
			<div class='syslogPanelOnlyBreakdown'>
				<label for='syslog_panel_top_n'><?php print __('Top Count', 'syslog'); ?></label>
				<input type='number' id='syslog_panel_top_n' min='1' max='50' value='10'>
			</div>
			<div>
				<label for='syslog_panel_timespan'><?php print __('Time Range', 'syslog'); ?></label>
				<select id='syslog_panel_timespan'>
					<option value='dashboard'><?php print __esc('Dashboard', 'syslog'); ?></option>
					<option value='3600'><?php print __esc('Last Hour', 'syslog'); ?></option>
					<option value='21600'><?php print __esc('Last %d Hours', 6, 'syslog'); ?></option>
					<option value='86400'><?php print __esc('Last Day', 'syslog'); ?></option>
					<option value='604800'><?php print __esc('Last Week', 'syslog'); ?></option>
					<option value='1209600'><?php print __esc('Last %d Weeks', 2, 'syslog'); ?></option>
					<option value='2592000'><?php print __esc('Last Month', 'syslog'); ?></option>
					<option value='3months'><?php print __esc('Last %d Months', 3, 'syslog'); ?></option>
					<option value='6months'><?php print __esc('Last %d Months', 6, 'syslog'); ?></option>
				</select>
			</div>
			<div class='syslogPanelOnlySyslog'>
				<label for='syslog_panel_removal'><?php print __('Record Type', 'syslog'); ?></label>
				<select id='syslog_panel_removal'>
					<option value='1'><?php print __esc('All Records', 'syslog'); ?></option>
					<option value='-1'><?php print __esc('Main Records', 'syslog'); ?></option>
					<option value='2'><?php print __esc('Removed Records', 'syslog'); ?></option>
				</select>
			</div>
			<div>
				<label for='syslog_panel_saved'><?php print __('Start from Saved Search', 'syslog'); ?></label>
				<select id='syslog_panel_saved'>
					<option value='0'><?php print __esc('None', 'syslog'); ?></option>
					<?php
					if (cacti_sizeof($saved_searches)) {
						foreach ($saved_searches as $saved) {
							print "<option value='" . (int) $saved['id'] . "'"
								. " data-tree='" . html_escape(json_encode($saved_trees[(int) $saved['id']] ?? null)) . "'"
								. " data-removal='" . html_escape($saved['removal']) . "'>"
								. html_escape($saved['name']) . '</option>';
						}
					}
					?>
				</select>
			</div>
		</div>
		<div id='syslog_panel_builder' class='syslogSearchBuilder'
			data-theme='cacti'
			data-choices='<?php print html_escape(json_encode($choices)); ?>'
			data-fields='<?php print html_escape(json_encode($fields)); ?>'
			data-message='<?php print __esc('Message', 'syslog'); ?>'
			data-placeholder='<?php print __esc('Enter message text…', 'syslog'); ?>'
			data-remove='<?php print __esc('Remove condition', 'syslog'); ?>'
			data-match='<?php print __esc('Match group', 'syslog'); ?>'
			data-exclude='<?php print __esc('Exclude group', 'syslog'); ?>'>
		</div>
	</div>
	<div id='syslog_dashboard_prompt' class='syslogSavedPrompt' title='<?php print __esc('Dashboard Name', 'syslog'); ?>' style='display:none'>
		<div class='syslogSavedNameRow'>
			<label for='syslog_dashboard_prompt_name'><?php print __('Name', 'syslog'); ?></label>
			<input type='text' id='syslog_dashboard_prompt_name' size='40' maxlength='128'>
		</div>
	</div>
	<?php
}