// Browser checks against rendered production PHP controls with mocked WordPress data.
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const puppeteer = require('puppeteer');
(async () => {
  const html = execFileSync('php', [path.join(__dirname, 'lead-ghl-regression.php'), '--render'], {encoding:'utf8'});
  const browser = await puppeteer.launch({headless:true});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const width of [1280,390]) {
      await page.setViewport({width,height:1000});
      await page.setContent(html);
      assert.equal(await page.$eval('[role=switch]', e => e.checked), false);
      await page.click('[role=switch]');
      assert.equal(await page.$eval('[role=switch]', e => e.checked), true);
      await page.click('[role=switch]');
      assert.equal(await page.$eval('#wnq-ghl-token', e => e.value), '');
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No page-level horizontal overflow');
      if (process.env.WNQ_UI_OUTPUT) { await page.screenshot({path:path.join(process.env.WNQ_UI_OUTPUT, `lead-ghl-${width}.png`),fullPage:true}); }
    }
    let confirmed = false;
    page.once('dialog', async dialog => { confirmed = dialog.message().includes('live emails'); await dialog.dismiss(); });
    await page.click('input[value=approve] ~ button');
    assert(confirmed, 'Live-send confirmation displayed');
    assert.deepEqual(errors, []);
    console.log('PASS: desktop/mobile layout, switch, empty token field, live-send confirmation, no JS errors.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
