/**
 * A panel page (plan P5, the break scene's right column): a head slab and a stack of glass rows in any slot, one page
 * at a time, built from a plain spec so a section (next matches, the ladders' leaders, what comes up, the pot) is data,
 * not code. The materials, curves and reading rule are the board's (resources/js/broadcast/elements/board.js), only
 * the slot and the sizes are free.
 *
 * Spec: { title, rows: [{ h, heat, metal, items }] }; an item is one of
 *   { text, style, color, left | right, top, maxWidth }   a line (right: its type box ends `right` px from the edge)
 *   { image, w, h, x, y, rim }                           an asset of the pack, centred on (x, y) of the row
 *   { chip, left | right, top }                          a small light tab ("Live")
 *   { bar, top, h }                                      a hot edge on the row's left (a winner, the leader)
 *   { plane, left, top }                                 a drawn plane made by plane(stage) (a QR tile)
 *
 * Build-in (TIMING.panelIntroMs = 1000): the head is set (swing from 80 deg about its left edge, 0-460), the rows
 * follow 70 ms apart (420 ms each), each row's words reveal 140 ms after it is set (320 ms). Build-out
 * (TIMING.panelOutroMs = 600): words cover (0-220), rows and head swing away (100-560). Hold: nothing on a word moves.
 */

import { CURVES, span } from '../curves.js';
import { createHeat, createImage, createSlab } from '../materials.js';
import { createLine, disposeTree } from '../text.js';
import { COLOR } from '../tokens.js';
import { TIMING, holdForTexts } from '../timing.js';
import { createChip } from './board.js';
import { DEG, place } from './lowerThird.js';

export const PANEL_HEAD = { family: 'display', weight: 700, size: 30, leading: 1.2, tracking: 0 };
const HEAD_H = 68;
const GAP = 12;

/** Width of a line's type box (without its padding). */
export const boxW = (line) => line.width - line.pad * 2;

export function createPanelPage(stage, timeline, { start, slot, title, rows = [], holdMs = 0, kind = 'panel', extra = {} }) {
    const { THREE } = stage;
    const W = slot.w;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const slabs = [];
    const lines = [];
    const texts = [];
    const images = [];

    const slab = (y, h, { corner = 'br', metal = false, heat = false } = {}) => {
        const s = createSlab(THREE, W, h, { corner, depth: 22, top: metal ? COLOR.metal : undefined, bottom: metal ? '#1D1D22' : undefined, spill: heat ? 'left' : null });
        s.group.position.y = -y;
        root.add(s.group);
        const entry = { s, words: [], heats: [], planes: [], images: [], index: slabs.length };
        if (heat) {
            const bar = createHeat(THREE, 5, h);
            bar.position.set(-5, 0, 1);
            s.pivot.add(bar);
            entry.heats.push(bar);
        }
        slabs.push(entry);

        return entry;
    };

    const head = slab(0, HEAD_H, { corner: 'tr', metal: true, heat: true });
    const headLine = createLine(stage, String(title), PANEL_HEAD, { color: COLOR.ink, maxWidth: W - 56 });
    place(headLine, 28, (HEAD_H - PANEL_HEAD.size * PANEL_HEAD.leading) / 2);
    head.s.pivot.add(headLine.mesh);
    head.words.push(headLine);
    lines.push(headLine);
    texts.push(String(title));

    let y = HEAD_H + GAP + 4;
    rows.forEach((row) => {
        const entry = slab(y, row.h, { heat: !!row.heat, metal: !!row.metal, corner: row.corner === undefined ? 'br' : row.corner });
        (row.items || []).forEach((it) => {
            if (it.text !== undefined) {
                const l = createLine(stage, String(it.text), it.style, { color: it.color || COLOR.ink, maxWidth: it.maxWidth || W - 48 });
                const left = it.right !== undefined ? W - it.right - boxW(l) : it.left;
                place(l, left, it.top);
                entry.s.pivot.add(l.mesh);
                entry.words.push(l);
                lines.push(l);
                if (!it.silent) texts.push(String(it.text));
            } else if (it.image) {
                const img = createImage(THREE, it.image, it.w, it.h, { rim: it.rim ?? 0.9, rimFrom: [1, 0.3] });
                img.position.set(it.x, -it.y, 10);
                entry.s.pivot.add(img);
                entry.images.push(img);
                images.push(img);
            } else if (it.chip) {
                const chip = createChip(stage, it.chip);
                chip.mesh.renderOrder = 6;
                place(chip, it.right !== undefined ? W - it.right - chip.width : it.left, it.top);
                entry.s.pivot.add(chip.mesh);
                entry.planes.push(chip);
                texts.push(it.chip);
            } else if (it.bar) {
                const bar = createHeat(THREE, 5, it.h);
                bar.position.set(0, -it.top, 1);
                entry.s.pivot.add(bar);
                entry.heats.push(bar);
            } else if (it.plane) {
                const p = it.plane(stage);
                place(p, it.left, it.top, 2);
                entry.s.pivot.add(p.mesh);
                entry.planes.push(p);
            }
        });
        y += row.h + (row.gap ?? GAP);
    });

    const seg = timeline.add({
        kind, slot: kind, texts, start, introMs: TIMING.panelIntroMs,
        holdMs: Math.max(holdForTexts(texts), holdMs), outroMs: TIMING.panelOutroMs, extra: { height: y, ...extra },
    });
    const meshes = [...lines.map((l) => l.mesh), ...slabs.flatMap((e) => e.planes.map((p) => p.mesh))];

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        slabs.forEach((e) => {
            let k = 1, w = 1, fade = 1;
            const s0 = e.index === 0 ? 0 : 110 + (e.index - 1) * 70;
            if (ph.name === 'intro') {
                k = CURVES.set(span(ph.t, s0, e.index === 0 ? 460 : 420));
                w = CURVES.reveal(span(ph.t, s0 + 140, 320));
            } else if (ph.name === 'outro') {
                w = 1 - CURVES.leave(span(ph.t, 0, 220));
                k = 1 - CURVES.leave(span(ph.t, 100 + Math.min(6, e.index) * 30, 300));
                fade = 1 - span(ph.t, 420, 160);
            } else if (ph.name === 'hold') {
                // A light sweep crosses each glass row in turn every 7 s; the words under it do not move.
                const cycle = (ph.t + e.index * 140) % 7000;
                e.s.front.uniforms.sweep.value = -0.4 + 1.8 * CURVES.sweep(span(cycle, 3000, 1300));
            }
            e.s.pivot.rotation.y = (1 - k) * 80 * DEG;
            e.s.setOpacity(Math.min(1, k * 1.4) * fade);
            e.heats.forEach((h) => { h.material.opacity = Math.min(1, k * 1.4) * fade; });
            e.words.forEach((l) => { l.material.uniforms.reveal.value = w; l.material.uniforms.opacity.value = fade; });
            e.planes.forEach((p) => { p.material.uniforms.opacity.value = Math.min(1, w * 1.2) * fade; });
            e.images.forEach((img) => { img.material.uniforms.opacity.value = Math.min(1, w * 1.3) * fade; });
        });
        timeline.observe(seg, ph, t, meshes);
    }

    return {
        seg,
        update,
        images,
        retire(t) { timeline.endAt(seg, t); },
        dispose() {
            stage.scene.remove(root);
            disposeTree(root);
        },
    };
}
