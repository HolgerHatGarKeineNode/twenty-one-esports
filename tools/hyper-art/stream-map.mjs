#!/usr/bin/env node
/**
 * The world map of the Hyperbitcoinization stream slide (h1, App\Support\TwentyOne\Stream\HyperScene): the game
 * page's map (public/hyper/mapdata.js, 1600 x 860) cropped to the land, scaled to the slide's map width and thinned
 * to what a 1280 x 720 frame can show, with the English territory names of resources/js/hyper/data.js. The slide is
 * rendered once a second by rsvg-convert; the page's 412 KB of territory paths would make every frame slow, the
 * thinned ones are a fraction of that.
 *
 * Usage: node tools/hyper-art/stream-map.mjs  (writes resources/stream/hyper/map.json)
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TDEF, ZONES } from '../../resources/js/hyper/data.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
/** The land's box in the page's map (x 24..1576, y 85..762 measured), with a margin. */
const CROP = { x: 20, y: 80, w: 1560, h: 690 };
/** The slide's map width in px (resources/views/stream/rotation/h1-hyper.blade.php). */
const WIDTH = 832;
/** A point closer than this to the last one kept is dropped; a ring smaller than MIN_RING across is dropped. */
const MIN_STEP = 1.6;
const MIN_RING = 1.2;

const raw = readFileSync(join(ROOT, 'public/hyper/mapdata.js'), 'utf8');
const map = JSON.parse(raw.slice(raw.indexOf('=') + 1).replace(/;\s*$/, ''));
const scale = WIDTH / CROP.w;
const at = (x, y) => [Math.round((x - CROP.x) * scale * 10) / 10, Math.round((y - CROP.y) * scale * 10) / 10];

function thin(d) {
    const rings = [];
    for (const ring of d.split('Z')) {
        const points = [...ring.matchAll(/(-?[\d.]+),(-?[\d.]+)/g)].map((m) => at(+m[1], +m[2]));
        if (points.length < 3) continue;
        const kept = [points[0]];
        for (const p of points.slice(1)) {
            const last = kept[kept.length - 1];
            if (Math.hypot(p[0] - last[0], p[1] - last[1]) >= MIN_STEP) kept.push(p);
        }
        const xs = kept.map((p) => p[0]); const ys = kept.map((p) => p[1]);
        if (kept.length < 3 || Math.max(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys)) < MIN_RING) continue;
        rings.push('M' + kept.map((p) => p.join(',')).join('L') + 'Z');
    }

    return rings.join('');
}

const territories = {};
for (const [id, name, zone, flags = ''] of TDEF) {
    const t = map.terr[id];
    if (!t) throw new Error('no map path for ' + id);
    const [cx, cy] = at(t.cx, t.cy);
    territories[id] = { name, zone, bank: flags.includes('b'), mine: flags.includes('m'), cx, cy, d: thin(t.d) };
}

const out = {
    source: 'public/hyper/mapdata.js, thinned by tools/hyper-art/stream-map.mjs',
    width: WIDTH,
    height: Math.round(CROP.h * scale),
    zones: Object.fromEntries(Object.entries(ZONES).map(([key, z]) => [key, { name: z.name, color: z.color }])),
    neutral: thin(map.neutral),
    territories,
};
mkdirSync(join(ROOT, 'resources/stream/hyper'), { recursive: true });
const json = JSON.stringify(out);
writeFileSync(join(ROOT, 'resources/stream/hyper/map.json'), json + '\n');
console.log(`map.json: ${Object.keys(territories).length} territories, ${json.length} bytes, ${out.width} x ${out.height}`);
