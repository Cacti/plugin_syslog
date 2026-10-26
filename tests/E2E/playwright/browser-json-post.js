// Posts form fields to a plugin JSON endpoint as the signed in user and checks the reply.
const { withSession, fail, url } = require('./session');

withSession(async (page) => {
  const fields = JSON.parse(process.env.JSON_FIELDS || '{}');
  const reply = await page.evaluate(async ({ target, fields }) => {
    const body = new URLSearchParams({ __csrf_magic: csrfMagicToken, ...fields });
    const response = await fetch(target, { method: 'POST', body, credentials: 'same-origin' });

    return { status: response.status, text: await response.text() };
  }, { target: url(process.env.JSON_PATH), fields });

  if (reply.status !== 200) {
    fail(`Expected HTTP 200 from ${process.env.JSON_PATH}, got ${reply.status}`);
  }

  let payload;

  try {
    payload = JSON.parse(reply.text);
  } catch (error) {
    fail(`The reply is not JSON: ${reply.text.replace(/\s+/g, " ").slice(0, 300)}`);
  }

  const wantError = process.env.EXPECT_ERROR === '1';

  if (wantError !== Boolean(payload.error)) {
    fail(`Expected ${wantError ? 'an error' : 'no error'}, got: ${reply.text}`);
  }

  for (const key of (process.env.EXPECT_KEYS || '').split(',').filter(Boolean)) {
    if (!(key in payload)) {
      fail(`Expected the reply to contain ${key}, got: ${reply.text}`);
    }
  }

  console.log(reply.text);
}).catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
