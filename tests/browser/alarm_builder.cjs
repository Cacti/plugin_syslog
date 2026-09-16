const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');

(async () => {
	const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
	try {
		const page = await browser.newPage();
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.setContent('<form id="syslog_edit"><select id="type"><option value="filter">Filter Builder</option><option value="messagec">Contains</option></select><textarea id="message"></textarea></form>');
		for (const file of ['jquery.js', 'jquery-ui.js', 'jquery.timepicker.js']) {
			await page.addScriptTag({path: path.join(cacti, 'include/js', file)});
		}
		// Cacti registers its AJAX serializer before editor initialization.
		// The builder must populate message before that earlier bubble handler runs.
		await page.evaluate(() => {
			document.getElementById('syslog_edit').addEventListener('submit', event => {
				event.preventDefault();
				window.serializedMessage = document.getElementById('message').value;
			});
		});
		// Reproduce loading a list page and then navigating to its editor.
		for (let visit = 0; visit < 2; visit++) {
			await page.addScriptTag({path: path.join(root, 'js/filter-builder.js')});
			await page.addScriptTag({path: path.join(root, 'js/functions.js')});
		}
		let source = fs.readFileSync(path.join(root, 'syslog_alerts.php'), 'utf8');
		source = source.slice(source.indexOf('var allowEdits='));
		source = source.slice(0, source.indexOf('</script>'));
		// PHP supplies translated labels and DB choices; execute the actual
		// editor initialization with deterministic fixture values in their place.
		source = source.replace(/<\?php[\s\S]*?\?>/g, 'true');
		source = source.replace(/var alertFilterConfig = [\s\S]*?\n\t};/, `var alertFilterConfig = {fields: {message: 'Message', host: 'Host', priority_id: 'Priority'}, operators: {message: ['contains', '='], host: ['=', 'contains'], priority_id: ['=', '!=']}, conditions: [], choices: {priority_id: [['3', 'Error']]}};`);
		await page.addScriptTag({content: source});
		const builder = page.locator('#syslog_alert_filter_builder');
		await builder.waitFor({state: 'visible'});
		assert.equal(await builder.locator('.syslogSearchRow').count(), 1);
		assert.equal(await builder.locator('.ui-selectmenu-button').count(), 2);
		await builder.locator('.syslogSearchText').fill('interface');
		await builder.locator('.syslogSearchAdd').filter({hasText: /^AND$/}).click();
		assert.equal(await builder.locator('.syslogSearchRow').count(), 2);
		await builder.locator('.syslogSearchText').nth(1).fill('router');
		await page.selectOption('#type', 'messagec');
		assert.equal(await builder.isVisible(), false);
		await page.selectOption('#type', 'filter');
		assert.equal(await builder.isVisible(), true);
		const document = await page.evaluate(() => {
			document.getElementById('syslog_edit').dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
			return JSON.parse(window.serializedMessage);
		});
		assert.equal(document.conditions.length, 2);
		assert.equal(document.conditions[0].field, 'message');
		assert.equal(document.conditions[0].operator, 'contains');
		assert.equal(document.conditions[0].value, 'interface');
		assert.equal(document.conditions[1].field, 'message');
		assert.equal(document.conditions[1].operator, 'contains');
		assert.deepEqual(errors, []);
		console.log('alarm_builder browser test passed: repeated script loading, visible dropdowns, conditions, type switching, serialization');
	} finally {
		await browser.close();
	}
})().catch(error => { console.error(error); process.exitCode = 1; });
