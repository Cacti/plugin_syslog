// Pastes an import document into a rule import form and checks the outcome message.
const { withSession, fail, url } = require('./session');

withSession(async (page) => {
  await page.goto(url(process.env.PAGE_PATH), { waitUntil: 'domcontentloaded' });
  const padding = Number(process.env.IMPORT_PAD_BYTES || 0);
  const text = (process.env.IMPORT_TEXT || '') + ' '.repeat(padding);

  if (process.env.IMPORT_AS_FILE === '1') {
    await page.locator('#import_file').setInputFiles({ name: 'import.json', mimeType: 'application/json', buffer: Buffer.from(text) });
  } else {
    await page.locator('#import_text').fill(text);
  }

  // On newer Cacti cores the Import button and the action field are rendered with
  // an empty form attribute, which detaches them from the form. The form is
  // submitted directly with the action field added back.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('#import_text').evaluate((field) => {
      const action = document.createElement('input');

      action.type = 'hidden';
      action.name = 'action';
      action.value = 'save';
      field.form.appendChild(action);
      HTMLFormElement.prototype.submit.call(field.form);
    })
  ]);
  await page.waitForLoadState('networkidle');

  const body = await page.locator('body').innerText();

  if (!body.includes(process.env.EXPECT_TEXT || '')) {
    fail(`Expected the page to contain "${process.env.EXPECT_TEXT}", got: ${body.slice(0, 400)}`);
  }

  console.log(`ok ${process.env.PAGE_PATH}`);
}).catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
