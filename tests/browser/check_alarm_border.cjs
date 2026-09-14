const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const cacti = process.env.CACTI_ROOT || path.resolve(root, '../cacti');

(async () => {
	const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
	try {
		const page = await browser.newPage();
		await page.setContent('<link rel="stylesheet" href="file://' + cacti + '/include/themes/classic/main.css"><form id="syslog_edit"><select id="type"><option value="filter">Filter Builder</option></select><textarea id="message"></textarea></form>');
		await page.addScriptTag({path: root + '/js/filter-builder.js'});
		await page.addScriptTag({path: root + '/js/functions.js'});
		let source = fs.readFileSync(root + '/syslog_alerts.php', 'utf8');
		source = source.slice(source.indexOf('var allowEdits='), source.indexOf('</script>'));
		source = source.replace(/<\?php[\s\S]*?\?>/g, 'true');
		await page.addStyleTag({path: root + '/css/search.css'});
		await page.addScriptTag({content: source});
		await page.waitForSelector('#syslog_alert_filter_builder .syslogSearchText');
		const style = await page.evaluate(() => {
			const input = document.querySelector('.syslogSearchText');
			const cs = getComputedStyle(input);
			return {borderWidth: cs.borderTopWidth, borderStyle: cs.borderTopStyle, borderColor: cs.borderTopColor, background: cs.backgroundColor};
		});
		console.log('text field style:', JSON.stringify(style));
		await page.screenshot({path: '/tmp/alarm_builder_border.png', clip: {x: 0, y: 0, width: 900, height: 220}});
	} finally {
		await browser.close();
	}
})().catch(error => { console.error(error); process.exitCode = 1; });