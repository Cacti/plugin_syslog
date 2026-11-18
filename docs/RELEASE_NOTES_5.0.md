# Syslog 5.0: reusable investigations and clearer collector health

**Unreleased.** These notes describe the 5.0 changes on `develop`; the latest
published GitHub release is [4.2](https://github.com/Cacti/plugin_syslog/releases/tag/v4.2).

Syslog 5.0 brings saved searches, shared dashboards and grouped messages to
everyday log investigation. Preview alert and removal rules against queued
messages, view explanations for recognized message identifiers, and monitor
collection backlogs and processing delays from the Status page.

## Highlights

- **Reuse and share investigations.** Save named searches, collapse repeated
  messages into groups with occurrence counts, and build dashboards with time
  series and breakdown charts. Share searches and dashboards through the
  corresponding permission realms; recipients can make their own dashboard copy.
- **Preview a rule before enabling it.** The **Test rule** action returns a
  matching count and bounded message sample without saving the rule or performing
  its actions. It searches queued incoming messages, so an empty preview does not
  mean previously archived logs contain no matches.
- **See when collection needs attention.** Collector health shows the incoming
  backlog, oldest queued message, last received log and recent processing count.
  Phase timings and worker activity help locate delays; optional pipeline health
  notifications report problems and recovery. Complete Cacti or database outages
  still need external monitoring.
- **Give operators more context and control.** Recognized message identifiers can
  show an explanation and recommended action. Device Alert Rules support alert
  pauses and priority exceptions, while threshold alerts support recovery commands
  and the documented quiet-period recovery lifecycle.
- **Support remote collection and recovery.** Configured remote collectors keep
  processed events in a durable delivery queue through outages and retry delivery
  to the main collector. Parallel processing is configurable; partition recovery
  is bounded and reports blocked maintenance instead of silently pruning history.

The release also includes theme and message-readability improvements, security
hardening and fixes for installations using a separate Syslog database. See the
[full changelog](../CHANGELOG.md) for individual changes.

## Before upgrading

The documented runtime floor is **Cacti 1.2.23 or later and PHP 8.0 or later**;
Cacti itself may require a newer PHP version. See the [runtime and test baseline](../README.md#runtime-and-test-baseline)
for the versions exercised by CI.

Back up the Syslog database and local configuration. Replace the plugin files and
run the Upgrade Advisor from Plugin Management, following the
[installation and upgrade instructions](../README.md#installation). Review the
advisor's selected upgrade type: **Truncate Syslog Table** discards stored
messages, while rules are preserved. The release does not require truncating an
existing installation merely to obtain the new interface features.

### Compatibility and operator actions

- **Sharing permissions:** review the new dashboard and saved-search sharing
  realms before granting access to shared investigations.
- **Statistics and storage:** the Statistics tab and its collection are deprecated.
  New installations use partitioned tables; existing traditional tables continue
  working with a migration notice. See [storage support](../README.md#table-architecture-and-storage-engine-support).
- **Alert commands:** use absolute executable paths with the execute bit set;
  the earlier `/bin/sh` fallback for non-executable templates has been removed.
- **Threshold recovery:** review the [quiet-period recovery behavior](THRESHOLD_RECOVERY.md)
  and any configured recovery command before deployment.

### Optional millisecond timestamp conversion

Normal upgrades keep the existing timestamp precision. New MariaDB installations
can use millisecond timestamps; MySQL uses second precision for partition pruning.

**Converting an existing MariaDB installation is optional and destructive.**
`cli/updatedb_convert_ms.php` deletes stored messages from `syslog`,
`syslog_incoming` and `syslog_removed`. It preserves rules and settings and widens
the remote-delivery outbox in place. Take a backup and schedule a maintenance
window before using it. Read the [conversion instructions](../README.md#converting-an-existing-mariadb-install)
first; this is not a routine upgrade step.

## Contributors and complete history

Thank you to the community members who developed, translated, tested and reviewed
these changes. The [commit index](COMMITS_5.0.md) records the current 5.0 snapshot
since v4.2, with links to commits and their associated contributors. It is a fixed
snapshot, not a claim that 5.0 has been published; refresh it when the release is finalized.

[Browse the snapshot comparison](https://github.com/Cacti/plugin_syslog/compare/v4.2...2489d3d73555ac8b914e5768da55f1f15621b97c).
