const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../..');

async function tablePage(browser, tab, columns) {
	const page = await browser.newPage();
	const requests = [];
	const unexpected = [];
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.route('**/*', route => {
		const url = new URL(route.request().url());
		if (url.pathname === '/view') {
			return route.fulfill({contentType: 'text/html', body: `
				<select id="rows" data-default-rows="2"><option value="-1">Default</option><option value="2" selected>2</option><option value="750">750</option></select>
				<select id="refresh"><option value="0" selected>Off</option></select>
				<div id="syslog_workspace"><div class="syslogResultsMain"><table>
				<tr class="tableHeader">${Array.from({length: columns}, (_, i) => `<th>Column ${i}</th>`).join('')}</tr>
				<tr><td colspan="${columns}">Initial row</td></tr>
				</table></div></div>`});
		}
		if (url.pathname === '/syslog.php') {
			const data = new URLSearchParams(route.request().postData());
			requests.push(data);
			const start = Number(data.get('start'));
			const length = Number(data.get('length'));
			return route.fulfill({contentType: 'application/json', body: JSON.stringify({
				draw: Number(data.get('draw')),
				recordsTotal: 4,
				recordsFiltered: 4,
				data: Array.from({length: Math.min(length, 4 - start)}, (_, i) => ({
					cells: Array.from({length: columns}, (_, j) => `${tab}-${start + i}-${j}`),
					DT_RowClass: 'syslogRow'
				}))
			})});
		}
		unexpected.push(route.request().url());
		return route.abort();
	});
	await page.goto('http://syslog.test/view');
	await page.addScriptTag({path: path.join(root, '../cacti/include/js/jquery.js')});
	await page.addScriptTag({path: path.join(root, 'vendor/datatables/dataTables.min.js')});
	await page.addStyleTag({path: path.join(root, 'vendor/datatables/dataTables.dataTables.min.css')});
	await page.addScriptTag({path: path.join(root, 'js/functions.js')});
	await page.evaluate(tabName => {
		window.pageTab = tabName;
		window.csrfMagicToken = 'fixture';
		window.initSyslogMessagesDisplay = () => {};
		window.initSyslogValueFilters = () => {};
		window.initSyslogWorkspace = () => {};
		window.initSyslogDataTable();
	}, tab);
	await page.waitForFunction(() => document.querySelectorAll('#syslog_workspace tbody tr').length === 2);
	return {page, requests, unexpected, errors};
}

async function main() {
	const browser = await chromium.launch({headless: true,
		...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE} : {}),
		args: ['--no-sandbox']});
	try {
		const system = await tablePage(browser, 'syslog', 6);
		const alerts = await tablePage(browser, 'alerts', 8);
		assert.equal(system.requests[0].get('tab'), 'syslog');
		assert.equal(alerts.requests[0].get('tab'), 'alerts');
		assert.equal(system.requests[0].get('action'), 'datatable');
		assert.equal(alerts.requests[0].get('__csrf_magic'), 'fixture');
		assert.equal(system.requests[0].get('length'), '2');
		assert.equal(alerts.requests[0].get('length'), '2');
		assert.equal(await system.page.locator('.dt-length').count(), 0, 'Cacti Results Limit is the only page-size control');
		await system.page.selectOption('#rows', '750');
		await system.page.waitForFunction(() => window.syslogDataTable.page.len() === 750);
		assert.equal(system.requests.at(-1).get('length'), '750');
		await system.page.selectOption('#rows', '2');
		await system.page.waitForFunction(() => window.syslogDataTable.page.len() === 2);
		await system.page.evaluate(() => window.syslogDataTable.page('next').draw('page'));
		await system.page.waitForFunction(() => document.querySelector('#syslog_workspace tbody').textContent.includes('syslog-2-0'));
		assert.equal(system.requests.at(-1).get('start'), '2');
		await system.page.evaluate(() => refreshResults());
		await system.page.waitForFunction(() => window.syslogDataTable.ajax.json().draw >= 3);
		assert.equal(system.requests.at(-1).get('start'), '2', 'Refresh keeps the current page');
		assert.equal(alerts.requests.length, 1, 'Refreshing System Logs does not redraw Alert Logs');
		assert.deepEqual(system.unexpected.concat(alerts.unexpected), [], 'No external asset requests');
		assert.deepEqual(system.errors.concat(alerts.errors), [], 'No JavaScript errors');
		console.log('DataTables offline browser check passed');
	} finally {
		await browser.close();
	}
}

main().catch(error => { console.error(error); process.exit(1); });
