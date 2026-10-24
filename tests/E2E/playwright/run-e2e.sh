#!/usr/bin/env bash
set -euo pipefail

PW_IMAGE="mcr.microsoft.com/playwright:v1.52.0-jammy"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PW_PREFIX=(
  docker run --rm
  --network cacti-syslog-e2e_default
  --ipc=host
  -v "${ROOT_DIR}/tests/E2E/playwright:/work" \
  -w /work
)

pass_count=0

db_scalar() {
  docker exec cacti_db mariadb -N -B -ucacti -pcacti cacti -e "$1"
}

db_exec() {
  docker exec cacti_db mariadb -ucacti -pcacti cacti -e "$1" >/dev/null
}

browser_check() {
  local page_path="$1"
  local expect_title="$2"
  local expect_text="$3"
  local expect_text_2="${4:-}"
  local expect_text_3="${5:-}"

  "${PW_PREFIX[@]}" \
    -e "PAGE_PATH=${page_path}" \
    -e "EXPECT_TITLE=${expect_title}" \
    -e "EXPECT_TEXT=${expect_text}" \
    -e "EXPECT_TEXT_2=${expect_text_2}" \
    -e "EXPECT_TEXT_3=${expect_text_3}" \
    "$PW_IMAGE" \
    node browser-check.js >/dev/null
}

node_check() {
  local script="$1"
  shift

  local env_args=()
  local pair

  for pair in "$@"; do
    env_args+=(-e "$pair")
  done

  "${PW_PREFIX[@]}" "${env_args[@]}" "$PW_IMAGE" node "$script" >/dev/null
}

run_test() {
  local name="$1"
  shift
  echo "TEST ${name}"
  "$@"
  pass_count=$((pass_count + 1))
  echo "PASS ${name}"
}

assert_equals() {
  local actual="$1"
  local expected="$2"
  local label="$3"

  if [[ "$actual" != "$expected" ]]; then
    echo "FAIL ${label}: expected '${expected}', got '${actual}'" >&2
    exit 1
  fi
}

normalize_admin() {
  docker exec cacti_web php -r 'require "include/global.php"; $hash = password_hash("Admin123!", PASSWORD_BCRYPT); db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = \"\", password_change = \"\" WHERE username = \"admin\"", [$hash]);' >/dev/null
}

reset_syslog_data() {
  db_exec "TRUNCATE syslog_incoming; TRUNCATE syslog; TRUNCATE syslog_removed; TRUNCATE syslog_hosts; TRUNCATE syslog_programs; TRUNCATE syslog_host_facilities; DELETE FROM syslog_remove; DELETE FROM syslog_alert; DELETE FROM syslog_logs; DELETE FROM syslog_saved_searches;"
}

seed_incoming() {
  local host="$1"
  local program="$2"
  local message="$3"
  db_exec "INSERT INTO syslog_incoming (facility_id, priority_id, program, logtime, host, message) VALUES (1, 6, '${program}', NOW(), '${host}', '${message}')"
}

run_processor() {
  docker exec cacti_web php /var/www/html/cacti/plugins/syslog/syslog_process.php --debug >/dev/null
}

test_login_console() {
  browser_check "index.php" "Console" "Main Console" "Logged in as admin"
}

test_syslog_empty_page() {
  reset_syslog_data
  browser_check "plugins/syslog/syslog.php" "Syslog" "No Syslog Messages" "Unprocessed Messages: 0"
}

test_alerts_page() {
  browser_check "plugins/syslog/syslog_alerts.php" "Console > Syslog Alerts" "No Syslog Alerts Defined"
}

test_removal_page() {
  browser_check "plugins/syslog/syslog_removal.php" "Console > Syslog Removal" "No Syslog Removal Rules Defined"
}

test_reports_page() {
  browser_check "plugins/syslog/syslog_reports.php" "Console > Syslog Reports" "No Syslog Reports Defined"
}

test_plain_ingest_to_main() {
  seed_incoming "e2e-host-1" "e2e-prog-1" "E2E-TOKEN-ONE plain ingest"
  run_processor
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_incoming")" "0" "incoming queue should be drained"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog WHERE message = 'E2E-TOKEN-ONE plain ingest'")" "1" "plain message should land in syslog"
}

test_plain_ingest_normalization() {
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_hosts WHERE host = 'e2e-host-1'")" "1" "host should be normalized"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_programs WHERE program = 'e2e-prog-1'")" "1" "program should be normalized"
}

test_plain_ingest_visible_in_ui() {
  browser_check "plugins/syslog/syslog.php?rfilter=E2E-TOKEN-ONE" "Syslog" "E2E-TOKEN-ONE" "e2e-host-1" "e2e-prog-1"
}

test_second_ingest_accumulates() {
  seed_incoming "e2e-host-2" "e2e-prog-2" "E2E-TOKEN-TWO second ingest"
  run_processor
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog")" "2" "two processed records should exist"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_hosts WHERE host = 'e2e-host-2'")" "1" "second host should be normalized"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_programs WHERE program = 'e2e-prog-2'")" "1" "second program should be normalized"
}

test_second_ingest_visible_in_ui() {
  browser_check "plugins/syslog/syslog.php?rfilter=E2E-TOKEN-TWO" "Syslog" "E2E-TOKEN-TWO" "e2e-host-2" "e2e-prog-2"
}

test_dashboard_all_records_lists_every_host() {
  local panel_id
  panel_id="$(db_scalar "SELECT id FROM syslog_dashboard_panels WHERE title = 'Top 10 Hosts' LIMIT 1")"
  node_check browser-json-check.js \
    "JSON_PATH=plugins/syslog/syslog.php?action=dashboard_chart&panel_id=${panel_id}&dashboard_timespan=86400" \
    "EXPECT_LABELS=e2e-host-1,e2e-host-2"
}

test_archive_rule_on_host_moves_messages() {
  db_exec "DELETE FROM syslog_remove; INSERT INTO syslog_remove (hash, name, type, message, method, enabled, user, date) VALUES (MD5('e2e archive host'), 'e2e archive host', 'host', 'e2e-host-3', 'trans', 'on', 'admin', UNIX_TIMESTAMP())"
  seed_incoming "e2e-host-3" "e2e-prog-3" "E2E-TOKEN-ARCHIVE archived by host"
  seed_incoming "e2e-host-4" "e2e-prog-3" "E2E-TOKEN-KEEP stays in the main table"
  run_processor
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_removed WHERE message = 'E2E-TOKEN-ARCHIVE archived by host'")" "1" "host rule should archive its message"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog WHERE message = 'E2E-TOKEN-KEEP stays in the main table'")" "1" "other hosts should reach the main table"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_incoming")" "0" "incoming queue should be drained"
}

test_failing_alert_rule_does_not_silence_others() {
  db_exec "DELETE FROM syslog_alert; DELETE FROM syslog_logs; INSERT INTO syslog_alert (hash, name, type, message, method, num, enabled, severity, suppression_schedule, user, date) VALUES (MD5('e2e good'), 'e2e good', 'sql', 'message LIKE ''%E2E-TOKEN-ALERT%''', 0, 1, 'on', 1, '', 'admin', UNIX_TIMESTAMP()), (MD5('e2e bad'), 'e2e bad', 'sql', 'message LIKE ''%x'' AND (', 0, 1, 'on', 1, '', 'admin', UNIX_TIMESTAMP())"
  seed_incoming "e2e-host-5" "e2e-prog-5" "E2E-TOKEN-ALERT should raise the valid rule"
  run_processor
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_logs WHERE logmsg LIKE 'E2E-TOKEN-ALERT%'")" "1" "valid rule should still raise its alert"
}

test_import_rejects_alert_that_does_not_run() {
  db_exec "DELETE FROM syslog_alert"
  node_check browser-import.js \
    "PAGE_PATH=plugins/syslog/syslog_alerts.php?action=import" \
    'IMPORT_TEXT={"version":1,"table":"syslog_alert","templates":[{"hash":"0123456789abcdef0123456789abcdef","name":"e2e broken","severity":1,"method":0,"suppression_schedule":"","type":"sql","message":"message LIKE ("}]}' \
    "EXPECT_TEXT=Alert Filters"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_alert")" "0" "an alert that cannot run should not be imported"
}

test_import_rejects_saved_search_that_does_not_parse() {
  db_exec "DELETE FROM syslog_saved_searches"
  node_check browser-import.js \
    "PAGE_PATH=plugins/syslog/syslog_saved_searches.php?action=import" \
    'IMPORT_TEXT={"version":1,"table":"syslog_saved_searches","templates":[{"name":"e2e broken","search":"host = \"unterminated"}]}' \
    "EXPECT_TEXT=Saved Search Templates"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_saved_searches")" "0" "a search that does not parse should not be imported"
}

test_reprocess_runs_only_the_selected_rule() {
  db_exec "DELETE FROM syslog_remove; DELETE FROM syslog; INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 1, 1, 1, 6, 'E2E-REPROCESS alpha line'), (NOW(), 1, 1, 1, 6, 'E2E-REPROCESS beta line')"
  db_exec "INSERT INTO syslog_remove (hash, name, type, message, method, enabled, user, date) VALUES (MD5('e2e alpha rule'), 'e2e alpha rule', 'messagec', 'alpha line', 'del', 'on', 'admin', UNIX_TIMESTAMP()), (MD5('e2e beta rule'), 'e2e beta rule', 'messagec', 'beta line', 'del', 'on', 'admin', UNIX_TIMESTAMP())"
  node_check browser-bulk-action.js \
    "PAGE_PATH=plugins/syslog/syslog_removal.php" \
    "ROW_TEXT=e2e alpha rule" \
    "ACTION_VALUE=4"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog WHERE message = 'E2E-REPROCESS alpha line'")" "0" "the selected rule should remove its messages"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog WHERE message = 'E2E-REPROCESS beta line'")" "1" "the other rule should not run"
}

normalize_admin

run_test "1 login console" test_login_console
run_test "2 syslog empty page" test_syslog_empty_page
run_test "3 alerts page" test_alerts_page
run_test "4 removal page" test_removal_page
run_test "5 reports page" test_reports_page
run_test "6 plain ingest to main" test_plain_ingest_to_main
run_test "7 plain ingest normalization" test_plain_ingest_normalization
run_test "8 plain ingest visible in ui" test_plain_ingest_visible_in_ui
run_test "9 second ingest accumulates" test_second_ingest_accumulates
run_test "10 second ingest visible in ui" test_second_ingest_visible_in_ui

run_test "11 dashboard all records lists every host" test_dashboard_all_records_lists_every_host
run_test "12 archive rule on host moves messages" test_archive_rule_on_host_moves_messages
run_test "13 failing alert rule does not silence others" test_failing_alert_rule_does_not_silence_others
run_test "14 import rejects an alert that does not run" test_import_rejects_alert_that_does_not_run
run_test "15 import rejects a saved search that does not parse" test_import_rejects_saved_search_that_does_not_parse
run_test "16 reprocess runs only the selected rule" test_reprocess_runs_only_the_selected_rule

echo "ALL PASS ${pass_count}"
