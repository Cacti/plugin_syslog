<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

use PHPUnit\Framework\ExpectationFailedException;

afterEach(function () {
	syslog_test_reset_globals();
});

/**
 * A placeholder-to-parameter mismatch only fails against a real PDO
 * connection (SQLSTATE HY093), so the stubbed db layer and the
 * source-text assertions used elsewhere never catch it. This test pulls
 * the prepared INSERT/DELETE pairs that syslog_incoming_to_syslog()
 * builds (both the unsliced and the parallel-worker sliced variants)
 * and verifies that every '?' token carries a bound parameter, and that
 * every parameter belongs to a '?' token, using a MySQL-aware scan that
 * skips quoted strings, escapes, and backtick identifiers.
 */
it('balances prepared placeholders with bound parameters in the archival statements', function () {
	syslog_load_plugin_source('includes/functions.php');
	$GLOBALS['config']['poller_id'] = 1;
	$GLOBALS['syslogdb_default']    = 'syslog';

	$statements = [];
	test_override('db_affected_rows', static fn() => 1);

	test_override('syslog_db_execute', function ($sql) {
		if ($sql === 'START TRANSACTION' || $sql === 'COMMIT' || $sql === 'ROLLBACK') {
			return true;
		}

		throw new ExpectationFailedException("Unexpected syslog_db_execute() query: $sql");
	});

	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$statements) {
		$statements[] = [$sql, $params];

		return true;
	});

	// No placeholder may arrive without a bound parameter: the archival
	// INSERT binds poller_id for both the new row and the retry check, while the
	// digest is computed in SQL and never bound.
	expect(syslog_incoming_to_syslog(100)['success'])->toBeTrue()
		->and(syslog_incoming_to_syslog(100, 51, 100)['success'])->toBeTrue();

	expect($statements)->toHaveCount(6);

	foreach ($statements as [$sql, $params]) {
		$tokens = syslog_test_count_placeholders($sql);

		expect($tokens)->toBeGreaterThan(0)
			->and(count($params))->toBe($tokens, "Placeholder/parameter mismatch in: $sql");
	}

	// Both archival variants must keep an unbound MD5(message) digest and
	// two bound poller_id values; the sliced variant adds one bound
	// seq pair.
	[$archive_sql, $archive_params] = $statements[1];
	$slice_sql = $statements[4][0];

	expect($archive_sql)->toContain('INSERT INTO `syslog`.`syslog`')
		->toContain('message_digest')
		->toContain('MD5(message)');
	expect(substr_count($archive_sql, '?'))->toBe(3)
		->and($archive_params)->toBe([1, 1, 100])
		->and(substr_count($slice_sql, '?'))->toBe(5);
});

/**
 * Count the '?' placeholders in an SQL string the driver would see,
 * ignoring '?' inside single/double quoted strings, escaped quotes,
 * and ``-escaped backtick identifiers.
 *
 * @param string $sql The SQL to scan
 *
 * @return int The number of driver-visible placeholders
 */
function syslog_test_count_placeholders(string $sql): int {
	$tokens = 0;
	$length = strlen($sql);

	for ($i = 0; $i < $length; $i++) {
		$char = $sql[$i];

		if ($char === "'" || $char === '"' || $char === '`') {
			$i = syslog_test_skip_quoted($sql, $i, $char);

			continue;
		}

		if ($char === '?') {
			$tokens++;
		}
	}

	return $tokens;
}

/**
 * Return the index just past the closing quote starting at $offset,
 * honouring repeated-character escaping ("", '', ``).
 *
 * @param string $sql    The SQL being scanned
 * @param int    $offset Index of the opening quote
 * @param string $char   The quote character
 *
 * @return int Index after the closing quote
 */
function syslog_test_skip_quoted(string $sql, int $offset, string $char): int {
	$length = strlen($sql);
	$next   = $offset + 1;

	while ($next < $length) {
		if ($sql[$next] === '\\') {
			$next += 2;

			continue;
		}

		if ($sql[$next] === $char) {
			if ($char !== '\\' && isset($sql[$next + 1]) && $sql[$next + 1] === $char) {
				$next += 2;

				continue;
			}

			return $next;
		}

		$next++;
	}

	return $length;
}