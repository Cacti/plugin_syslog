# `setup.php` module ownership

`setup.php` is the Syslog plugin's compatibility entry point. Page and worker
entry points may continue to include it directly; lifecycle code loads only
the module it needs, while Cacti loads hook callback modules by their registered
path.

This is a relocation refactor.  A module move must not rename a function,
change SQL, or change hook ownership in the same commit.

## Stable facade

`setup.php` owns the plugin lifecycle and upgrade orchestration:

- `plugin_syslog_install()` and `plugin_syslog_uninstall()`
- `plugin_syslog_check_config()` and `plugin_syslog_upgrade()`
- `plugin_syslog_version()` and `syslog_check_upgrade()`
- `syslog_connect()` as the compatibility facade for direct `setup.php`
  consumers
- `syslog_determine_config()` and `syslog_config_safe()`
- realm/permission upgrade helpers called by `syslog_check_upgrade()`

## Module map

| Module | Owns |
| --- | --- |
| `includes/schema.php` | Schema creation, migration helpers, capacity checks, and table setup |
| `includes/processing.php` | Poller and replication callbacks |
| `includes/settings.php` | Settings definitions, permission configuration, and Settings-page assets |
| `includes/navigation.php` | Tabs, navigation text, refresh handling, and graph buttons |
| `includes/installer.php` | Install/uninstall advisor and confirmation rendering |
| `includes/utilities.php` | Utilities menu/action callbacks and the purge dialog |

`includes/database.php` remains the dual-database `syslog_db_*` wrapper layer;
it is not replaced by `includes/schema.php`.

## Hook ownership

After extraction, registrations must reference the owning module:

| Hook callback group | Owning file |
| --- | --- |
| `syslog_config_settings`, `syslog_settings_bottom`, `syslog_config_arrays`, `syslog_config_insert` | `includes/settings.php` |
| `syslog_show_tab`, `syslog_draw_navigation_text`, `syslog_graph_buttons` | `includes/navigation.php` |
| `syslog_poller_bottom`, `syslog_replicate_out` | `includes/processing.php` |
| `syslog_utilities_list`, `syslog_utilities_action` | `includes/utilities.php` |

Each hook-path change follows the move in a separate commit.  The installer
entry points remain registered through `setup.php`.

## Test rule

Source-inspection tests must obtain a function's source through
`plugin_test_read_function_source()` instead of hard-coding `setup.php`.
Whenever ownership changes, update that central map and the test in the same
commit.

## Rule saves and collector replication

Rule saves write to Syslog's own tables. With remote rule synchronization enabled,
rule changes reach remote collectors during Cacti's next full replication cycle,
through the registered `syslog_replicate_out` hook. The save handler does not
query or change Cacti's collector tables, or discover and connect to collectors.
Synchronized remote collectors continue to reject local rule edits.

The rule snapshot helper uses the main Syslog connection for source schema and
Cacti's supplied remote connection for delivery. It builds each snapshot in a
plugin-owned staging table and publishes it with an atomic table rename, so an
empty source clears deleted rules and a failed copy preserves the live table.
Remote collectors copy Cacti's local rule snapshot into a dedicated Syslog
database only from the registered master process.
