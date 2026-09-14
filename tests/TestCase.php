<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Base class for Syslog tests.
 *
 * Pest's functional tests do not need this directly, but every test file
 * `uses(TestCase::class)` (see Pest.php) to get a clean stub-call log and
 * override registry per test, plus a helper for loading real plugin source.
 */
abstract class TestCase extends PHPUnit\Framework\TestCase {
	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		syslog_test_reset_globals();
	}

	/**
	 * Load a plugin source file once per process.
	 *
	 * @param string $file File name relative to the plugin root.
	 *
	 * @return void
	 */
	protected static function loadPluginSource($file) {
		syslog_test_load(dirname(__DIR__) . '/' . $file);
	}
}
