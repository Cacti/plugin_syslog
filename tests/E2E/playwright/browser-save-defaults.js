// Exercise the authenticated save action that used to disable the plugin.
const { withSession, fail, url } = require('./session');

withSession(async (page) => {
  const target = url('plugins/syslog/syslog.php');
  await page.goto(`${target}?tab=syslog&removal=-1&rows=30&refresh=60`, { waitUntil: 'networkidle' });
  await page.locator('#syslog_view_options summary').click();

  const saved = page.waitForResponse((response) => response.url() === target && response.request().method() === 'POST');
  await page.locator('#save').click();
  const response = await saved;

  if (response.status() !== 200 || (await response.text()).trim() !== '') {
    fail(`Saving view defaults failed: ${response.status()} ${await response.text()}`);
  }

  // Old clients can omit the tab; the save must use the active session tab.
  await page.goto(`${target}?tab=alerts`, { waitUntil: 'networkidle' });
  const fallback = await page.evaluate(async (target) => {
    const response = await fetch(target, {
      method: 'POST',
      body: new URLSearchParams({ action: 'save', rows: '30', refresh: '60', removal: '-1', __csrf_magic: csrfMagicToken })
    });
    return { status: response.status, body: await response.text() };
  }, target);

  if (fallback.status !== 200 || fallback.body.trim() !== '') {
    fail(`Saving without a tab failed: ${JSON.stringify(fallback)}`);
  }

  await page.goto(`${target}?tab=syslog`, { waitUntil: 'networkidle' });
  if (await page.locator('#save').count() !== 1) {
    fail('Syslog is unavailable after saving view defaults');
  }

  console.log('Save view defaults and session-tab fallback passed');
}).catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
