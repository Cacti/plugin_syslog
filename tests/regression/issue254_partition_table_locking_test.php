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

// ---- Partition boundary must be computed in PHP, not via strtotime/UNIX_TIMESTAMP('date') ----

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

// strtotime() mixes PHP's local time zone into UTC-intended math. Forbid it inside partition code.
if (preg_match('/strtotime\s*\(/', $create_body)) {
	fwrite(STDERR, "syslog_partition_create must not call strtotime(); partition math should be integer arithmetic.\n");
	exit(1);
}

// UNIX_TIMESTAMP('YYYY-MM-DD') interprets the literal in the MySQL session TZ.
// Boundaries must be integer literals computed in PHP instead.
if (preg_match("/UNIX_TIMESTAMP\s*\(\s*'/", $create_body)) {
	fwrite(STDERR, "syslog_partition_create must not pass a date literal to UNIX_TIMESTAMP(); compute the epoch in PHP.\n");
	exit(1);
}

// The boundary computation must be explicit (next UTC midnight).
if (!preg_match('/\(intdiv\(\$time\s*,\s*86400\)\s*\+\s*1\)\s*\*\s*86400/', $create_body)) {
	fwrite(STDERR, "syslog_partition_create is missing the UTC-midnight boundary epoch computation.\n");
	exit(1);
}

// ---- syslog_partition_create must fall back to dMaxValue when the expression cannot be detected ----

if (!preg_match('/SHOW CREATE TABLE.*Unable to determine partition expression.*dMaxValue/s', $functions)) {
	fwrite(STDERR, "syslog_partition_create does not preserve dMaxValue fallback on unknown partition expression.\n");
	exit(1);
}

// ---- syslog_partition_manage must gate syslog_partition_remove on syslog_partition_create's return ----

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

// The remove() call must be inside an if that checks create()'s return.
if (!preg_match('/if\s*\(\s*syslog_partition_create\s*\(\s*\'syslog\'\s*,[^)]*\)\s*\)\s*\{\s*\$syslog_deleted\s*=\s*syslog_partition_remove\s*\(\s*\'syslog\'\s*\)/s', $manage_body)) {
	fwrite(STDERR, "syslog_partition_manage does not gate syslog_partition_remove('syslog') on syslog_partition_create's return value.\n");
	exit(1);
}

if (!preg_match('/if\s*\(\s*syslog_partition_create\s*\(\s*\'syslog_removed\'\s*,[^)]*\)\s*\)\s*\{\s*\$syslog_deleted\s*\+\=\s*syslog_partition_remove\s*\(\s*\'syslog_removed\'\s*\)/s', $manage_body)) {
	fwrite(STDERR, "syslog_partition_manage does not gate syslog_partition_remove('syslog_removed') on syslog_partition_create's return value.\n");
	exit(1);
}

// ---- syslog_manage_items must validate $from_table and $to_table against an allowlist ----

if (!preg_match('/function\s+syslog_manage_items\s*\(\s*\$from_table\s*,\s*\$to_table\s*\)\s*\{(.{0,800})/s', $functions, $m_manage)) {
	fwrite(STDERR, "syslog_manage_items function not found.\n");
	exit(1);
}

$manage_head = $m_manage[1];

// The allowlist literal must appear explicitly in the guard block.
if (!preg_match("/\\\$allowed_tables\s*=\s*\[\s*'syslog'\s*,\s*'syslog_incoming'\s*,\s*'syslog_removed'\s*\]/", $manage_head)) {
	fwrite(STDERR, "syslog_manage_items does not declare the expected \$allowed_tables literal.\n");
	exit(1);
}

// Both $from_table and $to_table must be checked with in_array against the allowlist, and the guard must fail closed.
if (!preg_match('/!in_array\(\$from_table,\s*\$allowed_tables,\s*true\).*!in_array\(\$to_table,\s*\$allowed_tables,\s*true\)/s', $manage_head)) {
	fwrite(STDERR, "syslog_manage_items does not check both \$from_table and \$to_table with in_array(..., true).\n");
	exit(1);
}

if (!preg_match("/return\s*\[\s*'removed'\s*=>\s*0\s*,\s*'xferred'\s*=>\s*0\s*\]/", $manage_head)) {
	fwrite(STDERR, "syslog_manage_items guard does not fail closed with ['removed' => 0, 'xferred' => 0].\n");
	exit(1);
}

print "issue254_partition_table_locking_test passed\n";
