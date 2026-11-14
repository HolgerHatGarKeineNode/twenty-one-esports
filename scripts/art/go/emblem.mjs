// Go's broadcast emblem (public/broadcast/art/emblem-go.webp): a thick block cut from the wooden board with four
// stones, seen from the upper left like the pack's other emblems, on a transparent ground.
//
//   node scripts/art/go/emblem.mjs [OUT_DIR]      (from the repo root; default: public/broadcast/art)
//
// Writes emblem-go.webp (1024 x 869, RGBA) into OUT_DIR: the page is screenshot at 2048 x 2048 on a clear ground,
// trimmed to the block and halved, WebP quality 92 with a lossless alpha. The page and its screenshot stay in the
// system's temp folder. Needs Node, Chromium (/usr/bin/chromium, or CHROMIUM=<path>) and ImageMagick 7 (magick) with
// WebP; no npm package. Material and colours: material.mjs, read from resources/js/boardGame.js.
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { EDGE, GO, GRADIENTS, ROOT, grainAt, magick, screenshot } from './material.mjs';

const outDir = resolve(process.argv[2] || join(ROOT, 'public/broadcast/art'));
const work = mkdtempSync(join(tmpdir(), 'go-emblem-'));

const W = 2048;
const LINES = 4; // four lines each way; the block is cut a little past the outer ones, so the lines run out of it
const NEAR = 0.42;
const FAR = 0.42;
const THICK = 0.95;
const cam = { yaw: -42, pitch: 50, dist: 60, focal: 60 * 370, ox: W / 2, oy: W * 0.53 };
// A moment of a game: the two sides cut across each other, the black stone set last carries the ring.
const stones = { black: [[1, 1], [2, 2]], white: [[2, 1], [1, 2]] };
const last = '2,2';

const yaw = (cam.yaw * Math.PI) / 180;
const pitch = (cam.pitch * Math.PI) / 180;
const mid = (LINES - 1) / 2;
const project = (x, y, z) => {
    const [px, pz] = [x - mid, z - mid];
    const x1 = px * Math.cos(yaw) - pz * Math.sin(yaw);
    const z1 = px * Math.sin(yaw) + pz * Math.cos(yaw);
    const depth = -y * Math.sin(pitch) + z1 * Math.cos(pitch) + cam.dist;
    const scale = cam.focal / depth;
    return { x: cam.ox + x1 * scale, y: cam.oy - (y * Math.cos(pitch) + z1 * Math.sin(pitch)) * scale, scale, depth };
};
const pt = (p) => p.x.toFixed(2) + ',' + p.y.toFixed(2);
const poly = (points, attrs) => '<polygon points="' + points.map(pt).join(' ') + '" ' + attrs + '/>';
const [lo, hi] = [-NEAR, LINES - 1 + FAR];
const corners = [[lo, hi], [hi, hi], [hi, lo], [lo, lo]];
const top = corners.map(([x, z]) => project(x, 0, z));
const bottom = corners.map(([x, z]) => project(x, -THICK, z));
const unit = project(mid, 0, mid).scale;

let defs = GRADIENTS +
    `<linearGradient id="wood" gradientUnits="userSpaceOnUse" x1="${top[0].x}" y1="${top[0].y}" x2="${top[2].x}" y2="${top[2].y}"><stop offset="0" stop-color="#C08A47"/><stop offset="0.45" stop-color="${GO.wood[0]}"/><stop offset="1" stop-color="${GO.wood[1]}"/></linearGradient>` +
    `<linearGradient id="face" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6E4722"/><stop offset="0.35" stop-color="${GO.side}"/><stop offset="1" stop-color="#3A2410"/></linearGradient>` +
    `<radialGradient id="gloss" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#FFFFFF" stop-opacity="1"/><stop offset="0.45" stop-color="#FFFFFF" stop-opacity="0.75"/><stop offset="1" stop-color="#FFFFFF" stop-opacity="0"/></radialGradient>` +
    `<radialGradient id="halo" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#FFFFFF" stop-opacity="0.3"/><stop offset="1" stop-color="#FFFFFF" stop-opacity="0"/></radialGradient>` +
    `<radialGradient id="under" cx="38%" cy="30%" r="78%"><stop offset="0.72" stop-color="#000000" stop-opacity="0"/><stop offset="1" stop-color="#000000" stop-opacity="0.32"/></radialGradient>` +
    `<linearGradient id="lacquer" gradientUnits="userSpaceOnUse" x1="${top[3].x}" y1="${top[0].y}" x2="${top[1].x}" y2="${top[2].y}"><stop offset="0.18" stop-color="${GO.sheen}" stop-opacity="0"/><stop offset="0.36" stop-color="${GO.sheen}" stop-opacity="0.2"/><stop offset="0.5" stop-color="${GO.sheen}" stop-opacity="0.04"/><stop offset="1" stop-color="#1E0F02" stop-opacity="0.2"/></linearGradient>` +
    `<clipPath id="top"><polygon points="${top.map(pt).join(' ')}"/></clipPath>`;

let body = '';
// The block's faces, the far ones first; the two the viewer sees: one turned to the light, one from it.
const faces = [0, 1, 2, 3].map((i) => ({ i, quad: [top[i], top[(i + 1) % 4], bottom[(i + 1) % 4], bottom[i]], depth: (top[i].depth + top[(i + 1) % 4].depth) / 2 }));
faces.sort((a, b) => b.depth - a.depth);
const nearest = faces.slice(-2);
const lit = nearest.reduce((a, b) => ((a.quad[0].x + a.quad[1].x) < (b.quad[0].x + b.quad[1].x) ? a : b));
for (const face of faces) {
    body += poly(face.quad, 'fill="url(#face)"');
    // Straight grain down the cut wood.
    for (let streak = 1; streak <= 14; streak++) {
        const t = grainAt(streak + face.i * 20);
        const w = 0.006 + grainAt(streak + 50) * 0.012;
        const mix = (a, b, f) => ({ x: a.x + (b.x - a.x) * f, y: a.y + (b.y - a.y) * f });
        body += poly([mix(face.quad[0], face.quad[1], t), mix(face.quad[0], face.quad[1], t + w), mix(face.quad[3], face.quad[2], t + w), mix(face.quad[3], face.quad[2], t)], `fill="#1E0F02" opacity="${(0.08 + grainAt(streak + 90) * 0.1).toFixed(3)}"`);
    }
    if (face !== lit) body += poly(face.quad, 'fill="#000" opacity="0.34"');
}
body += poly(top, 'fill="url(#wood)"');
let grain = '';
for (let streak = 1; streak <= 22; streak++) {
    const width = (0.004 + grainAt(streak + 40) * 0.014) * (hi - lo);
    const x = lo + grainAt(streak) * (hi - lo - width);
    grain += poly([project(x, 0, hi), project(x + width, 0, hi), project(x + width, 0, lo), project(x, 0, lo)], `fill="${GO.grain}" opacity="${(0.05 + grainAt(streak + 80) * 0.09).toFixed(3)}"`);
}
body += `<g clip-path="url(#top)">${grain}</g>`;
body += poly(top, 'fill="url(#lacquer)"');
// The light on the block's upper edges, strongest on the two near ones.
const edge = (a, b, o) => `<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" stroke="${GO.sheen}" stroke-width="${(unit * 0.035).toFixed(2)}" opacity="${o}" stroke-linecap="round"/>`;
body += edge(top[3], top[2], 0.7) + edge(top[0], top[3], 0.6) + edge(top[2], top[1], 0.18) + edge(top[0], top[1], 0.18);
// The lines run to the cut on every side.
let lines = '';
for (let i = 0; i < LINES; i++) {
    for (const [a, b] of [[project(i, 0, lo), project(i, 0, hi)], [project(lo, 0, i), project(hi, 0, i)]]) {
        lines += `<line x1="${a.x.toFixed(2)}" y1="${a.y.toFixed(2)}" x2="${b.x.toFixed(2)}" y2="${b.y.toFixed(2)}" stroke="${GO.line}" stroke-width="${(unit * 0.05).toFixed(2)}" stroke-linecap="square"/>`;
    }
}
body += `<g clip-path="url(#top)">${lines}</g>`;
// The stones, the far ones first: shadow, the lens (0.42 as high as wide), the wood's light under its rim, the gloss.
const squash = Math.sin(pitch);
const radius = 0.47;
const lens = 0.42;
const tall = Math.sqrt(squash ** 2 + (lens * Math.cos(pitch)) ** 2);
const placed = ['black', 'white'].flatMap((kind) => stones[kind].map(([x, z]) => ({ kind, key: x + ',' + z, x, z, ground: project(x, 0, z), centre: project(x, radius * lens, z) })));
placed.sort((a, b) => b.ground.depth - a.ground.depth);
for (const stone of placed) {
    const rx = stone.ground.scale * radius;
    const ry = rx * tall;
    const shade = { x: stone.ground.x + rx * 0.2, y: stone.ground.y + rx * 0.16 };
    body += `<ellipse cx="${shade.x.toFixed(2)}" cy="${shade.y.toFixed(2)}" rx="${(rx * 1.22).toFixed(2)}" ry="${(rx * 1.22 * squash).toFixed(2)}" fill="url(#go-stone-shadow)"/>`;
    body += `<ellipse cx="${stone.centre.x.toFixed(2)}" cy="${stone.centre.y.toFixed(2)}" rx="${rx.toFixed(2)}" ry="${ry.toFixed(2)}" fill="url(#go-stone-${stone.kind})" stroke="${EDGE[stone.kind]}" stroke-width="${(rx * 0.03).toFixed(2)}"/>`;
    if (stone.kind === 'white') body += `<ellipse cx="${stone.centre.x.toFixed(2)}" cy="${stone.centre.y.toFixed(2)}" rx="${rx.toFixed(2)}" ry="${ry.toFixed(2)}" fill="url(#under)"/>`;
    const [gx, gy] = [stone.centre.x - rx * 0.34, stone.centre.y - ry * 0.4];
    body += `<ellipse cx="${gx.toFixed(2)}" cy="${gy.toFixed(2)}" rx="${(rx * 0.62).toFixed(2)}" ry="${(ry * 0.5).toFixed(2)}" fill="url(#halo)" opacity="${stone.kind === 'black' ? 1 : 0}"/>`;
    body += `<ellipse cx="${gx.toFixed(2)}" cy="${gy.toFixed(2)}" rx="${(rx * 0.27).toFixed(2)}" ry="${(ry * 0.15).toFixed(2)}" fill="url(#gloss)" opacity="${stone.kind === 'black' ? 0.95 : 0}" transform="rotate(-20 ${gx.toFixed(2)} ${gy.toFixed(2)})"/>`;
    if (stone.key === last) {
        const crown = project(stone.x, radius * lens * 2, stone.z);
        body += `<ellipse cx="${crown.x.toFixed(2)}" cy="${((crown.y + stone.centre.y) / 2).toFixed(2)}" rx="${(rx * 0.46).toFixed(2)}" ry="${(rx * 0.46 * squash).toFixed(2)}" fill="none" stroke="${GO.ring[stone.kind]}" stroke-width="${(rx * 0.2).toFixed(2)}"/>`;
    }
}

const html = `<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;background:transparent}svg{display:block}</style></head><body><svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${W}" viewBox="0 0 ${W} ${W}"><defs>${defs}</defs>${body}</svg></body></html>`;
const page = join(work, 'emblem-go.html');
writeFileSync(page, html);
const png = join(work, 'emblem-go-2048.png');
screenshot(page, png, W, W, true);
mkdirSync(outDir, { recursive: true });
magick(png, '-trim', '+repage', '-resize', '1024x1024', '-quality', '92', '-define', 'webp:alpha-quality=100', join(outDir, 'emblem-go.webp'));
console.log('wrote emblem-go.webp to ' + outDir + ' (screenshot: ' + png + ')');
