<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Optional auto_prepend_file for measuring which plugin lines the real entry
 * points execute during the E2E and regression runs.
 *
 * It does nothing unless the process environment (SYSLOG_COVERAGE_DIR and
 * SYSLOG_COVERAGE_ROOT) or the php.ini entries syslog.coverage_dir and
 * syslog.coverage_root name the dump directory and the plugin root, and it
 * answers 404 when requested directly over the web. The only file it writes is
 * <SYSLOG_COVERAGE_DIR>/<sha256 of pid and time>.jsonl (one plugin file per line), and only for plugin
 * files outside tests/. Merge the dumps with tests/bin/coverage-report.php.
 */
$syslog_cov_dir  = getenv('SYSLOG_COVERAGE_DIR') ?: get_cfg_var('syslog.coverage_dir');
$syslog_cov_root = getenv('SYSLOG_COVERAGE_ROOT') ?: get_cfg_var('syslog.coverage_root');

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	http_response_code(404);

	exit;
}

if ($syslog_cov_dir === false || $syslog_cov_dir === '' || $syslog_cov_root === false || $syslog_cov_root === ''
	|| !function_exists('xdebug_start_code_coverage') || !is_dir($syslog_cov_dir)) {
	return;
}

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | XDEBUG_CC_BRANCH_CHECK);

register_shutdown_function(static function () use ($syslog_cov_dir, $syslog_cov_root) {
	$root = rtrim($syslog_cov_root, '/') . '/';
	$out  = $syslog_cov_dir . '/' . hash('sha256', getmypid() . microtime(true)) . '.jsonl';

	foreach (xdebug_get_code_coverage() as $file => $data) {
		if (strpos($file, $root) !== 0 || strpos($file, $root . 'tests/') === 0) {
			continue;
		}

		file_put_contents($out, json_encode(['file' => substr($file, strlen($root)), 'data' => $data]) . "\n", FILE_APPEND);
	}

	xdebug_stop_code_coverage();
});
