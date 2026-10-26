/** Keep native rule controls aligned with the selected notification destination. */
function syslogAlertDestinationVisibility($, notifyExists) {
	var threshold = $('#report_method').val() == '1';
	var syslogAlarming = $('#alarm_destination').val() !== 'alarmmanager';
	$('#row_num, #row_threshold_window, #row_threshold_recovery_minutes').toggle(threshold);
	$('#row_spacer1, #row_open_ticket, #row_command, #row_header_email, #row_email, #row_body').toggle(syslogAlarming);
	$('#row_notify').toggle(syslogAlarming && notifyExists);
	$('#row_recovery_command').toggle(threshold && syslogAlarming);
}

if (typeof module !== 'undefined') {
	module.exports = syslogAlertDestinationVisibility;
}
