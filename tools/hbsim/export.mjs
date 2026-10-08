// Pulls the map data out of the game page so the simulator plays exactly the same board.
import fs from 'node:fs';
const html = fs.readFileSync(process.argv[2], 'utf8');
const lines = html.split('\n');
const start = lines.findIndex((l) => l.startsWith('const ZONES = {'));
const end = lines.findIndex((l) => l.startsWith('const ZT = '));
const code = lines.slice(start, end + 1).join('\n')
  .replace(/^const T = TDEF\.map\(\(\[id, name, zone, f = ''\]\) => \{[\s\S]*?\n\}\);/m, "const T = TDEF.map(([id, name, zone, f = '']) => ({ id, name, zone, bank: f.includes('b'), mine: f.includes('m') }));");
const fn = new Function(code + '\nreturn { ZONES, T: T.map((t) => ({ id: t.id, zone: t.zone, bank: !!t.bank, mine: !!t.mine })), ADJ: Object.fromEntries(Object.entries(ADJ).map(([k, v]) => [k, [...v]])) };');
const d = fn();
const idx = Object.fromEntries(d.T.map((t, i) => [t.id, i]));
const zones = Object.keys(d.ZONES);
const out = {
  zones: zones.map((z) => ({ key: z, sats: d.ZONES[z].sats })),
  terr: d.T.map((t) => ({ id: t.id, zone: zones.indexOf(t.zone), bank: t.bank, mine: t.mine, adj: d.ADJ[t.id].map((n) => idx[n]) })),
};
fs.writeFileSync(process.argv[3], JSON.stringify(out));
console.log(out.terr.length, 'territories', out.zones.length, 'zones', out.terr.filter((t) => t.bank).length, 'banks', out.terr.filter((t) => t.mine).length, 'mines');
