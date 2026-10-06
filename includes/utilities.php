<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Utilities callbacks. */

/**
 * Handle the Syslog utilities actions.
 *
 * @param string $action The action requested from the utilities page.
 *
 * @return string|void The action, unmodified when it is not a Syslog action, or
 *                     nothing when the Syslog config file is not available.
 */
function syslog_utilities_action($action) {
	include_once(dirname(__DIR__) . '/setup.php');

	if (!syslog_config_safe()) {
		return;
	}

	syslog_connect();

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

		$records = 0;

		syslog_db_execute('DELETE FROM syslog_hosts
			WHERE host_id NOT IN (
				SELECT DISTINCT host_id
				FROM syslog
				UNION
				SELECT DISTINCT host_id
				FROM syslog_removed
			)');
		$records += syslog_db_affected_rows();

		syslog_db_execute('DELETE FROM syslog_host_facilities
			WHERE host_id NOT IN (
				SELECT DISTINCT host_id
				FROM syslog
				UNION
				SELECT DISTINCT host_id
				FROM syslog_removed
			)');
		$records += syslog_db_affected_rows();

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
	include_once(dirname(__DIR__) . '/setup.php');

	if (!syslog_config_safe()) {
		return;
	}

	syslog_connect();

	html_header([__('Syslog Utilities', 'syslog')], 2); ?>

	<tr class='even'>
		<td>
			<input id='syslog_purge_hosts' type='button' value='<?php print __esc('Purge Syslog Devices', 'syslog'); ?>'>
			<div id='syslog_purge_dialog' style='display:none;'>
				<p><?php print __esc('Are you sure you want to purge stale Syslog devices?', 'syslog'); ?></p>
			</div>
			<script type='text/javascript'>
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
