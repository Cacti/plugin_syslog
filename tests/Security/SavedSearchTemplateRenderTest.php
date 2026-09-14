<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the shared saved-search template list: rows must
 * render escaped, the only per-row action must be a POST delete form (no
 * GET-triggerable purge link), and -- when a real Cacti checkout is present
 * -- every action form must actually receive a CSRF token from Cacti's
 * csrf-magic output handler.
 */

it('renders saved-search template rows escaped with only a CSRF-protected delete action', function () {
	// Render a populated list: an empty list did not exercise the original fatal.
	$source = plugin_test_read_source('syslog_saved_searches.php');
	$start  = strpos($source, 'function syslog_template_list(');
	$end    = strpos($source, 'function syslog_template_edit(', $start);

	if ($start === false || $end === false) {
		throw new RuntimeException('Template rendering block not found');
	}

	test_override('__', function ($text, ...$args) {
		return $text;
	});

	test_override('__esc', function ($text, ...$args) {
		return html_escape($text);
	});

	test_override('html_escape', function ($text) {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	});

	test_override('html_start_box', function (...$args) {
		print '<table>';
	});

	test_override('html_end_box', function () {
		print '</table>';
	});

	test_override('html_header', function ($columns) {
		print '<tr><th>' . implode('</th><th>', $columns) . '</th></tr>';
	});

	test_override('form_alternate_row', function ($id = '') {
		print '<tr>';
	});

	test_override('form_end_row', function () {
		print '</tr>';
	});

	eval(substr($source, $start, $end - $start));

	$rows = [
		['id' => 1, 'name' => 'Example <one>', 'user' => 'admin', 'search' => 'message contains "error"'],
		['id' => 2, 'name' => 'Example two', 'user' => 'admin', 'search' => 'message contains "warning"'],
	];

	ob_start();
	syslog_template_list($rows);
	$html = ob_get_clean();

	if (substr_count($html, "<form method='post'") !== 2 || strpos($html, 'Example &lt;one&gt;') === false) {
		throw new RuntimeException('Expected escaped rows with POST action forms');
	}

	if (strpos($html, 'purge') !== false || substr_count($html, "value='delete'") !== 2) {
		throw new RuntimeException('Delete must be the only removal action');
	}

	// Optionally check token injection with the installed Cacti output handler.
	$cactiRoot = $GLOBALS['config']['base_path'] ?? '';
	$csrfMagicPath = $cactiRoot . '/include/vendor/csrf/csrf-magic.php';

	if ($cactiRoot !== '' && is_readable($csrfMagicPath)) {
		$csrf  = file_get_contents($csrfMagicPath);
		$start = strpos($csrf, 'function csrf_ob_handler(');
		$end   = strpos($csrf, 'function csrf_check(', $start);

		if ($start === false || $end === false) {
			throw new RuntimeException('Cacti CSRF output handler not found');
		}

		eval(substr($csrf, $start, $end - $start));

		if (!function_exists('csrf_get_tokens')) {
			function csrf_get_tokens() {
				return 'test-token';
			}
		}

		if (!function_exists('csrf_log')) {
			function csrf_log($name, $text) {
			}
		}

		$GLOBALS['csrf'] = ['input-name' => '__csrf_magic', 'xhtml' => false, 'frame-breaker' => false, 'rewrite-js' => ''];
		$html = csrf_ob_handler('<html><body>' . $html . '</body></html>', 0);

		if (substr_count($html, "name='__csrf_magic'") !== 2) {
			throw new RuntimeException('Every action form must receive a CSRF token');
		}
	}

	expect(true)->toBeTrue();
});
