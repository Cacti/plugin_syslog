The compact workspace browser test renders the actual PHP filter markup with fixture data and uses Cacti's jQuery UI and classic theme. It intercepts all fixture requests and does not connect to a database or live Cacti server.

Run with Node 20+, PHP, Playwright, and Chromium available:

```sh
CACTI_ROOT=/path/to/cacti node tests/browser/compact_workspace.cjs
```

Optional environment variables: `PLAYWRIGHT_MODULE` (installed module path), `CHROMIUM_PATH` (browser executable), and `TEST_ARTIFACT_DIR` (existing screenshot directory).

Covers builder date and negation submission, severity color preservation, opening the message details from any cell of a row, selected-message rule links and unavailable actions, escaped raw messages, inspector keyboard close, filter collapse, the results limit beside the search buttons, view options, refresh without detaching a saved search, and narrow-screen overflow.

The threshold message fixture renders the actual message row markup, including
Yes/No flags, grouped summaries, and individual detail rows:

```sh
node tests/browser/threshold_messages.cjs
```

It uses the same `PLAYWRIGHT_MODULE` and `CHROMIUM_PATH` overrides and does not
connect to a live Cacti server or database.

Threshold recovery state and its expiry are tested against MariaDB separately:
`SYSLOG_TEST_MYSQL_SOCKET=/path/to/test.sock php tests/regression/threshold_recovery_database_test.php`.
The disposable database check also runs in CI against the MariaDB service.

The Dashboard card fixture checks shared Status styling in light/dark themes,
existing tools, drag/keyboard ordering requests, and narrow-screen panel sizing:

```sh
node tests/browser/dashboard_cards.cjs
```

It uses the actual Dashboard renderer and styles with fixture panels and records
ordering requests; it does not verify a live database or chart data.
