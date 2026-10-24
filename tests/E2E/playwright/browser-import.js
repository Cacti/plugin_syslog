// Pastes an import document into a rule import form and checks the outcome message.
const { withSession, fail } = require('./session');

withSession(async (page) => {
  await page.goto(`http://cacti_web/cacti/${process.env.PAGE_PATH}`, { waitUntil: 'domcontentloaded' });
  await page.locator('#import_text').fill(process.env.IMPORT_TEXT || '');
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
      field.form.submit();
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
