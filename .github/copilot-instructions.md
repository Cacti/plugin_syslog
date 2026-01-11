# Cacti Syslog Plugin - AI Coding Instructions

## Project Context
This is the **Syslog Plugin** for Cacti, a PHP-based network monitoring and graphing tool. It collects, stores, and analyzes syslog messages from network devices.
- **Language:** PHP (compatible with Cacti's supported versions).
- **Database:** MySQL/MariaDB.
- **Framework:** Cacti Plugin Architecture.

## Architecture & Data Flow
- **Dual Database Support:** The plugin can store data in the main Cacti database OR a dedicated syslog database.
  - **Critical:** ALWAYS use the `syslog_db_*` wrapper functions (defined in `database.php`) for all database operations. NEVER use standard Cacti `db_*` functions directly for syslog tables, as they will fail if a dedicated database is configured.
- **Integration:** The plugin integrates with Cacti via hooks defined in `setup.php`.
- **Poller Integration:** Background processes (`syslog_process.php`, `syslog_removal.php`) are triggered by Cacti's poller or run independently.
- **Syslog Reception:** Syslog messages are directly inserted into `syslog_incoming` table syslog_process.php then processes them.

## Critical Developer Workflows

### Database Interactions
- **Read:** `syslog_db_fetch_assoc($sql)`, `syslog_db_fetch_cell($sql)`
- **Write:** `syslog_db_execute($sql)`, `syslog_db_execute_prepared($sql, $params)`
- **Connection:** Managed via `$syslog_cnn` global.
- **Schema:** Tables are defined/updated in `setup.php` (`syslog_setup_table_new`).

### Cacti Integration Patterns
- **Hooks:** Register hooks in `plugin_syslog_install()` in `setup.php`.
  - Example: `api_plugin_register_hook('syslog', 'top_header_tabs', 'syslog_show_tab', 'setup.php');`
- **Permissions:** Register realms in `setup.php`.
  - Example: `api_plugin_register_realm('syslog', 'syslog.php', 'Syslog User', 1);`
- **UI:** Follow Cacti's UI patterns (top tabs, breadcrumbs, filter bars).

### Configuration
- **Config File:** `config.php` (derived from `config.php.dist`).
- **Globals:** The plugin relies heavily on global variables:
  - `$config`: Cacti configuration.
  - `$syslogdb_default`: Name of the syslog database.
  - `$syslog_cnn`: Database connection resource.

## Coding Conventions
- **Localization:** Wrap all user-facing strings in `__('string', 'syslog')`. The second argument `'syslog'` is the text domain.
- **Error Handling:** Use `raise_message($id)` or `raise_message('id', 'message', MESSAGE_LEVEL_*)` for UI feedback.
- **Remote Pollers:** Logic for syncing rules to remote pollers is handled in `functions.php` (e.g., `syslog_sync_save`). Check `read_config_option('syslog_remote_enabled')`.

## Clean as You Code
- **Refactoring:** When touching legacy code, modernize it where safe (e.g., replace `array()` with `[]`, improve variable naming).
- **Type Safety:** Add type hints to function arguments and return types where possible, ensuring backward compatibility with supported PHP versions.
- **Cleanup:** Remove unused variables and commented-out code blocks found in the modified sections.

## DBA & Query Optimization
- **Query Analysis:** Always review SQL queries for performance. Suggest indexes if filtering by non-indexed columns.
- **Prepared Statements:** Prefer `syslog_db_execute_prepared` over string concatenation for security and performance.
- **Optimization:** Identify and suggest improvements for N+1 query problems or inefficient joins, especially in poller-related scripts (`syslog_process.php`).

## Key Files
- `setup.php`: Plugin installation, hook registration, and schema updates.
- `database.php`: Database abstraction layer wrappers (`syslog_db_*`).
- `config.php.dist`: Template for database configuration.
- `functions.php`: Core logic and utility functions.
- `syslog.php`: Main UI entry point.


**Documentation & Resources**
- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- [cacti documentation](https://www.github.com/Cacti/documentation)
