const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');
const html = execFileSync('php', [path.join(__dirname, '../Fixtures/threshold_messages.php')], {encoding: 'utf8'});
(async () => {
 const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
 try {
  const page = await browser.newPage({viewport: {width: 1600, height: 900}});
  const errors = [];
  const requests = [];
  let pages;
  let failNext = false;
  page.on('pageerror', error => errors.push(error.message));
  const assets = {
   '/main.css': path.join(cacti, 'include/themes/classic/main.css'),
   '/jquery-ui.css': path.join(cacti, 'include/themes/classic/jquery-ui.css'),
   '/jquery.js': path.join(cacti, 'include/js/jquery.js'),
   '/jquery-ui.js': path.join(cacti, 'include/js/jquery-ui.js'),
   '/search.css': path.join(root, 'css/search.css'),
   '/functions.js': path.join(root, 'js/functions.js')
  };
  await page.route('http://fixture/**', async route => {
   const url = new URL(route.request().url());
   if (url.pathname === '/') return route.fulfill({body: html, contentType: 'text/html'});
   if (url.pathname === '/syslog.php') {
    requests.push(url);
    if (url.searchParams.get('action') === 'group_details') {
     if (failNext) { failNext = false; return route.fulfill({status: 500, body: 'Fixture query failure'}); }
     const offset = url.searchParams.get('offset');
     assert(['0', '50'].includes(offset), 'Only one bounded page is requested');
     assert.match(url.searchParams.get('context'), /^[a-f0-9]{32}$/);
     assert.equal(url.searchParams.get('group'), '0');
     return route.fulfill({contentType: 'application/json', body: JSON.stringify({html: pages[offset], more: offset === '0'})});
    }
    return route.fulfill({contentType: 'application/json', body: JSON.stringify({found: true, record: {id: '201', source: 'main', received: '2026-10-15 12:00:00', message: 'Example message'}, recommendations: []})});
   }
   return assets[url.pathname] ? route.fulfill({path: assets[url.pathname], contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'}) : route.fulfill({status: 404, body: ''});
  });
  await page.goto('http://fixture/');
  await page.waitForTimeout(100);
  pages = await page.locator('template').evaluateAll(templates => Object.fromEntries(templates.map(template => [template.id.slice('group-page-'.length), template.innerHTML])));
  assert.equal(await page.locator('#individual th').last().textContent(), 'Breach');
  assert.equal(await page.locator('#individual th').last().getAttribute('title'), 'Threshold breached');
  assert.ok((await page.locator('#individual th').last().boundingBox()).width < 90, 'Breach column stays compact');
  const rows = page.locator('#individual tbody tr');
  assert.equal(await rows.count(), 2);
  assert.equal(await rows.nth(0).locator('td').last().textContent(), 'Yes');
  assert.equal(await rows.nth(1).locator('td').last().textContent(), 'No');
  assert.equal(await rows.nth(0).locator('td').count(), 8);
  assert.equal(await rows.nth(0).locator('td').nth(3).locator('.syslogRecommendationIndicator').count(), 1);
  assert.equal(await rows.nth(1).locator('td').nth(3).textContent(), '');
  assert.equal(await rows.nth(0).locator('td').nth(3).evaluate(cell => cell.style.width), '20px', 'Individual recommendation cells declare their fixed width');
  const grouped = page.locator('#grouped tr');
  assert.equal(await grouped.count(), 1, 'Even a million-occurrence group initially renders only its summary');
  assert.equal(await grouped.nth(0).locator('td').nth(7).textContent(), 'Yes');
  assert.equal(await grouped.nth(0).locator('td').count(), 9);
  assert.equal(requests.length, 0, 'Rendering a collapsed group makes no detail requests');
  const toggle = page.locator('.syslog-group-toggle');
  await toggle.click();
  await page.waitForFunction(() => document.querySelectorAll('#grouped .syslogGroupMember').length === 50);
  assert.equal(requests.length, 1);
  assert.equal(await page.locator('#grouped .syslogGroupMember').first().locator('td').count(), 9);
  assert.equal(await page.locator('#grouped .syslogGroupMember').first().locator('td').nth(7).textContent(), 'Yes');
  assert.equal(await page.locator('#grouped .syslogGroupMember').nth(1).locator('td').nth(7).textContent(), 'No');
  await toggle.click();
  assert.equal(await page.locator('#grouped .syslogGroupMember:visible').count(), 0);
  await toggle.click();
  assert.equal(requests.length, 1, 'Reopening reuses the already-loaded bounded page');
  const next = page.locator('.syslogGroupPage button').filter({hasText: /^Next$/});
  failNext = true;
  await next.click();
  await page.waitForFunction(() => document.querySelector('.syslogGroupPage [role="status"]').textContent === 'Group messages could not be loaded.');
  assert.equal(await page.locator('#grouped .syslogGroupMember').count(), 50, 'A failed page request preserves the current members and reports an error');
  await next.click();
  await page.waitForFunction(() => document.querySelectorAll('#grouped .syslogGroupMember').length === 2);
  assert.equal(await next.isDisabled(), true);
  await page.locator('.syslogGroupPage button').filter({hasText: /^Previous$/}).click();
  await page.waitForFunction(() => document.querySelectorAll('#grouped .syslogGroupMember').length === 50);
  assert.equal(await page.locator('#grouped td.syslogRecommendation').evaluateAll(cells => cells.every(cell => cell.style.width === '20px')), true);
  await page.locator('#grouped .syslogGroupMember .syslogMessageOpen').first().click();
  await page.waitForFunction(() => !document.getElementById('syslog_message_details').hidden);
  assert.equal(await page.locator('#syslog_details_raw').textContent(), 'Example message', 'Dynamically loaded members still open the details flyout');
  assert.deepEqual(errors, []);
  console.log('Grouped message checks passed: bounded loading, paging, retry, alignment, and flyout');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
