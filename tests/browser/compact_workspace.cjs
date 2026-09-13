const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');
const fixture = execFileSync('php', [path.join(root, 'tests/fixtures/compact_workspace.php')], {encoding: 'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1600,height:950}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const routes = {
  '/main.css': path.join(cacti, 'include/themes/classic/main.css'),
  '/jquery-ui.css': path.join(cacti, 'include/themes/classic/jquery-ui.css'),
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
 assert.equal(await page.locator('#syslog_search_builder .syslogSearchRow').count(),1);
 assert.equal(await page.locator('#syslog_time_range').inputValue(),'86400');
 assert.match(await page.locator('#syslog_time_range-button').textContent(), /Last 24 hours/);
 await page.locator('#go').click();
 assert.match(await page.evaluate(()=>posts.at(-1).rfilter),/host = "10.0.0.5".*logtime last "86400"/);
 await page.locator('.syslogMessageOpen').first().click();
 assert.equal(await page.locator('#syslog_details_raw').textContent(),'nf_conntrack: table full, dropping packet <script>alert("unsafe")</script>');
 assert.equal(await page.locator('#syslog_message_details script').count(),0);
 if (process.env.TEST_ARTIFACT_DIR) await page.screenshot({path:path.join(process.env.TEST_ARTIFACT_DIR, 'syslog-workspace-desktop.png'),fullPage:true});
 await page.keyboard.press('Escape');assert.equal(await page.locator('#syslog_message_details').isVisible(),false);
 await page.locator('#syslog_search_toggle').click();assert.equal(await page.locator('#syslog_search_content').isVisible(),false);
 await page.locator('#syslog_search_toggle').click();
 await page.evaluate(()=>$('#syslog_time_range').val('custom').change());
 await page.locator('#syslog_time_from').fill('2026-09-12T00:00');await page.locator('#syslog_time_to').fill('2026-09-13T00:00');
 await page.locator('#go').click();assert.match(await page.evaluate(()=>posts.at(-1).rfilter),/logtime >= "2026-09-12 00:00:00" AND logtime <= "2026-09-13 00:00:00"/);
 await page.locator('#syslog_time_to').fill('2026-09-11T00:00');const n=await page.evaluate(()=>posts.length);await page.locator('#go').click();assert.equal(await page.evaluate(()=>posts.length),n);
 await page.evaluate(()=>$('#syslog_time_range').val('86400').change());
 await page.locator('#syslog_view_options summary').click();assert.equal(await page.locator('#save').isVisible(),true);
 await page.keyboard.press('Escape');assert.equal(await page.locator('#save').isVisible(),false);
 await page.setViewportSize({width:390,height:844});if (process.env.TEST_ARTIFACT_DIR) await page.screenshot({path:path.join(process.env.TEST_ARTIFACT_DIR, 'syslog-workspace-mobile.png'),fullPage:true});
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);assert.equal(overflow,false,'Page must not overflow horizontally');
 await page.locator('#refresh_results').click();assert.equal(await page.evaluate(()=>Object.hasOwn(posts.at(-1),'rfilter')),false);
 assert.deepEqual(errors,[]);console.log('Workspace browser checks passed');await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
