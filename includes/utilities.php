<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

// Utilities callbacks.

require_once(dirname(__DIR__) . '/setup.php');
require_once(__DIR__ . '/functions.php');

/**
 * Handle the Syslog utilities actions.
 *
 * @param string $action The action requested from the utilities page.
 *
 * @return string The action, unmodified for other plugins in the hook chain.
 */
function syslog_utilities_action($action) {
	if (!syslog_config_safe()) {
		return $action;
	}

	if ($action === 'purge_syslog_hosts') {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			cacti_log('WARNING: syslog purge blocked -- non-POST request', false, 'SYSLOG');
			raise_message('syslog_method_error', __('Invalid request. Please try again.', 'syslog'), MESSAGE_LEVEL_ERROR);
			header('Location: utilities.php?header=false');
			exit;
		}

		// csrf_check($fatal) returns bool; $fatal=false tells the helper not to
		// die/exit on failure so we can log and redirect with a user-visible
		// message ourselves.
		if (!function_exists('csrf_check')) {
			cacti_log('WARNING: syslog purge blocked -- CSRF validation unavailable', false, 'SYSLOG');
			raise_message('syslog_csrf_unavailable', __('Invalid request. Please try again.', 'syslog'), MESSAGE_LEVEL_ERROR);
			header('Location: utilities.php?header=false');
			exit;
		}

		if (!csrf_check(false)) {
			cacti_log('WARNING: syslog purge blocked -- CSRF token validation failed', false, 'SYSLOG');
			raise_message('syslog_csrf_error', __('Invalid request. Please try again.', 'syslog'), MESSAGE_LEVEL_ERROR);
			header('Location: utilities.php?header=false');
			exit;
		}

		// Cacti loads only this hook file, so open the Syslog database (and its
		// syslog_db_* helpers) before purging.
		if (!syslog_connect()) {
			cacti_log('ERROR: syslog purge failed -- unable to connect to the Syslog database', false, 'SYSLOG');
			raise_message('syslog_connect_error', __('Unable to connect to the Syslog database. No Device records were removed.', 'syslog'), MESSAGE_LEVEL_ERROR);
			header('Location: utilities.php?header=false');
			exit;
		}

		global $syslogdb_default;

		$records = 0;

		// NOT EXISTS probes the host_id indexes and, unlike NOT IN, is not
		// defeated by a NULL host_id in the message tables.
		foreach (['syslog_hosts', 'syslog_host_facilities'] as $table) {
			syslog_db_execute("DELETE FROM `$syslogdb_default`.`$table`
				WHERE NOT EXISTS (
					SELECT 1 FROM `$syslogdb_default`.`syslog` AS s
					WHERE s.host_id = `$table`.host_id
				)
				AND NOT EXISTS (
					SELECT 1 FROM `$syslogdb_default`.`syslog_removed` AS r
					WHERE r.host_id = `$table`.host_id
				)");
			$records += syslog_db_affected_rows();
		}

		raise_message('syslog_info', __('There were %s Device records removed from the Syslog database', $records, 'syslog'), MESSAGE_LEVEL_INFO);

		header('Location: utilities.php?header=false');
		exit;
	}

	return $action;
}

/**
 * Add the Syslog purge utility to the utilities page.
 *
 * @return void
 */
function syslog_utilities_list(): void {
	if (!syslog_config_safe()) {
		return;
	}

	html_header([__('Syslog Utilities', 'syslog')], 2); ?>

	<tr class='even'>
		<td>
			<input id='syslog_purge_hosts' type='button' value='<?php print __esc('Purge Syslog Devices', 'syslog'); ?>'>
			<div id='syslog_purge_dialog' style='display:none;'>
				<p><?php print __esc('Are you sure you want to purge stale Syslog devices?', 'syslog'); ?></p>
			</div>
			<script type='text/javascript' <?php print plugin_syslog_csp_nonce(); ?>>
			$(function() {
				$('#syslog_purge_hosts').on('click', function() {
					$('#syslog_purge_dialog').dialog({
						title: <?php print syslog_json_safe(__('Confirm Purge', 'syslog')); ?>,
						minHeight: 80,
						minWidth: 400,
						resizable: false,
						draggable: true,
						buttons: {
							'Cancel': {
								text: <?php print syslog_json_safe(__('Cancel', 'syslog')); ?>,
								id: 'btnPurgeCancel',
								click: function() {
									$(this).dialog('close');
								}
							},
							'Continue': {
								text: <?php print syslog_json_safe(__('Continue', 'syslog')); ?>,
								id: 'btnPurgeContinue',
								click: function() {
									$(this).dialog('close');

									/* set the URL */
									var strURL = 'utilities.php?header=false';

									/* ensure that the csrf magic is appended */
									var json = {action: 'purge_syslog_hosts'};
									json.__csrf_magic = csrfMagicToken;

									if (typeof postUrl == 'function') {
										postUrl({url: strURL}, json);
									} else {
										loadPageUsingPost(strURL, json);
									}
								}
							}
						}
					});
				});
			});
			</script>
		</td>
		<td>
			<?php print __('This menu pick provides a means to remove Devices that are no longer reporting into Cacti\'s syslog server.', 'syslog'); ?>
		</td>
	</tr>
	<?php
}
