const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');

// Status toolbar and cards as syslog_status() renders them. Cacti develop wraps
// every plain select in select2, which reports changes through jQuery only.
const card = (key, title) => `<section class="ccCard" data-card="${key}" data-expanded="0"><header class="ccCardHeader"><h2 class="ccTitle">${title}</h2>`
	+ `<span class="ccTools"><button type="button" class="ccTool" data-tool="refresh">r</button><button type="button" class="ccTool" data-tool="remove">x</button></span></header>`
	+ `<div class="ccCardBody">${title} body</div></section>`;
const page_html = `<!doctype html><html><head><link rel="stylesheet" href="/select2.css"></head><body>
<div id="syslog_status_panel"><div class="ccToolbar">
<select id="syslog_status_add" class="ccAdd"><option value="">Add a status card…</option><option value="storage">Storage</option></select>
<div class="ccRefresh"><select id="syslog_status_refresh" class="ccRefreshInterval"><option value="0">Never</option><option value="1">1 Second</option><option value="300" selected>5 Minutes</option></select>
<button type="button" id="syslog_status_refresh_now"><i class="fa fa-sync-alt"></i></button></div></div>
<div id="syslog_status_grid" class="ccGrid">${card('latest_run', 'Latest run')}${card('workers', 'Workers')}</div>
<div id="syslog_status_dialog" style="display:none"></div></div>
<script src="/jquery.js"></script><script src="/select2.js"></script><script src="/cards.js"></script><script src="/syslog_dashboard.js"></script>
<script>var csrfMagicToken = 'fixture'; var syslogStatusCatalog = {latest_run: 'Latest run', workers: 'Workers', storage: 'Storage'};
initSyslogStatus();
$(function() { $('select').each(function() { $(this).select2({width: 'auto', minimumResultsForSearch: Infinity}); }); });</script>
</body></html>`;

(async () => {
	const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
	const page = await browser.newPage();
	const errors = [];
	const posts = [];
	let navigations = 0;
	let rendered = 0;

	page.on('pageerror', error => errors.push(error.message));
	page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations++; });

	const routes = {
		'/jquery.js': path.join(cacti, 'include/js/jquery.js'),
		'/select2.js': path.join(cacti, 'include/js/select2.js'),
		'/select2.css': path.join(cacti, 'include/themes/cacti/select2.css'),
		'/cards.js': path.join(root, 'js/cards.js'),
		'/syslog_dashboard.js': path.join(root, 'js/syslog_dashboard.js')
	};

	await page.route('http://fixture/**', route => {
		const url = new URL(route.request().url());

		if (url.pathname === '/') {
			return route.fulfill({body: page_html, contentType: 'text/html; charset=utf-8'});
		}

		if (url.pathname === '/syslog.php' && route.request().method() === 'POST') {
			const data = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
			assert.equal(data.__csrf_magic, 'fixture', 'Status callbacks carry the CSRF token');
			posts.push(data);

			if (data.action === 'status_card') {
				rendered++;

				return route.fulfill({contentType: 'application/json', body: JSON.stringify({card: data.card, html: card(data.card, 'Rendered ' + data.card + ' ' + rendered)})});
			}

			return route.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true})});
		}

		return routes[url.pathname] ? route.fulfill({path: routes[url.pathname], contentType: 'text/javascript'}) : route.fulfill({status: 404, body: ''});
	});

	await page.goto('http://fixture/');
	await page.waitForTimeout(200);
	assert.deepEqual(errors, []);
	assert.equal(await page.locator('#syslog_status_add').evaluate(select => select.classList.contains('select2-hidden-accessible')), true, 'The fixture uses select2 like Cacti');
	const loaded = navigations;

	// Adding a card through the select2 widget appends it and saves the layout.
	await page.locator('#syslog_status_add + .select2 .select2-selection').click();
	await page.locator('.select2-results__option', {hasText: 'Storage'}).click();
	await page.waitForFunction(() => document.querySelectorAll('#syslog_status_grid .ccCard').length === 3);
	assert.deepEqual(await page.locator('#syslog_status_grid .ccCard').evaluateAll(cards => cards.map(item => item.dataset.card)), ['latest_run', 'workers', 'storage'], 'The added card goes to the end');
	await page.waitForTimeout(100);
	const layout = posts.filter(post => post.action === 'status_layout').pop();
	assert.ok(layout, 'Adding a card saves the layout');
	assert.deepEqual(JSON.parse(layout.layout).order, ['latest_run', 'workers', 'storage']);
	assert.equal(await page.locator('#syslog_status_add').inputValue(), '', 'The add control resets');
	assert.equal(await page.locator('#syslog_status_add option[value="storage"]').count(), 0, 'An added card leaves the catalogue');

	// Changing the interval saves it through a callback and refreshes cards in place.
	posts.length = 0;
	await page.locator('#syslog_status_refresh + .select2 .select2-selection').click();
	await page.locator('.select2-results__option', {hasText: '1 Second'}).click();
	await page.waitForFunction(() => document.querySelectorAll('#syslog_status_grid .ccCard .ccCardBody').length === 3
		&& [...document.querySelectorAll('#syslog_status_grid .ccCardBody')].every(body => body.textContent.startsWith('Rendered')), null, {timeout: 5000});
	assert.deepEqual(posts.find(post => post.action === 'status_refresh'), {action: 'status_refresh', tab: 'status', refresh: '1', __csrf_magic: 'fixture'});
	assert.deepEqual(posts.filter(post => post.action === 'status_card').map(post => post.card).sort(), ['latest_run', 'storage', 'workers']);

	// Never stops the timer; the manual refresh still uses callbacks.
	await page.locator('#syslog_status_refresh + .select2 .select2-selection').click();
	await page.locator('.select2-results__option', {hasText: 'Never'}).click();
	await page.waitForTimeout(1500);
	posts.length = 0;
	await page.waitForTimeout(1500);
	assert.equal(posts.filter(post => post.action === 'status_card').length, 0, 'Never stops automatic refreshes');
	await page.locator('#syslog_status_refresh_now').click();
	await page.waitForFunction(() => document.querySelectorAll('#syslog_status_refresh_now .fa-spin').length === 0);
	await page.waitForTimeout(100);
	assert.equal(posts.filter(post => post.action === 'status_card').length, 3, 'Refresh now re-renders every card');

	assert.equal(navigations, loaded, 'No status interaction reloads the page');
	assert.deepEqual(errors, []);
	await browser.close();
	console.log('Status card add and callback refresh passed (browser fixture)');
})().catch(error => {
	console.error(error);
	process.exit(1);
});
