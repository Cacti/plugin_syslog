const { chromium } = require('playwright');

// The address is built from the environment set by run-e2e.sh.
function url(path = '') {
  const scheme = process.env.E2E_SCHEME;
  const host = process.env.E2E_HOST;

  if (!scheme || !host) {
    throw new Error('E2E_SCHEME and E2E_HOST must be set');
  }

  return `${scheme}://${host}/cacti/${path}`;
}

async function login(page) {
  await page.goto(url(), { waitUntil: 'domcontentloaded' });
  // The form limits the field to 25 characters; setting the value directly sends the full name.
  await page.locator('#login_username').evaluate((field, name) => { field.value = name; }, process.env.E2E_USER || 'admin');
  await page.locator('#login_password').fill(process.env.E2E_PASSWORD || 'Admin123!');
  await Promise.all([
    page.waitForURL(/\/cacti\/index\.php/, { timeout: 30000 }),
    page.locator('#login_password').evaluate((field) => HTMLFormElement.prototype.submit.call(field.form))
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

module.exports = { withSession, fail, url };
