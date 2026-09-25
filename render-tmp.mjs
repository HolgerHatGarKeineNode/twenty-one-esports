import { chromium } from 'playwright';
import fs from 'node:fs';
const dir = '/tmp/claude-1000/-home-user-Code-einundzwanzig-esports/f61b56d8-f1ba-49af-9d00-e17a350ecc65/scratchpad/src/';
const out = process.argv[2];
const names = process.argv.slice(3);
const support = fs.readFileSync(out + '/../support.js', 'utf8');
const browser = await chromium.launch({ executablePath: '/usr/bin/chromium' });
for (const n of names) {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  page.on('pageerror', (e) => console.log('pageerror', n, e.message));

  await page.goto('file://' + dir + n + '.dc.html');
  await page.waitForTimeout(1000);
  const h = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.screenshot({ path: out + '/' + n + '.png', fullPage: true });
  fs.writeFileSync(out + '/' + n + '.txt', await page.evaluate(() => document.body.innerText));
  console.log(n, h, await page.evaluate(() => document.body.dataset.rendered));
  await page.close();
}
await browser.close();
