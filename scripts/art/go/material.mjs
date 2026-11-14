// Go's board material as standalone SVG, shared by the cover (cover.mjs) and the broadcast emblem (emblem.mjs).
//
// The colours are not written here: they are read from resources/js/boardGame.js (GO, STONE_EDGE, STONE_GRADIENTS,
// grainAt), the one place the page's board takes them from. That module imports CSS and Alpine code, so Node cannot
// import it; its four declarations are cut out of the source text and evaluated. A declaration that is renamed or
// reshaped stops the generators with an error instead of drawing with stale colours.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/** The repository's root, whatever the working directory is. */
export const ROOT = fileURLToPath(new URL('../../../', import.meta.url)).replace(/\/$/, '');

const source = readFileSync(ROOT + '/resources/js/boardGame.js', 'utf8');
function declaration(pattern, name) {
    const found = source.match(pattern);
    if (!found) throw new Error('resources/js/boardGame.js no longer declares ' + name + ' the way scripts/art/go/material.mjs reads it');
    return found[0];
}
const cut = [
    declaration(/^const GO = \{\n[\s\S]*?\n\};$/m, 'const GO = { … }'),
    declaration(/^const STONE_EDGE = \{[^\n]*\};$/m, 'const STONE_EDGE'),
    declaration(/^const STONE_GRADIENTS =\n[\s\S]*?;$/m, 'const STONE_GRADIENTS'),
    declaration(/^function grainAt\(seed\) \{\n[\s\S]*?\n\}$/m, 'function grainAt(seed)'),
].join('\n');
const board = new Function(cut + '\nreturn { GO, STONE_EDGE, STONE_GRADIENTS, grainAt };')();

export const GO = board.GO;
export const EDGE = board.STONE_EDGE;
export const GRADIENTS = board.STONE_GRADIENTS;
/** A number from 0 to 1 that is the same for the same seed: the grain is the same on every drawing. */
export const grainAt = board.grainAt;

/** Chromium for the screenshot: the system's, or the one named in CHROMIUM. */
export const CHROMIUM = process.env.CHROMIUM || '/usr/bin/chromium';

/** Screenshots a page of exactly width x height CSS pixels into a PNG; `transparent` keeps the ground clear. */
export function screenshot(html, png, width, height, transparent = false) {
    execFileSync(CHROMIUM, [
        '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
        ...(transparent ? ['--default-background-color=00000000'] : []),
        '--window-size=' + width + ',' + height, '--screenshot=' + png, 'file://' + html,
    ], { stdio: 'ignore', timeout: 60_000 });
}

/** ImageMagick 7: magick <args>. */
export function magick(...args) {
    execFileSync('magick', args, { stdio: 'inherit', timeout: 60_000 });
}

const FILES = 'abcdefghjklmnopqrst';
/** "f6" -> [5, 5] (file index, rank index from 0). */
export function at(id) {
    return [FILES.indexOf(id[0]), parseInt(id.slice(1), 10) - 1];
}

/** Every group of the position has a liberty, and the counts fit a game Black began: a position that can be played. */
export function checkLegal(size, stones) {
    const grid = new Map();
    for (const kind of ['black', 'white']) for (const id of stones[kind]) {
        const key = at(id).join(',');
        if (grid.has(key)) throw new Error('two stones on ' + id);
        grid.set(key, kind);
    }
    const seen = new Set();
    const out = [];
    for (const [key, kind] of grid) {
        if (seen.has(key)) continue;
        const group = [key];
        const libs = new Set();
        seen.add(key);
        for (let i = 0; i < group.length; i++) {
            const [x, y] = group[i].split(',').map(Number);
            for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
                const [nx, ny] = [x + dx, y + dy];
                if (nx < 0 || ny < 0 || nx >= size || ny >= size) continue;
                const n = nx + ',' + ny;
                if (!grid.has(n)) libs.add(n);
                else if (grid.get(n) === kind && !seen.has(n)) { seen.add(n); group.push(n); }
            }
        }
        if (libs.size === 0) throw new Error('group without a liberty at ' + key);
        out.push(kind + ' ' + group.length + ' stones, ' + libs.size + ' liberties');
    }
    const diff = stones.black.length - stones.white.length;
    if (diff !== 0 && diff !== 1) throw new Error('stone counts do not fit alternate play: ' + diff);
    return out;
}

/**
 * The board seen from above its near edge. cam: { yaw, pitch (degrees), dist, focal, ox, oy }.
 * Returns { defs, body, corners } - SVG strings in screen pixels.
 */
export function perspectiveBoard({ size, stones, last = null, cam, margin = 0.95, thick = 0.5, id = 'pb' }) {
    const yaw = (cam.yaw * Math.PI) / 180;
    const pitch = (cam.pitch * Math.PI) / 180;
    const mid = (size - 1) / 2;
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
    const [lo, hi] = [-margin, size - 1 + margin];
    const top = [project(lo, 0, hi), project(hi, 0, hi), project(hi, 0, lo), project(lo, 0, lo)];
    const bottom = [project(lo, -thick, hi), project(hi, -thick, hi), project(hi, -thick, lo), project(lo, -thick, lo)];

    let defs = GRADIENTS +
        `<linearGradient id="${id}-wood" gradientUnits="userSpaceOnUse" x1="${top[0].x}" y1="${top[0].y}" x2="${top[2].x}" y2="${top[2].y}"><stop offset="0" stop-color="${GO.wood[0]}"/><stop offset="1" stop-color="${GO.wood[1]}"/></linearGradient>` +
        `<linearGradient id="${id}-far" gradientUnits="userSpaceOnUse" x1="0" y1="${Math.min(top[0].y, top[1].y)}" x2="0" y2="${Math.max(top[2].y, top[3].y)}"><stop offset="0" stop-color="#1E0F02" stop-opacity="0.22"/><stop offset="0.55" stop-color="#1E0F02" stop-opacity="0"/><stop offset="1" stop-color="${GO.sheen}" stop-opacity="0.07"/></linearGradient>` +
        `<filter id="${id}-blur" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="${cam.focal / 60}"/></filter>` +
        `<clipPath id="${id}-top"><polygon points="${top.map(pt).join(' ')}"/></clipPath>`;

    let body = '';
    // The slab's shadow on the ground.
    const drop = bottom.map((p) => ({ x: p.x + cam.focal * 0.012, y: p.y + cam.focal * 0.02 }));
    body += poly(drop, `fill="#000" opacity="0.75" filter="url(#${id}-blur)"`);
    // The slab's faces, the far ones first: the lower face of the wood (GO.side), the one turned from the light darker.
    const faces = [0, 1, 2, 3].map((i) => ({ i, quad: [top[i], top[(i + 1) % 4], bottom[(i + 1) % 4], bottom[i]], depth: (top[i].depth + top[(i + 1) % 4].depth) / 2 }));
    faces.sort((a, b) => b.depth - a.depth);
    for (const face of faces) {
        body += poly(face.quad, `fill="${GO.side}"`);
        if (face.i !== 2) body += poly(face.quad, 'fill="#000" opacity="0.38"');
    }
    body += poly(top, `fill="url(#${id}-wood)"`);
    // Straight grain along the board, the same streaks as on the page.
    let grain = '';
    for (let streak = 1; streak <= 28; streak++) {
        const width = (0.002 + grainAt(streak + 40) * 0.009) * (hi - lo);
        const x = lo + 0.08 + grainAt(streak) * (hi - lo - 0.16 - width);
        grain += poly([project(x, 0, hi), project(x + width, 0, hi), project(x + width, 0, lo), project(x, 0, lo)], `fill="${GO.grain}" opacity="${(0.05 + grainAt(streak + 80) * 0.09).toFixed(3)}"`);
    }
    body += `<g clip-path="url(#${id}-top)">${grain}</g>`;
    body += poly(top, `fill="url(#${id}-far)"`);
    // The light on the slab's near upper edges.
    const edge = (a, b, o) => `<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" stroke="${GO.sheen}" stroke-width="${(cam.focal / 700).toFixed(2)}" opacity="${o}" stroke-linecap="round"/>`;
    body += edge(top[3], top[2], 0.55) + edge(top[2], top[1], 0.25) + edge(top[0], top[3], 0.25);
    // Lines and star points.
    for (let i = 0; i < size; i++) {
        const outer = i === 0 || i === size - 1;
        for (const [a, b] of [[project(i, 0, 0), project(i, 0, size - 1)], [project(0, 0, i), project(size - 1, 0, i)]]) {
            const width = ((a.scale + b.scale) / 2) * (outer ? 0.045 : 0.026);
            body += `<line x1="${a.x.toFixed(2)}" y1="${a.y.toFixed(2)}" x2="${b.x.toFixed(2)}" y2="${b.y.toFixed(2)}" stroke="${GO.line}" stroke-width="${width.toFixed(2)}" stroke-linecap="square"/>`;
        }
    }
    const squash = Math.sin(pitch);
    const stars = size === 9 ? [[2, 2], [6, 2], [4, 4], [2, 6], [6, 6]] : size === 13 ? [[3, 3], [9, 3], [6, 6], [3, 9], [9, 9]] : [3, 9, 15].flatMap((x) => [3, 9, 15].map((y) => [x, y]));
    for (const [x, z] of stars) {
        const p = project(x, 0, z);
        body += `<ellipse cx="${p.x.toFixed(2)}" cy="${p.y.toFixed(2)}" rx="${(p.scale * 0.1).toFixed(2)}" ry="${(p.scale * 0.1 * squash).toFixed(2)}" fill="${GO.line}"/>`;
    }
    // The stones, the far ones first: a shadow on the wood, then the stone as the lens it is (0.42 as high as wide).
    const radius = 0.47;
    const lens = 0.42;
    const all = ['black', 'white'].flatMap((kind) => stones[kind].map((name) => ({ kind, name, cell: at(name) })));
    const placed = all.map((stone) => ({ ...stone, ground: project(stone.cell[0], 0, stone.cell[1]), centre: project(stone.cell[0], radius * lens, stone.cell[1]) }));
    placed.sort((a, b) => b.ground.depth - a.ground.depth);
    const tall = Math.sqrt(squash ** 2 + (lens * Math.cos(pitch)) ** 2);
    for (const stone of placed) {
        const rx = stone.ground.scale * radius;
        const shade = project(stone.cell[0] + 0.07, 0, stone.cell[1] - 0.09);
        body += `<ellipse cx="${shade.x.toFixed(2)}" cy="${shade.y.toFixed(2)}" rx="${(rx * 1.2).toFixed(2)}" ry="${(rx * 1.2 * squash).toFixed(2)}" fill="url(#go-stone-shadow)"/>`;
        body += `<ellipse cx="${stone.centre.x.toFixed(2)}" cy="${stone.centre.y.toFixed(2)}" rx="${rx.toFixed(2)}" ry="${(rx * tall).toFixed(2)}" fill="url(#go-stone-${stone.kind})" stroke="${EDGE[stone.kind]}" stroke-width="${Math.max(1, rx * 0.03).toFixed(2)}" data-stone="${stone.name}"/>`;
        if (stone.name === last) {
            const crown = project(stone.cell[0], radius * lens * 2, stone.cell[1]);
            body += `<ellipse cx="${crown.x.toFixed(2)}" cy="${((crown.y + stone.centre.y) / 2).toFixed(2)}" rx="${(rx * 0.43).toFixed(2)}" ry="${(rx * 0.43 * squash).toFixed(2)}" fill="none" stroke="${GO.ring[stone.kind]}" stroke-width="${(rx * 0.16).toFixed(2)}" data-last="1"/>`;
        }
    }
    return { defs, body, top, bottom };
}
