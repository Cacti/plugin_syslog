const { chromium } = require('playwright');

function expectContains(haystack, needle, label) {
  if (!haystack.includes(needle)) {
    throw new Error(`Expected ${label} to contain: ${needle}`);
  }
}

async function login(page) {
  await page.goto('http://cacti_web/cacti/', { waitUntil: 'domcontentloaded' });
  await page.locator('#login_username').fill('admin');
  await page.locator('#login_password').fill('Admin123!');
  await Promise.all([
    page.waitForURL(/\/cacti\/index\.php/, { timeout: 30000 }),
    page.locator('form#auth').evaluate((form) => form.submit())
  ]);
}

async function main() {
  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage']
  });
  const page = await browser.newPage();

  await login(page);

  const pagePath = process.env.PAGE_PATH || 'index.php';
  await page.goto(`http://cacti_web/cacti/${pagePath}`, { waitUntil: 'domcontentloaded' });

  const title = await page.title();
  const body = await page.locator('body').innerText();

  if (process.env.EXPECT_TITLE) {
    expectContains(title, process.env.EXPECT_TITLE, 'title');
  }

  for (const key of ['EXPECT_TEXT', 'EXPECT_TEXT_2', 'EXPECT_TEXT_3']) {
    if (process.env[key]) {
      expectContains(body, process.env[key], 'body');
    }
  }

  console.log(`ok ${pagePath}`);
  await browser.close();
}

main().catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
