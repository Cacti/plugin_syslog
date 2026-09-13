const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const theme = process.env.CACTI_THEME || 'classic';
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');
const fixture = execFileSync('php', [path.join(root, 'tests/fixtures/compact_workspace.php')], {encoding: 'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1600,height:950}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const routes = {
  '/main.css': path.join(cacti, 'include/themes/' + theme + '/main.css'),
  '/jquery-ui.css': path.join(cacti, 'include/themes/' + theme + '/jquery-ui.css'),
  '/jquery.js': path.join(cacti, 'include/js/jquery.js'),
  '/jquery-ui.js': path.join(cacti, 'include/js/jquery-ui.js'),
  '/jquery.timepicker.js': path.join(cacti, 'include/js/jquery.timepicker.js'),
  '/search.css': path.join(root, 'css/search.css'),
  '/functions.js': path.join(root, 'js/functions.js')
 };
 await page.route('http://fixture/**', r => {
  const url = new URL(r.request().url());
  if (url.pathname === '/') return r.fulfill({body: fixture, contentType: 'text/html; charset=utf-8'});
  return routes[url.pathname] ? r.fulfill({path: routes[url.pathname], contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'}) : r.fulfill({status: 404, body: ''});
 });
 await page.goto('http://fixture/'); await page.waitForTimeout(250);
 assert.deepEqual(errors,[]);
 const themeColors = await page.evaluate(() => {
  const probe = document.createElement('div'); probe.className = 'ui-widget-content'; document.body.append(probe);
  const color = getComputedStyle(probe).backgroundColor;
  const panel = getComputedStyle(document.querySelector('.syslogSearchPanel')).backgroundColor;
  probe.remove(); return {color, panel};
 });
 assert.equal(themeColors.panel, themeColors.color, 'Search panel inherits the active Cacti theme');
 assert.equal(await page.locator('#saved_saveas').isVisible(), false);
 await page.locator('.syslogSearchSavedBar summary').click();
 assert.equal(await page.locator('#saved_saveas').isVisible(), true);
 await page.locator('#saved_saveas').click();
 assert.equal(await page.locator('#syslog_saved_prompt').isVisible(), true);
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('#saved_saveas').isVisible(), false);
 assert.ok(await page.locator('.syslogSearchAdd').first().evaluate(el => $(el).button('instance')));

 assert.equal(await page.locator('#syslog_search_builder .syslogSearchRow').count(),2);
 assert.equal(await page.locator('#syslog_time_range').count(),0);
 assert.equal(await page.locator('.syslogSearchNegate').count(),0);
 await page.locator('#go').click();
 assert.match(await page.evaluate(()=>posts.at(-1).rfilter),/host = "10.0.0.5".*logtime last "86400"/);
 const originalColor = await page.locator('.syslogRow').first().evaluate(row=>getComputedStyle(row).backgroundColor);
 assert.notEqual(originalColor,'rgb(255, 255, 255)');
 await page.locator('.syslogMessageOpen').first().click();
 assert.equal(await page.locator('#syslog_details_raw').textContent(),'nf_conntrack: table full, dropping packet <script>alert("unsafe")</script>');
 assert.equal(await page.locator('#syslog_message_details script').count(),0);
 assert.equal(await page.locator('.syslogRow').first().evaluate(row=>getComputedStyle(row).backgroundColor),originalColor,'Selection retains severity color');
 const alarm = page.locator('[data-rule="alarm"]');
 const removal = page.locator('[data-rule="removal"]');
 assert.equal(await alarm.textContent(),'Create Alarm Rule');
 assert.equal(await removal.textContent(),'Create Removal Rule');
 assert.match(await alarm.getAttribute('href'), /^syslog_alerts.php\?id=101&action=newedit&type=0&date=/);
 assert.match(await removal.getAttribute('href'), /^syslog_removal.php\?id=101&action=newedit&type=0&date=/);
 await page.locator('.syslogMessageOpen').nth(1).click();
 assert.match(await alarm.getAttribute('href'), /id=102&/);
 await page.locator('.syslogMessageOpen').last().click();
 assert.equal(await alarm.isVisible(),false);
 assert.equal(await removal.isVisible(),false);
 assert.equal(await alarm.getAttribute('href'),null,'Unavailable rows clear stale links');
 await page.locator('.syslogMessageOpen').first().click();
 // Clicking anywhere on the row, not only the message, opens the details pane.
 await page.locator('.syslogRow').first().locator('td').nth(1).click();
 assert.equal(await page.locator('#syslog_message_details').isVisible(),true,'Row cells other than the message open the pane');
 await page.locator('.syslogRow').nth(1).locator('td').first().click();
 assert.match(await page.locator('[data-rule="alarm"]').getAttribute('href'), /id=102&/);

 if (process.env.TEST_ARTIFACT_DIR) await page.screenshot({path:path.join(process.env.TEST_ARTIFACT_DIR, 'syslog-workspace-desktop.png'),fullPage:true});
 await page.keyboard.press('Escape');assert.equal(await page.locator('#syslog_message_details').isVisible(),false);
 await page.locator('#syslog_search_toggle').click();assert.equal(await page.locator('#syslog_search_content').isVisible(),false);
 await page.locator('#syslog_search_toggle').click();
 const timeRow = page.locator('#syslog_search_builder .syslogSearchRow').nth(1);
 await timeRow.locator('select[aria-label="Operator"] + .ui-selectmenu-button').click();
 await page.getByRole('option', {name: 'NOT In the last', exact: true}).click();
 await page.locator('#go').click();
 assert.match(await page.evaluate(()=>posts.at(-1).rfilter), /NOT logtime last "86400"/);
 await timeRow.locator('select[aria-label="Operator"] + .ui-selectmenu-button').click();
 await page.getByRole('option', {name: 'In the last', exact: true}).click();
 await page.locator('#syslog_view_options summary').click();assert.equal(await page.locator('#save').isVisible(),true);
 assert.equal(await page.locator('.syslogQueryActions .syslogResultsLimit').count(),1,'Results limit sits beside the search buttons');
 assert.equal(await page.locator('#syslog_view_options #rows').count(),0,'Results limit no longer hides in view options');
 await page.keyboard.press('Escape');assert.equal(await page.locator('#save').isVisible(),false);
 await page.setViewportSize({width:390,height:844});if (process.env.TEST_ARTIFACT_DIR) await page.screenshot({path:path.join(process.env.TEST_ARTIFACT_DIR, 'syslog-workspace-mobile.png'),fullPage:true});
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);assert.equal(overflow,false,'Page must not overflow horizontally');
 await page.locator('#refresh_results').click();assert.equal(await page.evaluate(()=>Object.hasOwn(posts.at(-1),'rfilter')),false);
 assert.deepEqual(errors,[]);console.log('Workspace browser checks passed');await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
