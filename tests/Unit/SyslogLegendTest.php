<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Rendering of the two status legends, syslog_syslog_legend() and
 * syslog_log_legend(), plus the theme-aware legend stylesheet selection in
 * syslog_include_js().
 *
 * Each legend prints one rounded, solid-colour .syslogLegendItem chip per
 * severity, carrying the log* class the result rows use so the active theme's
 * legend CSS supplies the background. html_start_box()/html_end_box() are
 * no-op stubs from the bootstrap, so only the chip markup reaches the buffer.
 */

it('renders one chip per severity for the system log legend', function () {
	syslog_load_plugin_source('includes/functions.php');

	ob_start();
	syslog_syslog_legend();
	$output = ob_get_clean();

	expect($output)->toContain('<tr class="tableRow"><td>');
	expect($output)->toContain('<div class="syslogLegend" style="--syslog-chip-min:');
	expect(substr_count($output, 'syslogLegendItem'))->toBe(8);

	foreach (['logEmergency' => 'Emergency', 'logCritical' => 'Critical', 'logAlert' => 'Alert',
		'logError' => 'Error', 'logWarning' => 'Warning', 'logNotice' => 'Notice',
		'logInfo' => 'Info', 'logDebug' => 'Debug'] as $class => $label) {
		expect($output)->toContain('<div class="syslogLegendItem ' . $class . '">' . $label . '</div>');
	}
});

it('renders the smaller alert log legend', function () {
	syslog_load_plugin_source('includes/functions.php');

	ob_start();
	syslog_log_legend();
	$output = ob_get_clean();

	expect($output)->toContain('<div class="syslogLegend" style="--syslog-chip-min:');
	expect(substr_count($output, 'syslogLegendItem'))->toBe(3);

	foreach (['logAlert' => 'Alert', 'logWarning' => 'Warning', 'logInfo' => 'Informational'] as $class => $label) {
		expect($output)->toContain('<div class="syslogLegendItem ' . $class . '">' . $label . '</div>');
	}
});

it('links the theme legend stylesheet when the theme ships one', function () {
	syslog_load_plugin_source('includes/functions.php');

	test_override('get_selected_theme', function () {
		return 'modern';
	});

	ob_start();
	syslog_include_js();
	$output = ob_get_clean();

	expect($output)->toContain('plugins/syslog/css/modern.css?v=');
	expect($output)->not->toContain('plugins/syslog/css/legend.css?v=');
});

it('falls back to legend.css when the theme ships no legend stylesheet', function () {
	syslog_load_plugin_source('includes/functions.php');

	test_override('get_selected_theme', function () {
		return 'carrot';
	});

	ob_start();
	syslog_include_js();
	$output = ob_get_clean();

	expect($output)->toContain('plugins/syslog/css/legend.css?v=');
	expect($output)->not->toContain('plugins/syslog/css/carrot.css?v=');
});

it('sanitises the theme name before building the stylesheet path', function () {
	syslog_load_plugin_source('includes/functions.php');

	test_override('get_selected_theme', function () {
		return '../../etc/passwd';
	});

	ob_start();
	syslog_include_js();
	$output = ob_get_clean();

	expect($output)->toContain('plugins/syslog/css/legend.css?v=');
	expect($output)->not->toContain('..');
});
