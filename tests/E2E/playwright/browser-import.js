// Pastes an import document into a rule import form and checks the outcome message.
const { withSession, fail } = require('./session');

withSession(async (page) => {
  await page.goto(`http://cacti_web/cacti/${process.env.PAGE_PATH}`, { waitUntil: 'domcontentloaded' });
  await page.locator('#import_text').fill(process.env.IMPORT_TEXT || '');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('form:has(#import_text) input[type="submit"], form:has(#import_text) button[type="submit"]').first().click()
  ]);
  await page.waitForLoadState('networkidle');

  const body = await page.locator('body').innerText();

  if (!body.includes(process.env.EXPECT_TEXT || '')) {
    fail(`Expected the page to contain "${process.env.EXPECT_TEXT}", got: ${body.slice(0, 400)}`);
  }

  console.log(`ok ${process.env.PAGE_PATH}`);
}).catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
