const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const html = execFileSync('php', [path.join(__dirname, '../Fixtures/breached_thresholds.php')], {encoding: 'utf8'});
(async () => {
 const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
 try {
  const page = await browser.newPage({viewport: {width: 1440, height: 900}});
  await page.setContent(html);
  assert.equal(await page.locator('#disabled a[href*="tab=breached"]').count(), 0);
  assert.equal(await page.locator('#healthy a.selected').textContent(), 'Breached Thresholds (0)');
  assert.match(await page.locator('#healthy table').textContent(), /No thresholds are currently breached/);
  assert.equal(await page.locator('#active a.selected').textContent(), 'Breached Thresholds (2)');
  assert.equal(await page.locator('#active th').count(), 6);
  assert.equal(await page.locator('#active table tr').count(), 3);
  assert.equal(await page.locator('#active a[href*="action=edit"]').first().textContent(), 'Authentication failures');
  assert.match(await page.locator('#active a').filter({hasText: 'server01'}).getAttribute('href'), /reset=1.*rfilter=host/);
  assert.match(await page.locator('#active table').textContent(), /All matching devices/);
  assert.equal(await page.locator('#active button, #active input').count(), 0);
  console.log('Breached thresholds rendered checks passed: conditional tab, count, healthy state, device/rule links and shared scope');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
