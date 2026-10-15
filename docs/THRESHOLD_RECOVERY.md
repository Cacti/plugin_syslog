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
the two tables, adds the rule's recovery duration, and adds nullable source identity
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
