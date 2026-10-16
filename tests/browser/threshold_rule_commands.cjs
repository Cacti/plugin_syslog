const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const cacti = process.argv[2];
if (!cacti) throw new Error('Supply the Cacti checkout path');
const html = execFileSync('php', [path.join(__dirname, '../Fixtures/threshold_rule_commands.php'), cacti], {encoding: 'utf8'});
(async () => {
 const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox']});
 try {
  const page = await browser.newPage({viewport: {width: 1440, height: 900}});
  await page.setContent(html);
  await page.addScriptTag({path: path.join(cacti, 'include/js/jquery.js')});
  await page.evaluate(() => changeMethod());
  assert.equal(await page.locator('#row_recovery_command').isVisible(), true);
  assert.equal(await page.locator('#recovery_command').inputValue(), '/usr/local/bin/notify.sh clear <HOSTNAME>');
  assert.equal(await page.locator('#command').inputValue(), '/usr/local/bin/notify.sh raise');
  await page.locator('#recovery_command').fill('/usr/local/bin/notify.sh <ALERTID>');
  await page.locator('#report_method').selectOption('0');
  await page.evaluate(() => changeMethod());
  assert.equal(await page.locator('#row_recovery_command').isVisible(), false);
  assert.equal(await page.locator('#row_command').isVisible(), true);
  await page.locator('#report_method').selectOption('1');
  await page.evaluate(() => changeMethod());
  assert.equal(await page.locator('#row_recovery_command').isVisible(), true);
  const submitted = await page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('#syslog_edit'))));
  assert.equal(submitted.recovery_command, '/usr/local/bin/notify.sh <ALERTID>');
  assert.equal(submitted.command, '/usr/local/bin/notify.sh raise');
  await page.setViewportSize({width: 390, height: 844});
  assert.equal(await page.locator('#recovery_command').isVisible(), true);
  console.log('Threshold command form checks passed: both commands, saved values, threshold-only visibility, method switching and form submission');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
