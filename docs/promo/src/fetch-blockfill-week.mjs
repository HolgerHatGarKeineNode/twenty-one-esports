// Reads this week's Blockfill board ("This week's hunt") from the public /blockfill page
// and writes docs/promo/data/blockfill-week.js for the `bfweek` poster template.
// The board is the app's own (pages/scores/partials/leaderboard): place, player name,
// best verified time (ScoreMetric::format, m:ss.mmm). Nothing is typed by hand; the
// poster shows only what the page shows to everyone. data/ is local only (gitignored).
// Usage: node docs/promo/src/fetch-blockfill-week.mjs [page url]
//   default https://esports.einundzwanzig.space/blockfill
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const url = process.argv[2] || 'https://esports.einundzwanzig.space/blockfill';
const res = await fetch(url, { headers: { 'Accept-Language': 'en', 'User-Agent': 'twentyone-promo-kit' } });
if (!res.ok) { console.error(`FAIL ${url}: HTTP ${res.status}`); process.exit(1); }
const html = await res.text();

const text = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#039;|&#39;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/\s+/g, ' ').trim();
if (!html.includes('data-test="stacker-week"')) { console.error('FAIL: no "This week\'s hunt" section on the page'); process.exit(1); }
const week = html.slice(html.indexOf('data-test="stacker-week"'));
const win = (week.match(/data-test="stacker-week-window"[^>]*>([^<]+)</) || [])[1];
if (!win) { console.error('FAIL: no week window on the page'); process.exit(1); }
const MON = { Jan: 1, Feb: 2, Mar: 3, Apr: 4, May: 5, Jun: 6, Jul: 7, Aug: 8, Sep: 9, Oct: 10, Nov: 11, Dec: 12 };
const dates = [...text(win).matchAll(/(\d{1,2}) ([A-Z][a-z]{2}) (\d{4})/g)].map(([, d, m, y]) => `${y}-${String(MON[m]).padStart(2, '0')}-${d.padStart(2, '0')}`);
if (dates.length !== 2) { console.error(`FAIL: week window "${text(win)}" has ${dates.length} dates`); process.exit(1); }

const rows = [];
const board = week.split('data-test="stacker-week-mine"')[0];
for (const li of board.split('data-test="score-row"').slice(1)) {
  const place = (li.match(/^\s*data-place="(\d*)"/) || [])[1];
  const name = (li.match(/class="[^"]*truncate font-bold[^"]*"[^>]*>([^<]+)</) || [])[1];
  const time = (li.match(/data-test="score-value"[^>]*>([^<]+)</) || [])[1];
  if (!name || !time || !place) { console.error('FAIL: a board row without place, name or time'); process.exit(1); }
  if (!/^\d+:\d{2}\.\d{3}$/.test(time.trim())) { console.error(`FAIL: time "${time}" is not m:ss.mmm`); process.exit(1); }
  rows.push({ place: +place, name: text(name), time: time.trim() });
}
const data = { source: url, fetched: new Date().toISOString(), window: text(win), start: dates[0], end: dates[1], rows };
fs.mkdirSync(path.join(ROOT, 'data'), { recursive: true });
fs.writeFileSync(path.join(ROOT, 'data/blockfill-week.js'), `/* Written by src/fetch-blockfill-week.mjs from ${url}. Do not edit. */\nwindow.BF_WEEK = ${JSON.stringify(data, null, 1)};\n`);
console.log(`blockfill week ${data.start}..${data.end}: ${rows.length} row(s)${rows.length ? ', #1 ' + rows[0].time : ''}`);
