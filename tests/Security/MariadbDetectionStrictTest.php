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
	$schema = plugin_test_read_source('includes/schema.php');

	$legacyPattern = '/stripos\s*\(\s*\$version\s*,\s*[\'"]{1}mariadb[\'"]{1}\s*\)\s*==\s*false/';
	$fixedPattern  = '/stripos\s*\(\s*\$version\s*,\s*[\'"]{1}mariadb[\'"]{1}\s*\)\s*===\s*false/';

	if (preg_match($legacyPattern, $schema)) {
		throw new RuntimeException('Legacy loose MariaDB stripos comparison is still present.');
	}

	if (!preg_match($fixedPattern, $schema)) {
		throw new RuntimeException('Strict MariaDB stripos comparison is missing.');
	}

	expect(true)->toBeTrue();
});
