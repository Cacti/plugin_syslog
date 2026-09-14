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

uses(TestCase::class)->in(__DIR__ . '/Security', __DIR__ . '/Unit');
