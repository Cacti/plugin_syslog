#!/usr/bin/env bash
set -euo pipefail

PW_IMAGE="mcr.microsoft.com/playwright:v1.52.0-jammy"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PW_PREFIX=(
  docker run --rm
  --network cacti-syslog-e2e_default
  --ipc=host
  -e E2E_SCHEME=http
  -e E2E_HOST=cacti_web
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

node_check_as() {
  local script="$1"
  shift

  local env_args=()
  local pair

  for pair in "$@"; do
    env_args+=(-e "$pair")
  done

  "${PW_PREFIX[@]}" -e "E2E_USER=${E2E_USER}" -e "E2E_PASSWORD=Admin123!" "${env_args[@]}" "$PW_IMAGE" node "$script" >/dev/null
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

long_username() {
  printf 'u%.0s' $(seq 50)
}

create_long_user() {
  local name
  name="$(long_username)"
  docker exec cacti_web php -r 'require "include/global.php"; $admin = db_fetch_row("SELECT * FROM user_auth WHERE username = \"admin\""); $old = $admin["id"]; unset($admin["id"]); $admin["username"] = $argv[1]; $admin["password"] = password_hash("Admin123!", PASSWORD_BCRYPT); $admin["must_change_password"] = ""; $admin["password_change"] = ""; db_execute_prepared("DELETE FROM user_auth WHERE username = ?", [$argv[1]]); $id = sql_save($admin, "user_auth"); db_execute_prepared("INSERT INTO user_auth_realm (user_id, realm_id) SELECT ?, realm_id FROM user_auth_realm WHERE user_id = ?", [$id, $old]);' "$name" >/dev/null
}

import_check() {
  local page="$1" text="$2" expect="$3" pad="${4:-0}" as_file="${5:-0}"
  node_check browser-import.js "PAGE_PATH=${page}" "IMPORT_TEXT=${text}" "EXPECT_TEXT=${expect}" "IMPORT_PAD_BYTES=${pad}" "IMPORT_AS_FILE=${as_file}"
}

ALERT_IMPORT_PAGE="plugins/syslog/syslog_alerts.php?action=import"
SEARCH_IMPORT_PAGE="plugins/syslog/syslog_saved_searches.php?action=import"

alert_file() {
  # alert_file <hash> <name> <type> <message> [extra json members]
  printf '{"version":1,"table":"syslog_alert","templates":[{"hash":"%s","name":"%s","severity":1,"method":0,"suppression_schedule":"","type":"%s","message":"%s"%s}]}' "$1" "$2" "$3" "$4" "${5:-}"
}

test_alert_import_paths() {
  db_exec "DELETE FROM syslog_alert"
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 11111111111111111111111111111111 'e2e import one' messagec error)" "Alert Filters"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_alert WHERE name = 'e2e import one'")" "1" "a valid pasted file should import"
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 11111111111111111111111111111111 'e2e import one' messagec changed ',"id":9999')" "Alert Filters"
  assert_equals "$(db_scalar "SELECT CONCAT(COUNT(*), ':', MAX(message)) FROM syslog_alert")" "1:changed" "the same hash should update that rule and ignore an id in the file"
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 22222222222222222222222222222222 'e2e import file' messagec fromfile)" "Alert Filters" 0 1
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_alert WHERE name = 'e2e import file'")" "1" "a valid uploaded file should import"
  import_check "$ALERT_IMPORT_PAGE" 'not json at all' "Alert Filters"
  import_check "$ALERT_IMPORT_PAGE" '{"version":1,"table":"syslog_alert","templates":[' "Alert Filters"
  import_check "$ALERT_IMPORT_PAGE" '{"version":1,"table":"syslog_remove","templates":[{"name":"x","method":"del","message":"y"}]}' "Alert Filters"
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 33333333333333333333333333333333 'e2e bad sql' sql 'message LIKE (')" "Alert Filters"
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 44444444444444444444444444444444 'e2e bad type' nonsense error)" "Alert Filters"
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 55555555555555555555555555555555 'e2e bad filter' filter '{\"version\":1,\"conditions\":[{\"field\":\"nope\"}]}')" "Alert Filters"
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 66666666666666666666666666666666 'e2e oversized' messagec error)" "Alert Filters" 6000000
  import_check "$ALERT_IMPORT_PAGE" "$(alert_file 77777777777777777777777777777777 'e2e oversized file' messagec error)" "Alert Filters" 6000000 1
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_alert")" "2" "malformed, hostile and oversized files should import nothing"
}

test_saved_search_import_paths() {
  db_exec "DELETE FROM syslog_saved_searches"
  local good='{"version":1,"table":"syslog_saved_searches","templates":[{"name":"e2e search","search":"message contains \"error\"","removal":1,"grouping":0}]}'
  import_check "$SEARCH_IMPORT_PAGE" "$good" "Saved Search Templates"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_saved_searches")" "1" "a valid search should import"
  import_check "$SEARCH_IMPORT_PAGE" '{"version":1,"table":"syslog_saved_searches","templates":[{"name":"e2e quotes","search":"message contains \"a'"'"'); DROP TABLE syslog; --\"","removal":1,"grouping":0}]}' "Saved Search Templates"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_saved_searches")" "2" "text that looks like SQL should be stored as text"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'cacti' AND table_name = 'syslog'")" "1" "the message table should still exist"
  import_check "$SEARCH_IMPORT_PAGE" 'not json at all' "Saved Search Templates"
  import_check "$SEARCH_IMPORT_PAGE" '{"version":1,"table":"syslog_saved_searches","templates":[{"name":"e2e open","search":"host = \"open","removal":1,"grouping":0}]}' "Saved Search Templates"
  import_check "$SEARCH_IMPORT_PAGE" '{"version":1,"table":"syslog_saved_searches","templates":[{"name":"e2e type","search":"message contains \"x\"","removal":9,"grouping":0}]}' "Saved Search Templates"
  import_check "$SEARCH_IMPORT_PAGE" '{"version":1,"table":"syslog_saved_searches","templates":[{"name":"e2e group","search":"message contains \"x\"","removal":1,"grouping":4}]}' "Saved Search Templates"
  import_check "$SEARCH_IMPORT_PAGE" "$good" "Saved Search Templates" 6000000
  import_check "$SEARCH_IMPORT_PAGE" "$good" "Saved Search Templates" 6000000 1
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_saved_searches")" "2" "invalid and oversized search files should import nothing"
}

test_reprocess_selected_rules_among_several() {
  db_exec "DELETE FROM syslog_remove; DELETE FROM syslog; INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 1, 1, 1, 6, 'E2E-MULTI alpha line'), (NOW(), 1, 1, 1, 6, 'E2E-MULTI beta line'), (NOW(), 1, 1, 1, 6, 'E2E-MULTI gamma line'), (NOW(), 1, 1, 1, 6, 'E2E-MULTI delta line')"
  db_exec "INSERT INTO syslog_remove (hash, name, type, message, method, enabled, user, date) VALUES (MD5('m alpha'), 'e2e multi alpha', 'messagec', 'alpha line', 'del', 'on', 'admin', UNIX_TIMESTAMP()), (MD5('m beta'), 'e2e multi beta', 'messagec', 'beta line', 'del', '', 'admin', UNIX_TIMESTAMP()), (MD5('m gamma'), 'e2e multi gamma', 'messagec', 'gamma line', 'trans', 'on', 'admin', UNIX_TIMESTAMP()), (MD5('m delta'), 'e2e multi delta', 'messagec', 'delta line', 'del', 'on', 'admin', UNIX_TIMESTAMP())"
  node_check browser-bulk-action.js "PAGE_PATH=plugins/syslog/syslog_removal.php" "ROW_TEXT=e2e multi alpha,e2e multi gamma,e2e multi beta" "ACTION_VALUE=4"
  assert_equals "$(db_scalar "SELECT GROUP_CONCAT(SUBSTRING_INDEX(message, ' ', -2) ORDER BY message) FROM syslog WHERE message LIKE 'E2E-MULTI%'")" "beta line,delta line" "only the selected enabled rules should run"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_removed WHERE message = 'E2E-MULTI gamma line'")" "1" "a selected move rule should archive its message"
}

seed_dashboard_data() {
  db_exec "TRUNCATE syslog; TRUNCATE syslog_removed; DELETE FROM syslog_hosts; DELETE FROM syslog_programs; INSERT INTO syslog_hosts (host_id, host) VALUES (1, 'dash-host-a'), (2, 'dash-host-b'), (3, 'dash-host-c'); INSERT INTO syslog_programs (program_id, program) VALUES (1, 'dash-prog-a'), (2, 'dash-prog-b')"
  db_exec "INSERT INTO syslog (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 1, 1, 1, 3, 'm'), (NOW(), 1, 2, 1, 4, 'm'), (NOW(), 2, 1, 16, 3, 'm')"
  db_exec "INSERT INTO syslog_removed (logtime, host_id, program_id, facility_id, priority_id, message) VALUES (NOW(), 3, 2, 16, 4, 'm'), (NOW(), 3, 2, 16, 4, 'm')"
  db_exec "DELETE FROM syslog_dashboard_panels WHERE title LIKE 'e2e panel%'"
}

dashboard_panel_check() {
  # dashboard_panel_check <removal> <field> <expected labels> <absent labels> <total>
  local removal="$1" field="$2" expect="$3" absent="$4" total="$5" panel_id
  db_exec "INSERT INTO syslog_dashboard_panels (dashboard_id, title, expression, source, removal, kind, chart, field, \`interval\`, timespan, top_n, width, height, position, date) SELECT MIN(id), 'e2e panel ${removal} ${field}', '', 'syslog', ${removal}, 'breakdown', 'donut', '${field}', 'auto', 'dashboard', 10, 1, 0, 90, UNIX_TIMESTAMP() FROM syslog_dashboards"
  panel_id="$(db_scalar "SELECT MAX(id) FROM syslog_dashboard_panels WHERE title = 'e2e panel ${removal} ${field}'")"
  node_check browser-json-check.js \
    "JSON_PATH=plugins/syslog/syslog.php?action=dashboard_chart&panel_id=${panel_id}&dashboard_timespan=86400" \
    "EXPECT_LABELS=${expect}" "ABSENT_LABELS=${absent}" "EXPECT_TOTAL=${total}"
}

test_dashboards_every_record_type_and_dimension() {
  seed_dashboard_data
  dashboard_panel_check -1 host "dash-host-a,dash-host-b" "dash-host-c" 3
  dashboard_panel_check 2 host "dash-host-c" "dash-host-a,dash-host-b" 2
  dashboard_panel_check 1 host "dash-host-a,dash-host-b,dash-host-c" "" 5
  dashboard_panel_check -1 program "dash-prog-a,dash-prog-b" "" 3
  dashboard_panel_check 2 program "dash-prog-b" "dash-prog-a" 2
  dashboard_panel_check 1 program "dash-prog-a,dash-prog-b" "" 5
  dashboard_panel_check -1 priority "err,warning" "" 3
  dashboard_panel_check 2 priority "warning" "err" 2
  dashboard_panel_check 1 priority "err,warning" "" 5
  dashboard_panel_check -1 facility "user,local0" "" 3
  dashboard_panel_check 2 facility "local0" "user" 2
  dashboard_panel_check 1 facility "user,local0" "" 5
}

test_long_username_owns_templates() {
  local owner
  owner="$(long_username)"
  create_long_user
  db_exec "DELETE FROM syslog_saved_searches WHERE user = '${owner}'; DELETE FROM syslog_dashboards WHERE user = '${owner}'"
  E2E_USER="$owner" node_check_as browser-json-post.js "JSON_PATH=plugins/syslog/syslog.php?action=saved_search_save" 'JSON_FIELDS={"name":"e2e long owner","rfilter":"message contains \"x\"","tab":"syslog","removal":"1","grouping":"0"}' "EXPECT_KEYS=id"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_saved_searches WHERE user = '${owner}' AND name = 'e2e long owner'")" "1" "a 50 character user name should own its saved search"
  E2E_USER="$owner" node_check_as browser-json-post.js "JSON_PATH=plugins/syslog/syslog.php?action=dashboard_save" 'JSON_FIELDS={"name":"e2e long owner dashboard"}' "EXPECT_KEYS=id"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_dashboards WHERE user = '${owner}'")" "1" "a 50 character user name should own its dashboard"
  E2E_USER="$owner" node_check_as browser-json-post.js "JSON_PATH=plugins/syslog/syslog.php?action=saved_search_save" 'JSON_FIELDS={"name":"","rfilter":"message contains \"x\"","tab":"syslog"}' "EXPECT_ERROR=1"
  E2E_USER="$owner" node_check_as browser-json-post.js "JSON_PATH=plugins/syslog/syslog.php?action=saved_search_save" 'JSON_FIELDS={"name":"e2e bad search","rfilter":"host = \"open","tab":"syslog"}' "EXPECT_ERROR=1"
  assert_equals "$(db_scalar "SELECT COUNT(*) FROM syslog_saved_searches WHERE user = '${owner}'")" "1" "rejected saves should not add rows"
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

run_test "17 alert import paths" test_alert_import_paths
run_test "18 saved search import paths" test_saved_search_import_paths
run_test "19 reprocess selected rules among several" test_reprocess_selected_rules_among_several
run_test "20 dashboards for every record type and dimension" test_dashboards_every_record_type_and_dimension
run_test "21 a 50 character user name owns its templates" test_long_username_owns_templates

echo "ALL PASS ${pass_count}"
