#!/usr/bin/env bash
set -euo pipefail

PW_IMAGE="mcr.microsoft.com/playwright:v1.52.0-jammy"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PW_PREFIX=(
  docker run --rm
  --network cacti-syslog-e2e_default
  --ipc=host
  -v "${ROOT_DIR}/tests/e2e/playwright:/work"
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
  db_exec "TRUNCATE syslog_incoming; TRUNCATE syslog; TRUNCATE syslog_removed; TRUNCATE syslog_statistics; TRUNCATE syslog_hosts; TRUNCATE syslog_programs; TRUNCATE syslog_host_facilities; DELETE FROM syslog_remove;"
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
  browser_check "plugins/syslog/syslog.php" "Console > Syslog" "No Syslog Messages" "Unprocessed Messages: 0"
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
  browser_check "plugins/syslog/syslog.php?rfilter=E2E-TOKEN-ONE" "Console > Syslog" "E2E-TOKEN-ONE" "e2e-host-1" "e2e-prog-1"
}

test_second_ingest_accumulates() {
  seed_incoming "e2e-host-2" "e2e-prog-2" "E2E-TOKEN-TWO second ingest"
  run_processor
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog")" "2" "two processed records should exist"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_hosts WHERE host = 'e2e-host-2'")" "1" "second host should be normalized"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_programs WHERE program = 'e2e-prog-2'")" "1" "second program should be normalized"
}

test_second_ingest_visible_in_ui() {
  browser_check "plugins/syslog/syslog.php?rfilter=E2E-TOKEN-TWO" "Console > Syslog" "E2E-TOKEN-TWO" "e2e-host-2" "e2e-prog-2"
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

echo "ALL PASS ${pass_count}"
