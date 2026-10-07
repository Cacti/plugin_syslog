# DataTables

Vendored DataTables core **3.1.3** from the [official release files](https://datatables.net/download/release). No extensions or second jQuery copy are included. `LICENSE` is the upstream MIT license (SpryMedia Ltd.).

Files:

- `dataTables.min.js` — `https://cdn.datatables.net/3.1.3/js/dataTables.min.js`
- `dataTables.dataTables.min.css` — `https://cdn.datatables.net/3.1.3/css/dataTables.dataTables.min.css`

These URLs are used only when updating vendored files. Runtime pages load local plugin paths. To upgrade, replace the pinned files and license with a single upstream release, update the version in `syslog_include_js()`, and rerun the log-viewer tests and browser checks in both Cacti themes.
