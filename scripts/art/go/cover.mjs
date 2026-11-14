// Go's cover (public/images/games/go-{1280,480}.{webp,jpg}): the wooden board with a 9 x 9 game seen from its near
// edge, "GO" and the three sizes as three slabs of the same wood.
//
//   node scripts/art/go/cover.mjs [OUT_DIR]      (from the repo root; default: public/images/games)
//
// Writes go-1280.webp, go-480.webp, go-1280.jpg and go-480.jpg into OUT_DIR; the page and its screenshot stay in the
// system's temp folder. Needs Node, Chromium (/usr/bin/chromium, or CHROMIUM=<path>) and ImageMagick 7 (magick) with
// WebP and JPEG; no npm package. Material and colours: material.mjs, read from resources/js/boardGame.js. Fonts:
// resources/fonts/stream/Unbounded-800.ttf and resources/fonts/og/JetBrainsMono-Bold.ttf.
//
// The four encodings are the ones the committed files were written with (measured 2026-11-14: from the same
// screenshot all four come out byte for byte): WebP quality 92, JPEG quality 86, the large JPEG progressive.
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { GO, ROOT, checkLegal, magick, perspectiveBoard, screenshot } from './material.mjs';

const repo = ROOT;
const outDir = resolve(process.argv[2] || join(ROOT, 'public/images/games'));
const work = mkdtempSync(join(tmpdir(), 'go-cover-'));
const out = join(work, 'cover.html');
// A 9 x 9 game after 17 moves: Black holds the left, White the right, and Black has just cut at f6.
const stones = {
    black: ['c7', 'd6', 'c5', 'e5', 'e4', 'c3', 'e3', 'f2', 'f6'],
    white: ['e7', 'g7', 'e6', 'f5', 'f4', 'h4', 'f3', 'g2'],
};
console.log(checkLegal(9, stones).join('\n'));

const cam = { yaw: -29, pitch: 36, dist: 15.5, focal: 1010, ox: 1040, oy: 384 };
const board = perspectiveBoard({ size: 9, stones, last: 'f6', cam });
console.log('top corners', board.top.map((p) => Math.round(p.x) + ',' + Math.round(p.y)).join(' '));

// The three board sizes as three small slabs of the same wood, each with its number of lines: at 222 px (the lobby
// header) a numeral is still 8 px high, where a line of "9×9 · 13×13 · 19×19" was 4.
const sizes = ['9', '13', '19'];
let x = 90;
let tiles = '';
for (const label of sizes) {
    const width = 100;
    tiles += `<g data-size-tile><rect x="${x}" y="420" width="${width}" height="100" rx="5" fill="${GO.side}"/><rect x="${x}" y="412" width="${width}" height="100" rx="5" fill="url(#tile-wood)"/>` +
        `<line x1="${x + 5}" y1="413" x2="${x + width - 5}" y2="413" stroke="${GO.sheen}" stroke-width="1.5" opacity="0.45"/>` +
        `<text x="${x + width / 2}" y="464" text-anchor="middle" dominant-baseline="central" font-family="JBM" font-weight="700" font-size="64" fill="${GO.label}" data-size-text>${label}</text></g>`;
    x += width + 16;
}

const html = `<!doctype html><html><head><meta charset="utf-8"><style>
@font-face { font-family: UNB; src: url('file://${repo}/resources/fonts/stream/Unbounded-800.ttf'); font-weight: 800; }
@font-face { font-family: JBM; src: url('file://${repo}/resources/fonts/og/JetBrainsMono-Bold.ttf'); font-weight: 700; }
html, body { margin: 0; background: #0A0A0B; }
svg { display: block; }
</style></head><body>
<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">
<defs>${board.defs}
<radialGradient id="halo" gradientUnits="userSpaceOnUse" cx="1000" cy="350" r="640"><stop offset="0" stop-color="#B07C3D" stop-opacity="0.42"/><stop offset="0.55" stop-color="#B07C3D" stop-opacity="0.09"/><stop offset="1" stop-color="#B07C3D" stop-opacity="0"/></radialGradient>
<linearGradient id="tile-wood" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${GO.wood[0]}"/><stop offset="1" stop-color="${GO.wood[1]}"/></linearGradient>
</defs>
<rect width="1280" height="720" fill="#0A0A0B"/>
<rect width="1280" height="720" fill="url(#halo)"/>
${board.body}
<text x="84" y="330" font-family="UNB" font-weight="800" font-size="226" letter-spacing="-4" fill="#FFFFFF" data-title>GO</text>
<rect x="90" y="368" width="120" height="10" rx="5" fill="${GO.wood[0]}" data-rule/>
${tiles}
</svg></body></html>`;
writeFileSync(out, html);

const png = join(work, 'cover-1280.png');
screenshot(out, png, 1280, 720);
mkdirSync(outDir, { recursive: true });
magick(png, '-quality', '92', join(outDir, 'go-1280.webp'));
magick(png, '-resize', '480x270', '-quality', '92', join(outDir, 'go-480.webp'));
magick(png, '-quality', '86', '-interlace', 'JPEG', '-sampling-factor', '4:2:0', join(outDir, 'go-1280.jpg'));
magick(png, '-resize', '480x270', '-quality', '86', join(outDir, 'go-480.jpg'));
console.log('wrote go-1280.webp, go-480.webp, go-1280.jpg, go-480.jpg to ' + outDir + ' (screenshot: ' + png + ')');
