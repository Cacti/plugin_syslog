const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');
const layout = fs.readFileSync(path.join(cacti, 'include/layout.js'), 'utf8');
const sizing = layout.slice(layout.indexOf('function saveTableWidths('), layout.indexOf('function appendHeaderSuppression('));
assert(sizing.includes('function applyTableSizing()'), 'Exercise the actual Cacti column-resize callbacks');
const storage = `var Storages = {localStorage: {
 isSet: key => localStorage.getItem(key) !== null,
 get: key => JSON.parse(localStorage.getItem(key)),
 set: (key, value) => localStorage.setItem(key, JSON.stringify(value)),
 remove: key => localStorage.removeItem(key)
}};`;
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
   if (url.pathname === '/table-sizing.js') return route.fulfill({body: storage + sizing, contentType: 'text/javascript'});
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
  const checkFit = async id => {
   const sizes = await page.locator(id).evaluate(table => {
    const main = table.closest('.syslogResultsMain');
    const message = table.querySelector('.syslogMessageOpen');
    return {
     table: table.getBoundingClientRect().width, available: main.clientWidth,
     scroll: main.scrollWidth, message: message.clientWidth, text: message.scrollWidth,
     ellipsis: getComputedStyle(message).textOverflow,
     headers: [...table.querySelectorAll('th')].map(cell => cell.getBoundingClientRect().width)
    };
   });
   assert(sizes.table <= sizes.available + 1, `${id} must fit the results area: ${JSON.stringify(sizes)}`);
   assert(sizes.scroll <= sizes.available + 1, `${id} must not create a desktop horizontal scrollbar`);
   assert(sizes.message >= 50, `${id} keeps a usable message preview`);
   return sizes;
  };
  const drag = async (id, column, delta) => {
   const handle = page.locator(`${id} th`).nth(column).locator('.ui-resizable-e');
   await handle.scrollIntoViewIfNeeded();
   const box = await handle.boundingBox();
   await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
   await page.mouse.down();
   await page.mouse.move(box.x + box.width / 2 + delta, box.y + box.height / 2, {steps: 12});
   await checkFit(id);
   await page.mouse.up();
   await page.waitForTimeout(60);
  };
  for (const [id, messageIndex] of [['#individual', 4], ['#alerts', 5]]) {
   await checkFit(id);
   await drag(id, 0, -100);
   const before = await checkFit(id);
   await drag(id, 1, 50);
   const after = await checkFit(id);
   assert(after.headers[1] > before.headers[1] + 30, `${id} metadata columns really resize`);
   assert(after.headers[messageIndex] < before.headers[messageIndex] - 30, `${id} the message absorbs metadata resizing`);
   await drag(id, messageIndex + 1, 70);
   await checkFit(id);
   await drag(id, 0, 2500);
   await checkFit(id);
   await drag(id, 0, -2500);
   await checkFit(id);
   const beforeMessage = await checkFit(id);
   await drag(id, messageIndex, -50);
   const smallerMessage = await checkFit(id);
   assert(smallerMessage.headers[messageIndex] < beforeMessage.headers[messageIndex] - 30, `${id} the message itself can shrink`);
   await drag(id, messageIndex, 50);
   const largerMessage = await checkFit(id);
   assert(largerMessage.headers[messageIndex] > smallerMessage.headers[messageIndex] + 30, `${id} the message itself can expand`);
  }
  const preview = await checkFit('#alerts');
  assert(preview.text > preview.message, 'Alert Logs retains the full long preview');
  assert.equal(preview.ellipsis, 'ellipsis', 'Alert Logs visually truncates using ellipsis');
  const wrapped = await page.locator('#alerts .syslogMessageWrap').evaluate(button => ({
   width: button.clientWidth, scroll: button.scrollWidth, height: button.clientHeight,
   whitespace: getComputedStyle(button).whiteSpace, ellipsis: getComputedStyle(button).textOverflow
  }));
  assert.equal(wrapped.whitespace, 'pre-wrap', 'Alert Logs All Text continues to wrap');
  assert.equal(wrapped.ellipsis, 'clip');
  assert(wrapped.height > 30 && wrapped.scroll <= wrapped.width + 1, 'Wrapped alert messages stay inside their column');
  assert.notEqual(await page.evaluate(() => localStorage.getItem('alerts')), null, 'Column drags retain Cacti width persistence');
  await page.evaluate(() => localStorage.setItem('alerts', JSON.stringify([innerWidth, 1800, 1000, 1000, 1000, 20, 1800, 1000, 1000])));
  await page.reload();
  await page.waitForTimeout(100);
  await checkFit('#individual');
  await checkFit('#alerts');
  assert.equal(await page.locator('#alerts th:has(.syslogRecommendationHeading)').evaluate(cell => cell.getBoundingClientRect().width), 20, 'Saved oversized widths do not stretch the indicator');
  await page.setViewportSize({width: 1050, height: 900});
  await page.waitForTimeout(60);
  await checkFit('#individual');
  await checkFit('#alerts');
  await page.setViewportSize({width: 1600, height: 900});
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
  console.log('Log browser checks passed: bounded groups, constrained resizing, saved widths, alert ellipsis/wrapping, and flyout');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
