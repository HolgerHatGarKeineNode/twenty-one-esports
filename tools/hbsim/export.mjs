// Pulls the map data and the balance values out of the game page so the simulator and the PHP rules core
// play exactly the same board: node export.mjs ../../resources/js/hyper/prototype/index.html map.json
import fs from 'node:fs';
const html = fs.readFileSync(process.argv[2], 'utf8');
const lines = html.split('\n');
const start = lines.findIndex((l) => l.startsWith('const ZONES = {'));
const end = lines.findIndex((l) => l.startsWith('const ZT = '));
const code = lines.slice(start, end + 1).join('\n')
  .replace(/^const T = TDEF\.map\(\(\[id, name, zone, f = ''\]\) => \{[\s\S]*?\n\}\);/m, "const T = TDEF.map(([id, name, zone, f = '']) => ({ id, name, zone, bank: f.includes('b'), mine: f.includes('m') }));");
const fn = new Function(code + '\nreturn { ZONES, T: T.map((t) => ({ id: t.id, name: t.name, zone: t.zone, bank: !!t.bank, mine: !!t.mine })), ADJ: Object.fromEntries(Object.entries(ADJ).map(([k, v]) => [k, [...v]])) };');
const d = fn();
// The balance values sit further down the page, each on one line.
const constant = (name) => {
  const line = lines.find((l) => l.startsWith(`const ${name} = `));
  if (!line) throw new Error(`${name} not found in the game page`);
  return new Function(`return ${line.slice(`const ${name} = `.length).replace(/;\s*$/, '')};`)();
};
const startValue = constant('START_VALUE');
// balance.json beside this script overrides the page's seat compensation after a retune (the page stays the template).
const override = new URL('./balance.json', import.meta.url);
const seatComp = fs.existsSync(override) ? JSON.parse(fs.readFileSync(override, 'utf8')).seatComp : constant('SEAT_COMP');
const idx = Object.fromEntries(d.T.map((t, i) => [t.id, i]));
const zones = Object.keys(d.ZONES);
const out = {
  zones: zones.map((z) => ({ key: z, name: d.ZONES[z].name, bank: d.ZONES[z].bank, sats: d.ZONES[z].sats })),
  terr: d.T.map((t) => ({ id: t.id, name: t.name, zone: zones.indexOf(t.zone), bank: t.bank, mine: t.mine, value: startValue[t.id] ?? 0, adj: d.ADJ[t.id].map((n) => idx[n]) })),
  seatComp: { open: [2, 3, 4, 5, 6].map((n) => seatComp.open[n] ?? 0), limit: [2, 3, 4, 5, 6].map((n) => seatComp.limit[n] ?? 0) },
};
fs.writeFileSync(process.argv[3], JSON.stringify(out));
console.log(out.terr.length, 'territories', out.zones.length, 'zones', out.terr.filter((t) => t.bank).length, 'banks', out.terr.filter((t) => t.mine).length, 'mines');
