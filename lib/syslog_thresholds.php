<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/** Keep the tab available when configured thresholds are healthy. */
function syslog_thresholds_enabled(): bool {
	return (bool) syslog_db_fetch_cell("SELECT EXISTS (SELECT 1 FROM syslog_alert WHERE enabled = 'on' AND method = 1)");
}

/** Read only live episodes, including collector snapshots that have not expired. */
function syslog_breached_threshold_where(): string {
	return "a.enabled = 'on' AND a.method = 1 AND s.rule_date = a.date AND s.expires_at > UNIX_TIMESTAMP()";
}

function syslog_breached_threshold_count(): int {
	return (int) syslog_db_fetch_cell('SELECT COUNT(*) FROM syslog_threshold_state AS s JOIN syslog_alert AS a ON a.id = s.alert_id WHERE ' . syslog_breached_threshold_where());
}

/** Paginate the small episode table rather than scanning message history. */
function syslog_breached_thresholds(): void {
	global $config;

	$total = syslog_breached_threshold_count();
	$page = max(1, min((int) get_filter_request_var('breach_page'), max(1, (int) ceil($total / 50))));
	$offset = ($page - 1) * 50;
	$breaches = syslog_db_fetch_assoc('SELECT s.*, a.name, a.level,
		(SELECT MIN(h.host_id) FROM syslog_hosts AS h WHERE h.host = s.scope_key) AS host_id
		FROM syslog_threshold_state AS s JOIN syslog_alert AS a ON a.id = s.alert_id
		WHERE ' . syslog_breached_threshold_where() . " ORDER BY s.last_match DESC, s.source_poller_id, s.alert_id, s.scope_key LIMIT 50 OFFSET $offset");
	$base = $config['url_path'] . 'plugins/syslog/';

	html_start_box(__('Breached Thresholds', 'syslog'), '100%', '', '3', 'center', '');
	html_header([__('Rule', 'syslog'), __('Device', 'syslog'), __('Collector', 'syslog'), __('Breached Since', 'syslog'), __('Last Matching Message', 'syslog'), __('Recovers After', 'syslog')]);
	foreach ($breaches as $breach) {
		$rule = html_escape($breach['name']);
		if (api_plugin_user_realm_auth('syslog_alerts.php')) {
			$rule = '<a href="' . html_escape($base . 'syslog_alerts.php?action=edit&id=' . (int) $breach['alert_id']) . '">' . $rule . '</a>';
		}
		$device = (int) $breach['level'] === 1 ? html_escape($breach['scope_key']) : __('All matching devices', 'syslog');
		if ((int) $breach['level'] === 1 && !empty($breach['host_id'])) {
			$device = '<a href="' . html_escape($base . 'syslog.php?' . http_build_query(['tab' => 'syslog', 'reset' => 1, 'search_mode' => 'logical', 'rfilter' => 'host = ' . json_encode($breach['scope_key'])])) . '">' . $device . '</a>';
		}
		print '<tr><td>' . $rule . '</td><td>' . $device . '</td><td>' . (int) $breach['source_poller_id'] . '</td>';
		foreach (['breached_at', 'last_match', 'expires_at'] as $field) {
			print '<td class="nowrap">' . html_escape(empty($breach[$field]) ? __('Unknown', 'syslog') : date('Y-m-d H:i:s', (int) $breach[$field])) . '</td>';
		}
		print '</tr>';
	}
	if (!$breaches) {
		print '<tr><td colspan="6">' . __('No thresholds are currently breached.', 'syslog') . '</td></tr>';
	}
	html_end_box(false);
	print '<p>' . __('Recovery requires no matching messages until the displayed time. Every match restarts the quiet period.', 'syslog') . '</p>';
	print '<p><a href="' . html_escape($base . 'syslog.php?tab=breached&breach_page=' . $page) . '">' . __('Refresh', 'syslog') . '</a>';
	if ($page > 1) {
		print ' | <a href="' . html_escape($base . 'syslog.php?tab=breached&breach_page=' . ($page - 1)) . '">' . __('Previous', 'syslog') . '</a>';
	}
	if ($offset + 50 < $total) {
		print ' | <a href="' . html_escape($base . 'syslog.php?tab=breached&breach_page=' . ($page + 1)) . '">' . __('Next', 'syslog') . '</a>';
	}
	print '</p>';
}
