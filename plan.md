# Issue Tracker Validity Report — Cacti/plugin_syslog

_Reviewed 2026-09-15 — all 227 issues in https://github.com/Cacti/plugin_syslog/issues._
_Open issues verified against the codebase and the full regression suite (25 / 28 passing)._

---

## Snapshot

| Metric | Count |
|---|---|
| Total issues | 227 |
| Open | 16 |
| Closed | 211 |
| Regression tests passing | 25 / 28 |

---

## Valid findings — confirmed present in current code

### [#331](https://github.com/Cacti/plugin_syslog/issues/331) — SQL save errors: "Column 'seq'/'id' does not exist" (Cacti 1.2.31)

**Verdict: VALID — but the defect is in Cacti core, not the plugin.**

- Root cause confirmed in the thread: Cacti commit `aa1faf3e0` hardened `db_get_table_column_types()` to reject qualified `database.table` identifiers. Syslog legitimately calls `sql_save()` with `` `syslog_prod`.`syslog_logs` `` on its own PDO connection (`database.php:219` → `sql_save(..., $syslog_cnn)`), the metadata lookup returns empty, and every submitted key is reported "missing".
- The plugin side is correct (qualified names, correct connection). Fix belongs in Cacti core — tracked as Cacti/cacti#7826. **No plugin change needed**; do not bump INFO minimums to mask it.

### [#298](https://github.com/Cacti/plugin_syslog/issues/298) — Poller: lock timeout, signal handler, earlier partition rotation

**Verdict: VALID (partially fixed — one real mismatch remains).**

Already delivered by PR #316 and verified in code:
- Signal handlers: `pcntl_signal(SIGTERM/SIGINT)` + `unregister_process()` in `sig_handler` (`syslog_process.php:97–99`, `:259–273`)
- Partition created one hour ahead: `syslog_partition_manage()` uses `time() + 3600` (`functions.php:562`)
- Named locks with 10s timeouts: `GET_LOCK(?, 10)` (`functions.php:649`, `:767`) released in `finally`

**Remaining valid gap:** `syslog_process.php` allows a 3600-second run (`:43`, `:46`) but registers with `register_process_start('syslog', 'master', ..., 1200)` (`:144`). A legitimate run over 20 minutes can be killed/replaced while still active. Fix: align the registration timeout with allowed runtime or heartbeat it.

### [#263](https://github.com/Cacti/plugin_syslog/issues/263) — Make move/delete operations transactional

**Verdict: VALID — no transactions anywhere in the plugin.**

- `syslog_remove_items()` performs `INSERT INTO syslog_removed … SELECT` then `DELETE` as independent statements (`functions.php:~1400–1445`)
- `syslog_manage_items()` same INSERT-then-DELETE pattern (`functions.php:~1900–1930`)
- Zero `START TRANSACTION`/`COMMIT`/`ROLLBACK` in plugin code. A crash between statements leaves partial state.

### [#255](https://github.com/Cacti/plugin_syslog/issues/255) — SQL concatenation in legacy `syslog_manage_items()`

**Verdict: VALID — the `sql` rule type still concatenates raw rule text.**

- `WHERE (" . $remove['message'] . ')` remains in `syslog_manage_items()` for `type='sql'` (`functions.php:1885–1893`), reached via `syslog_batch_transfer.php:130`.
- Other branches use `db_qstr()` quoting (escaped but not prepared). Rule text can still alter query semantics in this legacy path.

### [#325](https://github.com/Cacti/plugin_syslog/issues/325) — Millisecond timestamps

**Verdict: VALID feature request (member-triaged, awaiting focused migration PR).**

- All four `logtime` columns are plain `TIMESTAMP` (`setup.php:506, 614, 665, 815`).
- Correctly scoped: needs `TIMESTAMP(3)`, partition DDL, upgrade path, ingestion, and `logtime, seq` ordering changes — not just a display tweak.

### [#284](https://github.com/Cacti/plugin_syslog/issues/284) — Secure QueryBuilder for JSON rules

**Verdict: VALID, SUBSTANTIALLY IMPLEMENTED — remaining scope is retiring the legacy fallback.**

- Done: `lib/QueryBuilder.php` (`Cacti\Syslog\QueryBuilder`) — versioned JSON schema, strict field/operator allow-list, fully parameterized SQL, depth/size limits; integrated as the `filter` type in `syslog_get_alert_sql()` (`functions.php:2538–2576`) and `syslog_get_removal_rule_sql()` (`:1069–1092`).
- Remaining: raw `type='sql'` fallback still concatenates `alert['message']`/`remove['message']` directly into WHERE clauses in both engines (`functions.php:2645–2652`, `:1370–1376`). Intentional migration path, but the risk it describes is still reachable until deprecated.

### [#285](https://github.com/Cacti/plugin_syslog/issues/285) — MVC separation of concerns

**Verdict: VALID — not started.** No `src/` directory, no PSR-4 autoload; `functions.php` remains a ~3,400-line monolith mixing UI, poller logic, and DB access. Long-term architecture item.

### Enhancement requests — valid as filed

| Issue | Verdict |
|---|---|
| [#317](https://github.com/Cacti/plugin_syslog/issues/317) v2 design principles | VALID tracking issue — accepted, bmfmancini working the list |
| [#202](https://github.com/Cacti/plugin_syslog/issues/202) Thresholds over a time window | VALID — verified: threshold queries only scan `syslog_incoming` within the poller cycle (`functions.php:2645+`) |
| [#46](https://github.com/Cacti/plugin_syslog/issues/46) CEMDB integration | VALID enhancement — WIP PR exists in bmfmancini's fork |
| [#8](https://github.com/Cacti/plugin_syslog/issues/8) Graphs for triggered alerts | VALID enhancement — WIP PR exists in bmfmancini's fork |
| [#229](https://github.com/Cacti/plugin_syslog/issues/229) SQL expression help | Valid *support* question, no code defect |

---

## Findings no longer valid (fixed on `develop`)

### [#286](https://github.com/Cacti/plugin_syslog/issues/286) — Visual Filter Builder UI

**Fixed.** Local `origin/develop` contains `c72e379` ("Visual filter builder for alarm and removal rules"); refreshed `upstream/develop` contains the merged upstream commits `1c327cf` (#343) and `61ad199` (#344). Current code includes `js/filter-builder.js`, `lib/QueryBuilder.php`, filter-builder loading in `functions.php`, alert/removal integrations in `syslog_alerts.php` and `syslog_removal.php`, and browser/regression coverage under `tests/browser/` and `tests/regression/`.

### [#262](https://github.com/Cacti/plugin_syslog/issues/262) — Bound XML import memory usage

**Fixed.** `SYSLOG_IMPORT_MAX_BYTES` is now enforced for both pasted XML and uploaded files: `syslog_get_import_xml_payload()` rejects oversized `import_text` before returning it, and `syslog_read_import_file()` rejects empty, unreadable, or oversized files before `fread()` (`functions.php:450–510`). Current coverage includes `tests/Security/CsvImportHardeningTest.php` and `tests/Security/ImportPayloadLoaderTest.php`.

### [#256](https://github.com/Cacti/plugin_syslog/issues/256) — CSV formula injection

**Fixed.** `syslog_csv_safe()` (`functions.php:1564–1590`) prefixes `'` for leading `= + - @` **and tab/CR**, preserves content (no data-destroying `trim()`), and both export branches use `fputcsv($fp, $line, ',', '"', '')` (RFC-4180, `functions.php:1638–1715`). Current coverage lives in `tests/Security/CsvImportHardeningTest.php`.

### [#259](https://github.com/Cacti/plugin_syslog/issues/259) — POST + CSRF for purge_syslog_hosts

**Fixed.** `syslog_utilities_action()` requires POST (`setup.php:1670–1676`), validates `csrf_check(false)` with an unavailable-helper guard (`:1683–1700`), logs each block, and the UI posts `__csrf_magic` behind a confirm dialog (`:1764+`). *The failing test expects 3 DELETE statements but the hardened code intentionally purges 2 tables — a stale test expectation, not a product defect.*

---

## Regression-suite health (affects confidence in "fixed" claims)

The old import-hardening failure has been replaced by current security coverage. Remaining listed failures are stale/harness issues rather than product regressions:

| Test | Status | Cause |
|---|---|---|
| `issue254_partition_table_locking_test.php` | FAIL (pre-existing) | Test greps for `strtotime()/UNIX_TIMESTAMP` in `syslog_partition_create`; code now uses integer UTC boundary math (`intdiv($time, 86400)…`) per the CHANGELOG "integer UTC partition boundaries" change. Test outdated. |
| `issue256_262_csv_import_hardening_test.php` | FIXED/RENAMED | Replaced by current security coverage: `tests/Security/CsvImportHardeningTest.php` verifies CSV hardening and the XML import size limit. |
| `issue259_csrf_purge_test.php` | FAIL (pre-existing) | Expects 3 deletes; hardened code performs 2. Test outdated. |
| `message_details_escape_test.php` | FAIL (pre-existing) | Test harness calls `syslog_message_button()` standalone; `title_trim()` comes from Cacti core and isn't loaded. Harness limitation. |

---

## Recommended priority order

1. **#298** — align `register_process_start` timeout (1200s) with the 3600s allowed runtime
2. **#263** — wrap coupled INSERT+DELETE in transactions
3. **#255** — deprecate/remove raw-SQL concatenation in the legacy batch path
4. Refresh the remaining stale regression tests so CI reflects real state
5. Long-term: #284 (retire `sql` fallback), #285, #325, #317
