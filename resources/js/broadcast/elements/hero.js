/**
 * The break scene's hero (plan P5): what the viewer waits for, the one thing on the screen read from across a room.
 *
 * Hierarchy, decided before any colour: 1. the state ("Starting soon", 64 px Unbounded 800, white; a long tournament
 * name shrinks to 32 px before it is cut), 2. the clock (HH:MM:SS always, Unbounded 800 in fixed cells, so the digits
 * change in place and nothing shifts while it counts; its size is what fits all eight characters into the column,
 * 168 px at most), 3. what
 * it counts to (the tournament and its game, 30 px Unbounded 500) and when (JetBrains Mono, grey). The league's 21
 * mark stands above it as a block of lit metal with the rig's light behind it: the scene's one monument.
 *
 * At the end of a tournament the clock gives way to its winner: the name in 80 px with a hot edge beside it, and the
 * crown rising behind the mark.
 *
 * Build-in (TIMING.heroIntroMs = 1200): the rays open (0-700), the mark rises and turns into place (0-800, curve
 * `set`) and lands with a burst (760); the state reveals (300-900), the line (420-1000), the label (500-1060), the
 * clock or the winner (560-1140), the time (640-1200). Hold: the mark swings slowly (TIMING.markSwingMs) and a light
 * crosses it every TIMING.heroSweepMs; the words stand still. Build-out (TIMING.heroOutroMs = 700): the words cover
 * (0-300), the mark sinks and the light goes (150-700).
 */

import { CURVES, span } from '../curves.js';
import { markGeometry } from '../mark.js';
import { createEnergy, createHalo, createHeat, createImage } from '../materials.js';
import { clockText, createClockPlane, createLine, disposeTree } from '../text.js';
import { COLOR, TYPE } from '../tokens.js';
import { TIMING, holdForTexts } from '../timing.js';
import { place } from './lowerThird.js';
import { litMaterial } from './stinger.js';

export const CLOCK = { family: 'display', weight: 800, size: 168 };
const LEFT = 96;
const MARK = 280;
export const HERO_W = 904;

/** The hero's clock: the shared clock plane (resources/js/broadcast/text.js) at CLOCK size at most. */
function createClock(stage, w, h) {
    return createClockPlane(stage, w, h, CLOCK, { ink: COLOR.ink, colon: COLOR.ink3, nudge: 6 });
}

/**
 * `hero`: { headline, line, label, startsAt (the clock runs to it) | big (a text in its place), when, winner }.
 * `art`: { crown }.
 */
export function createHero(stage, timeline, particles, { start, hero, art, holdMs = 3_600_000 }) {
    const { THREE } = stage;
    const root = new THREE.Group();
    stage.scene.add(root);

    // The monument: the mark, its rays and halo. It stands 80 px in front of the frame plane, so its centre is pulled
    // in for the perspective: its left edge lines up with the words under it.
    const plain = stage.toWorld(LEFT + MARK / 2, 54 + 36 + MARK / 2);
    const markAt = stage.onScreen(plain.x, plain.y, 80);
    const rays = createEnergy(THREE, '/broadcast/art/energy-rays.webp', 1250, 1250, { round: true });
    rays.position.set(markAt.x, markAt.y, -40);
    root.add(rays);
    const halo = createHalo(THREE, 820, COLOR.btc, 0);
    halo.position.set(markAt.x, markAt.y, -30);
    root.add(halo);
    const geo = markGeometry(THREE, MARK);
    const tileMat = litMaterial(THREE, COLOR.btcHi, { low: COLOR.btcDeep, rim: '#FFE2B8', spec: 1.1 });
    const glyphMat = litMaterial(THREE, '#1E1710', { rim: COLOR.btc, spec: 1.2, ambient: 0.6 });
    const mark = new THREE.Group();
    mark.add(new THREE.Mesh(geo.tile, tileMat), new THREE.Mesh(geo.glyphs, glyphMat));
    mark.children.forEach((c) => { c.renderOrder = 3; });
    mark.userData.layout = 'mark';
    root.add(mark);
    let crown = null;
    if (hero.winner && art.crown) {
        crown = createImage(THREE, art.crown, 300, 340, { rim: 1.2, rimFrom: [0, -1] });
        crown.renderOrder = 2;
        root.add(crown);
    }

    // The words, in a group whose origin is the frame's left top: a line keeps its place when it is redrawn (a tick
    // of the clock, a new text scale).
    const words = new THREE.Group();
    const origin = stage.toWorld(0, 0);
    words.position.set(origin.x, origin.y, 0);
    root.add(words);
    const lines = [];
    const texts = [];
    const add = (text, style, color, top, maxWidth = HERO_W, minScale = 0.72) => {
        const l = createLine(stage, String(text), style, { color, maxWidth, minScale });
        place(l, LEFT, top);
        words.add(l.mesh);
        lines.push(l);
        texts.push(String(text));

        return l;
    };
    // A tournament's name stands here in the bracket scene before the draw: it shrinks to half before it is cut.
    const headline = add(hero.headline, TYPE.name, COLOR.ink, 432, HERO_W, 0.5);
    const sub = hero.line ? add(hero.line, TYPE.line, COLOR.ink, 516) : null;
    const label = hero.label ? add(hero.label, TYPE.data, COLOR.ink2, 592) : null;
    let clock = null;
    let big = null;
    let bar = null;
    if (hero.startsAt) {
        clock = createClock(stage, HERO_W, 196);
        clock.set(clockText(hero.startsAt));
        place(clock, LEFT - 4, 626);
        words.add(clock.mesh);
        texts.push('00:00:00');
    } else if (hero.big) {
        big = add(hero.big, hero.winner ? TYPE.hero : TYPE.headline, COLOR.ink, hero.winner ? 640 : 646, HERO_W - 28, 0.5);
        if (hero.winner) {
            place(big, LEFT + 28, 640);
            bar = createHeat(THREE, 6, Math.round(big.size * 1.05));
            const p = stage.toWorld(LEFT, 642);
            bar.position.set(p.x, p.y, 1);
            root.add(bar);
        }
    }
    const when = hero.when ? add(hero.when, TYPE.data, COLOR.ink2, 838) : null;
    const order = [headline, sub, label, clock || big, when];

    const seg = timeline.add({ kind: 'hero', slot: 'hero', texts, start, introMs: TIMING.heroIntroMs, holdMs: Math.max(holdForTexts(texts), holdMs), outroMs: TIMING.heroOutroMs, extra: { state: hero.state } });
    const meshes = [...lines.map((l) => l.mesh), ...(clock ? [clock.mesh] : [])];
    let landed = false;
    let lastEmber = 0;

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        if (!root.visible) {
            timeline.observe(seg, ph, t, meshes);

            return;
        }
        if (clock) clock.set(clockText(hero.startsAt));
        let markK = 1, light = 1, fade = 1, sink = 0;
        const reveal = [1, 1, 1, 1, 1];
        if (ph.name === 'intro') {
            const x = ph.t;
            markK = CURVES.set(span(x, 0, 800));
            light = CURVES.reveal(span(x, 0, 700));
            [300, 420, 500, 560, 640].forEach((d, i) => { reveal[i] = CURVES.reveal(span(x, d, 560)); });
            if (!landed && x >= 760) {
                landed = true;
                particles.emit(LEFT + MARK / 2, 54 + 36 + MARK / 2, 90, { speed: 420, rise: 30, lifeMs: 1300, sizePx: 9, radial: true, inner: MARK * 0.66, z: 60 });
            }
        } else if (ph.name === 'outro') {
            const x = ph.t;
            reveal.fill(1 - CURVES.leave(span(x, 0, 300)));
            sink = CURVES.leave(span(x, 150, 550));
            light = 1 - sink;
            fade = 1 - span(x, 400, 300);
        }
        const swing = Math.sin(((t - seg.start) / TIMING.markSwingMs) * Math.PI * 2);
        mark.position.set(markAt.x, markAt.y - (1 - markK) * 160 - sink * 120, 40 + markK * 40);
        mark.rotation.set(0.08 * Math.sin(((t - seg.start) / TIMING.markSwingMs) * Math.PI * 4), -0.9 * (1 - markK) + 0.24 * swing * markK, 0);
        mark.scale.setScalar(Math.max(0.001, (0.7 + 0.3 * markK) * (1 - sink * 0.3)));
        const cycle = (t - seg.start) % TIMING.heroSweepMs;
        tileMat.uniforms.sweep.value = glyphMat.uniforms.sweep.value = ph.name === 'intro' ? -1.6 + 3.2 * CURVES.sweep(span(ph.t, 600, 600)) : -1.6 + 3.2 * CURVES.sweep(span(cycle, 3600, 1400));
        rays.material.uniforms.opacity.value = 0.55 * light * fade;
        rays.material.uniforms.spin.value = t * 0.00005;
        rays.scale.setScalar(0.85 + 0.15 * light);
        halo.material.uniforms.opacity.value = 0.32 * light * fade;
        if (crown) {
            const k = ph.name === 'intro' ? CURVES.set(span(ph.t, 400, 800)) : 1;
            const s = crown.userData.size;
            crown.position.set(markAt.x + 330, markAt.y + 10 - (1 - k) * 80, 20);
            crown.material.uniforms.opacity.value = Math.min(1, k * 1.3) * fade * (s ? 1 : 0);
            crown.material.uniforms.sheen.value = -0.5 + 2 * CURVES.sweep(span(cycle, 3800, 1200));
        }
        // Embers rise from behind the monument now and then: background, never over a word.
        if (ph.name === 'hold' && t - lastEmber > 700) {
            lastEmber = t;
            particles.emit(LEFT + 20, 54 + 300, 5, { w: MARK - 40, h: 40, speed: 20, rise: 46, lifeMs: 3800, sizePx: 6, z: -10 });
        }
        order.forEach((l, i) => {
            if (!l) return;
            l.material.uniforms.reveal.value = reveal[i];
            l.material.uniforms.opacity.value = fade;
        });
        if (bar) {
            bar.scale.y = Math.max(0.001, reveal[3]);
            bar.material.opacity = fade;
        }
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
