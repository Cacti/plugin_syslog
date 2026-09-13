The compact workspace browser test renders the actual PHP filter markup with fixture data and uses Cacti's jQuery UI and classic theme. It intercepts all fixture requests and does not connect to a database or live Cacti server.

Run with Node 20+, PHP, Playwright, and Chromium available:

```sh
CACTI_ROOT=/path/to/cacti node tests/browser/compact_workspace.cjs
```

Optional environment variables: `PLAYWRIGHT_MODULE` (installed module path), `CHROMIUM_PATH` (browser executable), and `TEST_ARTIFACT_DIR` (existing screenshot directory).

Covers builder date and negation submission, severity color preservation, selected-message rule links and unavailable actions, escaped raw messages, inspector keyboard close, filter collapse, view options, refresh without detaching a saved search, and narrow-screen overflow.
