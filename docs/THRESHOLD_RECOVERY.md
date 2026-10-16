# Threshold recovery

Recover After Quiet Period accepts a positive value and Minutes, Hours, or Days
on the same row, just like Threshold Window. Existing settings remain in minutes;
the default is 5 minutes.

For 5 matching messages in 10 minutes and a 5-minute quiet period:

1. The collector batch that reaches 5 opens a breach and generates the initial alert.
2. Subsequent matching batches extend the quiet timer, even if their rolling count drops below 5.
3. Five minutes without a processed match clears the breach and resets its count to zero.
4. Another 5 matches within 10 minutes can open a new breach.

Times use collector processing time. Recovery runs on every processing cycle,
including cycles without incoming messages. System rules share a counting and
recovery scope across hosts; device rules maintain each host independently.
Individual reporting is unchanged. Notification cooldown, duplicate suppression,
maintenance, and Re-Alert settings continue to apply. Recovery does not send an
additional notification.

The Messages view derives Breach from active episode associations. Messages from
an ended episode display No and do not become Yes again when another episode
starts. A message matching multiple rules remains Yes while any associated
episode is active. Group summaries aggregate that same state. Stored historical
message flags are retained for compatibility, but do not represent active state.

`syslog_threshold_state` stores current episodes and their quiet deadlines.
`syslog_threshold_messages` associates immutable collector/event identities with
episodes, using indexes for page lookups and episode cleanup. Associations are
removed on recovery; historical message rows are never rewritten. Ongoing
breaches retain their message associations until recovery.

Upgrade the main collector before remote collectors. Enable/schema repair creates
the episode and message-association tables, adds the rule's recovery duration, and adds nullable source identity
columns to removed-message history if absent. Column additions use the add-only
helper and preserve keys. No historical backfill is performed; existing history
without episode associations displays No. Large-table ALTER behavior still depends
on the database version and storage engine.

New archive and replication records preserve source identities. Replication sends
active state with message batches; the central view expires it at the quiet deadline,
including when no explicit recovery event arrives. Late deliveries cannot shorten
or replace a more recent state snapshot.

Run focused lifecycle checks against a disposable local MariaDB instance:

```sh
SYSLOG_TEST_MYSQL_SOCKET=/path/to/test.sock php tests/regression/threshold_recovery_database_test.php
```

The test creates randomly named databases and removes them afterward. It checks
quiet timer extension, count reset, rearming, multiple rules, per-device recovery,
central state expiry, and indexed message lookups without contacting a provider.

## Breached Thresholds tab

The viewer shows Breached Thresholds when at least one enabled rule uses threshold
reporting. Its badge counts active episodes; healthy rules keep the tab visible
with an explicit empty state. The list is independent of notification delivery.
It shows the rule, device, collector, breach start, last match, and the recovery
deadline. Device rules have separate rows per host; shared rules show All matching
devices. Results are paginated in groups of 50. Expired, disabled, deleted, or
edited rules are excluded without waiting for episode cleanup.

Breach start is recorded in the small episode table and carried through collector
replication. Episodes already active before this upgrade display Unknown for their
start time until the next breach. Existing syslog message tables are unchanged.

## Alert and recovery commands

Configure both commands on the **same alert rule**. The existing **Alert command** runs
when the threshold breaches. The optional **Recovery command** appears when the
Reporting Method is Threshold and runs after the quiet period completes. Leave it
blank to keep the existing behavior. No separate clear rule is required.

Both commands can use the existing escaped replacement tokens (`<HOSTNAME>`,
`<ALERTID>`, `<MESSAGE>`, `<FACILITY>`, `<PRIORITY>`, `<SEVERITY>`) and `ALERT_`
environment variables. Recovery uses the first matching batch's saved command and
message context. Existing `ALERT_` values retain their shell-quoted representation.
The following additional environment variables contain plain, unquoted values:

| Variable | Meaning |
| --- | --- |
| `SYSLOG_EVENT` | `breach` for the alert command, `clear` for recovery |
| `SYSLOG_BREACH_ID` | One unique identity shared by the breach and its clear, including retries |
| `SYSLOG_ALERT_ID` | Alert rule ID |
| `SYSLOG_HOSTNAME` | Device hostname for device thresholds; empty for shared thresholds |
| `SYSLOG_POLLER_ID` | Origin collector ID |

For example, set both command fields to `/usr/local/bin/notify.sh`. That script can
read `SYSLOG_EVENT` to raise or clear an alarm and `SYSLOG_BREACH_ID` to identify
which alarm to clear. A later breach has a different ID, so a delayed clear cannot
close that newer alarm when the receiver correlates on this value.

Clears are saved in `syslog_threshold_recovery_queue` in the same transaction that
removes the expired episode. Only the origin collector delivers them. Replicated
snapshots never carry commands or saved script context. Delivery runs during alert
processing even when no new messages arrive, with at most 25 due commands per cycle.
Commands run synchronously, as existing alert commands do; keep them short.

Exit code `0` acknowledges delivery. Other exit codes or a missing executable retain
the clear for retry after 1, 2, 4, 8 minutes, up to a maximum one-hour delay. Retries
continue until success or the rule is edited, disabled, deleted, switched to
Individual, or its Recovery command is removed. These rule changes cancel queued
clears and retire active state without sending a healthy event.

A connection-scoped database lock prevents two workers from delivering the same
queue concurrently. A crash after script success but before acknowledgment can
still repeat delivery: scripts must treat `(SYSLOG_BREACH_ID, SYSLOG_EVENT)`
idempotently. This queue does not add retries to existing breach commands, email,
or ticket delivery.

Install/schema repair adds the recovery command column, a nullable saved-context
column on the small episode table, and the queue. No message history is rewritten.
Existing episodes without saved context cannot emit a correlated clear until a
subsequent matching batch captures context; new episodes capture it before an
alarm is raised. Recovery commands execute with the same poller account and
executable-path checks as alert commands.
