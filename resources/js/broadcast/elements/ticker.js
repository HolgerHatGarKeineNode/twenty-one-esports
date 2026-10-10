/**
 * The ticker (SLOTS.ticker): the league's live chip (its "21" mark and the label on orange, chamfered towards the rail)
 * and a glass rail along the foot of the frame. The crawl is made of segments: an emblem (a game's, the trophy, or the
 * league mark), a metal chip naming what kind of news follows, the news itself, and a slanted orange cut before the
 * next segment. Everything on the rail is drawn by the same material as its words, so it fades out at both ends
 * together. The crawl is a conveyor: segments are laid out one after the other as the rail needs them and dropped once
 * they have left it on the left. Each segment is laid from the list `source()` returns at that moment, so a fresh
 * snapshot or feed rebuilds the crawl seamlessly: off-screen, from the segment after the last one laid, never in the
 * middle of a word on screen (P3). `seg.loops` counts the passes through the list, `seg.rebuilt` the times the list
 * changed under the crawl.
 *
 * Speed: TIMING.tickerPxPerS (64 logical px/s, cap 80). The crawl is the one place text moves while read, as the
 * plan allows it: slow enough that a word stays ~25 s on the rail. Its speed is measured from the meshes' real
 * positions every second (seg.observedPxPerS) for the tempo test. A sweep and its flare cross the rail every 9 s.
 */

import { CURVES, span } from '../curves.js';
import { drawMark } from '../mark.js';
import { createFlare, createHeat, createSlab } from '../materials.js';
import { createCanvasPlane, createLine, disposeTree, placeLine } from '../text.js';
import { COLOR, SLOTS, TYPE } from '../tokens.js';
import { TIMING } from '../timing.js';
import { DEG, flareAt, place } from './lowerThird.js';

const images = new Map();

/** An image for a canvas plane: calls back (once per plane) when it can be drawn. */
function imageFor(url, onLoad) {
    if (!images.has(url)) {
        const img = new Image();
        img.decoding = 'async';
        img.src = url;
        images.set(url, img);
    }
    const img = images.get(url);
    if (!img.complete) img.addEventListener('load', onLoad, { once: true });

    return img;
}

function fontOf(style) {
    const kind = style.family === 'mono' ? '--font-face-mono' : '--font-face-display';
    const family = getComputedStyle(document.documentElement).getPropertyValue(kind).trim() || (style.family === 'mono' ? "'JetBrains Mono'" : "'Unbounded'");

    return `${style.weight} ${style.size}px ${family}`;
}

/** A segment's head: label in a dark metal chip with an orange tick on its left. */
function createHead(stage, text) {
    const style = TYPE.head;
    const probe = document.createElement('canvas').getContext('2d');
    probe.font = fontOf(style);
    const w = Math.ceil(probe.measureText(text).width + 30);
    const h = 30;

    return createCanvasPlane(stage, w, h, (ctx) => {
        const g = ctx.createLinearGradient(0, 0, 0, h);
        g.addColorStop(0, '#34343B');
        g.addColorStop(1, '#222227');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.roundRect(0.5, 0.5, w - 1, h - 1, 3);
        ctx.fill();
        ctx.strokeStyle = 'rgba(255,255,255,0.14)';
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.fillStyle = COLOR.btc;
        ctx.fillRect(0, 0, 4, h);
        ctx.font = fontOf(style);
        ctx.fillStyle = COLOR.ink;
        ctx.textBaseline = 'middle';
        ctx.fillText(text, 16, h / 2 + 1);
    });
}

/** A segment's emblem, 40 px: an asset of the pack, or the league mark for `mark`. */
function createEmblem(stage, emblem) {
    const S = 40;
    let plane = null;
    const img = emblem === 'mark' ? null : imageFor(emblem, () => plane && plane.redraw());
    plane = createCanvasPlane(stage, S, S, (ctx) => {
        if (!img) {
            drawMark(ctx, 4, 4, S - 8);

            return;
        }
        if (!img.complete || !img.naturalWidth) return;
        const a = img.naturalWidth / img.naturalHeight;
        const [w, h] = a > 1 ? [S, S / a] : [S * a, S];
        ctx.drawImage(img, (S - w) / 2, (S - h) / 2, w, h);
    });

    return plane;
}

/** The cut between segments: a slanted bar of orange, the same 18 deg as the stinger's shutter. */
function createCut(stage) {
    const w = 18;
    const h = 28;

    return createCanvasPlane(stage, w, h, (ctx) => {
        const g = ctx.createLinearGradient(0, 0, 0, h);
        g.addColorStop(0, COLOR.btcHi);
        g.addColorStop(1, COLOR.btcDeep);
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.moveTo(9, 0);
        ctx.lineTo(18, 0);
        ctx.lineTo(9, h);
        ctx.lineTo(0, h);
        ctx.closePath();
        ctx.fill();
    });
}

export function createTicker(stage, timeline, { start, label, items, source = null }) {
    const { THREE } = stage;
    const slot = SLOTS.ticker;
    const H = slot.h;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);
    const normalize = (list) => (list || []).map((it) => (typeof it === 'string' ? { text: it } : it)).filter((it) => it && it.text);
    let list = normalize(items);

    // The chip: mark + label on orange glass-metal, chamfered on the corner towards the rail.
    const chipText = createLine(stage, label, TYPE.tag, { color: COLOR.onBtc });
    const markSize = 28;
    const chipMark = createCanvasPlane(stage, markSize, markSize, (ctx) => drawMark(ctx, 0, 0, markSize, { tile: false, ink: COLOR.onBtc }));
    const chipW = Math.ceil(16 + markSize + 10 + chipText.width - chipText.pad * 2 + 28);
    const chipFace = new THREE.ShaderMaterial({
        uniforms: { opacity: { value: 1 }, sweep: { value: -1 }, a: { value: new THREE.Color(COLOR.btcHi) }, b: { value: new THREE.Color(COLOR.btc) }, size: { value: new THREE.Vector2(chipW, H) } },
        vertexShader: 'varying vec3 vPos; void main() { vPos = position; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader: `uniform float opacity; uniform float sweep; uniform vec3 a; uniform vec3 b; uniform vec2 size; varying vec3 vPos;
            void main() { vec2 q = vec2(vPos.x / size.x, (vPos.y + size.y) / size.y); vec3 c = mix(b, a, q.y * 0.45);
              c += vec3(0.30) * smoothstep(size.y - 2.0, size.y, vPos.y + size.y);
              float d = q.x - sweep + (1.0 - q.y) * 0.25; c += vec3(0.32) * exp(-d * d * 80.0); gl_FragColor = vec4(c, opacity); }`,
        transparent: true,
        depthWrite: false,
    });
    const chip = createSlab(THREE, chipW, H, { face: chipFace, depth: 14, corner: 'tr' });
    root.add(chip.group);
    place(chipMark, 16, (H - markSize) / 2);
    place(chipText, 16 + markSize + 10, (H - TYPE.tag.size * TYPE.tag.leading) / 2);
    chip.pivot.add(chipMark.mesh, chipText.mesh);

    const railX = chipW + 8;
    const railW = slot.w - railX;
    const rail = createSlab(THREE, railW, H, { depth: 14 });
    rail.group.position.x = railX;
    root.add(rail.group);
    const under = createHeat(THREE, chipW, 3);
    under.position.set(0, -H - 3, 0);
    root.add(under);
    const flare = createFlare(THREE, 380, 20);
    flare.position.set(railX, -3, 3);
    root.add(flare);

    // The conveyor: parts in strip coordinates (x grows to the right without end), created ahead of the rail's
    // right end and dropped behind its left end.
    const crawl = new THREE.Group();
    crawl.position.set(railX, 0, 1);
    root.add(crawl);
    const parts = [];
    let tail = 0;
    let cursor = 0;
    let itemK = 0;
    const push = (plane, w, top, gapAfter) => {
        crawl.add(plane.mesh);
        plane.material.uniforms.clipFade.value = 56;
        plane.material.uniforms.opacity.value = itemK;
        parts.push({ plane, x: tail, w, top });
        tail += w + gapAfter;
    };
    const append = (sgm) => {
        if (sgm.emblem) push(createEmblem(stage, sgm.emblem), 40, (H - 40) / 2, 12);
        if (sgm.head) {
            const head = createHead(stage, sgm.head);
            push(head, head.width, (H - head.height) / 2, 14);
        }
        const l = createLine(stage, sgm.text, TYPE.crawl, { color: COLOR.ink });
        push(l, l.width - l.pad * 2, (H - TYPE.crawl.size * TYPE.crawl.leading) / 2, 40);
        push(createCut(stage), 18, (H - 28) / 2, 40);
    };
    const keyOf = (l) => l.map((it) => `${it.head}|${it.text}`).join('\n');

    const seg = timeline.add({
        kind: 'ticker', slot: 'ticker', texts: list.map((sg) => [sg.head, sg.text].filter(Boolean).join(' ')), start, introMs: TIMING.tickerIntroMs, holdMs: 3_600_000, outroMs: 600,
        extra: { pxPerS: TIMING.tickerPxPerS, observedPxPerS: null, loops: 0, rebuilt: 0 },
    });

    const itemKey = (it) => `${it.head}|${it.text}`;
    let lastKey = null;

    /**
     * Lay segments up to `ahead` px past the rail's right end. Every segment laid is taken from the list as it is
     * now (a result that went stale leaves the crawl with its next pass, never from the screen); the next one follows
     * the last laid one in the fresh list. A pass through the list ends at a loop boundary.
     */
    function fill(offset) {
        const ahead = offset + railW + 240;
        let guard = 0;
        while (tail < ahead && list.length > 0 && guard++ < 64) {
            const fresh = source ? normalize(source()) : list;
            if (fresh.length > 0 && keyOf(fresh) !== keyOf(list)) {
                const at = lastKey === null ? -1 : fresh.findIndex((it) => itemKey(it) === lastKey);
                cursor = at >= 0 ? at + 1 : Math.min(cursor, fresh.length);
                list = fresh;
                seg.rebuilt++;
            }
            if (cursor >= list.length) {
                cursor = 0;
                seg.loops++;
            }
            lastKey = itemKey(list[cursor]);
            append(list[cursor++]);
        }
        while (parts.length > 0 && parts[0].x + parts[0].w < offset - 160) {
            const gone = parts.shift();
            crawl.remove(gone.plane.mesh);
            disposeTree(gone.plane.mesh);
        }
    }

    let probe = null;
    const clipL = root.position.x + railX + 12;
    const clipR = root.position.x + railX + railW - 12;

    function layout(offset) {
        const left = 20;
        parts.forEach((p) => {
            placeLine(p.plane, left + p.x - offset, p.top, 0);
            p.plane.material.uniforms.clip.value.set(clipL, 0, clipR, 0);
        });
    }

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        timeline.observe(seg, ph, t, []);
        if (!root.visible) return;
        let chipK = 1, railK = 1;
        itemK = 1;
        if (ph.name === 'intro') {
            chipK = CURVES.set(span(ph.t, 0, 600));
            railK = CURVES.set(span(ph.t, 120, 780));
            itemK = CURVES.reveal(span(ph.t, 500, 400));
        } else if (ph.name === 'outro') {
            itemK = 1 - CURVES.leave(span(ph.t, 0, 300));
            railK = chipK = 1 - CURVES.leave(span(ph.t, 150, 450));
        }
        chip.pivot.rotation.x = (1 - chipK) * 80 * DEG;
        rail.pivot.rotation.x = (1 - railK) * 80 * DEG;
        chip.setOpacity(Math.min(1, chipK * 1.4));
        rail.setOpacity(Math.min(1, railK * 1.4));
        chipText.material.uniforms.opacity.value = Math.min(1, chipK * 1.4);
        chipMark.material.uniforms.opacity.value = Math.min(1, chipK * 1.4);
        under.scale.x = Math.max(0.001, chipK);
        const cycle = t % 9000;
        chipFace.uniforms.sweep.value = -0.3 + 1.6 * CURVES.sweep(span(cycle, 0, 900));
        const sweep = -0.2 + 1.4 * CURVES.sweep(span(cycle, 500, 2400));
        rail.front.uniforms.sweep.value = sweep;
        flare.position.x = railX + sweep * railW;
        flare.material.uniforms.opacity.value = flareAt(sweep) * 0.7 * railK;
        const moving = Math.max(0, t - seg.start - seg.introMs);
        const offset = (moving * TIMING.tickerPxPerS) / 1000;
        fill(offset);
        layout(offset);
        parts.forEach((p) => { p.plane.material.uniforms.opacity.value = itemK; });

        // Speed from real positions: one part's world x, sampled once a second; a part that left resets the probe.
        if (ph.name === 'hold' && parts.length > 0) {
            const v = new THREE.Vector3();
            if (probe && !parts.includes(probe.part)) probe = null;
            const part = probe ? probe.part : parts[parts.length - 1];
            part.plane.mesh.getWorldPosition(v);
            if (!probe) probe = { part, t, x: v.x };
            else if (t - probe.t >= 1000) {
                const d = probe.x - v.x;
                if (d >= 0) seg.observedPxPerS = Math.max(seg.observedPxPerS || 0, +((d / (t - probe.t)) * 1000).toFixed(2));
                probe = { part, t, x: v.x };
            }
        }
    }

    return {
        seg,
        update,
        dispose() {
            stage.scene.remove(root);
            disposeTree(root);
        },
    };
}
