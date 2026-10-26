# Alarm Manager forwarding

In **Console → Configuration → Settings → Syslog**, enable **Forward alarms to
Alarm Manager**. It is off by default. Install and enable `plugin_alarmmanager`
on each processing collector, with matching enabled routing rules and delivery
destinations. Cacti's normal settings replication carries this setting; Syslog's
existing rule replication continues unchanged. No HTTP endpoint or credentials
are needed by Syslog.

In each Alert Rule, select **Alarm Destination → Syslog Alarming** (the default)
or **Alarm Manager**. The selector appears only when Alarm Manager is installed.
The global forwarding setting must also be enabled for Alarm Manager rules.
Their Email Options and Actions sections are hidden, and ticket, alert, and recovery commands never
run, including when forwarding is unavailable. Configured email remains the
fallback. Removing or disabling Alarm Manager retains that email fallback.

Syslog checks Cacti's `api_plugin_installed()` and `api_plugin_is_enabled()` and
the enabled `alarmmanager_submit` hook registration, then calls
`api_plugin_hook_function()` with contract version 1. Only an unchanged event
returned with `accepted` or `already_accepted` and a positive integer
`event_row_id` suppresses the corresponding email/SMS email. `unhandled`,
`failed` (including invalid-event rejection), exceptions, and malformed results
retain normal Syslog email. Acceptance means committed ingestion and processing
job storage, not successful routing or external delivery. Syslog Alarming rules retain their existing commands and email. Alarm history,
maintenance and suppression checks remain active for both destinations.

Events include the rule, source message IDs, message text (bounded to 16 KiB),
hosts, severity, timestamp, count, and processing collector. Syslog host IDs are
not Cacti device IDs; they are sent as external identities with a null device
ID. Event identity uses the rule, collector and sorted source sequence/time
pairs. Retrying the same batch sends the same event; separate occurrences have
separate stream keys. Keep the Cacti base URL, Syslog database name, collector ID
and collector timezone stable across retries. Failures are logged by rule and
safe reason code without message or provider exception contents.

Windowed threshold events use their existing breach key to correlate raises
and recovery. Only accepted raises enable forwarding of their quiet-period
clear. The existing collector-owned recovery queue retains clear timestamps and
retries failed submissions. Alarm Manager recovery does not run commands.
Occurrence alarms and thresholds without breach state send occurrences
and never invent clears. Replicated history and foreign-owned breaches cannot
submit events. Alarm Manager owns its own native queue/history replication.

Raise forwarding is synchronous at the existing notification boundary; this
change does not add a crash-durable raise outbox. The local recovery-ownership
write follows Alarm Manager's ingestion commit; a crash or failed local write
between them can leave an accepted raise without a forwarded clear (logged when
the write fails). Existing Syslog notification retry semantics otherwise apply.
Disabling forwarding, deleting/disabling/editing a rule, or changing its recovery
command can cancel queued recovery under the existing lifecycle rules.

Local checks:

```sh
php tests/regression/alarm_destination_save_test.php
node tests/regression/alarm_destination_ui_test.js
php tests/regression/alarmmanager_forward_test.php
SYSLOG_TEST_MYSQL_SOCKET=/path/to/disposable/mysql.sock php tests/regression/threshold_recovery_database_test.php
php tests/regression/threshold_alert_host_test.php
```

The forwarding tests use Alarm Manager's real event validator from the sibling
checkout (an alternate validator path can be passed as the first argument to
`alarmmanager_forward_test.php`). Ingestion and mail delivery are stubbed. The
MariaDB test creates and drops randomly named test databases and exercises real
Syslog recovery SQL and local fixture commands. Neither test verifies live
remote collectors, Alarm Manager ingestion commits, routing, or provider delivery.
