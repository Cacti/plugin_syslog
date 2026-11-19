const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');
const longMessage = 'nf_conntrack: table full, dropping packet <script>alert("unsafe")</script> ' + 'width preview '.repeat(4);
const shortMessage = 'nf_conntrack: table full, dropping packet <script>alert("unsafe")</script>';
const dispatched = [];
const heldFilters = [];
let holdFilters = false;
let filterReceived;
const fixture = execFileSync('php', [path.join(root, 'tests/Fixtures/compact_workspace.php')], {encoding: 'utf8'});
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
  if (url.pathname === '/') return r.fulfill({body: url.searchParams.has('error') ? fixture.replace("id='logical_search_error' role='alert'>", "id='logical_search_error' role='alert'>Invalid search") : fixture, contentType: 'text/html; charset=utf-8'});
  if (url.pathname === '/syslog.php') {
   if (r.request().method() === 'POST') assert.equal(new URLSearchParams(r.request().postData()).get('__csrf_magic'), 'fixture', 'Fallback requests carry the CSRF token without relying on the global XHR shim');
   if (holdFilters && url.searchParams.get('header') === 'false') {
    heldFilters.push(r);
    if (filterReceived) { filterReceived(); filterReceived = null; }
    return;
   }
   dispatched.push(r.request().method() + ' ' + (url.searchParams.get('action') || new URLSearchParams(r.request().postData() || '').get('action')) + ' ' + (url.searchParams.get('source') || '') + ':' + (url.searchParams.get('id') || ''));
   if (url.searchParams.get('action') !== 'message_details') return r.fulfill({body: JSON.stringify({found: false, recommendations: []}), contentType: 'application/json'});
   const id = url.searchParams.get('id');
   const query = 'id=' + id + '&action=newedit&type=0&date=2026-09-13+00%3A12%3A42';
   return r.fulfill({contentType: 'application/json', body: JSON.stringify({found: true,
    record: {id, source: 'main', received: '2026-09-13 00:12:42', device: 'fixture-router', program: 'kernel', facility: 'kern', severity: 'warning', message: id === '101' ? longMessage : shortMessage, alert: ''},
    rules: {alarm: 'syslog_alerts.php?' + query, removal: 'syslog_removal.php?' + query},
    recommendations: [{identifier: '%NF-4-TABLE_FULL', vendor: 'Acme', severity: 4, severity_name: 'Warning', explanation: '<img src=x onerror=alert(1)> explained', recommended_action: 'Raise the table size', reference: ''}],
    labels: {explanation: 'Explanation', action: 'Recommended Action', reference: 'Reference'}})});
  }
  return routes[url.pathname] ? r.fulfill({path: routes[url.pathname], contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'}) : r.fulfill({status: 404, body: ''});
 });
 await page.goto('http://fixture/'); await page.waitForTimeout(250);
 assert.deepEqual(errors,[]);
 assert.equal(await page.locator('#refresh_results').getAttribute('aria-label'), 'Refresh Results');
 assert.equal(await page.locator('#refresh_results .fa.fa-sync-alt').count(), 1, 'Refresh uses the Font Awesome glyph');
 assert.equal(await page.locator('#refresh_results .fa-spin').count(), 0, 'Refresh is not spinning while idle');
 const messageButton = page.locator('.syslogMessageOpen').first();
 const indicatorColumns = page.locator('th:has(.syslogRecommendationHeading), td.syslogRecommendation');
 async function checkIndicatorWidths() {
  const widths = await indicatorColumns.evaluateAll(cells => cells.map(cell => cell.getBoundingClientRect().width));
  assert(widths.length > 1 && widths.every(width => width === 20), 'The lightbulb header and cells stay exactly 20px wide');
  assert.equal(await page.locator('th:has(.syslogRecommendationHeading)').evaluate(cell => getComputedStyle(cell).textAlign), 'center', 'The lightbulb header is centered');
 }
 await checkIndicatorWidths();
 assert.equal(await messageButton.textContent(), longMessage, 'Preview retains text beyond 100 characters');
 const widePreview = await messageButton.evaluate(button => {
  const range = document.createRange();
  range.setStart(button.firstChild, 110);
  range.setEnd(button.firstChild, 111);
  return { characterRight: range.getBoundingClientRect().right, buttonRight: button.getBoundingClientRect().right };
 });
 assert(widePreview.characterRight <= widePreview.buttonRight, 'Characters beyond 100 are visible when the column has room');
 // The filter panel is collapsed by default; open it before exercising its controls.
 assert.equal(await page.locator('#syslog_search_content').isVisible(), false, 'Filter panel starts collapsed');
 assert.equal(await page.locator('#syslog_search_summary').isVisible(), true, 'Collapsed panel shows the query summary');
 await page.locator('#syslog_search_toggle').click();
 assert.equal(await page.locator('#syslog_search_content').isVisible(), true, 'Toggle opens the filter panel');
 assert.equal(await page.locator('#saved_saveas').isVisible(), false);
 await page.locator('.syslogSearchSavedBar summary').click();
 assert.equal(await page.locator('#saved_saveas').isVisible(), true);
 await page.locator('#saved_saveas').click();
 assert.equal(await page.locator('#syslog_saved_prompt').isVisible(), true);
 const nameLabel = await page.locator('label[for="syslog_saved_prompt_name"]').boundingBox();
 const nameInput = await page.locator('#syslog_saved_prompt_name').boundingBox();
 const defaultLabel = await page.locator('label[for="syslog_saved_prompt_default"]').boundingBox();
 const defaultSwitch = await page.locator('#syslog_saved_prompt_default').boundingBox();
 assert.equal(defaultLabel.x, nameLabel.x, 'Default aligns with Name in the label column');
 assert.equal(defaultSwitch.x, nameInput.x, 'Default switch aligns with the Name textbox');
 assert(defaultLabel.y > nameLabel.y && defaultSwitch.y > nameInput.y, 'Default occupies the next row');
 assert.equal(await page.locator('#syslog_saved_prompt_default').evaluate(input => getComputedStyle(input).appearance), 'none', 'Default uses the native Cacti toggle, not a raw checkbox');
 assert(defaultSwitch.width > 30 && defaultSwitch.height > 15, 'Default toggle has a visible switch track');
 await page.locator('label[for="syslog_saved_prompt_default"]').click();
 assert.equal(await page.locator('#syslog_saved_prompt_default').isChecked(), true, 'Clicking Default toggles the switch');
 await page.evaluate(() => savedSearchPrompt('New search', (name, isDefault) => { window.savedPromptResult = {name, isDefault}; }));
 assert.equal(await page.locator('#syslog_saved_prompt_default').isChecked(), false, 'A new search is not default unless selected');
 await page.locator('label[for="syslog_saved_prompt_default"]').click();
 const saveSearchButtons = page.locator('.ui-dialog:has(#syslog_saved_prompt) .ui-dialog-buttonpane button');
 assert.deepEqual(await saveSearchButtons.allTextContents(), ['Cancel', 'Save'], 'Save Search As places Cancel before Save');
 assert.equal(await saveSearchButtons.filter({hasText: /^Cancel$/}).getAttribute('type'), 'button', 'Cancel remains a non-submit button');
 assert.equal(await saveSearchButtons.filter({hasText: /^Save$/}).getAttribute('type'), 'submit', 'Save is a submit button');
 await saveSearchButtons.filter({hasText: /^Save$/}).click();
 assert.deepEqual(await page.evaluate(() => savedPromptResult), {name: 'New search', isDefault: true}, 'Saving preserves the toggle value');
 await page.evaluate(() => { document.querySelector('#saved_search option:checked').dataset.default = '1'; savedSearchPrompt('Existing default', () => {}); });
 assert.equal(await page.locator('#syslog_saved_prompt_default').isChecked(), true, 'Editing a default keeps the toggle selected');
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('#saved_saveas').isVisible(), false);
 assert.equal(await page.locator('#syslog_search_builder .syslogSearchRow').count(),2);
 assert.equal(await page.locator('#syslog_time_range').count(),0);
 assert.equal(await page.locator('.syslogSearchNegate').count(),0);
 await page.locator('#go').click();
 assert.match(await page.evaluate(()=>posts.at(-1).rfilter),/host = "10.0.0.5".*logtime last "86400"/);
 // A value the search is already limited to is not added a second time; a new
 // value, or one under an OR, still narrows the search.
 assert.equal(await page.evaluate(()=>syslogFilterInScope([{join:'AND',field:'host',operator:'=',value:'Router1'}],'host','router1')),true,'Hostnames compare without case');
 assert.equal(await page.evaluate(()=>syslogFilterInScope([{join:'AND',field:'host',operator:'=',value:'a'},{join:'OR',field:'host',operator:'=',value:'b'}],'host','a')),false,'A condition under OR does not limit the search');
 assert.equal(await page.evaluate(()=>syslogFilterInScope([{join:'AND',negative:true,field:'host',operator:'=',value:'a'}],'host','a')),false,'A negated condition does not count');
 const postCount = await page.evaluate(()=>posts.length);
 await page.evaluate(()=>syslogAddFilterCondition('host',document.getElementById('syslog_search_builder').searchRows.find(row=>row.field==='host').value));
 assert.equal(await page.evaluate(()=>posts.length),postCount,'Clicking a host already in the search adds nothing');
 await page.evaluate(()=>syslogAddFilterCondition('host','edge-router'));
 assert.match(await page.evaluate(()=>posts.at(-1).rfilter),/AND host = "edge-router"$/);
 const originalColor = await page.locator('.syslogRow').first().evaluate(row=>getComputedStyle(row).backgroundColor);
 assert.notEqual(originalColor,'rgb(255, 255, 255)');
 assert.equal(await page.locator('.syslogRow').first().locator('.syslogRecommendationIndicator').count(), 1, 'Matching messages advertise recommendations before a click');
 assert.equal(await page.locator('.syslogRow').nth(1).locator('.syslogRecommendation').textContent(), '', 'Unmatched messages leave the indicator column blank');
 assert.equal(await page.locator('.syslogRecommendationIndicator').first().evaluate(icon => icon.parentElement.nextElementSibling.classList.contains('syslogMessage')), true, 'The indicator immediately precedes the message');
 assert.equal(await page.locator('.syslogRow[title], .syslogRow [title]').count(), 0, 'Message rows and their contents have no native hover titles');
 const beforeHover = dispatched.length;
 for (const target of [messageButton, ...await page.locator('.syslogRow').first().locator('td').all(), page.locator('.syslogRecommendationIndicator').first(), messageButton]) {
  await target.hover();
  await page.waitForTimeout(400);
  assert.equal(await page.locator('.ui-tooltip:visible').count(), 0, 'Hovering messages, metadata, or the lightbulb never opens a tooltip');
 }
 assert.equal(dispatched.length, beforeHover, 'Message-row and lightbulb hovers issue no requests');
 await page.mouse.move(0, 0);
 await page.waitForFunction(() => $('.ui-tooltip:visible').length === 0);
 await page.locator('.syslogMessageOpen').first().click();
 assert.equal(await page.locator('#syslog_details_raw').textContent(),longMessage);
 assert.equal(await page.locator('#syslog_message_details script').count(),0);
 // The flyout dispatches the record by table and id, then lists the matching
 // recommendations as text, from a <body>-level panel no theme layer can cover.
 await page.locator('#syslog_details_rec_body .syslogRecCard').waitFor();
 assert.equal(dispatched.at(-1), 'GET message_details main:101');
 assert.equal(await page.evaluate(() => document.getElementById('syslog_message_details').parentElement.tagName), 'BODY');
 assert.equal(await page.locator('#syslog_details_rec_body .syslogRecId').textContent(), '%NF-4-TABLE_FULL');
 assert.equal(await page.locator('#syslog_details_rec_body img').count(), 0, 'Recommendation text is never parsed as HTML');
 assert.equal(await page.locator('#syslog_details_link').isVisible(), true, 'Dispatchable records can be shared by link');
 assert.equal(await page.locator('[data-detail="id"]').textContent(), '101');
 assert.equal(await page.locator('[data-detail="alert"]').isVisible(), false, 'Rows without an alert rule hide that field');
 const flyout = await page.locator('#syslog_message_details').boundingBox();
 assert(flyout.x + flyout.width <= 1600 && flyout.x > 1000, 'The flyout docks to the right edge');
 await page.mouse.move(0, 0);
 await page.waitForFunction(color => getComputedStyle(document.querySelector('.syslogRow')).backgroundColor === color, originalColor);
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
 // Rows that do not name a dispatchable record fall back to the message lookup.
 await page.waitForFunction(() => /No recommendation|could not/.test(document.getElementById('syslog_details_rec_body').textContent));
 assert.equal(dispatched.at(-1), 'POST recommendation :');
 assert.equal(await page.locator('#syslog_details_link').isVisible(), false);
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
 await timeRow.locator('select[aria-label="Operator"]').selectOption('NOT last');
 await page.locator('#go').click();
 assert.match(await page.evaluate(()=>posts.at(-1).rfilter), /NOT logtime last "86400"/);
 await timeRow.locator('select[aria-label="Operator"]').selectOption('last');
 await page.locator('#syslog_view_options summary').click();assert.equal(await page.locator('#save').isVisible(),true);
 assert.equal(await page.locator('.syslogQueryActions .syslogResultsLimit').count(),1,'Results limit sits beside the search buttons');
 assert.equal(await page.locator('#syslog_view_options #rows').count(),0,'Results limit no longer hides in view options');
 await page.keyboard.press('Escape');assert.equal(await page.locator('#save').isVisible(),false);
 await page.mouse.move(0, 0);
 await page.waitForFunction(() => $('.ui-tooltip:visible').length === 0);
 await page.setViewportSize({width:390,height:844});if (process.env.TEST_ARTIFACT_DIR) await page.screenshot({path:path.join(process.env.TEST_ARTIFACT_DIR, 'syslog-workspace-mobile.png'),fullPage:true});
 await checkIndicatorWidths();
 assert.equal(await messageButton.textContent(), longMessage, 'Narrow layouts retain the complete preview text');
 const narrowPreview = await messageButton.evaluate(button => ({width: button.clientWidth, content: button.scrollWidth, ellipsis: getComputedStyle(button).textOverflow}));
 assert(narrowPreview.content > narrowPreview.width, 'Long previews overflow their own button on narrow screens');
 assert.equal(narrowPreview.ellipsis, 'ellipsis', 'The browser controls visual truncation');
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);assert.equal(overflow,false,'Page must not overflow horizontally');
 await page.locator('#refresh_results').click();assert.equal(await page.evaluate(()=>Object.hasOwn(posts.at(-1),'rfilter')),false);
 holdFilters = true;
 await page.evaluate(() => { window.mockPostSyslog = postSyslog; postSyslog = realPostSyslog; });
 const refreshReceived = new Promise(resolve => { filterReceived = resolve; });
 await page.locator('#refresh_results').click();
 await refreshReceived;
 assert.equal(await page.locator('#refresh_results .fa-spin').count(), 1, 'Clicking Refresh spins its glyph while the callback is pending');
 const searchReceived = new Promise(resolve => { filterReceived = resolve; });
 await page.evaluate(() => applyFilter());
 await searchReceived;
 await heldFilters[0].fulfill({body: '', contentType: 'text/html'});
 await page.waitForFunction(() => $.active === 1);
 assert.equal(await page.locator('#refresh_results .fa-spin').count(), 1, 'The glyph keeps spinning while another filter callback remains pending');
 await heldFilters[1].fulfill({status: 500, body: 'Fixture filter failure'});
 await page.waitForFunction(() => $.active === 0);
 assert.equal(await page.locator('#refresh_results .fa-spin').count(), 0, 'The glyph stops after the final callback, including failures');
 await page.evaluate(() => { postSyslog = mockPostSyslog; });
 holdFilters = false;
 // A saved expanded preference reopens the panel on reload; an active search error forces it open even when collapse is stored.
 await page.setViewportSize({width:1600,height:950});
 await page.evaluate(()=>localStorage.setItem('syslog.search.collapsed','false'));
 await page.goto('http://fixture/'); await page.waitForTimeout(250);
 assert.equal(await page.locator('#syslog_search_content').isVisible(), true, 'Saved expanded preference reopens the panel');
 await page.evaluate(()=>localStorage.setItem('syslog.search.collapsed','true'));
 await page.goto('http://fixture/?error=1'); await page.waitForTimeout(250);
 assert.equal(await page.locator('#syslog_search_content').isVisible(), true, 'Active search error expands the collapsed panel');
 assert.deepEqual(errors,[]);console.log('Workspace browser checks passed');await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
