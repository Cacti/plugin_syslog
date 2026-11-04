/* Copyright (C) 2004-2026 The Cacti Group. GPL-2.0-or-later. */
const assert = require('node:assert/strict');
const { readFileSync, mkdirSync, writeFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const root = resolve(__dirname, '../../..');
  const output = resolve(process.env.THEME_TEST_OUTPUT || resolve(__dirname, 'artifacts/theme-contrast'));
  mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_EXECUTABLE || undefined });
  const page = await browser.newPage();
  await page.route('http://syslog-fixture.test/css/search.css', route => route.fulfill({ contentType: 'text/css', body: readFileSync(resolve(root, 'css/search.css'), 'utf8') }));
  await page.coverage.startCSSCoverage({ resetOnNavigation: false });
  try {
    for (const [mode, foreground, surface] of [['light', 'rgb(35, 54, 47)', 'rgb(255, 255, 255)'], ['dark', 'rgb(238, 238, 238)', 'rgb(32, 32, 32)'], ['muted-dark', 'rgb(150, 155, 163)', 'rgb(0, 0, 0)']]) {
      await page.setContent(`<!DOCTYPE html><style>
        * { transition: none !important; }
        body { background: ${surface}; }
        tr.selected { color: white !important; background: blue !important; }
        tr.selected a, tr.selected a:hover { color: white !important; }
        button:hover { color: red; background: yellow; }
      </style><div id="syslog_workspace" style="--search-text:${foreground};--search-card:${surface}">
        <table><tbody>${[1, 2].map(id => `<tr class="syslogRow logWarning" id="row${id}"><td><a href="#device">edge-rtr-0${id}</a></td><td><button class="syslogSeverity syslogSeverity-warning">Warning</button></td><td>ifDown Gi1/0/24</td></tr>`).join('')}</tbody></table></div>`);
      await page.addStyleTag({ url: 'http://syslog-fixture.test/css/search.css' });
      for (const state of ['rest', 'hover', 'selected', 'selected-hover']) {
        await page.mouse.move(0, 0);
        if (state === 'selected') await page.locator('tr').evaluateAll(rows => rows.forEach(row => row.classList.add('selected')));
        if (state.includes('hover')) await page.locator('#row1 button').hover();
        for (const selector of ['#row1 a', '#row2 a', '#row1 button', '#row2 button']) {
          assert.equal(await page.locator(selector).evaluate(element => getComputedStyle(element).color), foreground, `${mode} ${state} ${selector}`);
        }
        assert.equal(await page.locator('#row1 button').evaluate(element => getComputedStyle(element).backgroundColor), surface);
      }
      await page.screenshot({ path: resolve(output, `syslog-${mode}.png`), fullPage: true });
    }
    writeFileSync(resolve(output, 'changed-coverage.json'), JSON.stringify({ css: await page.coverage.stopCSSCoverage(), js: [] }));
    console.log('PASS: three palettes of result links and severity buttons in four row states');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
