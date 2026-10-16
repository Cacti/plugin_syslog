<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Render helpers used by the syslog list tables: the metadata label wraps a
 * translated facility/priority value in its severity/facility class, and the
 * value filter button turns displayed values into clickable filter actions.
 * Both escape everything they print.
 */

afterEach(function () {
	syslog_test_reset_globals();
});

it('wraps facility and priority values in their metadata class', function ($type, $class) {
	syslog_load_plugin_source('includes/functions.php');

	$html = syslog_metadata_label('daemon', $type);

	expect($html)->toBe('<span class="' . $class . ' ' . $class . '-daemon">daemon</span>');
})->with([
	['priority', 'syslogSeverity'],
	['facility', 'syslogFacility']
]);

it('escapes hostile metadata values and keeps the modifier class clean', function () {
	syslog_load_plugin_source('includes/functions.php');

	$html = syslog_metadata_label('<img src=x onerror=alert(1)>', 'priority');

	expect($html)->not->toContain('<img')
		->and($html)->toContain('syslogSeverity')
		->and($html)->toContain(html_escape('<img src=x onerror=alert(1)>'));
});

it('renders an Unknown placeholder for empty filter values', function () {
	syslog_load_plugin_source('includes/functions.php');

	$html = syslog_value_filter_button('', 'host');

	expect($html)->toBe(html_escape(__('Unknown', 'syslog')));
});

it('adds the host and program filter classes to the button', function ($field, $class) {
	syslog_load_plugin_source('includes/functions.php');

	$html = syslog_value_filter_button('router1', $field);

	expect($html)->toContain($class)
		->and($html)->toContain('data-filter-field="' . $field . '"')
		->and($html)->toContain('data-filter-value="router1"')
		->and($html)->toContain('>router1</button>');
})->with([
	['host', 'syslogHostLabel'],
	['program', 'syslogProgramLabel']
]);

it('renders the severity class for priority filter buttons', function () {
	syslog_load_plugin_source('includes/functions.php');

	$html = syslog_value_filter_button('3', 'priority');

	expect($html)->toContain('syslogSeverity')
		->and($html)->toContain('data-filter-field="priority"');
});