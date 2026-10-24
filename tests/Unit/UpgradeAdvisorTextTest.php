<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The install and upgrade advisor describes what each upgrade choice keeps.
 */

function upgrade_advisor_output(int $exists): string {
	$installer = dirname(__DIR__, 2) . '/includes/installer.php';
	$script    = tempnam(sys_get_temp_dir(), 'syslog-advisor-');

	file_put_contents($script, '<?php
		function __($text, ...$args) { array_pop($args); return $args ? vsprintf($text, $args) : $text; }
		function top_header() {}
		function bottom_footer() {}
		function syslog_config_arrays() { $GLOBALS["syslog_retentions"] = [30 => "30 days"]; }
		function syslog_connect() { return true; }
		function syslog_db_fetch_row($sql) { return ["Value" => "10.11.0-MariaDB"]; }
		function syslog_remote_collector_requires_innodb() { return false; }
		function read_config_option($name) { return ""; }
		function inject_form_variables($fields, $args) { return $fields; }
		function html_start_box($title, ...$args) { print "BOX " . $title . "\n"; }
		function html_end_box() {}
		function draw_edit_form($form) { foreach ($form["fields"] as $name => $field) { print "FIELD " . $name . ": " . ($field["description"] ?? "") . "\n"; } }
		require ' . var_export($installer, true) . ';
		syslog_install_advisor(' . $exists . ');
	');

	try {
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output);

		return implode("\n", $output);
	} finally {
		unlink($script);
	}
}

it('tells an upgrade that truncate keeps the rules', function () {
	$output = upgrade_advisor_output(1);

	expect($output)->toContain('FIELD upgrade_type:')
		->toContain('A Truncate discards the stored syslog and removed messages only; alert, removal and report rules are always kept.')
		->toContain('all existing removal and alert rules will be maintained');
});

it('offers no upgrade choice for a new install', function () {
	$output = upgrade_advisor_output(0);

	expect($output)->not->toContain('FIELD upgrade_type:')
		->toContain('FIELD engine:');
});
