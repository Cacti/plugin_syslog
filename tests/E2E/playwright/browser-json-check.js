// Fetches a JSON endpoint as the logged in admin and checks the labels it returns.
const { withSession, fail, url } = require('./session');

withSession(async (page) => {
  const path = process.env.JSON_PATH || '';
  const response = await page.evaluate(async (url) => {
    const result = await fetch(url, { credentials: 'same-origin' });
    return { status: result.status, text: await result.text() };
  }, url(path));

  if (response.status !== 200) {
    fail(`Expected HTTP 200 from ${path}, got ${response.status}`);
  }

  const payload = JSON.parse(response.text);

  if (payload.error) {
    fail(`Endpoint reported an error: ${payload.error}`);
  }

  const labels = (payload.labels || []).join('|');

  for (const wanted of (process.env.EXPECT_LABELS || '').split(',').filter(Boolean)) {
    if (!labels.includes(wanted)) {
      fail(`Expected labels to contain ${wanted}, got ${labels}`);
    }
  }

  console.log(`ok ${path}`);
}).catch((error) => {
  console.error(error.stack || String(error));
  process.exit(1);
});
