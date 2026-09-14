<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression guard for issue #254 (partition table locking) and the
 * partition-boundary correctness fixes from the PR #313 follow-up.
 *
 * Static analysis: greps functions.php for safety invariants that must hold
 * regardless of how the rotation logic is refactored. Tracks the
 * two-argument signatures introduced in PR #313 (syslog_partition_check and
 * syslog_partition_create each accept an optional $time parameter).
 */

it('keeps syslog partition table locking and DDL identifiers safe', function () {
	$functions = plugin_test_read_source('functions.php');

	// All three information_schema queries must be prepared statements
	// scoped to the requested table via a placeholder. Match only calls
	// whose first argument contains 'information_schema' to exclude the
	// GET_LOCK / RELEASE_LOCK uses of syslog_db_fetch_cell_prepared.
	$partition_query_count = preg_match_all('/syslog_db_fetch_(?:row|assoc|cell)_prepared\s*\([^)]*information_schema/', $functions);

	if ($partition_query_count === false || $partition_query_count !== 3) {
		throw new RuntimeException('Partition queries are not consistently scoped to the requested table.');
	}

	if (!preg_match('/SELECT\s+GET_LOCK\s*\(\s*\?\s*,\s*10\s*\)/', $functions)) {
		throw new RuntimeException('Partition create lock acquisition is missing.');
	}

	if (!preg_match('/SELECT\s+RELEASE_LOCK\s*\(\s*\?\s*\)/', $functions)) {
		throw new RuntimeException('Partition create lock release is missing.');
	}

	if (!preg_match('/function\s+syslog_partition_table_allowed\s*\(/', $functions)) {
		throw new RuntimeException('Partition table validation helper is missing.');
	}

	// RELEASE_LOCK must appear inside a finally block, not just anywhere in the function.
	if (!preg_match('/finally\s*\{[^}]*RELEASE_LOCK/s', $functions)) {
		throw new RuntimeException('RELEASE_LOCK is not inside a finally block; lock may be held on exception.');
	}

	// The allowlist must be exactly the two known partition tables, nothing else.
	if (!preg_match("/in_array\(\s*\\\$table\s*,\s*(?:array\s*\(\s*'syslog'\s*,\s*'syslog_removed'\s*\)|\[\s*'syslog'\s*,\s*'syslog_removed'\s*\])\s*,\s*true\s*\)/", $functions)) {
		throw new RuntimeException("syslog_partition_table_allowed allowlist is not exactly ['syslog', 'syslog_removed'].");
	}

	// syslog_partition_check must return false in its guard clause for disallowed tables.
	// Signature may be ($table) or ($table, $time = null).
	if (!preg_match('/function\s+syslog_partition_check\s*\(\s*\$table(?:\s*,\s*\$time[^)]*)?\s*\)\s*\{(.{0,600})/s', $functions, $m_check)) {
		throw new RuntimeException('syslog_partition_check function not found.');
	}

	if (!preg_match('/!syslog_partition_table_allowed[^}]*return\s+false/s', $m_check[1])) {
		throw new RuntimeException('syslog_partition_check does not return false for disallowed tables.');
	}

	// syslog_partition_remove must return 0 in its guard clause for disallowed tables.
	if (!preg_match('/function\s+syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,400})/s', $functions, $m_remove)) {
		throw new RuntimeException('syslog_partition_remove function not found.');
	}

	if (!preg_match('/!syslog_partition_table_allowed[^}]*return\s+0/s', $m_remove[1])) {
		throw new RuntimeException('syslog_partition_remove does not return 0 for disallowed tables.');
	}

	// syslog_partition_remove's information_schema query must bind table_name via placeholder.
	if (!preg_match('/function\s+syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,1800})/s', $functions, $m_remove_full)) {
		throw new RuntimeException('syslog_partition_remove function body not found.');
	}

	if (!preg_match('/syslog_db_fetch_(?:row|assoc|cell)_prepared[^)]*information_schema[^)]*table_name\s*=\s*\?/s', $m_remove_full[1])) {
		throw new RuntimeException('syslog_partition_remove information_schema query is not scoped to table_name via placeholder.');
	}

	// ---- Allowlist acceptance / rejection tests ----

	// The allowlist function must reject values not in the list by returning false.
	if (!preg_match('/function\s+syslog_partition_table_allowed\s*\(\s*\$table\s*\)\s*\{(.{0,600})/s', $functions, $m_allowed)) {
		throw new RuntimeException('syslog_partition_table_allowed function not found.');
	}

	if (!preg_match('/return\s+false/', $m_allowed[1])) {
		throw new RuntimeException('syslog_partition_table_allowed does not explicitly return false for non-members.');
	}

	// Defense-in-depth: the allowlist function must include a regex guard for safe identifiers.
	if (!preg_match('/preg_match.*\[a-z_\]/', $m_allowed[1])) {
		throw new RuntimeException('syslog_partition_table_allowed is missing regex defense-in-depth guard.');
	}

	// ---- Lock name must be scoped to both database and table ----

	if (!preg_match('/hash\s*\(\s*[\'"]sha256[\'"]\s*,\s*\$syslogdb_default\s*\.\s*[\'"][^"\']*[\'"]\s*\.\s*\$table\s*\)/', $functions)) {
		throw new RuntimeException('Lock name hash does not include both database and table.');
	}

	// ---- syslog_partition_remove must log when called with a disallowed table ----

	if (!preg_match('/function\s+syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,600})/s', $functions, $m_remove_log)) {
		throw new RuntimeException('syslog_partition_remove function not found for log check.');
	}

	if (!preg_match('/!syslog_partition_table_allowed.*cacti_log.*disallowed.*return\s+0/s', $m_remove_log[1])) {
		throw new RuntimeException('syslog_partition_remove does not log a warning for disallowed tables.');
	}

	// ---- DDL safety comment must exist near ALTER TABLE in syslog_partition_create ----

	if (!preg_match('/MySQL does not support parameter binding for DDL/', $functions)) {
		throw new RuntimeException('Missing DDL safety comment in syslog_partition_create.');
	}

	// ---- No raw (non-prepared) information_schema partition queries may exist ----

	$raw_partition_queries = preg_match_all('/syslog_db_fetch_(?:row|assoc|cell)\s*\([^)]*information_schema/', $functions);

	if ($raw_partition_queries !== false && $raw_partition_queries > 0) {
		throw new RuntimeException("Found $raw_partition_queries raw (non-prepared) information_schema partition queries; all must use _prepared.");
	}

	// ---- syslog_partition_remove must also use GET_LOCK / RELEASE_LOCK in a finally block ----

	$remove_start = strpos($functions, 'function syslog_partition_remove');

	if ($remove_start === false) {
		throw new RuntimeException('Could not locate syslog_partition_remove.');
	}

	$remove_end = strpos($functions, 'function syslog_partition_check', $remove_start);

	if ($remove_end === false) {
		throw new RuntimeException('Could not bound syslog_partition_remove.');
	}

	$remove_body = substr($functions, $remove_start, $remove_end - $remove_start);

	if (!preg_match('/GET_LOCK/', $remove_body)) {
		throw new RuntimeException('syslog_partition_remove does not acquire a lock before ALTER TABLE.');
	}

	if (!preg_match('/finally\s*\{[^}]*RELEASE_LOCK/s', $remove_body)) {
		throw new RuntimeException('syslog_partition_remove does not release its lock in a finally block.');
	}

	// ---- Lock names must differ between create and remove (per-operation scoping) ----

	if (!preg_match('/syslog_partition_create\.\'\s*\.\s*\$table/', $functions)) {
		throw new RuntimeException('syslog_partition_create lock name does not include function scope.');
	}

	if (!preg_match('/syslog_partition_remove\.\'\s*\.\s*\$table/', $functions)) {
		throw new RuntimeException('syslog_partition_remove lock name does not include function scope.');
	}

	// ---- syslog_partition_create must return early (no DDL) when allowlist fails ----
	// Signature may be ($table) or ($table, $time = null).
	if (!preg_match('/function\s+syslog_partition_create\s*\(\s*\$table(?:\s*,\s*\$time[^)]*)?\s*\)\s*\{(.{0,400})/s', $functions, $m_create_guard)) {
		throw new RuntimeException('syslog_partition_create function not found.');
	}

	if (!preg_match('/!syslog_partition_table_allowed[^}]*return\s+false;/s', $m_create_guard[1])) {
		throw new RuntimeException('syslog_partition_create does not return early for disallowed tables.');
	}

	// ---- syslog_partition_check must use _prepared for info_schema ----

	if (!preg_match('/function\s+syslog_partition_check\s*\(\s*\$table(?:\s*,\s*\$time[^)]*)?\s*\)\s*\{(.{0,1200})/s', $functions, $m_check_prep)) {
		throw new RuntimeException('syslog_partition_check function not found for _prepared check.');
	}

	if (!preg_match('/syslog_db_fetch_cell_prepared[^)]*information_schema[^)]*table_name\s*=\s*\?/s', $m_check_prep[1])) {
		throw new RuntimeException('syslog_partition_check does not use _prepared with table_name placeholder.');
	}

	// ---- Partition boundary must be driven by the optional $time parameter ----

	$create_start = strpos($functions, 'function syslog_partition_create');

	if ($create_start === false) {
		throw new RuntimeException('Could not locate syslog_partition_create.');
	}

	$create_end = strpos($functions, 'function syslog_partition_remove', $create_start);

	if ($create_end === false) {
		throw new RuntimeException('Could not locate syslog_partition_remove to bound syslog_partition_create.');
	}

	$create_body = substr($functions, $create_start, $create_end - $create_start);

	if (!preg_match('/\$time\b/', $create_body)) {
		throw new RuntimeException('syslog_partition_create is missing time-based partition boundary handling.');
	}

	// Boundary math may be expressed in SQL (strtotime()/UNIX_TIMESTAMP()) or,
	// as of the UTC epoch rewrite, in PHP via intdiv() against 86400 seconds.
	if (!preg_match('/(?:strtotime\s*\(|UNIX_TIMESTAMP\s*\(|intdiv\s*\()/', $create_body)) {
		throw new RuntimeException('syslog_partition_create is missing partition boundary computation logic.');
	}

	// ---- syslog_partition_create must fall back to dMaxValue when the expression cannot be detected ----

	if (!preg_match('/SHOW CREATE TABLE/', $create_body)) {
		throw new RuntimeException('syslog_partition_create is missing the SHOW CREATE TABLE lookup.');
	}

	if (!preg_match('/dMaxValue/', $create_body)) {
		throw new RuntimeException('syslog_partition_create does not preserve dMaxValue fallback on unknown partition expression.');
	}

	if (!preg_match('/Unable to determine/i', $create_body)) {
		throw new RuntimeException("syslog_partition_create does not log a warning when the partition expression can't be determined.");
	}

	// ---- syslog_partition_manage must exist and drive both tables through check/create/remove ----

	$manage_start = strpos($functions, 'function syslog_partition_manage');

	if ($manage_start === false) {
		throw new RuntimeException('Could not locate syslog_partition_manage.');
	}

	$manage_end = strpos($functions, 'function syslog_partition_table_allowed', $manage_start);

	if ($manage_end === false) {
		throw new RuntimeException('Could not bound syslog_partition_manage.');
	}

	$manage_body = substr($functions, $manage_start, $manage_end - $manage_start);

	foreach (['syslog', 'syslog_removed'] as $table) {
		if (!preg_match('/syslog_partition_create\s*\(\s*\'' . $table . '\'/', $manage_body)) {
			throw new RuntimeException("syslog_partition_manage does not call syslog_partition_create('$table').");
		}

		if (!preg_match('/syslog_partition_remove\s*\(\s*\'' . $table . '\'\s*\)/', $manage_body)) {
			throw new RuntimeException("syslog_partition_manage does not call syslog_partition_remove('$table').");
		}
	}

	// ---- syslog_manage_items must exist with the current two-table signature ----

	if (!preg_match('/function\s+syslog_manage_items\s*\(\s*\$from_table\s*,\s*\$to_table\s*\)/', $functions)) {
		throw new RuntimeException('syslog_manage_items function with $from_table, $to_table signature not found.');
	}
});
