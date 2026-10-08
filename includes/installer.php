<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Install and uninstall adviser UI. */

/** Confirm an existing plugin upgrade without sending it through Cacti's install action. */
function syslog_upgrade_advisor(string $installed, string $current): void {
	top_header();
	print "<table align='center' width='80%'><tr><td>";
	html_start_box(__('Syslog Upgrade Advisor', 'syslog'), '100%', '', '3', 'center', '');
	print '<tr><td>' . __('Upgrade Syslog from %s to %s? Existing messages and rules will be preserved. Schema changes may take time.', html_escape($installed), html_escape($current), 'syslog') . '</td></tr>';
	html_end_box();
	print "<form id='syslog_install' action='plugins.php' method='post'>";
	form_hidden_box('mode', 'enable', '');
	form_hidden_box('id', 'syslog', '');
	form_hidden_box('syslog_upgrade_confirm', '1', '');
	syslog_confirm_button('install', 'plugins.php', 1, true);
	print '</td></tr></table>';
	bottom_footer();
	exit;
}

/**
 * Display the install/upgrade advisor form.
 *
 * @param int                  $syslog_exists     Whether the Syslog table already exists.
 * @param array<string, mixed> $submitted_options The rejected install options to redisplay.
 *
 * @return void
 */
function syslog_install_advisor(int $syslog_exists, array $submitted_options = []): void {
	global $config, $syslog_retentions;

	top_header();

	syslog_config_arrays();

	$fields_syslog_update = [
		'upgrade_type' => [
			'method'        => 'drop_array',
			'friendly_name' => __('What upgrade/install type do you wish to use', 'syslog'),
			'description'   => __('When you have very large tables, performing a Truncate will be much quicker.  If you are concerned about archive data, you can choose either Inline, which will freeze your browser for the period of this upgrade, or background, which will create a background process to bring your old syslog data from a backup table to the new syslog format.  Again this process can take several hours.', 'syslog'),
			'value'         => 'truncate',
			'array'         => [
				'truncate'   => __('Truncate Syslog Table', 'syslog'),
				'inline'     => __('Inline Upgrade', 'syslog'),
				'background' => __('Background Upgrade', 'syslog')
			]
		],
		'engine' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Database Storage Engine', 'syslog'),
			'description'   => __('The storage engine for the analytical tables.  Only InnoDB and, on MariaDB, Aria storage engines are supported.  Any Remote Data Collector option requires InnoDB; incompatible combinations cannot be submitted.', 'syslog'),
			'value'         => isset($submitted_options['engine']) ? $submitted_options['engine'] : 'innodb',
			'array'         => [
				'innodb' => __('InnoDB Storage', 'syslog'),
				'aria'   => __('Aria Storage', 'syslog')
			]
		],
		'syslog_remote_header' => [
			'method'        => 'spacer',
			'friendly_name' => __('Remote Message Processing', 'syslog')
		],
		'syslog_remote_enabled' => [
			'method'        => 'checkbox',
			'friendly_name' => __('Enable Remote Data Collector Message Processing', 'syslog'),
			'description'   => __('If your Remote Data Collectors have their own Syslog databases and process their messages independently, check this checkbox.  By checking this Checkbox, your Remote Data Collectors will need to maintain their own \'config_local.php\' file in order to inform Syslog to use an independent database for message display and processing.  Please use the template file \'config_local.php.dist\' for this purpose.  WARNING: Syslog tables will be automatically created as soon as this option is enabled.', 'syslog'),
			'value'         => $submitted_options ? (isset($submitted_options['syslog_remote_enabled']) ? 'on' : '') : read_config_option('syslog_remote_enabled')
		],
		'syslog_remote_sync_rules' => [
			'method'        => 'checkbox',
			'friendly_name' => __('Remote Data Collector Rules Sync', 'syslog'),
			'description'   => __('If your Remote Data Collectors have their own Syslog databases and process thrie messages independently, check this checkbox if you wish the Main Cacti databases Alerts, Removal and Report rules to be sent to the Remote Cacti System.', 'syslog'),
			'value'         => $submitted_options ? (isset($submitted_options['syslog_remote_sync_rules']) ? 'on' : '') : read_config_option('syslog_remote_sync_rules')
		],
		'syslog_remote_store_records' => [
			'method'        => 'checkbox',
			'friendly_name' => __('Store Records on Remote Collector', 'syslog'),
			'description'   => __('Keep processed messages in the remote collector syslog table after they have been delivered to the Main Collector. When disabled, records are retained locally only while the Main Collector is unavailable and are removed after confirmed recovery delivery.', 'syslog'),
			'value'         => $submitted_options ? (isset($submitted_options['syslog_remote_store_records']) ? 'on' : '') : read_config_option('syslog_remote_store_records')
		],
		'db_type' => [
			'method' => 'hidden',
			'value'  => 'part'
		],
		'days' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Retention Policy', 'syslog'),
			'description'   => __('Choose how many days of Syslog values you wish to maintain in the database.', 'syslog'),
			'value'         => '30',
			'array'         => $syslog_retentions
		],
		'mode' => [
			'method' => 'hidden',
			'value'  => 'install'
		],
		'install' => [
			'method' => 'hidden',
			'value'  => 'true'
		],
		'id' => [
			'method' => 'hidden',
			'value'  => 'syslog'
		]
	];

	$fields_syslog_update['dayparts'] = [
		'method'        => 'drop_array',
		'friendly_name' => __('Partitions per Day', 'syslog'),
		'description'   => __('Select the number of partitions per day that you wish to create.', 'syslog'),
		'value'         => '1',
		'array'         => [
			'1'  => __('%d Per Day', 1, 'syslog'),
			'2'  => __('%d Per Day', 2, 'syslog'),
			'4'  => __('%d Per Day', 4, 'syslog'),
			'6'  => __('%d Per Day', 6, 'syslog'),
			'12' => __('%d Per Day', 12, 'syslog')
		]
	];

	if ($syslog_exists) {
		$type = __('Upgrade', 'syslog');
	} else {
		$type = __('Install', 'syslog');
	}

	syslog_connect();
	$database = syslog_db_fetch_row('SHOW GLOBAL VARIABLES LIKE "version"');

	// remove Aria as a storage engine if this is mysql
	if (!is_array($database) || !isset($database['Value']) || stripos($database['Value'], 'mariadb') === false) {
		unset($fields_syslog_update['engine']['array']['aria']);
	} elseif (!isset($submitted_options['engine'])) {
		$fields_syslog_update['engine']['value'] = 'aria';
	}

	if (!$submitted_options && syslog_remote_collector_requires_innodb()) {
		$fields_syslog_update['engine']['value'] = 'innodb';
	}

	print "<table align='center' width='80%'><tr><td>";
	html_start_box(__('Syslog %s Advisor', $type, 'syslog'), '100%', '', '3', 'center', '');
	print '<tr><td>';

	if ($syslog_exists) {
		print "<h2 style='color:red;'>" . __('WARNING: Syslog Upgrade is Time Consuming!!!', 'syslog') . '</h2>';
		print '<p>' . __('The upgrade of the \'main\' syslog table can be a very time consuming process.  As such, it is recommended that you either reduce the size of your syslog table prior to upgrading, or choose the background option</p> <p>If you choose the background option, your legacy syslog table will be renamed, and a new syslog table will be created.  Then, an upgrade process will be launched in the background.  Again, this background process can quite a bit of time to complete.  However, your data will be preserved</p> <p>Regardless of your choice, all existing removal and alert rules will be maintained during the upgrade process.</p> <p>Press <b>\'Upgrade\'</b> to proceed with the upgrade, or <b>\'Cancel\'</b> to return to the Plugins menu.', 'syslog') . '</p></td></tr>';
	} else {
		unset($fields_syslog_update['upgrade_type']);
		print '<p>' . __('You have several options to choose from when installing Syslog.  The first is the Database Architecture.  Partitioned tables are the only supported architecture as they prevent the size of the tables from becoming excessive thus slowing queries.  Traditional non-partitioned tables are deprecated and are no longer offered for new installs.', 'syslog') . '</p><p>' . __('You can also set the database storage engine for the analytical tables syslog and syslog_remove.  Only the InnoDB storage engine and, on MariaDB, the Aria storage engine are supported.  If using MariaDB, we recommend the Aria Storage Engine when no Remote Data Collector options are enabled; collector options require InnoDB.', 'syslog') . '</p>';
		print '<p id=\'syslog_engine_compatibility_notice\' role=\'status\' style=\'display:none;color:#000;font-weight:bold;\'>' . __('Aria storage is unavailable while any Remote Data Collector option is enabled. InnoDB has been selected.', 'syslog') . '</p>';
		print '<p>' . __('You can also select the retention duration.  Please keep in mind that if you have several hosts logging to syslog, this table can become quite large.', 'syslog') . '</p></td></tr>';
	}
	html_end_box();

	print "<form id='syslog_install' action='plugins.php' method='get'>";

	html_start_box(__('Syslog %s Settings', $type, 'syslog'), '100%', '', '3', 'center', '');

	draw_edit_form([
		'config' => ['no_form_tag' => true],
		'fields' => inject_form_variables($fields_syslog_update, [])]
	);

	?>
	<script type='text/javascript'>
	$(function() {
		var remoteOptions = $('#syslog_remote_enabled, #syslog_remote_sync_rules, #syslog_remote_store_records');
		var engine = $('#engine');
		var aria = engine.find('option[value="aria"]');
		var form = $('#syslog_install');
		var compatibilityNotice = $('#syslog_engine_compatibility_notice');

		function updateStorageEngine() {
			var remoteEnabled = remoteOptions.is(':checked');

			if (remoteEnabled) {
				if (engine.find('option[value="aria"]').length) {
					aria = engine.find('option[value="aria"]').detach();
				}
				engine.val('innodb');
				compatibilityNotice.show();
			} else {
				if (aria.length && !engine.find('option[value="aria"]').length) {
					engine.append(aria);
				}
				compatibilityNotice.hide();
			}
		}

		remoteOptions.on('change', updateStorageEngine);
		engine.on('change', updateStorageEngine);
		form.on('submit', function(event) {
			updateStorageEngine();

			if (remoteOptions.is(':checked') && engine.val() === 'aria') {
				event.preventDefault();
				event.stopImmediatePropagation();
				compatibilityNotice.show();
				engine.focus();
			}
		});
		updateStorageEngine();
	});
	</script>
	<?php

	html_end_box();
	syslog_confirm_button('install', 'plugins.php', $syslog_exists);
	print '</td></tr></table>';

	bottom_footer();
	exit;
}

/**
 * Display the uninstall advisor form.
 *
 * @return void
 */
function syslog_uninstall_advisor(): void {
	global $config, $syslogdb_default;

	syslog_connect();

	$syslog_exists = sizeof(syslog_db_fetch_row("SHOW TABLES FROM `$syslogdb_default` LIKE 'syslog'"));

	top_header();

	$fields_syslog_update = [
		'uninstall_method' => [
			'method'        => 'drop_array',
			'friendly_name' => __('What uninstall method do you want to use?', 'syslog'),
			'description'   => __('When uninstalling syslog, you can remove everything, or only components, just in case you plan on re-installing in the future.', 'syslog'),
			'value'         => 'all',
			'array'         => ['all' => __('Remove Everything (Logs, Tables, Settings)', 'syslog'), 'syslog' => __('Syslog Data Only', 'syslog')],
		],
		'mode' => [
			'method' => 'hidden',
			'value'  => 'uninstall'
		],
		'uninstall' => [
			'method' => 'hidden',
			'value'  => 'true'
		],
		'id' => [
			'method' => 'hidden',
			'value'  => 'syslog'
		]
	];

	form_start('plugins.php');

	print "<table align='center' width='80%'><tr><td>";

	html_start_box(__('Syslog Uninstall Preferences', 'syslog'), '100%', '', '3', 'center', '');

	draw_edit_form([
		'config' => [],
		'fields' => inject_form_variables($fields_syslog_update, [])]
	);

	html_end_box();

	syslog_confirm_button('uninstall', 'plugins.php', $syslog_exists);

	print '</td></tr></table>';

	bottom_footer();
	exit;
}

/**
 * Display the confirm and cancel buttons for the install/uninstall forms.
 *
 * @param string $action        The action being confirmed, either 'install' or 'uninstall'.
 * @param string $cancel_url    The URL to load when the action is cancelled.
 * @param int    $syslog_exists Whether the Syslog table already exists.
 *
 * @return void
 */
function syslog_confirm_button(string $action, string $cancel_url, int $syslog_exists, bool $full_page = false): void {
	if ($action == 'install') {
		if ($syslog_exists) {
			$value = __('Upgrade', 'syslog');
		} else {
			$value = __('Install', 'syslog');
		}
	} else {
		$value = __('Uninstall', 'syslog');
	}

	?>
	<table align='center' width='100%'>
		<tr>
			<td class='saveRow' align='right'>
				<input id='<?php print ($syslog_exists ? 'return' : 'cancel')?>' type='button' value='<?php print __('Cancel', 'syslog'); ?>'>
				<input id='<?php print $action; ?>' type='submit' value='<?php print $value; ?>'>
				<script type='text/javascript'>
				$(function() {
					$('#syslog_install').submit(function(event) {
						event.preventDefault();
						<?php if ($full_page) { ?>
						submitPageUsingPost('plugins.php?mode=enable&id=syslog&syslog_upgrade_confirm=1');
						return;
						<?php } ?>

						/* set the URL */
						var strURL = $(this).attr('action');
						strURL += (strURL.indexOf('?') >= 0 ? '&':'?') + 'header=false';

						/* ensure that the csrf magic is appended */
						var json = $(this).serializeObject();
						json.__csrf_magic = csrfMagicToken;

						if (typeof postUrl == 'function') {
							postUrl({url: strURL}, json);
						} else {
							loadPageUsingPost(strURL, json);
						}
					});

					$('#cancel, #return').click(function() {
						loadPageNoHeader('plugins.php?header=false');
					});
				});
				</script>
			</td>
		</tr>
	</table>
	</form>
	<?php
}
