// Render the reels from src/copy/reels.md into docs/promo/reels/<lang>/<reel>-<lang>.mp4.
// Usage:
//   node docs/promo/src/render-reels.mjs [reel ...]            video, 1080x1920 @60fps, music per src/reels/MUSIC.md
//   node docs/promo/src/render-reels.mjs --stills [reel ...]   one PNG per beat (mid-beat) to reels/stills/, no video
// Before rendering, every beat is probed at 3 points (caption in its box, scene inside
// its band, no clipped text) and its on-screen time is checked (>= 3 s). Any issue fails the reel.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parsePosters, parseReels, parsePosts } from './copy-parse.mjs';
import { checkPositions, timeline } from './check-positions.mjs';
import { checkUiStrings } from './check-ui-strings.mjs';

const require = createRequire('/home/user/.npm-global/lib/node_modules/playwright/package.json');
const { chromium } = require('playwright');

const SRC = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(SRC, '..');
const FPS = 60;
const MIN_BEAT = 3;

const argv = process.argv.slice(2);
const stills = argv.includes('--stills');
const only = argv.filter((a) => !a.startsWith('--'));

const bad = [...checkPositions(), ...checkUiStrings().bad];
if (bad.length) { console.error('PRECHECK FAIL\n' + bad.join('\n')); process.exit(1); }

const copy = { posters: parsePosters(), reels: parseReels(), posts: parsePosts() };
const TL = Object.fromEntries(['legal', 'opera', 'qgd', 'qgd7', 'italian'].map((g) => [g, timeline(g)]));
const music = {};
for (const line of fs.readFileSync(path.join(SRC, 'reels/MUSIC.md'), 'utf8').split('\n')) {
  const m = line.match(/^\| ([a-z-]+) \| `([^`]+)` \|$/);
  if (m) music[m[1]] = path.join(ROOT, 'assets/music', m[2]);
}

/* Window of `dur` seconds with the highest mean short-term loudness. */
function loudestWindow(file, dur) {
  const r = spawnSync('ffmpeg', ['-hide_banner', '-nostats', '-i', file, '-af', 'ebur128=metadata=1,ametadata=print:key=lavfi.r128.S', '-f', 'null', '-'], { encoding: 'utf8', maxBuffer: 1 << 28 });
  const pts = [], S = [];
  let t = null;
  for (const line of (r.stderr + r.stdout).split('\n')) {
    let m;
    if ((m = line.match(/pts_time:([\d.]+)/))) t = parseFloat(m[1]);
    else if ((m = line.match(/lavfi\.r128\.S=(-?[\d.]+|-inf)/)) && t !== null) { pts.push(t); S.push(m[1] === '-inf' ? -70 : Math.max(-70, parseFloat(m[1]))); }
  }
  const total = pts[pts.length - 1] || 0;
  let best = 4, bestV = -Infinity;
  for (let s = 4; s + dur <= total - 2; s += 0.5) {
    let sum = 0, n = 0;
    for (let i = 0; i < pts.length; i++) if (pts[i] >= s && pts[i] < s + dur) { sum += S[i]; n++; }
    if (n && sum / n > bestV) { bestV = sum / n; best = s; }
  }
  return { start: best, meanS: +bestV.toFixed(1), total: +total.toFixed(1) };
}

const browser = await chromium.launch({ headless: true, executablePath: '/usr/bin/chromium', args: ['--no-sandbox', '--disable-dev-shm-usage', '--allow-file-access-from-files'] });
const reels = copy.reels.filter((r) => !only.length || only.includes(r.id));
const log = [];
let ok = 0, fail = 0;
for (const reel of reels) {
  for (const lang of ['de', 'en']) {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, deviceScaleFactor: 1 });
    await ctx.addInitScript(([c, tl]) => { window.__COPY = c; window.__TL = tl; }, [copy, TL]);
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    page.on('requestfailed', (r) => errs.push('request failed: ' + r.url()));
    await page.goto(pathToFileURL(path.join(SRC, 'reels/reel.html')).href + `?reel=${reel.id}&lang=${lang}`, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    const info = await page.evaluate(() => window.__init());
    await page.evaluate(() => Promise.all([...document.images].map((i) => i.decode().catch(() => {}))));
    const issues = [...errs];
    // beat timing: caption is fully in from 0.2 s after the cut until the next cut
    info.beats.forEach((b, i) => { if (b.len - 0.2 < MIN_BEAT) issues.push(`beat ${i + 1} on screen ${(b.len - 0.2).toFixed(2)} s < ${MIN_BEAT} s`); });
    // probe every beat at three points
    const probes = [];
    for (const [i, b] of info.beats.entries()) {
      for (const f of [0.35, 0.6, 0.97]) {
        await page.evaluate((u) => window.frame(u), b.start + b.len * f);
        const pr = await page.evaluate(() => window.__probeFrame());
        pr.issues.forEach((x) => issues.push(`beat ${i + 1} @${Math.round(f * 100)}%: ${x}`));
        if (f === 0.6) probes.push(pr.caption);
      }
    }
    const name = `${reel.id}-${lang}`;
    if (issues.length) {
      console.log('FAIL', name, '\n  ' + [...new Set(issues)].slice(0, 12).join('\n  '));
      fail++; await ctx.close(); continue;
    }
    if (stills) {
      const dir = path.join(ROOT, 'reels/stills'); fs.mkdirSync(dir, { recursive: true });
      for (const [i, b] of info.beats.entries()) {
        for (const f of [0.08, 0.6]) {
          await page.evaluate((u) => window.frame(u), b.start + b.len * f);
          await page.screenshot({ path: path.join(dir, `${name}-beat${i + 1}-${Math.round(f * 100)}.png`) });
        }
      }
      console.log('STILLS', name, info.duration.toFixed(1) + 's', 'caption px', probes.join('/'));
      ok++; await ctx.close(); continue;
    }
    const outDir = path.join(ROOT, 'reels', lang); fs.mkdirSync(outDir, { recursive: true });
    const out = path.join(outDir, `${name}.mp4`);
    if (fs.existsSync(out)) fs.unlinkSync(out);
    const dur = info.duration;
    const track = music[reel.id];
    if (!track || !fs.existsSync(track)) { console.log('FAIL', name, 'no music for reel in MUSIC.md'); fail++; await ctx.close(); continue; }
    const win = loudestWindow(track, dur);
    const ff = spawn('ffmpeg', ['-v', 'error', '-y', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-',
      '-ss', String(win.start), '-t', String(dur), '-i', track,
      '-filter_complex', `[0:v]format=yuv420p[v];[1:a]afade=t=in:d=0.3,afade=t=out:st=${(dur - 1.5).toFixed(2)}:d=1.5,loudnorm=I=-14:TP=-1.5:LRA=11,aresample=48000[a]`,
      '-map', '[v]', '-map', '[a]', '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-r', String(FPS), '-pix_fmt', 'yuv420p',
      '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', '-t', String(dur), out], { stdio: ['pipe', 'inherit', 'inherit'] });
    const N = Math.round(dur * FPS);
    const t0 = Date.now();
    for (let k = 0; k < N; k++) {
      await page.evaluate((u) => window.frame(u), k / FPS);
      const buf = await page.screenshot({ type: 'jpeg', quality: 92 });
      if (!ff.stdin.write(buf)) await new Promise((r) => ff.stdin.once('drain', r));
    }
    ff.stdin.end();
    const code = await new Promise((r) => ff.on('close', r));
    if (errs.length || code !== 0) { console.log('FAIL', name, 'ffmpeg', code, errs.slice(0, 3)); fail++; }
    else {
      console.log('OK  ', path.relative(ROOT, out), dur.toFixed(1) + 's', `music ${path.basename(track)} @${win.start}s (S ${win.meanS})`, `${((Date.now() - t0) / 1000).toFixed(0)}s render`);
      log.push({ reel: reel.id, lang, file: path.relative(ROOT, out), duration: +dur.toFixed(2), track: path.basename(track), musicStart: win.start, beats: info.beats });
      ok++;
    }
    await ctx.close();
  }
}
await browser.close();
if (!stills && log.length) {
  const f = path.join(ROOT, 'reels/render-log.json');
  const prev = fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : [];
  const merged = [...prev.filter((p) => !log.some((l) => l.reel === p.reel && l.lang === p.lang)), ...log];
  fs.writeFileSync(f, JSON.stringify(merged, null, 1));
}
console.log(`DONE ok=${ok} fail=${fail}`);
process.exit(fail ? 1 : 0);
