<?php
/* Optional in-process Alarm Manager submission. No dependency on its PHP files. */

function syslog_alarmmanager_selected(array $alert): bool {
	return ($alert['alarm_destination'] ?? 'syslog') === 'alarmmanager';
}

/** True only when ingestion has committed this exact event (including retries). */
function syslog_alarmmanager_forward(array $alert, array $matches, int $count, bool $recovered = false, ?int $cleared_at = null): bool {
	global $config, $syslogdb_default;

	if (!syslog_alarmmanager_selected($alert) || read_config_option('syslog_alarmmanager_enabled') !== 'on') {
		return false;
	}

	try {
		if (!api_plugin_installed('alarmmanager') || !api_plugin_is_enabled('alarmmanager')) {
			throw new RuntimeException('plugin_unavailable');
		}
		// Cacti exposes no hook-presence helper; inspect its enabled registry.
		if (!db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_hooks WHERE name = ? AND hook = ? AND status = 1', ['alarmmanager', 'alarmmanager_submit'])) {
			throw new RuntimeException('hook_unavailable');
		}
		$collector = (int) ($config['poller_id'] ?? 1);
		$state = $alert['_threshold_state'] ?? [];
		if ($collector < 1 || (isset($state['source_poller_id']) && (int) $state['source_poller_id'] !== $collector) || !$matches || ($recovered && (!$state || $cleared_at === null))) {
			throw new RuntimeException('invalid_owner_or_event');
		}
		foreach ($matches as $match) {
			if (isset($match['replication_source_poller_id']) && (int) $match['replication_source_poller_id'] > 0 && (int) $match['replication_source_poller_id'] !== $collector) {
				throw new RuntimeException('foreign_collector');
			}
			if (empty($match['seq']) || empty($match['logtime'])) {
				throw new RuntimeException('source_identity_missing');
			}
		}
		usort($matches, static fn($a, $b) => (int) $a['seq'] <=> (int) $b['seq']);
		$first = $matches[0];
		$identity = hash('sha256', json_encode([$alert['id'], $collector, array_map(static fn($m) => [$m['seq'], $m['logtime']], $matches)], JSON_THROW_ON_ERROR));
		$episode = $state['breach_key'] ?? $identity;
		$transition = $recovered ? 'recovered' : ($state ? 'raised' : 'occurrence');
		$time = $recovered ? (new DateTimeImmutable('@' . $cleared_at)) : new DateTimeImmutable($first['logtime']);
		$event = [
			'event_id' => $recovered ? hash('sha256', $episode . ':clear') : $identity,
			'source' => 'syslog',
			'source_instance' => hash('sha256', read_config_option('base_url') . ':' . $syslogdb_default . ':' . $collector),
			'resource_id' => 'rule:' . $alert['id'],
			// Independent episodes must not share a fixed-sequence stream.
			'alarm_key' => 'rule:' . $alert['id'] . ':' . $episode,
			'episode_id' => $episode,
			'sequence' => $recovered ? PHP_INT_MAX : (int) $first['seq'],
			'transition' => $transition,
			'severity' => $recovered ? 'clear' : ([0 => 'info', 1 => 'warning', 2 => 'critical'][(int) $alert['severity']] ?? 'warning'),
			'occurred_at' => $time->format('Y-m-d\TH:i:sP'),
			'collector_id' => $collector,
			// Syslog host IDs are not Cacti device IDs.
			'device' => ['id' => null, 'hostname' => substr((string) $first['host'], 0, 255), 'external_id' => 'syslog:' . ($first['host_id'] ?? $first['host'])],
			'summary' => substr((string) $alert['name'], 0, 512),
			'details' => substr(implode("\n", array_map(static fn($m) => $m['host'] . ' ' . $m['logtime'] . ' #' . $m['seq'] . ' [priority ' . ($m['priority_id'] ?? '') . ', facility ' . ($m['facility_id'] ?? '') . ']: ' . $m['message'], $matches)), 0, 16384),
			'context' => ['rule_name' => substr((string) $alert['name'], 0, 512), 'rule_type' => (string) $alert['method'], 'metric_value' => (string) $count, 'threshold' => (string) ($alert['num'] ?? 1), 'source_notification_channels' => ['email']]
		];
		$envelope = ['contract_version' => 1, 'event' => $event];
		$result = api_plugin_hook_function('alarmmanager_submit', $envelope);
		if (is_array($result) && in_array($result['status'] ?? null, ['accepted', 'already_accepted'], true)
			&& is_int($result['event_row_id'] ?? null) && $result['event_row_id'] > 0
			&& ($result['event'] ?? null) === $event) {
			if (!$recovered && $state) {
				try {
					if (!syslog_db_execute_prepared("UPDATE syslog_threshold_state SET recovery_context = JSON_SET(recovery_context, '$.alarmmanager_accepted', true) WHERE source_poller_id = ? AND breach_key = ?", [$collector, $state['breach_key']])) {
						throw new RuntimeException('recovery_context_write_failed');
					}
				} catch (Throwable $error) {
					// Acceptance is already committed; a local failure must not resend email.
					cacti_log('SYSLOG: Unable to retain Alarm Manager recovery ownership for rule ' . (int) $alert['id'], false, 'SYSLOG');
				}
			}
			return true;
		}
		throw new RuntimeException(is_array($result) && in_array($result['status'] ?? null, ['failed', 'rejected', 'unhandled'], true) ? 'submission_' . $result['status'] : 'submission_not_accepted');
	} catch (Throwable $error) {
		// Never log provider exceptions or message contents.
		$reason = in_array($error->getMessage(), ['plugin_unavailable', 'hook_unavailable', 'invalid_owner_or_event', 'foreign_collector', 'source_identity_missing', 'submission_not_accepted', 'submission_failed', 'submission_rejected', 'submission_unhandled'], true) ? $error->getMessage() : 'submission_exception';
		cacti_log('SYSLOG: Alarm Manager ' . $reason . ' for rule ' . (int) $alert['id'] . '; retaining Syslog notification', false, 'SYSLOG');
		return false;
	}
}
