<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Settings callbacks. */

require_once(dirname(__DIR__) . '/setup.php');

/**
 * Register the Syslog settings in the Cacti settings table.
 *
 * @return void
 */
function syslog_alert_maintenance_time_options(): array {
	$options = [];
	for ($hour = 0; $hour < 24; $hour++) {
		foreach ([0, 30] as $minute) {
			$value = sprintf('%02d:%02d', $hour, $minute);
			$options[$value] = $value;
		}
	}

	return $options;
}

function syslog_alert_maintenance_day_options(bool $include_disabled = false): array {
	$options = [
		'1' => __('Monday', 'syslog'), '2' => __('Tuesday', 'syslog'), '3' => __('Wednesday', 'syslog'),
		'4' => __('Thursday', 'syslog'), '5' => __('Friday', 'syslog'), '6' => __('Saturday', 'syslog'), '7' => __('Sunday', 'syslog'),
		'1,2,3,4,5' => __('Monday through Friday', 'syslog'), '6,7' => __('Saturday and Sunday', 'syslog'),
		'1,2,3,4,5,6,7' => __('Every day', 'syslog')
	];

	return $include_disabled ? ['0' => __('Disabled', 'syslog')] + $options : $options;
}

function syslog_config_settings(): void {
	global $config, $tabs, $formats, $settings, $syslog_retentions, $syslog_alert_retentions, $syslog_refresh;

	include_once($config['base_path'] . '/lib/reports.php');

	if (get_nfilter_request_var('tab') == 'syslog') {
		$formats = reports_get_format_files();
	} elseif (empty($formats)) {
		$formats = [];
	}

	$tabs['syslog'] = __('Syslog', 'syslog');

	$temp = [
		'syslog_header' => [
			'friendly_name' => __('General Settings', 'syslog'),
			'method'        => 'spacer',
		],
		'syslog_enabled' => [
			'friendly_name' => __('Syslog Enabled', 'syslog'),
			'description'   => __('If this checkbox is set, records will be transferred from the Syslog Incoming table to the main syslog table and Alerts and Reports will be enabled.  Please keep in mind that if the system is disabled log entries will still accumulate into the Syslog Incoming table as this is defined by the rsyslog or syslog-ng process.', 'syslog'),
			'method'        => 'checkbox',
			'default'       => 'on'
		],
		'syslog_domains' => [
			'friendly_name' => __('Strip Domains', 'syslog'),
			'description'   => __('A comma delimited list of domains that you wish to remove from the syslog hostname, Examples would be \'mydomain.com, otherdomain.com\'', 'syslog'),
			'method'        => 'textbox',
			'default'       => '',
			'size'          => 80,
			'max_length'    => 255,
		],
		'syslog_refresh' => [
			'friendly_name' => __('Refresh Interval', 'syslog'),
			'description'   => __('This is the time in seconds before the page refreshes.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '300',
			'array'         => $syslog_refresh
		],
		'syslog_maxrecords' => [
			'friendly_name' => __('Max Report Records', 'syslog'),
			'description'   => __('For Threshold based Alerts, what is the maximum number that you wish to show in the report.  This is used to limit the size of the html log and Email.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '100',
			'array'         => [
				20  => __('%d Records', 20, 'syslog'),
				40  => __('%d Records', 40, 'syslog'),
				60  => __('%d Records', 60, 'syslog'),
				100 => __('%d Records', 100, 'syslog'),
				200 => __('%d Records', 200, 'syslog'),
				400 => __('%d Records', 400, 'syslog')
			]
		],
		'syslog_max_workers' => [
			'friendly_name' => __('Maximum Syslog Processing Processes', 'syslog'),
			'description'   => __('When set above 1, incoming message processing is spread across parallel worker processes.  Each worker handles a disjoint range of incoming messages, speeding up hostname resolution and message transfer under heavy loads.  A setting of 1 processes all messages in a single process.  Worker processes are not supported on Windows or when the PHP pcntl and POSIX extensions are unavailable, in which case processing falls back to a single process.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '1',
			'array'         => [
				1  => __('%d Worker Process', 1, 'syslog'),
				2  => __('%d Worker Processes', 2, 'syslog'),
				4  => __('%d Worker Processes', 4, 'syslog'),
				6  => __('%d Worker Processes', 6, 'syslog'),
				8  => __('%d Worker Processes', 8, 'syslog'),
				16 => __('%d Worker Processes', 16, 'syslog')
			]
		],
		'syslog_ticket_command' => [
			'friendly_name' => __('Command for Opening Tickets', 'syslog'),
			'description'   => __('This command will be executed for opening Help Desk Tickets.  The command will be required to parse multiple input parameters as follows: <b>--alert-name</b>, <b>--severity</b>, <b>--hostlist</b>, <b>--message</b>.  The hostlist will be a comma delimited list of hosts impacted by the alert.', 'syslog'),
			'method'        => 'textbox',
			'max_length'    => 255,
			'size'          => 80
		],
		'syslog_html_header' => [
			'friendly_name' => __('Host Discovery Options', 'syslog'),
			'method'        => 'spacer',
			'collapsible'   => 'true'
		],
		'syslog_resolve_hostname' => [
			'friendly_name' => __('Enable Hostname Resolution', 'syslog'),
			'description'   => __('If this checkbox is set, all hostnames are resolved via DNS lookup first (If enabled). If the DNS lookup fails, the system will attempt to resolve the hostname against the Cacti host table and replace it with the Cacti host description. If both DNS and Cacti lookups fail, records are assigned a prefix \'unresolved-Original_hostname\'.', 'syslog'),
			'method'        => 'checkbox',
			'default'       => ''
		],
		'syslog_no_dns' => [
			'friendly_name' => __('Skip DNS Resolution for incoming hosts', 'syslog'),
			'description'   => __('If this checkbox is set, the system will not attempt to resolve hosts via DNS lookups.  This is useful for environments where DNS resolution is not possible or not desired.', 'syslog'),
			'method'        => 'checkbox',
			'default'       => ''
		],
		'syslog_html_notification_header' => [
			'friendly_name' => __('HTML Notification Settings', 'syslog'),
			'method'        => 'spacer',
			'collapsible'   => 'true'
		],
		'syslog_html' => [
			'friendly_name' => __('Enable HTML Based Email', 'syslog'),
			'description'   => __('If this checkbox is set, all Emails will be sent in HTML format.  Otherwise, Emails will be sent in plain text.', 'syslog'),
			'method'        => 'checkbox',
			'default'       => 'on'
		],
		'syslog_format_file' => [
			'friendly_name' => __('Format File to Use', 'syslog'),
			'method'        => 'drop_array',
			'default'       => 'default.format',
			'description'   => __('Choose the custom html wrapper and CSS file to use.  This file contains both html and CSS to wrap around your report.  If it contains more than simply CSS, you need to place a special <REPORT> tag inside of the file.  This format tag will be replaced by the report content.  These files are located in the \'formats\' directory.', 'syslog'),
			'array'         => $formats
		],
		'syslog_retention_header' => [
			'friendly_name' => __('Data Retention Settings', 'syslog'),
			'method'        => 'spacer',
			'collapsible'   => 'true'
		],
		'syslog_retention' => [
			'friendly_name' => __('Syslog Retention', 'syslog'),
			'description'   => __('This is the number of days to keep events.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '30',
			'array'         => $syslog_retentions
		],
		'syslog_partition_ahead_days' => [
			'friendly_name' => __('Partition Pre-create Window', 'syslog'),
			'description'   => __('This is the number of future daily partitions to maintain for partitioned Syslog tables.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '3',
			'array'         => [
				'1' => __('%d Day', 1, 'syslog'),
				'2' => __('%d Days', 2, 'syslog'),
				'3' => __('%d Days', 3, 'syslog'),
				'4' => __('%d Days', 4, 'syslog'),
				'5' => __('%d Days', 5, 'syslog'),
				'6' => __('%d Days', 6, 'syslog'),
				'7' => __('%d Days', 7, 'syslog')
			]
		],
		'syslog_partition_recover_limit' => [
			'friendly_name' => __('Partition Recovery Limit', 'syslog'),
			'description'   => __('When partitions are missing (for example after maintenance downtime), at most this many missing partitions per table are created on each poller run.  The remaining gap heals over subsequent runs; retention pruning stays deferred until the future partition horizon is fully restored.  Applies to partitioned Syslog tables only.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '3',
			'array'         => [
				'1'  => __('%d Partition per run', 1, 'syslog'),
				'2'  => __('%d Partitions per run', 2, 'syslog'),
				'3'  => __('%d Partitions per run', 3, 'syslog'),
				'5'  => __('%d Partitions per run', 5, 'syslog'),
				'10' => __('%d Partitions per run', 10, 'syslog'),
				'31' => __('%d Partitions per run', 31, 'syslog')
			]
		],
		'syslog_stale_threshold' => [
			'friendly_name' => __('Collector Staleness Threshold', 'syslog'),
			'description'   => __('The Syslog Status page shows a warning when no new message has arrived within this many seconds.  Set to 0 to use the default of 300 seconds.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '300',
			'array'         => [
				'0'    => __('Default (300 seconds)', 'syslog'),
				'120'  => __('%d seconds', 120, 'syslog'),
				'300'  => __('%d seconds', 300, 'syslog'),
				'600'  => __('%d seconds', 600, 'syslog'),
				'1800' => __('%d seconds', 1800, 'syslog'),
				'3600' => __('%d seconds', 3600, 'syslog')
			]
		],
		'syslog_backlog_threshold' => [
			'friendly_name' => __('Collector Backlog Threshold', 'syslog'),
			'description'   => __('The Syslog Status page shows a warning when more than this many messages sit unprocessed in the Syslog Incoming table.  Set to 0 to use the default of 10000 messages.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '10000',
			'array'         => [
				'0'     => __('Default (10000 messages)', 'syslog'),
				'1000'  => __('%d Messages', 1000, 'syslog'),
				'5000'  => __('%d Messages', 5000, 'syslog'),
				'10000' => __('%d Messages', 10000, 'syslog'),
				'50000' => __('%d Messages', 50000, 'syslog')
			]
		],
		'syslog_alert_retention' => [
			'friendly_name' => __('Syslog Alert Retention', 'syslog'),
			'description'   => __('This is the number of days to keep alert logs.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '30',
			'array'         => $syslog_alert_retentions
		],
		'syslog_alert_suppression_header' => [
			'friendly_name' => __('Alert Suppression and Maintenance', 'syslog'),
			'method'        => 'spacer',
			'collapsible'   => 'true'
		],
		'syslog_alert_maintenance_days' => [
			'friendly_name' => __('Maintenance Window Days', 'syslog'),
			'description'   => __('Days when all alert notifications are muted during the configured maintenance timeframe.', 'syslog'),
			'method'        => 'drop_array',
			'array'         => syslog_alert_maintenance_day_options(true),
			'default'       => '0'
		],
		'syslog_alert_maintenance_start' => [
			'friendly_name' => __('Maintenance Window Starts', 'syslog'),
			'description'   => __('Local start time for the global maintenance window.', 'syslog'),
			'method'        => 'drop_array',
			'array'         => syslog_alert_maintenance_time_options(),
			'default'       => '00:00'
		],
		'syslog_alert_maintenance_end' => [
			'friendly_name' => __('Maintenance Window Ends', 'syslog'),
			'description'   => __('Local end time for the global maintenance window. An earlier end time continues into the next day.', 'syslog'),
			'method'        => 'drop_array',
			'array'         => syslog_alert_maintenance_time_options(),
			'default'       => '00:00'
		],
		'syslog_alert_maintenance_datetime_start' => [
			'friendly_name' => __('One-time Maintenance Starts', 'syslog'),
			'description'   => __('Optional local date and time to start a one-time global maintenance window.', 'syslog'),
			'method'        => 'textbox', 'size' => '18', 'max_length' => '16', 'default' => ''
		],
		'syslog_alert_maintenance_datetime_end' => [
			'friendly_name' => __('One-time Maintenance Ends', 'syslog'),
			'description'   => __('Optional local date and time to end a one-time global maintenance window. Format: YYYY-MM-DD HH:MM.', 'syslog'),
			'method'        => 'textbox', 'size' => '18', 'max_length' => '16', 'default' => ''
		],
		'syslog_alert_cooldown_minutes' => [
			'friendly_name' => __('Default Alert Cooldown', 'syslog'),
			'description'   => __('Suppress any repeat notification for the same rule and reporting scope for this many minutes. Per-rule values override this setting. Set to 0 to disable.', 'syslog'),
			'method'        => 'textbox',
			'size'          => '6',
			'max_length'    => '6',
			'default'       => '0'
		],
		'syslog_alert_deduplication_minutes' => [
			'friendly_name' => __('Default Duplicate Suppression', 'syslog'),
			'description'   => __('Suppress a notification with the same matched message set for this many minutes. Per-rule values override this setting. Set to 0 to disable.', 'syslog'),
			'method'        => 'textbox',
			'size'          => '6',
			'max_length'    => '6',
			'default'       => '0'
		],
		'syslog_remote_header' => [
			'friendly_name' => __('Remote Message Processing', 'syslog'),
			'method'        => 'spacer',
		],
		'syslog_remote_enabled' => [
			'friendly_name' => __('Enable Remote Data Collector Message Processing', 'syslog'),
			'description'   => __('If your Remote Data Collectors have their own Syslog databases and process their messages independently, check this checkbox.  By checking this Checkbox, your Remote Data Collectors will need to maintain their own \'config_local.php\' file in order to inform Syslog to use an independent database for message display and processing.  Please use the template file \'config_local.php.dist\' for this purpose.  WARNING: Syslog tables will be automatically created as soon as this option is enabled.', 'syslog'),
			'method'        => 'checkbox',
			'default'       => ''
		],
		'syslog_remote_sync_rules' => [
			'friendly_name' => __('Remote Data Collector Rules Sync', 'syslog'),
			'description'   => __('If your Remote Data Collectors have their own Syslog databases and process thrie messages independently, check this checkbox if you wish the Main Cacti databases Alerts, Removal and Report rules to be sent to the Remote Cacti System.', 'syslog'),
			'method'        => 'checkbox',
			'default'       => ''
		],
		'syslog_remote_store_records' => [
			'friendly_name' => __('Store Records on Remote Collector', 'syslog'),
			'description'   => __('Keep processed messages in the remote collector syslog table after they have been delivered to the Main Collector. When disabled, records are retained locally only while the Main Collector is unavailable and are removed after confirmed recovery delivery.', 'syslog'),
			'method'        => 'checkbox',
			'default'       => ''
		],
		'syslog_replication_recovery_header' => [
			'friendly_name' => __('Remote Syslog Recovery', 'syslog'),
			'method'        => 'spacer',
		],
		'syslog_replication_recovery_records_per_run' => [
			'friendly_name' => __('Maximum Recovery Records Per Execution', 'syslog'),
			'description'   => __('Maximum number of retained remote Syslog events sent to the Main Collector by one recovery worker execution. Each central database transaction remains capped at 100 records. Increase gradually while observing Main Collector load and lock waits.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '2000',
			'array'         => [
				'500'   => __('%d Records', 500, 'syslog'),
				'1000'  => __('%d Records', 1000, 'syslog'),
				'2000'  => __('%d Records', 2000, 'syslog'),
				'3000'  => __('%d Records', 3000, 'syslog'),
				'5000'  => __('%d Records', 5000, 'syslog'),
				'10000' => __('%d Records', 10000, 'syslog')
			]
		],
		'syslog_replication_recovery_batch_delay_ms' => [
			'friendly_name' => __('Recovery Pause Between Batches', 'syslog'),
			'description'   => __('Pause between each 100-record Main Collector delivery transaction. A longer pause lowers Main Collector pressure; a shorter pause speeds backlog convergence.', 'syslog'),
			'method'        => 'drop_array',
			'default'       => '100',
			'array'         => [
				'0'    => __('No pause', 'syslog'),
				'25'   => __('%d milliseconds', 25, 'syslog'),
				'50'   => __('%d milliseconds', 50, 'syslog'),
				'100'  => __('%d milliseconds', 100, 'syslog'),
				'250'  => __('%d milliseconds', 250, 'syslog'),
				'500'  => __('%d milliseconds', 500, 'syslog'),
				'1000' => __('%d second', 1, 'syslog')
			]
		],
	];

	if (isset($settings['syslog'])) {
		$settings['syslog'] = array_merge($settings['syslog'], $temp);
	} else {
		$settings['syslog'] = $temp;
	}
}

/**
 * Setup the Syslog specific global arrays used throughout the plugin.
 *
 * @return void
 */
function syslog_config_arrays(): void {
	global $syslog_actions, $config, $menu, $message_types, $severities, $messages;
	global $syslog_levels, $syslog_facilities, $syslog_freqs, $syslog_times, $syslog_refresh;
	global $syslog_retentions, $syslog_alert_retentions, $menu_glyphs;

	$syslog_actions = [
		1 => __('Delete', 'syslog'),
		2 => __('Disable', 'syslog'),
		3 => __('Enable', 'syslog'),
		4 => __('Export', 'syslog')
	];

	$syslog_levels = [
		0 => 'emerg',
		1 => 'crit',
		2 => 'alert',
		3 => 'err',
		4 => 'warn',
		5 => 'notice',
		6 => 'info',
		7 => 'debug',
		8 => 'other'
	];

	$syslog_facilities = [
		0  => 'kernel',
		1  => 'user',
		2  => 'mail',
		3  => 'daemon',
		4  => 'auth',
		5  => 'syslog',
		6  => 'lpr',
		7  => 'news',
		8  => 'uucp',
		9  => 'cron',
		10 => 'authpriv',
		11 => 'ftp',
		12 => 'ntp',
		13 => 'log audit',
		14 => 'log alert',
		15 => 'cron',
		16 => 'local0',
		17 => 'local1',
		18 => 'local2',
		19 => 'local3',
		20 => 'local4',
		21 => 'local5',
		22 => 'local6',
		23 => 'local7'
	];

	$syslog_retentions = [
		'0'   => __('Indefinite', 'syslog'),
		'1'   => __('%d Day', 1, 'syslog'),
		'2'   => __('%d Days', 2, 'syslog'),
		'3'   => __('%d Days', 3, 'syslog'),
		'4'   => __('%d Days', 4, 'syslog'),
		'5'   => __('%d Days', 5, 'syslog'),
		'6'   => __('%d Days', 6, 'syslog'),
		'7'   => __('%d Week', 1, 'syslog'),
		'14'  => __('%d Weeks', 2, 'syslog'),
		'30'  => __('%d Month', 1, 'syslog'),
		'60'  => __('%d Months', 2, 'syslog'),
		'90'  => __('%d Months', 3, 'syslog'),
		'120' => __('%d Months', 4, 'syslog'),
		'160' => __('%d Months', 5, 'syslog'),
		'183' => __('%d Months', 6, 'syslog'),
		'365' => __('%d Year', 1, 'syslog')
	];

	$syslog_alert_retentions = [
		'0'   => __('Indefinite', 'syslog'),
		'1'   => __('%d Day', 1, 'syslog'),
		'2'   => __('%d Days', 2, 'syslog'),
		'3'   => __('%d Days', 3, 'syslog'),
		'4'   => __('%d Days', 4, 'syslog'),
		'5'   => __('%d Days', 5, 'syslog'),
		'6'   => __('%d Days', 6, 'syslog'),
		'7'   => __('%d Week', 1, 'syslog'),
		'14'  => __('%d Weeks', 2, 'syslog'),
		'30'  => __('%d Month', 1, 'syslog'),
		'60'  => __('%d Months', 2, 'syslog'),
		'90'  => __('%d Months', 3, 'syslog'),
		'120' => __('%d Months', 4, 'syslog'),
		'160' => __('%d Months', 5, 'syslog'),
		'183' => __('%d Months', 6, 'syslog'),
		'365' => __('%d Year', 1, 'syslog')
	];

	$syslog_refresh = [
		9999999 => __('Never', 'syslog'),
		'60'    => __('%d Minute', 1, 'syslog'),
		'120'   => __('%d Minutes', 2, 'syslog'),
		'300'   => __('%d Minutes', 5, 'syslog'),
		'600'   => __('%d Minutes', 10, 'syslog')
	];

	$severities = [
		'0' => __('Notice', 'syslog'),
		'1' => __('Warning', 'syslog'),
		'2' => __('Critical', 'syslog')
	];

	$message_types = [
		'messageb' => __('Begins with', 'syslog'),
		'messagec' => __('Contains', 'syslog'),
		'messagee' => __('Ends with', 'syslog'),
		'host'     => __('Hostname is', 'syslog'),
		'program'  => __('Program is', 'syslog'),
		'facility' => __('Facility is', 'syslog'),
		'filter'   => __('Filter Builder', 'syslog'),
		'sql'      => __('SQL Expression', 'syslog')
	];

	$syslog_freqs = [
		'86400'  => __('Daily', 'syslog'),
		'604800' => __('Weekly', 'syslog')
	];

	for ($i = 0; $i <= 86400; $i += 1800) {
		$minute = $i % 3600;

		if ($minute > 0) {
			$minute = '30';
		} else {
			$minute = '00';
		}

		if ($i > 0) {
			$hour = strrev(substr(strrev('00' . intval($i / 3600)),0,2));
		} else {
			$hour = '00';
		}

		$syslog_times[$i] = $hour . ':' . $minute;
	}

	if (syslog_config_safe()) {
		$menu2 =  [];

		foreach ($menu as $temp => $temp2) {
			$menu2[$temp] = $temp2;

			if ($temp == __('Import/Export')) {
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_alerts.php']  = __('Alert Rules', 'syslog');
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_device_rules.php'] = __('Device Alert Rules', 'syslog');
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_removal.php'] = __('Removal Rules', 'syslog');
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_reports.php'] = __('Report Rules', 'syslog');
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_saved_searches.php'] = __('Saved Search Templates', 'syslog');
				$menu2[__('Syslog Settings', 'syslog')]['plugins/syslog/syslog_dashboards.php'] = __('Dashboards', 'syslog');
			}
		}
		$menu = $menu2;

		$menu_glyphs[__('Syslog Settings', 'syslog')] = 'fa fa-life-ring';
	}

	if (isset($_SESSION['syslog_info']) && $_SESSION['syslog_info'] != '') {
		$messages['syslog_info'] = ['message' => $_SESSION['syslog_info'], 'type' => 'info'];
	}

	if (isset($_SESSION['syslog_error']) && $_SESSION['syslog_error'] != '') {
		$messages['syslog_error'] = ['message' => $_SESSION['syslog_error'], 'type' => 'error'];
	}
}

/** Attach Cacti's date-time picker after the Syslog Settings form is drawn. */
function syslog_settings_bottom(): void {
	if (get_nfilter_request_var('tab') !== 'syslog') {
		return;
	}
	?>
	<script type='text/javascript'>
	$(function() {
		$('#syslog_alert_maintenance_datetime_start, #syslog_alert_maintenance_datetime_end').datetimepicker({
			minuteGrid: 10,
			stepMinute: 1,
			showAnim: 'slideDown',
			numberOfMonths: 1,
			timeFormat: 'HH:mm',
			dateFormat: 'yy-mm-dd',
			showButtonPanel: false
		});
	});
	</script>
	<?php
}

/** Legacy callback for hook rows awaiting the explicit plugin upgrade. */
function syslog_config_insert(): void {
}
