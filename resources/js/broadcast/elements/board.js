/**
 * The tournament board (SLOTS.board, plan P4): a column of glass slabs in the left strip, 40 px clear of the stream,
 * that shows one page at a time. A page is the round on now (its matches), a table's top rows, the heats, the
 * countdown to the start, or the prize pot. Pages take turns at page boundaries only; a page never changes while it
 * is read (a new result shows on the next page, and right away as a corner moment).
 *
 * Hierarchy inside a page: the head slab names the round (Unbounded 700, white, a hot edge on its left); a match card
 * puts the names first (Unbounded 600, white; the side that lost steps back to grey), the score in JetBrains Mono at
 * the right, the seed small and grey before the name. The winner's row carries a hot edge, a match on now carries one
 * down the card and a "Live" tab on its top edge; nothing is marked by colour alone.
 *
 * Build-in (TIMING.boardIntroMs = 900): the head is set (swing from 80 deg about its left edge, 0-460), the cards
 * follow 60 ms apart (each 380 ms), each card's words reveal 120 ms after it is set (300 ms); all done by ~800 ms.
 * Build-out (TIMING.boardOutroMs = 600): words cover (0-220), cards and head swing away (100-560).
 */

import { CURVES, span } from '../curves.js';
import { createHeat, createImage, createSlab } from '../materials.js';
import { createCanvasPlane, createLine, disposeTree } from '../text.js';
import { COLOR, SLOTS, TYPE } from '../tokens.js';
import { TIMING, holdForTexts } from '../timing.js';
import { DEG, place } from './lowerThird.js';

const W = SLOTS.board.w;
const HEAD_H = 52;
const CARD_H = 88;
const ROW_H = 40;
const GAP = 14;
const SEED = { family: 'mono', weight: 500, size: 20, leading: 1.2, tracking: 0 };
const NAME = { family: 'display', weight: 600, size: 22, leading: 1.2, tracking: 0 };
const SCORE = { family: 'mono', weight: 500, size: 24, leading: 1.2, tracking: 0 };
const DIGITS = { family: 'display', weight: 800, size: 46 };

/** Width of a line's type box (without its padding). */
const boxW = (line) => line.width - line.pad * 2;

function fontOf(style) {
    const kind = style.family === 'mono' ? '--font-face-mono' : '--font-face-display';
    const family = getComputedStyle(document.documentElement).getPropertyValue(kind).trim() || (style.family === 'mono' ? "'JetBrains Mono'" : "'Unbounded'");

    return `${style.weight} ${style.size}px ${family}`;
}

/** An SVG document as an image for the 2D canvas. */
function svgImage(svg, onLoad) {
    const img = new Image();
    img.addEventListener('load', onLoad, { once: true });
    img.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;

    return img;
}

/** A small chip of light ("Live"): dark words on orange, chamfered, drawn like the ticker's chip. */
export function createChip(stage, text) {
    const probe = document.createElement('canvas').getContext('2d');
    probe.font = fontOf(TYPE.head);
    const w = Math.ceil(probe.measureText(text).width + 20);
    const h = 26;

    return createCanvasPlane(stage, w, h, (ctx) => {
        const g = ctx.createLinearGradient(0, 0, 0, h);
        g.addColorStop(0, COLOR.btcHi);
        g.addColorStop(1, COLOR.btc);
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(w - 7, 0);
        ctx.lineTo(w, 7);
        ctx.lineTo(w, h);
        ctx.lineTo(0, h);
        ctx.closePath();
        ctx.fill();
        ctx.font = fontOf(TYPE.head);
        ctx.fillStyle = COLOR.onBtc;
        ctx.textBaseline = 'middle';
        ctx.fillText(text, 10, h / 2 + 1);
    });
}

/**
 * The countdown's digits on a fixed-size plane: every character sits in a fixed cell, so the digits change in place
 * and nothing on the page moves while it counts.
 */
function createDigits(stage, w, h) {
    let text = '';
    const plane = createCanvasPlane(stage, w, h, (ctx) => {
        ctx.font = fontOf(DIGITS);
        ctx.fillStyle = COLOR.ink;
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'center';
        const cell = (c) => (c === ':' ? 14 : 34);
        const total = [...text].reduce((n, c) => n + cell(c), 0);
        let x = Math.max(0, (w - total) / 2);
        [...text].forEach((c) => {
            ctx.fillText(c, x + cell(c) / 2, h / 2 + 2);
            x += cell(c);
        });
    });
    plane.set = (next) => {
        if (next === text) return;
        text = next;
        plane.redraw();
    };

    return plane;
}

/** "02:14:09" / "14:09": the time left until `startsAt`, never below zero. */
export function countdownText(startsAt, now = Date.now()) {
    const left = Math.max(0, Math.floor((new Date(startsAt).getTime() - now) / 1000));
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    const s = left % 60;
    const two = (n) => String(n).padStart(2, '0');

    return h > 0 ? `${two(h)}:${two(m)}:${two(s)}` : `${two(m)}:${two(s)}`;
}

/**
 * One page on the board. `page`: { kind: matches|table|heats|countdown|pot, title, ... } (resources/js/broadcast/
 * variants/tournament.js builds them from the snapshot), `texts`: the words the page itself adds.
 */
export function createBoardPage(stage, timeline, { start, page, words, art }) {
    const { THREE } = stage;
    const slot = SLOTS.board;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const slabs = [];
    const lines = [];
    const extras = [];
    const texts = [];
    let digits = null;
    const line = (text, style, opts) => {
        const l = createLine(stage, String(text), style, opts);
        texts.push(String(text));

        return l;
    };

    /** A slab at y (from the board's top) with its words; `cardIndex` staggers its build-in. */
    const slab = (y, h, { corner = 'br', metal = false, heat = false } = {}) => {
        const s = createSlab(THREE, W, h, { corner, depth: 18, top: metal ? COLOR.metal : undefined, bottom: metal ? '#1D1D22' : undefined, spill: heat ? 'left' : null });
        s.group.position.y = -y;
        root.add(s.group);
        const entry = { s, y, words: [], heats: [], extras: [], index: slabs.length };
        if (heat) {
            const bar = createHeat(THREE, 4, h);
            bar.position.set(-4, 0, 1);
            s.pivot.add(bar);
            entry.heats.push(bar);
        }
        slabs.push(entry);

        return entry;
    };
    const put = (entry, l, left, top) => {
        place(l, left, top);
        entry.s.pivot.add(l.mesh);
        entry.words.push(l);
        lines.push(l);
    };
    const putRight = (entry, l, right, top) => put(entry, l, W - right - boxW(l), top);

    // Head: the round (or what the page is), white on metal, the hot edge on its left.
    const head = slab(0, HEAD_H, { corner: 'tr', metal: true, heat: true });
    put(head, line(page.title, TYPE.tag, { color: COLOR.ink, maxWidth: W - 40 }), 18, (HEAD_H - TYPE.tag.size * TYPE.tag.leading) / 2);
    let y = HEAD_H + GAP;

    if (page.kind === 'matches' || page.kind === 'heats') {
        (page.matches || []).forEach((m) => {
            const sides = page.kind === 'heats' ? [...m.sides].sort((a, b) => (parseInt(String(a.score).replace('#', ''), 10) || 99) - (parseInt(String(b.score).replace('#', ''), 10) || 99)).slice(0, 3) : m.sides.slice(0, 2);
            const h = page.kind === 'heats' ? 34 + sides.length * ROW_H + 6 : CARD_H;
            const card = slab(y, h, { heat: !!m.live });
            let top = 6;
            if (page.kind === 'heats') {
                put(card, line(m.round, TYPE.head, { color: COLOR.ink2, maxWidth: W - 32 }), 16, 6);
                top = 34;
            }
            if (m.live) {
                const chip = createChip(stage, words.live);
                chip.mesh.renderOrder = 6;
                // A tab on the card's top edge at the right: the match is on, not one side of it. It stands 12 px
                // into the 14 px gap above and ends above the first name's glyphs, so both names keep their width.
                place(chip, W - 18 - chip.width, top - 18);
                card.s.pivot.add(chip.mesh);
                card.extras.push(chip);
                texts.push(words.live);
            }
            sides.forEach((side, i) => {
                const rowTop = top + i * ROW_H;
                const decided = m.status === 'done';
                const dim = decided && !side.won;
                const seedW = side.seed ? 30 : 0;
                if (side.seed) put(card, line(String(side.seed), SEED, { color: COLOR.ink3 }), 14, rowTop + (ROW_H - SEED.size * SEED.leading) / 2);
                const scoreText = side.score ?? '';
                let scoreW = 0;
                if (scoreText !== '' && !m.live) {
                    const sc = line(scoreText, SCORE, { color: dim ? COLOR.ink3 : COLOR.ink });
                    putRight(card, sc, 16, rowTop + (ROW_H - SCORE.size * SCORE.leading) / 2);
                    scoreW = boxW(sc) + 16;
                }
                // 16 px of air between a name and its score or the chip; a long name shrinks, then ends in an ellipsis.
                const nameMax = W - 14 - seedW - 16 - scoreW;
                put(card, line(side.name, NAME, { color: !side.known || dim ? COLOR.ink3 : COLOR.ink, maxWidth: nameMax }), 14 + seedW, rowTop + (ROW_H - NAME.size * NAME.leading) / 2);
                if (decided && side.won) {
                    const bar = createHeat(THREE, 4, ROW_H - 12);
                    bar.position.set(0, -(rowTop + 6), 1);
                    card.s.pivot.add(bar);
                    card.heats.push(bar);
                }
            });
            y += h + GAP;
        });
    } else if (page.kind === 'table') {
        (page.rows || []).forEach((row, i) => {
            const r = slab(y, ROW_H, { heat: i === 0, corner: null });
            put(r, line(String(row.rank), SEED, { color: COLOR.ink2 }), 14, (ROW_H - SEED.size * SEED.leading) / 2);
            const pts = line(row.points, SCORE, { color: COLOR.ink });
            putRight(r, pts, 16, (ROW_H - SCORE.size * SCORE.leading) / 2);
            put(r, line(row.name, NAME, { color: COLOR.ink, maxWidth: W - 44 - boxW(pts) - 28 }), 44, (ROW_H - NAME.size * NAME.leading) / 2);
            y += ROW_H + 6;
        });
        if (page.note) {
            const n = slab(y + 4, 40, { corner: null });
            put(n, line(page.note, TYPE.head, { color: COLOR.ink2, maxWidth: W - 32 }), 16, (40 - TYPE.head.size * TYPE.head.leading) / 2);
        }
    } else if (page.kind === 'countdown') {
        const h = page.qr ? 452 : 238;
        const card = slab(y, h, { heat: true });
        put(card, line(page.label, TYPE.line, { color: COLOR.ink2, maxWidth: W - 32 }), 18, 16);
        digits = createDigits(stage, W - 24, 72);
        digits.set(page.startsAt ? countdownText(page.startsAt) : page.big);
        place(digits, 12, 58);
        card.s.pivot.add(digits.mesh);
        card.extras.push(digits);
        texts.push(page.startsAt ? '00:00' : page.big);
        put(card, line(page.when, TYPE.data, { color: COLOR.ink, maxWidth: W - 32 }), 18, 140);
        if (page.entries) put(card, line(page.entries, TYPE.data, { color: COLOR.ink2, maxWidth: W - 32 }), 18, 180);
        if (page.qr) {
            const S = 168;
            let plane = null;
            const img = svgImage(page.qr, () => plane && plane.redraw());
            plane = createCanvasPlane(stage, S, S, (ctx) => {
                ctx.fillStyle = '#FFFFFF';
                ctx.beginPath();
                ctx.roundRect(0, 0, S, S, 6);
                ctx.fill();
                if (!img.complete || !img.naturalWidth) return;
                ctx.imageSmoothingEnabled = false;
                ctx.drawImage(img, 4, 4, S - 8, S - 8);
            });
            place(plane, (W - S) / 2, 228);
            card.s.pivot.add(plane.mesh);
            card.extras.push(plane);
            put(card, line(page.scan, TYPE.head, { color: COLOR.ink2, maxWidth: W - 32 }), (W - Math.min(W - 32, 999)) / 2, 228 + S + 14);
        }
    } else if (page.kind === 'pot') {
        const card = slab(y, 330, { heat: true });
        const cup = createImage(THREE, art.trophy, 150, 180, { rim: 1.1, rimFrom: [1, 0.3] });
        cup.position.set(W / 2, -110, 12);
        card.s.pivot.add(cup);
        extras.push(cup);
        put(card, line(page.amount, TYPE.title, { color: COLOR.ink, maxWidth: W - 32 }), 18, 210);
        put(card, line(page.unit, TYPE.line, { color: COLOR.ink2, maxWidth: W - 32 }), 18, 266);
    }

    const allTexts = [page.title, ...texts.slice(1)];
    const holdRule = holdForTexts(allTexts);
    const seg = timeline.add({
        kind: 'board', slot: 'board', texts: allTexts, start, introMs: TIMING.boardIntroMs,
        holdMs: Math.max(holdRule, page.holdMs || TIMING.boardPageMs), outroMs: TIMING.boardOutroMs, extra: { page: page.kind },
    });
    const meshes = [...lines.map((l) => l.mesh), ...slabs.flatMap((e) => e.extras.map((x) => x.mesh))];

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        if (root.visible && digits && page.startsAt) digits.set(countdownText(page.startsAt));
        slabs.forEach((e) => {
            let k = 1, w = 1, fade = 1;
            const s0 = e.index === 0 ? 0 : 120 + (e.index - 1) * 60;
            if (ph.name === 'intro') {
                k = CURVES.set(span(ph.t, s0, e.index === 0 ? 460 : 380));
                w = CURVES.reveal(span(ph.t, s0 + 120, 300));
            } else if (ph.name === 'outro') {
                w = 1 - CURVES.leave(span(ph.t, 0, 220));
                k = 1 - CURVES.leave(span(ph.t, 100 + Math.min(6, e.index) * 30, 280));
                fade = 1 - span(ph.t, 420, 160);
            }
            e.s.pivot.rotation.y = (1 - k) * 80 * DEG;
            e.s.setOpacity(Math.min(1, k * 1.4) * fade);
            e.heats.forEach((h) => { h.material.opacity = Math.min(1, k * 1.4) * fade; });
            e.words.forEach((l) => { l.material.uniforms.reveal.value = w; l.material.uniforms.opacity.value = fade; });
            e.extras.forEach((x) => { x.material.uniforms.opacity.value = Math.min(1, w * 1.2) * fade; });
        });
        extras.forEach((x) => { x.material.uniforms.opacity.value = Math.min(1, slabs[slabs.length - 1].s.front.uniforms.opacity.value) * (ph.name === 'outro' ? 1 - span(ph.t, 0, 300) : 1); });
        timeline.observe(seg, ph, t, meshes);
    }

    return {
        seg,
        update,
        retire(t) { timeline.endAt(seg, t); },
        dispose() {
            stage.scene.remove(root);
            disposeTree(root);
        },
    };
}
