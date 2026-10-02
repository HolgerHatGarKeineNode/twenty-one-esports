// Render every poster motif from src/copy/posters.md into docs/promo/posters/.
// Usage: node docs/promo/src/render-posters.mjs [motif ...]   (no args = all motifs)
// Before each screenshot the page runs its probe (overflow, collision, hero parts,
// small text, fonts). Any issue fails that poster: no PNG is written for it.
// Output: posters/<dir>/<motif>-<format>-<lang>.png at device scale 2.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parsePosters, parseReels, parsePosts } from './copy-parse.mjs';
import { checkPositions } from './check-positions.mjs';

const require = createRequire('/home/user/.npm-global/lib/node_modules/playwright/package.json');
const { chromium } = require('playwright');

const SRC = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(SRC, '..');
const OUT = path.join(ROOT, 'posters');

export const FORMATS = [
  { id: 'mobile-9x16', dir: 'mobile', w: 1080, h: 1920 },
  { id: 'x-16x9', dir: 'x', w: 1600, h: 900 },
  { id: 'nostr-square', dir: 'nostr', w: 1080, h: 1080 },
  { id: 'nostr-wide', dir: 'nostr', w: 1200, h: 630 },
  { id: 'stream-banner', dir: 'stream', w: 1920, h: 480 },
  { id: 'feed-4x5', dir: 'feed', w: 1080, h: 1350 },
];
/* The stream banner only where a stream audience is the addressee. */
export const STREAM_MOTIFS = ['blitz', 'watch', 'tournaments', 'mempool', 'onstream', 'livecup', 'aoe2', 'tmnf'];
export const LANGS = ['de', 'en'];
/* Formats a motif is limited to; motifs not listed get every format but the feed 4:5.
 * The feed format (Instagram-style 4:5) is the Blockfill set's, added on 2026-10-01. */
export const MOTIF_FORMATS = {
  blockfill: ['mobile-9x16', 'feed-4x5', 'nostr-square', 'x-16x9', 'nostr-wide'],
  bfweek: ['mobile-9x16', 'feed-4x5', 'nostr-square'],
};

const bad = checkPositions();
if (bad.length) { console.error('POSITIONS FAIL\n' + bad.join('\n')); process.exit(1); }

const copy = { posters: parsePosters(), reels: parseReels(), posts: parsePosts() };
const only = process.argv.slice(2);
const motifs = copy.posters.map((m) => m.id).filter((id) => !only.length || only.includes(id));

const browser = await chromium.launch({ headless: true, executablePath: '/usr/bin/chromium', args: ['--no-sandbox', '--disable-dev-shm-usage', '--allow-file-access-from-files'] });
const report = [];
let ok = 0, fail = 0;
for (const motif of motifs) {
  for (const f of FORMATS) {
    if (f.id === 'stream-banner' && !STREAM_MOTIFS.includes(motif)) continue;
    if (MOTIF_FORMATS[motif] ? !MOTIF_FORMATS[motif].includes(f.id) : f.id === 'feed-4x5') continue;
    fs.mkdirSync(path.join(OUT, f.dir), { recursive: true });
    for (const lang of LANGS) {
      const ctx = await browser.newContext({ viewport: { width: f.w, height: f.h }, deviceScaleFactor: 2 });
      await ctx.addInitScript((c) => { window.__COPY = c; }, copy);
      const page = await ctx.newPage();
      const errs = [];
      page.on('pageerror', (e) => errs.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
      page.on('requestfailed', (r) => errs.push('request failed: ' + r.url()));
      const out = path.join(OUT, f.dir, `${motif}-${f.id}-${lang}.png`);
      for (const stale of [out, out.replace(/\.png$/, '.FAIL.png')]) if (fs.existsSync(stale)) fs.unlinkSync(stale);
      try {
        await page.goto(pathToFileURL(path.join(SRC, 'posters/poster.html')).href + `?motif=${motif}&format=${f.id}&lang=${lang}`, { waitUntil: 'load' });
        await page.evaluate(() => document.fonts.ready);
        await page.evaluate(() => Promise.all([...document.images].map((i) => i.decode().catch(() => {}))));
        // fonts arrive after the first fit: fit again on final metrics
        await page.evaluate(() => window.__render(window.__COPY));
        await page.evaluate(() => Promise.all([...document.images].map((i) => i.decode().catch(() => {}))));
        // Positive control for the pixel probe: PROBE_BREAK=1 fades the copy (DOM unchanged).
        if (process.env.PROBE_BREAK) await page.addStyleTag({ content: '.copy { opacity: .05 }' });
        const probe = await page.evaluate(() => window.__probe());
        const issues = [...probe.issues, ...errs];
        const size = await page.evaluate(() => { const r = document.querySelector('.poster').getBoundingClientRect(); return [r.width, r.height]; });
        if (size[0] !== f.w || size[1] !== f.h) issues.push(`poster is ${size.join('x')}, expected ${f.w}x${f.h}`);
        report.push({ motif, format: f.id, lang, headline: probe.headline, fill: probe.fill, boxes: probe.boxes, issues });
        if (issues.length && process.env.PROBE_SOFT) await page.locator('.poster').screenshot({ path: out.replace(/\.png$/, '.FAIL.png') });
        if (issues.length) {
          if (fs.existsSync(out)) fs.unlinkSync(out);
          console.log('FAIL', motif, f.id, lang, '\n  ' + issues.join('\n  '));
          fail++;
        } else {
          const png = await page.locator('.poster').screenshot();
          // Pixel probe on the finished PNG: the DOM can be right while the raster is not.
          const px = await page.evaluate(async (b64) => {
            const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
            const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
            const g = c.getContext('2d'); g.drawImage(img, 0, 0);
            const P = document.querySelector('.poster').getBoundingClientRect(), k = img.width / P.width;
            const frac = (sel, test) => {
              const r = document.querySelector(sel).getBoundingClientRect();
              const d = g.getImageData(Math.round((r.left - P.left) * k), Math.round((r.top - P.top) * k), Math.max(1, Math.round(r.width * k)), Math.max(1, Math.round(r.height * k))).data;
              let n = 0; for (let i = 0; i < d.length; i += 4) if (test(d[i], d[i + 1], d[i + 2])) n++;
              return n / (d.length / 4);
            };
            return {
              headInk: frac('.head', (r, g2, b) => r > 235 && g2 > 235 && b > 235),
              ctaOrange: frac('.cta', (r, g2, b) => r > 220 && g2 > 120 && g2 < 170 && b < 60),
              size: [img.width, img.height],
            };
          }, png.toString('base64'));
          const pxIssues = [];
          if (px.headInk < 0.06) pxIssues.push(`pixel probe: headline box only ${(px.headInk * 100).toFixed(1)}% white ink`);
          if (px.ctaOrange < 0.35) pxIssues.push(`pixel probe: CTA box only ${(px.ctaOrange * 100).toFixed(1)}% orange`);
          if (px.size[0] !== f.w * 2 || px.size[1] !== f.h * 2) pxIssues.push(`pixel probe: png ${px.size.join('x')}`);
          if (pxIssues.length) {
            report[report.length - 1].issues.push(...pxIssues);
            if (process.env.PROBE_SOFT) fs.writeFileSync(out.replace(/\.png$/, '.FAIL.png'), png);
            console.log('FAIL', motif, f.id, lang, '\n  ' + pxIssues.join('\n  '));
            fail++;
            await ctx.close();
            continue;
          }
          fs.writeFileSync(out, png);
          report[report.length - 1].pixels = px;
          console.log('OK  ', path.relative(ROOT, out), `head ${probe.headline.size}px/${probe.headline.lines}l`, `${probe.boxes} boxes`);
          ok++;
        }
      } catch (e) {
        console.log('FAIL', motif, f.id, lang, String(e).split('\n')[0]);
        fail++;
      }
      await ctx.close();
    }
  }
}
await browser.close();
fs.mkdirSync(path.join(ROOT, 'posters'), { recursive: true });
fs.writeFileSync(path.join(ROOT, 'posters', 'probe-report.json'), JSON.stringify(report, null, 1));
console.log(`DONE ok=${ok} fail=${fail}`);
process.exit(fail ? 1 : 0);
