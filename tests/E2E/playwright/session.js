const { chromium } = require('playwright');

async function login(page) {
  await page.goto('http://cacti_web/cacti/', { waitUntil: 'domcontentloaded' });
  await page.locator('#login_username').fill('admin');
  await page.locator('#login_password').fill('Admin123!');
  await Promise.all([
    page.waitForURL(/\/cacti\/index\.php/, { timeout: 30000 }),
    page.locator('form#auth').evaluate((form) => form.submit())
  ]);
}

async function withSession(run) {
  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage']
  });

  try {
    const page = await browser.newPage();
    await login(page);
    await run(page);
  } finally {
    await browser.close();
  }
}

function fail(message) {
  throw new Error(message);
}

module.exports = { withSession, fail };
