# Cacti Syslog Plugin - AI Coding Instructions

## Priority Guidelines
When generating code for this repository:
1. **Version Compatibility**: This is a Cacti plugin (`syslog`, version 4.3) targeting Cacti 1.2.x (compat `1.2.23`); CI validates PHP 8.2-8.4.
2. **Context Files**: Prioritize patterns and standards defined in this file (`.github/copilot-instructions.md`).
3. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase for established patterns.
4. **Architectural Consistency**: Maintain plugin-based architecture extending Cacti core.
5. **Code Quality**: Prioritize security, maintainability, and compatibility in all generated code.

## Technology Stack

### Core Technologies
- **PHP**: 8.1+ (CI matrix runs 8.2, 8.3, 8.4).
- **Platform**: Cacti Plugin Architecture (Cacti 1.2.x).
- **Database**: MySQL/MariaDB, InnoDB engine, optionally partitioned (`syslog`, `syslog_removed`).

### Key Dependencies
- Cacti core framework (`api_plugin_*`, `db_*`, `read_config_option()`, `get_filter_request_var()`, etc.).
- This plugin's own `syslog_db_*` wrapper layer (`database.php`) instead of core `db_*` for syslog tables.
- Optional: `gettext` for internationalization.

## Project Context
This is the **Syslog Plugin** for Cacti, a PHP-based network monitoring and graphing tool. It collects, stores, and analyzes syslog messages from network devices.
- **Language:** PHP (compatible with Cacti's supported versions).
- **Database:** MySQL/MariaDB.
- **Framework:** Cacti Plugin Architecture.

## Project Structure
```
plugin_syslog/
├── setup.php                  # Install/uninstall/upgrade hooks, schema, realms, menu entries
├── database.php                # syslog_db_* wrapper layer (dual-database support)
├── functions.php                # Core logic: search DSL, CSV export, partitions, alerts
├── syslog.php                   # Main UI entry point (System Logs / Alert Logs tabs)
├── syslog_alerts.php            # Alert rule administration
├── syslog_removal.php           # Removal rule administration
├── syslog_reports.php           # Report rule administration
├── syslog_saved_searches.php    # Shared/admin saved-search templates
├── syslog_process.php           # CLI poller: ingest syslog_incoming -> syslog
├── syslog_removal.php / syslog_counter.php / syslog_batch_transfer.php  # CLI maintenance scripts
├── config.php.dist              # Template for dedicated syslog database config
├── js/functions.js               # Client-side search/UI logic
├── css/search.css                # Search UI styling
├── locales/                      # gettext .po/.mo translation catalogs
├── template/                     # Cacti graph template XML
└── tests/                        # Pest test suite (tests/Security, tests/Unit) run against Cacti's vendor tree
```

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

## Naming Conventions

### Function Names
- **Plugin lifecycle/hook-registration functions** MUST be prefixed `plugin_syslog_`: `plugin_syslog_install()`, `plugin_syslog_uninstall()`, `plugin_syslog_upgrade()`, `plugin_syslog_version()`.
- **All other functions** MUST be prefixed `syslog_`: `syslog_connect()`, `syslog_db_execute_prepared()`, `syslog_search_fields()`.
- Match the existing prefix used by the function you are editing; do not introduce a third naming scheme.

### Database Tables
Tables use a bare `syslog`/`syslog_removed` name or a `syslog_` prefix (NOT `plugin_syslog_`):
```
syslog, syslog_removed, syslog_incoming, syslog_hosts, syslog_programs,
syslog_facilities, syslog_priorities, syslog_alert, syslog_remove,
syslog_reports, syslog_saved_searches, syslog_host_facilities, syslog_logs
```

### Variables and Constants
- Use snake_case for variables: `$syslogdb_default`, `$syslog_cnn`, `$current_tab`.
- Constants are SCREAMING_SNAKE_CASE: `SYSLOG_IMPORT_MAX_BYTES`.

## Code Style

### Indentation and Formatting
- **Tabs**: Use tabs (not spaces) for indentation throughout all PHP files.
- **Braces**: Opening brace on the same line for functions and control structures.
- **Spacing**: Space after control structure keywords (`if`, `foreach`, `while`).

### File Headers
ALL PHP files MUST include the standard GPL v2 license header used throughout this repository:
```php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/
```

## Security Standards

### SQL Query Security
**ALWAYS use `syslog_db_*_prepared()` or `db_qstr()`** for values headed into SQL - never concatenate raw request input:
```php
// CORRECT
syslog_db_fetch_row_prepared('SELECT * FROM `syslog_hosts` WHERE host_id = ?', [$host_id]);
$sql .= ' AND host = ' . db_qstr($host);

// WRONG - never do this
syslog_db_fetch_row("SELECT * FROM syslog_hosts WHERE host_id = $host_id");
```
Table/column names can't be bound as parameters; when they must be interpolated (DDL, dynamic identifiers), validate them against a fixed allowlist or a strict regex first (see `syslog_partition_table_allowed()` in `functions.php`).

### Input Validation
- Use Cacti's request-var helpers with explicit filters: `get_filter_request_var('id', FILTER_VALIDATE_INT)`, `get_nfilter_request_var()` for values that must reach the DB layer unfiltered but quoted.
- Check realm permissions before privileged actions: `api_plugin_user_realm_auth('syslog_alerts.php')`.
- Require `POST` + `csrf_check()` for any state-changing action (see `syslog_utilities_action('purge_syslog_hosts')` in `setup.php`).

### Output Escaping
- Escape HTML with `html_escape()`; escape values embedded in `<script>` blocks with `syslog_json_safe()` (adds `JSON_HEX_TAG|AMP|APOS|QUOT`), never plain `json_encode()`.
- Defuse CSV formula injection on every exported cell with `syslog_csv_safe()` before `fputcsv()`.

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

## Common Pitfalls to Avoid

### ❌ NEVER Do This
```php
// Don't concatenate SQL queries
$sql = "SELECT * FROM syslog_hosts WHERE host_id = $id";  // WRONG

// Don't use core db_* functions on syslog tables
db_fetch_assoc("SELECT * FROM syslog");  // WRONG - breaks with a dedicated syslog DB

// Don't use hardcoded strings for UI
print 'Permission issue';  // WRONG

// Don't use spaces for indentation
    if ($condition) {  // WRONG (spaces used)

// Don't wire up state-changing actions to plain GET links
// <a href='utilities.php?action=purge_syslog_hosts'>  WRONG - CSRF-able
```

### ✅ ALWAYS Do This
```php
// Use prepared statements via the syslog_db_* wrapper
syslog_db_fetch_row_prepared('SELECT * FROM `syslog_hosts` WHERE host_id = ?', [$id]);  // CORRECT

// Translate all user-facing strings
print __('Permission issue', 'syslog');  // CORRECT

// Use tabs for indentation
	if ($condition) {  // CORRECT (tabs used)

// Gate state-changing actions behind POST + csrf_check()
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check(false)) { /* ... */ }  // CORRECT
```

## Version Control

### Changelog Maintenance
Document all changes in `CHANGELOG.md` under `--- develop ---`, prefixed by type:
```markdown
--- develop ---

* feature: Add saved searches: store named filter definitions...
* security: Require POST and CSRF validation for unused-host purge operations
* issue#262: Harden CSV exports and XML import payload handling
```

### Commit Messages
- Use descriptive commit messages; reference issue/PR numbers when applicable (`fix: ... (#339)`).
- Group related changes logically; prefer one concern per commit.
- Run `php -l` on changed files, and add/update the relevant Pest test under `tests/Security` or `tests/Unit` before committing.

## Key Files
- `setup.php`: Plugin installation, hook registration, and schema updates.
- `database.php`: Database abstraction layer wrappers (`syslog_db_*`).
- `config.php.dist`: Template for database configuration.
- `functions.php`: Core logic and utility functions.
- `syslog.php`: Main UI entry point.

## References
- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- [Cacti documentation](https://www.github.com/Cacti/documentation)
- `README.md` for feature descriptions
- `CHANGELOG.md` for version history

