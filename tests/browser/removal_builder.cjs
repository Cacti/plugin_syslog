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
		await page.setContent('<form id="syslog_edit"><select id="type"><option value="filter">Filter Builder</option><option value="messagec">Contains</option><option value="sql">SQL Expression</option></select><textarea id="message"></textarea></form>');
		for (const file of ['jquery.js', 'jquery-ui.js', 'jquery.timepicker.js']) {
			await page.addScriptTag({path: path.join(cacti, 'include/js', file)});
		}
		for (let visit = 0; visit < 2; visit++) {
			await page.addScriptTag({path: path.join(root, 'js/filter-builder.js')});
			await page.addScriptTag({path: path.join(root, 'js/functions.js')});
		}
		let source = fs.readFileSync(path.join(root, 'syslog_removal.php'), 'utf8');
		source = source.slice(source.indexOf('var allowEdits='));
		source = source.slice(0, source.indexOf('</script>'));
		// PHP supplies translated labels and DB choices; execute the actual
		// editor initialization with deterministic fixture values in their place.
		source = source.replace(/<\?php[\s\S]*?\?>/g, 'true');
		source = source.replace(/var removalFilterConfig = [\s\S]*?\n\t\};/, `var removalFilterConfig = {fields: {message: 'Message', host: 'Host', priority_id: 'Priority'}, operators: {message: ['contains', '='], host: ['=', 'contains'], priority_id: ['=', '!=']}, conditions: [], choices: {priority_id: [['3', 'Error']]}, labels: {message: 'Value', placeholder: 'Enter a value', match: 'Match', exclude: 'Exclude', remove: 'Remove condition', integer: 'Enter a nonnegative integer'}};`);
		await page.addScriptTag({content: source});
		const builder = page.locator('#syslog_removal_filter_builder');
		await builder.waitFor({state: 'visible'});
		assert.equal(await builder.locator('.syslogSearchRow').count(), 1);
		assert.equal(await builder.locator('.ui-selectmenu-button').count(), 2);
		await builder.locator('.syslogSearchText').fill('failure');
		await builder.locator('.syslogSearchAdd').filter({hasText: /^AND$/}).click();
		assert.equal(await builder.locator('.syslogSearchRow').count(), 2);
		await builder.locator('.syslogSearchText').nth(1).fill('router');
		// Switching to SQL keeps the textarea intact; filter hides it.
		await page.selectOption('#type', 'sql');
		assert.equal(await builder.isVisible(), false);
		assert.equal(await page.locator('#message').isVisible(), true);
		await page.selectOption('#type', 'filter');
		assert.equal(await builder.isVisible(), false === await page.locator('#message').isVisible());
		const document = await page.evaluate(() => {
			$('#syslog_edit').on('submit.test', event => event.preventDefault()).trigger('submit');
			return JSON.parse($('#message').val());
		});
		assert.equal(document.version, 1);
		assert.equal(document.conditions.length, 2);
		assert.equal(document.conditions[0].value, 'failure');
		assert.deepEqual(errors, []);
		console.log('removal_builder browser test passed: builder renders on removal rules, type switching, serialization, styled like alerts');
	} finally {
		await browser.close();
	}
})().catch(error => { console.error(error); process.exitCode = 1; });