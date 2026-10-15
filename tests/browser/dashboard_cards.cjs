const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');
(async () => {
 const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
 try {
  const page = await browser.newPage({viewport: {width: 1200, height: 800}});
  await page.route('http://fixture/webfonts/**', route => route.fulfill({path: path.join(cacti, 'include/fa/webfonts', path.basename(new URL(route.request().url()).pathname))}));
  await page.setContent('<base href="http://fixture/"><style>body{margin:8px;font:13px Arial}</style><div id="status"><article class="syslogStatusCard"><header class="syslogStatusCardHeader syslogStatusCardHeader--none"><button class="syslogStatusCardDrag">☰</button><h3 class="syslogStatusCardTitle">Status reference</h3><div class="syslogStatusCardTools"><button class="syslogStatusCardTool">↻</button></div></header><div class="syslogStatusCardBody">Status</div></article></div><div id="syslog_dashboard_grid" class="syslogDashboardGrid"></div>');
  await page.addStyleTag({path: path.join(cacti, 'include/fa/css/all.min.css')});
  await page.addStyleTag({path: path.join(root, 'css/search.css')});
  await page.addStyleTag({path: path.join(root, 'css/dashboard.css')});
  await page.addScriptTag({path: path.join(root, 'js/dashboard.js')});
  await page.evaluate(() => {
   window.posts = [];
   window.syslogDashboard = {canManage: true, dashboardId: 5, text: {dragPanel: 'Drag to reorder card', refreshPanel: 'Refresh Panel'}, panels: [1,2,3].map(id => ({id, title: 'Panel ' + id, width: 1, height: 0}))};
   syslogDashboardPost = data => posts.push(data);
   syslogDashboard.panels.forEach(panel => {
    const card = syslogDashboardPanelCard(panel);
    document.getElementById('syslog_dashboard_grid').append(card);
    syslogDashboardInitDrag(card, panel);
   });
  });
  const compare = () => page.evaluate(() => {
   const pairs = [['.syslogStatusCard', '.syslogDashboardCard', ['backgroundColor','border','borderRadius','boxShadow']], ['.syslogStatusCardHeader', '.syslogDashboardCardHeader', ['padding','backgroundColor','backgroundImage','color','height']], ['.syslogStatusCardTitle', '.syslogDashboardCardTitle', ['fontSize','fontWeight','lineHeight']], ['.syslogStatusCardTool', '.syslogDashboardCardButton', ['padding','backgroundColor','color','border']]];
   return pairs.map(([a,b,keys]) => keys.map(key => [key, getComputedStyle(document.querySelector('#status ' + a))[key], getComputedStyle(document.querySelector('#syslog_dashboard_grid ' + b))[key]]));
  });
  for (const mode of ['light', 'dark']) {
   await page.evaluate(mode => {
    for (const scope of document.querySelectorAll('#status, #syslog_dashboard_grid')) {
     const tokens = mode === 'dark' ? {text:'#eee',card:'#222',border:'#555','header-bg':'#333','header-fg':'#eee'} : {text:'#23362f',card:'#fff',border:'#cbd8d1','header-bg':'#e5ece8','header-fg':'#23362f'};
     Object.entries(tokens).forEach(([key,value]) => scope.style.setProperty('--search-' + key, value));
    }
   }, mode);
   for (const pair of await compare()) for (const [key,status,dashboard] of pair) assert.equal(dashboard,status, mode + ' ' + key);
  }
  assert.equal(await page.locator('#syslog_panel_1 [data-action="refresh"]').count(), 1);
  assert.equal(await page.locator('#syslog_panel_1 [data-action="edit"]').count(), 1);
  assert.equal(await page.locator('#syslog_panel_1 [data-action="delete"]').count(), 1);
  // Keyboard reordering persists the absolute position through the existing endpoint.
  await page.locator('#syslog_panel_1 .syslogStatusCardDrag').focus();
  await page.keyboard.press('ArrowRight');
  assert.deepEqual(await page.evaluate(() => syslogDashboard.panels.map(p => p.id)), [2,1,3]);
  assert.equal(await page.evaluate(() => posts.at(-1).position), 2);
  // Pointer drag handles work across columns and clear both placeholder states.
  const target = await page.locator('#syslog_panel_3').boundingBox();
  const handle = await page.locator('#syslog_panel_2 .syslogStatusCardDrag').boundingBox();
  await page.mouse.move(handle.x + handle.width/2, handle.y + handle.height/2);
  await page.mouse.down();
  await page.mouse.move(handle.x + handle.width/2 + 12, handle.y + handle.height/2, {steps: 3});
  await page.mouse.move(target.x + target.width*0.75, target.y + 45, {steps: 10});
  await page.mouse.up();
  assert.deepEqual(await page.evaluate(() => syslogDashboard.panels.map(p => p.id)), [1,3,2]);
  assert.equal(await page.evaluate(() => posts.at(-1).position), 3);
  assert.equal(await page.locator('.syslogDashboardCardDropBefore, .syslogDashboardCardDropAfter').count(), 0);
  if (process.env.TEST_ARTIFACT_DIR) await page.screenshot({path:path.join(process.env.TEST_ARTIFACT_DIR,'dashboard-status-style-desktop.png'),fullPage:true});
  await page.evaluate(() => syslogDashboardApplySize({width:3,height:300}, document.getElementById('syslog_panel_1')));
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
  await page.evaluate(() => {syslogDashboard.canManage = false; const card = syslogDashboardPanelCard({id:4,title:'Shared',width:1,height:0}); document.getElementById('syslog_dashboard_grid').append(card);syslogDashboardInitDrag(card,{id:4});});
  assert.equal(await page.locator('#syslog_panel_4 .syslogStatusCardDrag').count(), 0);
  assert.equal(await page.locator('#syslog_panel_4 [data-action="refresh"]').count(), 1);
  if (process.env.TEST_ARTIFACT_DIR) await page.screenshot({path:path.join(process.env.TEST_ARTIFACT_DIR,'dashboard-status-style.png'),fullPage:true});
  console.log('Dashboard card styles and drag ordering passed (browser fixture)');
 } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
