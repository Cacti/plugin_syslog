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

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$mode = $argv[1] ?? '';

// Each subprocess disables just the builtin whose failure it exercises.
if ($mode === 'export-init') {
	/**
	 * Simulate compression initialization failure.
	 *
	 * @param int $encoding The requested encoding.
	 *
	 * @return false
	 */
	function deflate_init($encoding) {
		return false;
	}
} elseif ($mode === 'export-write') {
	/**
	 * Simulate compression failure.
	 *
	 * @param DeflateContext $context The compression context.
	 * @param string         $data    The uncompressed data.
	 * @param int            $flush   The flush mode.
	 *
	 * @return false
	 */
	function deflate_add($context, $data, $flush) {
		return false;
	}
} elseif ($mode === 'import-init') {
	/**
	 * Simulate decompression initialization failure.
	 *
	 * @param int $encoding The requested encoding.
	 *
	 * @return false
	 */
	function inflate_init($encoding) {
		return false;
	}
} elseif ($mode !== 'import-unavailable') {
	throw new InvalidArgumentException('Unknown zlib failure fixture.');
}

if (function_exists('xdebug_info') && in_array('coverage', xdebug_info('mode'), true)) {
	xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
	register_shutdown_function(static function () use ($mode): void {
		$source = dirname(__DIR__, 2) . '/includes/recommendations.php';
		$lines = xdebug_get_code_coverage()[$source] ?? [];
		$xml = new SimpleXMLElement('<coverage><project/></coverage>');
		$file = $xml->project->addChild('file');
		$file->addAttribute('name', $source);

		foreach ($lines as $number => $hits) {
			$line = $file->addChild('line');
			$line->addAttribute('num', (string) $number);
			$line->addAttribute('type', 'stmt');
			$line->addAttribute('count', (string) max(0, $hits));
		}

		$directory = getenv('SYSLOG_COVERAGE_DIR') ?: dirname(__DIR__, 2) . '/coverage';

		if (!is_dir($directory)) {
			mkdir($directory, 0700, true);
		}

		if ($xml->asXML($directory . '/clover-recommendations-' . $mode . '.xml') === false) {
			throw new RuntimeException('Unable to write zlib fixture coverage.');
		}
	});
}

require dirname(__DIR__) . '/bootstrap-unit.php';
syslog_load_plugin_source('includes/recommendations.php');

if (str_starts_with($mode, 'export-')) {
	$result = null;
	ob_start();

	try {
		syslog_recommendations_export('', [], true);
	} catch (RuntimeException $error) {
		$result = $error->getMessage();
	} finally {
		ob_end_clean();
	}
} else {
	$path = tempnam(sys_get_temp_dir(), 'syslog-zlib-');

	try {
		file_put_contents($path, gzencode('[]'));
		$result = syslog_recommendations_read_file($path);
	} finally {
		unlink($path);
	}
}

print json_encode($result, JSON_THROW_ON_ERROR);
