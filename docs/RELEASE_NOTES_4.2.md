# Syslog 4.2: fixes for rule editing, filtering and message cleanup

Syslog 4.2 is a maintenance release focused on everyday log management: creating
program-based alerts, finding devices in the filter, and removing messages. It
also includes PHP 8.2 deprecation fixes and updated rsyslog configuration guidance.

## What changes for operators

- **Program-based alerts:** fixes an error when creating an alert rule based on
  a program name ([#220](https://github.com/Cacti/plugin_syslog/issues/220)).
- **Removal expressions:** fixes matching against the `program` column and SQL
  errors in removal rules ([#184](https://github.com/Cacti/plugin_syslog/issues/184),
  [#217](https://github.com/Cacti/plugin_syslog/issues/217)).
- **Message deletion:** fixes SQL errors when deleting messages
  ([#218](https://github.com/Cacti/plugin_syslog/issues/218)).
- **Device filtering:** restores devices missing from the Device Filter
  ([#211](https://github.com/Cacti/plugin_syslog/issues/211)).
- **Deployment fixes:** addresses an `escapeshellarg()` ValueError and PHP 8.2
  deprecation messages, and corrects the rsyslog configuration documentation
  ([#208](https://github.com/Cacti/plugin_syslog/issues/208),
  [#216](https://github.com/Cacti/plugin_syslog/issues/216),
  [#222](https://github.com/Cacti/plugin_syslog/issues/222)).

## Upgrade and verify

The release declares **Cacti 1.2.23 or later** in its plugin metadata. Back up the
Syslog database, plugin files and local database configuration before replacing
the plugin files. Use the Cacti Plugin Management upgrade path and the
[version 4.2 documentation](https://github.com/Cacti/plugin_syslog/blob/v4.2/README.md#installation)
for the installed version; retain the local database configuration.

After upgrading, confirm that a known message from your normal forwarder reaches
the viewer, that the Device Filter lists the expected devices, and that existing
program-based alert/removal rules handle controlled test messages as intended.
Check the Cacti/PHP logs for SQL and runtime errors. If rollback is necessary,
restore matching plugin files and the database from the pre-upgrade backup.

## Contributors and full history

Thank you to the community members who developed, translated, tested and reviewed
this maintenance release. Contributors recorded between v4.1 and v4.2 include:

[@arno-st](https://github.com/arno-st), [@bmfmancini](https://github.com/bmfmancini), [@click0](https://github.com/click0), [@Coool](https://github.com/Coool), [@edewillians10](https://github.com/edewillians10), [@Harry-Junhua-Huang](https://github.com/Harry-Junhua-Huang), [@ka1lie](https://github.com/ka1lie), [@Linegod](https://github.com/Linegod), [@netniV](https://github.com/netniV), [@TheWitness](https://github.com/TheWitness), [@weblate](https://github.com/weblate), [@xmacan](https://github.com/xmacan), [@xubowin](https://github.com/xubowin), [@zersh01](https://github.com/zersh01).

The history also records contributions under Edwin Lankamp. Unlinked names are kept as recorded; no account is guessed.

[Full changelog](https://github.com/Cacti/plugin_syslog/blob/v4.2/CHANGELOG.md) ·
[All commits and original author links](https://github.com/Cacti/plugin_syslog/compare/v4.1...v4.2)

The separate 5.0 development notes describe an unreleased version; dashboards,
saved searches and other 5.0 features are not part of this 4.2 package.
