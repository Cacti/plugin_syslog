// Selects one row of a rule list, runs a bulk action on it and confirms the action.
const { withSession, fail } = require('./session');

withSession(async (page) => {
  await page.goto(`http://cacti_web/cacti/${process.env.PAGE_PATH}`, { waitUntil: 'domcontentloaded' });

  const row = page.locator('tr', { hasText: process.env.ROW_TEXT }).first();

  if ((await row.count()) === 0) {
    fail(`No row contains ${process.env.ROW_TEXT}`);
  }

  await row.locator('input[type="checkbox"]').first().check({ force: true });
  await page.locator('#drp_action').selectOption({ label: process.env.ACTION_LABEL });
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#submit').click()
  ]);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('input[type="submit"][value="Continue"]').first().click()
  ]);
  await page.waitForLoadState('networkidle');

  console.log(`ok ${process.env.ACTION_LABEL}`);
}).catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
