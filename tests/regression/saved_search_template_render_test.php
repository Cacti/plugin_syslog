<?php

// Render a populated list: an empty list did not exercise the original fatal.
$source = file_get_contents(dirname(__DIR__, 2) . '/syslog_saved_searches.php');
$start = strpos($source, 'function syslog_template_list(');
$end = strpos($source, 'function syslog_template_edit(', $start);
if ($start === false || $end === false) {
	throw new RuntimeException('Template rendering block not found');
}

function __($text, $domain = '') { return $text; }
function __esc($text, $domain = '') { return html_escape($text); }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function html_start_box(...$args) { print '<table>'; }
function html_end_box() { print '</table>'; }
function html_header($columns) { print '<tr><th>' . implode('</th><th>', $columns) . '</th></tr>'; }
function form_alternate_row($id) { print '<tr>'; }
function form_end_row() { print '</tr>'; }
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
if (isset($argv[1])) {
	$csrf = file_get_contents($argv[1] . '/include/vendor/csrf/csrf-magic.php');
	$start = strpos($csrf, 'function csrf_ob_handler(');
	$end = strpos($csrf, 'function csrf_check(', $start);
	if ($start === false || $end === false) {
		throw new RuntimeException('Cacti CSRF output handler not found');
	}
	eval(substr($csrf, $start, $end - $start));
	function csrf_get_tokens() { return 'test-token'; }
	function csrf_log($name, $text) {}
	$GLOBALS['csrf'] = ['input-name' => '__csrf_magic', 'xhtml' => false, 'frame-breaker' => false, 'rewrite-js' => ''];
	$html = csrf_ob_handler('<html><body>' . $html . '</body></html>', 0);
	if (substr_count($html, "name='__csrf_magic'") !== 2) {
		throw new RuntimeException('Every action form must receive a CSRF token');
	}
}
echo "saved_search_template_render_test passed\n";
