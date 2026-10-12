<?php

it('renders the dashboard drag label and retains per-panel refresh text', function () {
	syslog_test_reset_globals();
	syslog_load_plugin_source('includes/functions.php');
	syslog_load_plugin_source('lib/syslog_dashboard.php');
	$GLOBALS['syslogdb_default'] = 'syslog';
	$GLOBALS['page_refresh_interval'] = [0 => 'Never'];
	$GLOBALS['request']['refresh'] = 0;
	$GLOBALS['request']['dashboard_id'] = 0;
	test_override('syslog_db_fetch_assoc_prepared', fn () => []);
	test_override('syslog_db_fetch_assoc', fn () => []);
	test_override('syslog_db_fetch_row_prepared', fn () => []);

	ob_start();
	try {
		syslog_dashboard();
		$html = ob_get_contents();
	} finally {
		ob_end_clean();
	}

	expect($html)->toContain('dragPanel: "Drag to reorder card"')
		->toContain('refreshPanel: "Refresh Panel"')
		->toContain("id='syslog_dashboard_grid'");
});
