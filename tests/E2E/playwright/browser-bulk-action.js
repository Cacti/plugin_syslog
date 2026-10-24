// Runs a bulk action on one row of a rule list: the confirmation request and the
// confirmed request, sent from the logged in page with its session and CSRF token.
const { withSession, fail } = require('./session');

withSession(async (page) => {
  await page.goto(`http://cacti_web/cacti/${process.env.PAGE_PATH}`, { waitUntil: 'domcontentloaded' });

  const rowId = await page.evaluate((text) => {
    const row = Array.from(document.querySelectorAll('tr')).find((candidate) => candidate.innerText.includes(text));
    const box = row ? row.querySelector('input[type="checkbox"]') : null;

    return box ? box.name : null;
  }, process.env.ROW_TEXT);

  if (!rowId) {
    fail(`No row contains ${process.env.ROW_TEXT}`);
  }

  const result = await page.evaluate(async ({ url, rowId, action }) => {
    const send = async (fields) => {
      const body = new URLSearchParams({ __csrf_magic: csrfMagicToken, drp_action: action, action: 'actions', ...fields });
      const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });

      return { status: response.status, text: await response.text() };
    };

    const confirm = await send({ [rowId]: 'on' });
    const selected = new DOMParser().parseFromString(confirm.text, 'text/html').querySelector('input[name="selected_items"]');

    if (!selected) {
      return { error: 'The confirmation page did not list the selected rule' };
    }

    const done = await send({ selected_items: selected.value });

    return { status: done.status };
  }, { url: `http://cacti_web/cacti/${process.env.PAGE_PATH}`, rowId, action: process.env.ACTION_VALUE });

  if (result.error || result.status !== 200) {
    fail(result.error || `Unexpected status ${result.status}`);
  }

  console.log(`ok action ${process.env.ACTION_VALUE}`);
}).catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
