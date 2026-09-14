<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #270: MariaDB detection must use a strict
 * (===) comparison against stripos(), since stripos() can return 0 for a
 * match at the start of the string, which is falsy under loose (==) compare.
 */

it('uses a strict comparison for MariaDB detection', function () {
	$setup = plugin_test_read_source('setup.php');

	$legacyPattern = '/stripos\s*\(\s*\$database\s*\[\s*[\'"]{1}Value[\'"]{1}\s*\]\s*,\s*[\'"]{1}mariadb[\'"]{1}\s*\)\s*==\s*false/';
	$fixedPattern  = '/stripos\s*\(\s*\$database\s*\[\s*[\'"]{1}Value[\'"]{1}\s*\]\s*,\s*[\'"]{1}mariadb[\'"]{1}\s*\)\s*===\s*false/';

	if (preg_match($legacyPattern, $setup)) {
		throw new RuntimeException('Legacy loose MariaDB stripos comparison is still present.');
	}

	if (!preg_match($fixedPattern, $setup)) {
		throw new RuntimeException('Strict MariaDB stripos comparison is missing.');
	}
});
