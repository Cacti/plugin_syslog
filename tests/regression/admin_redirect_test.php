<?php
// Native submissions must return to a complete Cacti page, not an AJAX fragment.
namespace SyslogAdminRedirectTest;

class Redirect extends \RuntimeException {}
function header($value) { throw new Redirect($value); }
function __($text, ...$args) { return $text; }
function get_request_var($name) { return $_POST[$name] ?? ''; }
function get_nfilter_request_var($name) { return get_request_var($name); }
function get_filter_request_var($name, ...$args) { return get_request_var($name); }
function isset_request_var($name) { return isset($_POST[$name]); }
function sanitize_unserialize_selected_items($value) {
	return unserialize($value, ['allowed_classes' => false]);
}
function syslog_apply_selected_items_action(...$args) {}
function top_header() {}
function form_start(...$args) {}
function html_start_box(...$args) {}
function cacti_sizeof($value) { return count($value); }
function raise_message(...$args) {}
function syslog_allow_edits() { return false; }

foreach ([
	'syslog_dashboards' => ['syslog_dashboard_actions', 'syslog_dashboard_import_form'],
	'syslog_saved_searches' => ['syslog_template_actions', 'syslog_saved_search_import_form']
] as $page => [$actions, $import]) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . $page . '.php');
	foreach ([$actions, $import] as $function) {
		$start = strpos($source, 'function ' . $function . '(');
		$end = strpos($source, "\n}", $start) + 2;
		eval('namespace ' . __NAMESPACE__ . ';' . substr($source, $start, $end - $start));
	}

	foreach ([
		[$actions, ['drp_action' => 'invalid']],
		[$actions, ['drp_action' => '2']], // No rows selected.
		[$actions, ['drp_action' => '1', 'selected_items' => serialize(['3'])]],
		[$actions, ['drp_action' => '2', 'selected_items' => serialize([])]],
		[$import, []] // Import is unavailable for this account.
	] as [$function, $request]) {
		$_POST = $request;
		try {
			call_user_func(__NAMESPACE__ . '\\' . $function);
			throw new \RuntimeException('Expected a redirect from ' . $function);
		} catch (Redirect $redirect) {
			if ($redirect->getMessage() !== 'Location: ' . $page . '.php') {
				throw new \RuntimeException('Native navigation would omit Cacti scripts: ' . $redirect->getMessage());
			}
		}
	}
}

print "admin_redirect_test passed\n";
