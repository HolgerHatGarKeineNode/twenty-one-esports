// Build docs/promo/manifest.json and docs/promo/gallery.html from what is on disk
// (posters/, reels/) plus the copy (src/copy/posts.md). Only the current set:
// the archive in _archive-glm/ is not listed.
// Usage: node docs/promo/src/build-gallery.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePosters, parseReels, parsePosts, motifId } from './copy-parse.mjs';

const SRC = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(SRC, '..');
const SITE = 'https://esports.einundzwanzig.space';

/* Page each motif points to (routes/web.php). */
const FEATURE = { login: `${SITE}/login`, blitz: `${SITE}/chess`, daily: `${SITE}/chess`, clans: `${SITE}/clans`, tournaments: `${SITE}/tournaments`, watch: `${SITE}/games`, invite: `${SITE}/`, opensource: 'https://github.com/HolgerHatGarKeineNode/twenty-one-esports', satspot: `${SITE}/tournaments/2`, fifa: `${SITE}/tournaments/1`, cups: `${SITE}/tournaments`, morris: `${SITE}/games/nine-mens-morris`, checkers: `${SITE}/games/checkers`, mempool: `${SITE}/matches`, onstream: `${SITE}/live`, livecup: `${SITE}/tournaments`, aoe2: `${SITE}/games/age-of-empires-2`, grasp: 'https://gitworkshop.dev/npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6/relay.ngit.dev/twenty-one-esports' };
const FMT = { 'mobile-9x16': ['MOB', 'mobile'], 'x-16x9': ['X', 'x'], 'nostr-square': ['SQ', 'nostr'], 'nostr-wide': ['WIDE', 'nostr'], 'stream-banner': ['STREAM', 'stream'] };

const posters = parsePosters();
const reels = parseReels();
const { posts, plan } = parsePosts();

/* motif order = posting plan order (the parser's own heading map); motifs outside the plan come last */
const planDay = {};
for (const p of plan) {
  let id = null;
  try { id = motifId(p.text); } catch { continue; }
  if (!(id in planDay)) planDay[id] = p.day;
}
const dayNum = (id) => { const n = parseFloat(planDay[id]); return Number.isFinite(n) ? n : 1000; };
/* a motif can be reel-only (no poster), e.g. grasp */
const motifs = [...posters, ...reels.filter((r) => !posters.some((m) => m.id === r.id))].map((m) => ({ id: m.id, title: m.title, day: m.id in planDay ? `Posting plan: day ${planDay[m.id]}` : 'Not in the posting plan' }))
  .sort((a, b) => dayNum(a.id) - dayNum(b.id));

const items = [];
const captionsFor = (id, lang) => {
  const p = posts[id] || {};
  return [[`nostr_${lang}`, `Nostr (${lang.toUpperCase()})`], [`x_${lang}`, `X (${lang.toUpperCase()})`]].filter(([k]) => p[k]).map(([k, label]) => ({ label, text: p[k] }));
};
for (const m of [...posters, ...reels.filter((r) => !posters.some((x) => x.id === r.id))]) {
  if (posters.includes(m)) for (const [fmt, [code, dir]] of Object.entries(FMT)) {
    for (const lang of ['de', 'en']) {
      const rel = `posters/${dir}/${m.id}-${fmt}-${lang}.png`;
      if (!fs.existsSync(path.join(ROOT, rel))) continue;
      items.push({ id: `P-${m.id.toUpperCase()}-${code}-${lang.toUpperCase()}`, type: 'poster', motif: m.id, lang, format: fmt, file: rel, title: m[lang].headline, featureUrl: FEATURE[m.id], captions: captionsFor(m.id, lang) });
    }
  }
  const r = reels.find((x) => x.id === m.id);
  if (r) {
    for (const lang of ['de', 'en']) {
      const rel = `reels/${lang}/${m.id}-${lang}.mp4`;
      if (!fs.existsSync(path.join(ROOT, rel))) continue;
      items.push({ id: `R-${m.id.toUpperCase()}-${lang.toUpperCase()}`, type: 'reel', motif: m.id, lang, format: 'reel-9x16', file: rel, title: r[lang].map((b) => b.text).join(' / '), featureUrl: FEATURE[m.id], captions: captionsFor(m.id, lang) });
    }
  }
  for (const lang of ['de', 'en']) {
    for (const [k, label] of [['nostr', 'Nostr'], ['x', 'X']]) {
      const text = posts[m.id]?.[`${k}_${lang}`];
      if (text) items.push({ id: `T-${m.id.toUpperCase()}-${k.toUpperCase()}-${lang.toUpperCase()}`, type: 'message', motif: m.id, lang, title: `${label} (${lang.toUpperCase()})`, text });
    }
  }
}
const manifest = { generated: new Date().toISOString(), note: 'Current set only; old set in _archive-glm/.', motifs, plan, items };
fs.writeFileSync(path.join(ROOT, 'manifest.json'), JSON.stringify(manifest, null, 1));
const tpl = fs.readFileSync(path.join(SRC, 'gallery/gallery.template.html'), 'utf8');
if (!tpl.includes('/*MANIFEST*/')) throw new Error('gallery template lost its /*MANIFEST*/ slot');
fs.writeFileSync(path.join(ROOT, 'gallery.html'), tpl.replace('/*MANIFEST*/', () => JSON.stringify(manifest).replace(/</g, '\\u003c')));
const by = items.reduce((a, i) => ((a[i.type] = (a[i.type] || 0) + 1), a), {});
console.log('manifest.json + gallery.html:', items.length, 'items', JSON.stringify(by), 'motifs', motifs.map((m) => m.id).join(','));
