<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Pest configuration file. The bootstrap is loaded via phpunit.xml's
 * bootstrap attribute (tests/bootstrap-unit.php), which requires Cacti's
 * own Composer-managed vendor tree checked out by the CI workflow.
 */

/*
 * Every test starts from the same clean slate: no leftover request vars,
 * session state, or per-test function fakes from a previous test file. A
 * global beforeEach() (rather than a custom TestCase bound via
 * pest()->extend()->in(), which proved unreliable across Pest versions/CI)
 * works regardless of what $this resolves to in a test.
 */
beforeEach(function () {
	syslog_test_reset_globals();
});
