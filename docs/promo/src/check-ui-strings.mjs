// Verifies every mock-up string in src/lib/ui-strings.js against the app's lang/de.json:
// the German value must exist as a translation, the English value as its key
// (":placeholders" may be filled). Usage: node docs/promo/src/check-ui-strings.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const SRC = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(SRC, '../../..');

export function checkUiStrings() {
  const de = JSON.parse(fs.readFileSync(path.join(REPO, 'lang/de.json'), 'utf8'));
  const src = fs.readFileSync(path.join(SRC, 'lib/ui-strings.js'), 'utf8');
  // a pluralised entry (":count game won|:count games won") counts once per variant
  const variants = (list) => list.flatMap((s) => (s.includes('|') ? s.split('|') : [s]));
  const keys = variants(Object.keys(de)), vals = variants(Object.values(de));
  const tmpl = (s) => new RegExp('^' + s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/:(\w+)/g, '.+') + '$');
  // a template counts only if its literal part covers at least half of the string:
  // a key made of placeholders (":name") would otherwise match anything
  const literal = (k) => k.replace(/:(\w+)/g, '').length;
  const match = (list, x) => list.includes(x) || list.some((k) => k.includes(':') && literal(k) >= x.length / 2 && tmpl(k).test(x));
  const re = /(\w+): \{ en: '((?:[^'\\]|\\.)*)', de: '((?:[^'\\]|\\.)*)' \}/g;
  const bad = [];
  let m, n = 0;
  while ((m = re.exec(src))) {
    n++;
    const [, id, en, deV] = m;
    const enOk = match(keys, en.replace(/\\'/g, "'")) || (en === deV && match(vals, deV));
    const deOk = match(vals, deV.replace(/\\'/g, "'"));
    if (!enOk || !deOk) bad.push(`${id}: en ${enOk ? 'ok' : 'MISSING'} "${en}", de ${deOk ? 'ok' : 'MISSING'} "${deV}"`);
  }
  return { n, bad };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const { n, bad } = checkUiStrings();
  if (bad.length) { console.error('UI STRINGS FAIL\n' + bad.join('\n')); process.exit(1); }
  console.log(`ui strings ok: ${n} checked against lang/de.json`);
}
