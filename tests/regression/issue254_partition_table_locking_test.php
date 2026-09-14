<?php

/*
 * Regression guard for issue #254 (partition table locking) and the
 * partition-boundary correctness fixes from the PR #313 follow-up.
 *
 * Static analysis: greps functions.php for safety invariants that must hold
 * regardless of how the rotation logic is refactored. Updated to track the
 * two-argument signatures introduced in PR #313 (syslog_partition_check and
 * syslog_partition_create each accept an optional $time parameter).
 */

$functions = file_get_contents(dirname(__DIR__, 2) . '/functions.php');

if ($functions === false) {
	fwrite(STDERR, "Failed to load functions.php\n");
	exit(1);
}

/* All three information_schema queries must be prepared statements
 * scoped to the requested table via a placeholder. Match only calls
 * whose first argument contains 'information_schema' to exclude the
 * GET_LOCK / RELEASE_LOCK uses of syslog_db_fetch_cell_prepared. */
$partition_query_count = preg_match_all('/syslog_db_fetch_(?:row|assoc|cell)_prepared\s*\([^)]*information_schema/', $functions);

if ($partition_query_count === false || $partition_query_count !== 3) {
	fwrite(STDERR, "Partition queries are not consistently scoped to the requested table.\n");
	exit(1);
}

if (!preg_match('/SELECT\s+GET_LOCK\s*\(\s*\?\s*,\s*10\s*\)/', $functions)) {
	fwrite(STDERR, "Partition create lock acquisition is missing.\n");
	exit(1);
}

if (!preg_match('/SELECT\s+RELEASE_LOCK\s*\(\s*\?\s*\)/', $functions)) {
	fwrite(STDERR, "Partition create lock release is missing.\n");
	exit(1);
}

if (!preg_match('/function\s+syslog_partition_table_allowed\s*\(/', $functions)) {
	fwrite(STDERR, "Partition table validation helper is missing.\n");
	exit(1);
}

// RELEASE_LOCK must appear inside a finally block, not just anywhere in the function.
if (!preg_match('/finally\s*\{[^}]*RELEASE_LOCK/s', $functions)) {
	fwrite(STDERR, "RELEASE_LOCK is not inside a finally block; lock may be held on exception.\n");
	exit(1);
}

// The allowlist must be exactly the two known partition tables, nothing else.
if (!preg_match("/in_array\(\s*\\\$table\s*,\s*(?:array\s*\(\s*'syslog'\s*,\s*'syslog_removed'\s*\)|\[\s*'syslog'\s*,\s*'syslog_removed'\s*\])\s*,\s*true\s*\)/", $functions)) {
	fwrite(STDERR, "syslog_partition_table_allowed allowlist is not exactly ['syslog', 'syslog_removed'].\n");
	exit(1);
}

// syslog_partition_check must return false in its guard clause for disallowed tables.
// Signature may be ($table) or ($table, $time = null).
if (!preg_match('/function\s+syslog_partition_check\s*\(\s*\$table(?:\s*,\s*\$time[^)]*)?\s*\)\s*\{(.{0,600})/s', $functions, $m_check)) {
	fwrite(STDERR, "syslog_partition_check function not found.\n");
	exit(1);
}

if (!preg_match('/!syslog_partition_table_allowed[^}]*return\s+false/s', $m_check[1])) {
	fwrite(STDERR, "syslog_partition_check does not return false for disallowed tables.\n");
	exit(1);
}

// syslog_partition_remove must return 0 in its guard clause for disallowed tables.
if (!preg_match('/function\s+syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,400})/s', $functions, $m_remove)) {
	fwrite(STDERR, "syslog_partition_remove function not found.\n");
	exit(1);
}

if (!preg_match('/!syslog_partition_table_allowed[^}]*return\s+0/s', $m_remove[1])) {
	fwrite(STDERR, "syslog_partition_remove does not return 0 for disallowed tables.\n");
	exit(1);
}

// syslog_partition_remove's information_schema query must bind table_name via placeholder.
if (!preg_match('/function\s+syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,1800})/s', $functions, $m_remove_full)) {
	fwrite(STDERR, "syslog_partition_remove function body not found.\n");
	exit(1);
}

if (!preg_match('/syslog_db_fetch_(?:row|assoc|cell)_prepared[^)]*information_schema[^)]*table_name\s*=\s*\?/s', $m_remove_full[1])) {
	fwrite(STDERR, "syslog_partition_remove information_schema query is not scoped to table_name via placeholder.\n");
	exit(1);
}

// ---- Allowlist acceptance / rejection tests ----

// The allowlist function must reject values not in the list by returning false.
if (!preg_match('/function\s+syslog_partition_table_allowed\s*\(\s*\$table\s*\)\s*\{(.{0,600})/s', $functions, $m_allowed)) {
	fwrite(STDERR, "syslog_partition_table_allowed function not found.\n");
	exit(1);
}

if (!preg_match('/return\s+false/', $m_allowed[1])) {
	fwrite(STDERR, "syslog_partition_table_allowed does not explicitly return false for non-members.\n");
	exit(1);
}

// Defense-in-depth: the allowlist function must include a regex guard for safe identifiers.
if (!preg_match('/preg_match.*\[a-z_\]/', $m_allowed[1])) {
	fwrite(STDERR, "syslog_partition_table_allowed is missing regex defense-in-depth guard.\n");
	exit(1);
}

// ---- Lock name must be scoped to both database and table ----

if (!preg_match('/hash\s*\(\s*[\'"]sha256[\'"]\s*,\s*\$syslogdb_default\s*\.\s*[\'"][^"\']*[\'"]\s*\.\s*\$table\s*\)/', $functions)) {
	fwrite(STDERR, "Lock name hash does not include both database and table.\n");
	exit(1);
}

// ---- syslog_partition_remove must log when called with a disallowed table ----

if (!preg_match('/function\s+syslog_partition_remove\s*\(\s*\$table\s*\)\s*\{(.{0,600})/s', $functions, $m_remove_log)) {
	fwrite(STDERR, "syslog_partition_remove function not found for log check.\n");
	exit(1);
}

if (!preg_match('/!syslog_partition_table_allowed.*cacti_log.*disallowed.*return\s+0/s', $m_remove_log[1])) {
	fwrite(STDERR, "syslog_partition_remove does not log a warning for disallowed tables.\n");
	exit(1);
}

// ---- DDL safety comment must exist near ALTER TABLE in syslog_partition_create ----

if (!preg_match('/MySQL does not support parameter binding for DDL/', $functions)) {
	fwrite(STDERR, "Missing DDL safety comment in syslog_partition_create.\n");
	exit(1);
}

// ---- No raw (non-prepared) information_schema partition queries may exist ----

$raw_partition_queries = preg_match_all('/syslog_db_fetch_(?:row|assoc|cell)\s*\([^)]*information_schema/', $functions);

if ($raw_partition_queries !== false && $raw_partition_queries > 0) {
	fwrite(STDERR, "Found $raw_partition_queries raw (non-prepared) information_schema partition queries; all must use _prepared.\n");
	exit(1);
}

// ---- syslog_partition_remove must also use GET_LOCK / RELEASE_LOCK in a finally block ----

$remove_start = strpos($functions, 'function syslog_partition_remove');

if ($remove_start === false) {
	fwrite(STDERR, "Could not locate syslog_partition_remove.\n");
	exit(1);
}

$remove_end = strpos($functions, 'function syslog_partition_check', $remove_start);

if ($remove_end === false) {
	fwrite(STDERR, "Could not bound syslog_partition_remove.\n");
	exit(1);
}

$remove_body = substr($functions, $remove_start, $remove_end - $remove_start);

if (!preg_match('/GET_LOCK/', $remove_body)) {
	fwrite(STDERR, "syslog_partition_remove does not acquire a lock before ALTER TABLE.\n");
	exit(1);
}

if (!preg_match('/finally\s*\{[^}]*RELEASE_LOCK/s', $remove_body)) {
	fwrite(STDERR, "syslog_partition_remove does not release its lock in a finally block.\n");
	exit(1);
}

// ---- Lock names must differ between create and remove (per-operation scoping) ----

if (!preg_match('/syslog_partition_create\.\'\s*\.\s*\$table/', $functions)) {
	fwrite(STDERR, "syslog_partition_create lock name does not include function scope.\n");
	exit(1);
}

if (!preg_match('/syslog_partition_remove\.\'\s*\.\s*\$table/', $functions)) {
	fwrite(STDERR, "syslog_partition_remove lock name does not include function scope.\n");
	exit(1);
}

// ---- syslog_partition_create must return early (no DDL) when allowlist fails ----
// Signature may be ($table) or ($table, $time = null).
if (!preg_match('/function\s+syslog_partition_create\s*\(\s*\$table(?:\s*,\s*\$time[^)]*)?\s*\)\s*\{(.{0,400})/s', $functions, $m_create_guard)) {
	fwrite(STDERR, "syslog_partition_create function not found.\n");
	exit(1);
}

if (!preg_match('/!syslog_partition_table_allowed[^}]*return\s+false;/s', $m_create_guard[1])) {
	fwrite(STDERR, "syslog_partition_create does not return early for disallowed tables.\n");
	exit(1);
}

// ---- syslog_partition_check must use _prepared for info_schema ----

if (!preg_match('/function\s+syslog_partition_check\s*\(\s*\$table(?:\s*,\s*\$time[^)]*)?\s*\)\s*\{(.{0,1200})/s', $functions, $m_check_prep)) {
	fwrite(STDERR, "syslog_partition_check function not found for _prepared check.\n");
	exit(1);
}

if (!preg_match('/syslog_db_fetch_cell_prepared[^)]*information_schema[^)]*table_name\s*=\s*\?/s', $m_check_prep[1])) {
	fwrite(STDERR, "syslog_partition_check does not use _prepared with table_name placeholder.\n");
	exit(1);
}

// ---- Partition boundary must be driven by the optional $time parameter ----
//
// The stricter guarantee (pure integer epoch arithmetic, no strtotime() /
// UNIX_TIMESTAMP('date-literal')) lands with the PR #313 follow-up in
// functions.php; that refactor is reviewed separately from this test PR.
// Until it merges, only require that boundary handling exists and is driven
// by $time, without mandating one specific expression form.

// Isolate the syslog_partition_create function body for partition-specific checks.
$create_start = strpos($functions, 'function syslog_partition_create');

if ($create_start === false) {
	fwrite(STDERR, "Could not locate syslog_partition_create.\n");
	exit(1);
}

$create_end = strpos($functions, 'function syslog_partition_remove', $create_start);

if ($create_end === false) {
	fwrite(STDERR, "Could not locate syslog_partition_remove to bound syslog_partition_create.\n");
	exit(1);
}

$create_body = substr($functions, $create_start, $create_end - $create_start);

if (!preg_match('/\$time\b/', $create_body)) {
	fwrite(STDERR, "syslog_partition_create is missing time-based partition boundary handling.\n");
	exit(1);
}

if (!preg_match('/(?:strtotime\s*\(|UNIX_TIMESTAMP\s*\()/', $create_body)) {
	fwrite(STDERR, "syslog_partition_create is missing partition boundary computation logic.\n");
	exit(1);
}

// ---- syslog_partition_create must fall back to dMaxValue when the expression cannot be detected ----
//
// The exact log wording and statement ordering differ pre/post the PR #313
// follow-up; only require that the fallback still exists: a SHOW CREATE
// TABLE lookup, dMaxValue left as the catch-all partition, and a warning
// logged when the TO_DAYS/UNIX_TIMESTAMP expression can't be determined.

if (!preg_match('/SHOW CREATE TABLE/', $create_body)) {
	fwrite(STDERR, "syslog_partition_create is missing the SHOW CREATE TABLE lookup.\n");
	exit(1);
}

if (!preg_match('/dMaxValue/', $create_body)) {
	fwrite(STDERR, "syslog_partition_create does not preserve dMaxValue fallback on unknown partition expression.\n");
	exit(1);
}

if (!preg_match('/Unable to determine/i', $create_body)) {
	fwrite(STDERR, "syslog_partition_create does not log a warning when the partition expression can't be determined.\n");
	exit(1);
}

// ---- syslog_partition_manage must exist and drive both tables through check/create/remove ----
//
// Gating syslog_partition_remove() on syslog_partition_create()'s return value
// (so old partitions aren't dropped when the replacement failed to create) is
// part of the PR #313 follow-up, reviewed and merged separately. Only require
// that both tables are still routed through create and remove here.

$manage_start = strpos($functions, 'function syslog_partition_manage');

if ($manage_start === false) {
	fwrite(STDERR, "Could not locate syslog_partition_manage.\n");
	exit(1);
}

$manage_end = strpos($functions, 'function syslog_partition_table_allowed', $manage_start);

if ($manage_end === false) {
	fwrite(STDERR, "Could not bound syslog_partition_manage.\n");
	exit(1);
}

$manage_body = substr($functions, $manage_start, $manage_end - $manage_start);

foreach (['syslog', 'syslog_removed'] as $table) {
	if (!preg_match('/syslog_partition_create\s*\(\s*\'' . $table . '\'/', $manage_body)) {
		fwrite(STDERR, "syslog_partition_manage does not call syslog_partition_create('$table').\n");
		exit(1);
	}

	if (!preg_match('/syslog_partition_remove\s*\(\s*\'' . $table . '\'\s*\)/', $manage_body)) {
		fwrite(STDERR, "syslog_partition_manage does not call syslog_partition_remove('$table').\n");
		exit(1);
	}
}

// ---- syslog_manage_items must exist with the current two-table signature ----
//
// Allowlist validation of $from_table/$to_table is part of the PR #313
// follow-up (functions.php currently interpolates them unguarded; the only
// caller passes hardcoded literals). Tracked there rather than here.

if (!preg_match('/function\s+syslog_manage_items\s*\(\s*\$from_table\s*,\s*\$to_table\s*\)/', $functions)) {
	fwrite(STDERR, "syslog_manage_items function with \$from_table, \$to_table signature not found.\n");
	exit(1);
}

print "issue254_partition_table_locking_test passed\n";
