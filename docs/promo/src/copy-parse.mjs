// Parses the campaign copy in src/copy/*.md (written by the kommunikator).
// Every text on a poster or in a reel comes from here; nothing is typed into a template.
// Usage: node docs/promo/src/copy-parse.mjs   (prints the parsed JSON)
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const COPY = path.join(path.dirname(fileURLToPath(import.meta.url)), 'copy');

/* Heading keyword -> motif id. An unknown heading fails loudly instead of being guessed. */
const MOTIF_KEYS = [
  // before login: the GRASP copy mentions Nostr, the heading must not fall into /nostr/
  [/grasp/i, 'grasp'],
  // motif 14, first: its texts name the casual cup, tournaments, clans and the ladder
  [/age of empires|aoe2/i, 'aoe2'],
  // motifs 11-13, before /tournament/, /watch/ and /nostr/ (their texts name all three)
  [/mempool/i, 'mempool'],
  [/on the stream|im stream/i, 'onstream'],
  [/tournaments live|turniere live/i, 'livecup'],
  // the upcoming-tournament reels (10-12), before /tournament/ and /clan/
  [/sats pot/i, 'satspot'],
  [/fifa/i, 'fifa'],
  [/casual cup/i, 'cups'],
  // the board games, before /nostr/ (their posts mention Nostr) and /daily/
  [/mühle|morris/i, 'morris'],
  [/\bdame\b|checkers/i, 'checkers'],
  [/nostr|login|anmeld/i, 'login'],
  [/blitz/i, 'blitz'],
  [/daily|fernschach|correspond/i, 'daily'],
  [/clan/i, 'clans'],
  [/tournament|turnier/i, 'tournaments'],
  [/watch|zuschau/i, 'watch'],
  [/invite|friend|freund|einlad/i, 'invite'],
  [/open source|quellcode|github/i, 'opensource'],
];
export function motifId(title) {
  for (const [re, id] of MOTIF_KEYS) if (re.test(title)) return id;
  throw new Error(`copy: unknown motif heading "${title}", add it to MOTIF_KEYS and give it a hero`);
}
function sections(md, re) {
  const out = [];
  const lines = md.split('\n');
  let cur = null;
  for (const line of lines) {
    const m = line.match(re);
    if (m) { cur = { title: m[1].trim(), lines: [] }; out.push(cur); continue; }
    if (/^## /.test(line)) { cur = null; continue; }
    if (cur) cur.lines.push(line);
  }
  return out;
}
const clean = (s) => s.replace(/\*\*/g, '').trim();

export function parsePosters() {
  const md = fs.readFileSync(path.join(COPY, 'posters.md'), 'utf8');
  const motifs = [];
  for (const sec of sections(md, /^## \d+\.\s+(.+)$/)) {
    const m = { id: motifId(sec.title), title: sec.title, de: { bullets: [] }, en: { bullets: [] }, cta: null };
    let list = null;
    for (const raw of sec.lines) {
      const line = raw.trim();
      let f;
      if ((f = line.match(/^\*\*(DE|EN) (Headline|Subline):\*\*\s*(.+)$/))) { m[f[1].toLowerCase()][f[2].toLowerCase()] = clean(f[3]); list = null; continue; }
      if ((f = line.match(/^\*\*(DE|EN) Bullets:\*\*\s*$/))) { list = m[f[1].toLowerCase()].bullets; continue; }
      if ((f = line.match(/^\*\*CTA:\*\*\s*(.+)$/))) { m.cta = clean(f[1]); list = null; continue; }
      if (list && (f = line.match(/^- (.+)$/))) { list.push(clean(f[1])); continue; }
      if (line.startsWith('**')) list = null;
    }
    for (const l of ['de', 'en']) for (const k of ['headline', 'subline']) if (!m[l][k]) throw new Error(`copy: ${m.id} ${l} ${k} missing`);
    if (!m.cta) throw new Error(`copy: ${m.id} CTA missing`);
    motifs.push(m);
  }
  if (!motifs.length) throw new Error('copy: no motifs found in posters.md');
  return motifs;
}

export function parseReels() {
  const md = fs.readFileSync(path.join(COPY, 'reels.md'), 'utf8');
  const reels = [];
  for (const sec of sections(md, /^## Reel \d+\s+[—-]\s+(.+)$/)) {
    const r = { id: motifId(sec.title), title: sec.title, de: [], en: [] };
    let lang = null;
    for (const raw of sec.lines) {
      const line = raw.trim();
      let f;
      if ((f = line.match(/^\*\*(DE|EN)\*\*$/))) { lang = f[1].toLowerCase(); continue; }
      // "1. (3s) Text — *scene note*": the scene note is the trailing italic part
      if (lang && (f = line.match(/^\d+\.\s*\((\d+(?:\.\d+)?)s\)\s*(.+?)(?:\s+[—-]\s+\*(.+)\*)?\s*$/))) {
        r[lang].push({ sec: parseFloat(f[1]), text: f[2].trim(), scene: (f[3] || '').trim() });
      }
    }
    if (!r.de.length || r.de.length !== r.en.length) throw new Error(`copy: reel ${r.id} has ${r.de.length} DE and ${r.en.length} EN beats`);
    reels.push(r);
  }
  if (!reels.length) throw new Error('copy: no reels found in reels.md');
  return reels;
}

/* posts.md: per motif the Nostr/X texts, plus the hype-week table. */
export function parsePosts() {
  const md = fs.readFileSync(path.join(COPY, 'posts.md'), 'utf8');
  const posts = {};
  for (const sec of sections(md, /^## (?!Hype)(.+)$/)) {
    let id;
    try { id = motifId(sec.title); } catch { continue; }
    const p = { title: sec.title };
    let key = null;
    for (const raw of sec.lines) {
      const line = raw.trim();
      let f;
      if ((f = line.match(/^\*\*(Nostr|X) \((DE|EN)\):\*\*\s*(.*)$/))) { key = `${f[1].toLowerCase()}_${f[2].toLowerCase()}`; p[key] = f[3].trim(); continue; }
      if (key && line && !line.startsWith('---')) p[key] = (p[key] ? p[key] + ' ' : '') + line;
      if (!line || line.startsWith('---')) key = null;
    }
    posts[id] = p;
  }
  const plan = [];
  const tbl = md.split(/^## Hype.*$/m)[1] || '';
  for (const line of tbl.split('\n')) {
    const f = line.match(/^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|$/);
    if (f && !/^(Tag|Day|-+)$/.test(f[1])) plan.push({ day: f[1], text: f[2], media: f[3] });
  }
  return { posts, plan };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  console.log(JSON.stringify({ posters: parsePosters(), reels: parseReels(), posts: parsePosts() }, null, 1));
}
