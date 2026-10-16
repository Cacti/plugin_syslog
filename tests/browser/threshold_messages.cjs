const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const html = execFileSync('php', [path.join(__dirname, '../Fixtures/threshold_messages.php')], {encoding: 'utf8'});
(async () => {
 const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
 try {
  const page = await browser.newPage({viewport: {width: 1600, height: 900}});
  await page.setContent(html);
  assert.equal(await page.locator('#individual th').last().textContent(), 'Breach');
  assert.equal(await page.locator('#individual th').last().getAttribute('title'), 'Threshold breached');
  assert.ok((await page.locator('#individual th').last().boundingBox()).width < 90, 'Breach column stays compact');
  const rows = page.locator('#individual tbody tr');
  assert.equal(await rows.count(), 2);
  assert.equal(await rows.nth(0).locator('td').last().textContent(), 'Yes');
  assert.equal(await rows.nth(1).locator('td').last().textContent(), 'No');
  assert.equal(await rows.nth(0).locator('td').count(), 7);
  const grouped = page.locator('#grouped tr');
  assert.equal(await grouped.count(), 3);
  assert.equal(await grouped.nth(0).locator('td').nth(6).textContent(), 'Yes');
  assert.equal(await grouped.nth(1).locator('td').nth(6).textContent(), 'Yes');
  assert.equal(await grouped.nth(2).locator('td').nth(6).textContent(), 'No');
  assert.equal(await grouped.nth(0).locator('td').count(), 8);
  assert.equal(await grouped.nth(1).locator('td').count(), 8);
  console.log('Threshold message column passed: Yes/No, grouped summary, and individual details');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
