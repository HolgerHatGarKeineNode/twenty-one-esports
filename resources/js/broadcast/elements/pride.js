/**
 * Pride moment in a top corner (SLOTS.cornerLeft / cornerRight): the biggest thing an overlay says about a player.
 * The emblem block sits at the outer edge with its trophy breaking out of the top, the text plate beside it towards
 * the centre; everything ends above the free centre (y 222 < 225).
 *
 * Total 8-12 s (TIMING.prideTargetMs = 10000, clamped by RULES): build-in 1200, hold at least the reading rule and
 * filled up to the target, build-out 700. Build-in: hot edge up the emblem's inner side (0-400), emblem block set
 * (0-750), trophy grows into place with a burst of embers when it lands (200-900, burst at 620), plate set
 * (250-1000) with the light sweep, the light runs along the plate's foot (300-1000), headline (450-1050), line
 * (550-1150), context (600-1200). Hold: text still; trophy floats 3 px, its halo breathes, embers drift, a sweep
 * crosses the glass every 4 s. Build-out: text covers (0-350), plate (200-650), emblem (300-700).
 */

import { CURVES, span } from '../curves.js';
import { createEnergy, createHalo, createHeat, createImage, createSlab } from '../materials.js';
import { createLine, disposeTree } from '../text.js';
import { COLOR, SLOTS, TYPE } from '../tokens.js';
import { RULES, TIMING, holdForTexts } from '../timing.js';
import { DEG, place } from './lowerThird.js';

export function createPride(stage, timeline, particles, { start, side = 'right', name, line, context, trophy, energy = null }) {
    const { THREE } = stage;
    const slot = side === 'right' ? SLOTS.cornerRight : SLOTS.cornerLeft;
    const H = slot.h;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const maxText = slot.w - H - 8 - 56;
    const head = createLine(stage, name, TYPE.headline, { color: COLOR.ink, maxWidth: maxText });
    const what = createLine(stage, line, TYPE.line, { color: COLOR.ink, maxWidth: maxText });
    const where = createLine(stage, context, TYPE.data, { color: COLOR.ink2, maxWidth: maxText });
    const contentW = Math.max(...[head, what, where].map((l) => l.width - l.pad * 2));
    const W = Math.min(slot.w - H - 8, Math.max(440, Math.ceil(contentW + 56)));

    // Emblem block at the outer edge, plate towards the centre.
    const blockX = side === 'right' ? slot.w - H : 0;
    const plateX = side === 'right' ? slot.w - H - 8 - W : H + 8;

    let glowPlate = null;
    if (energy) {
        // Behind the plates, kept inside y 14..222 so its light never reaches the free centre (y >= 225).
        glowPlate = createEnergy(THREE, energy, slot.w + 120, H + 40);
        glowPlate.position.set(slot.w / 2, -H / 2 + 20, -30);
        root.add(glowPlate);
    }

    const block = createSlab(THREE, H, H);
    block.group.position.x = blockX;
    root.add(block.group);
    const halo = createHalo(THREE, H * 2.1, COLOR.btc, 0.55);
    halo.position.set(blockX + H / 2, -H / 2 + 8, -12);
    root.add(halo);
    const cup = createImage(THREE, trophy, H * 1.25, H * 1.25);
    const cupHome = { x: H / 2, y: -H / 2 + 14 };
    cup.position.set(cupHome.x, cupHome.y, 6);
    block.pivot.add(cup);

    const edgeX = side === 'right' ? blockX - 4 : blockX + H - 2;
    const heat = createHeat(THREE, 6, H);
    heat.position.set(edgeX - 1, 0, 1);
    root.add(heat);

    const plate = createSlab(THREE, W, H);
    plate.group.position.x = plateX;
    root.add(plate.group);
    const foot = createHeat(THREE, W, 4);
    foot.position.set(plateX, -H + 2, 1);
    root.add(foot);
    const top0 = 14;
    const y1 = top0 + TYPE.headline.size * TYPE.headline.leading + 4;
    const y2 = y1 + TYPE.line.size * TYPE.line.leading + 4;
    // The plate swings about the edge next to the emblem, so it opens away from it.
    const textX = side === 'right' ? 28 - W : 28;
    if (side === 'right') {
        plate.group.position.x = plateX + W;
        plate.box.position.x = -W / 2;
    }
    place(head, textX, top0);
    place(what, textX, y1);
    place(where, textX, y2);
    plate.pivot.add(head.mesh, what.mesh, where.mesh);

    const intro = TIMING.prideIntroMs;
    const outro = TIMING.prideOutroMs;
    const texts = [name, line, context];
    const minHold = holdForTexts(texts);
    const hold = Math.min(RULES.prideMaxMs - intro - outro, Math.max(minHold, TIMING.prideTargetMs - intro - outro));
    const seg = timeline.add({ kind: 'pride', slot: side === 'right' ? 'cornerRight' : 'cornerLeft', texts, start, introMs: intro, holdMs: Math.max(hold, minHold), outroMs: outro });
    const meshes = [head.mesh, what.mesh, where.mesh];
    const dir = side === 'right' ? -1 : 1;
    let burst = false;
    let lastT = null;
    let lastEmber = 0;

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        if (!root.visible) {
            timeline.observe(seg, ph, t, meshes);

            return;
        }
        const dt = lastT === null ? 16 : t - lastT;
        lastT = t;
        let heatK = 1, blockK = 1, cupK = 1, plateK = 1, footK = 1, k1 = 1, k2 = 1, k3 = 1, sweep = -1, fade = 1, energyK = 1;
        if (ph.name === 'intro') {
            const x = ph.t;
            heatK = CURVES.reveal(span(x, 0, 400));
            blockK = CURVES.set(span(x, 0, 750));
            cupK = CURVES.set(span(x, 200, 700));
            plateK = CURVES.set(span(x, 250, 750));
            footK = CURVES.reveal(span(x, 300, 700));
            k1 = CURVES.reveal(span(x, 450, 600));
            k2 = CURVES.reveal(span(x, 550, 600));
            k3 = CURVES.reveal(span(x, 600, 600));
            sweep = -0.4 + 1.8 * CURVES.sweep(span(x, 350, 800));
            energyK = CURVES.drift(span(x, 100, 1100));
            if (!burst && x >= 620) {
                burst = true;
                particles.emit(slot.x + blockX + H * 0.2, slot.y + H * 0.15, 70, { w: H * 0.6, h: H * 0.5, speed: 160, rise: 50, lifeMs: 1700, sizePx: 9 });
            }
        } else if (ph.name === 'hold') {
            const cycle = ph.t % 4000;
            sweep = -0.4 + 1.8 * CURVES.sweep(span(cycle, 1400, 1400));
            if (t - lastEmber > 180) {
                lastEmber = t;
                // Embers rise from the trophy, never across the text being read.
                particles.emit(slot.x + blockX + H * 0.25, slot.y + H * 0.2, 2, { w: H * 0.5, h: H * 0.3, speed: 14, rise: 26, lifeMs: 2200, sizePx: 6 });
            }
        } else if (ph.name === 'outro') {
            const x = ph.t;
            k1 = k2 = k3 = 1 - CURVES.leave(span(x, 0, 350));
            plateK = footK = 1 - CURVES.leave(span(x, 200, 450));
            blockK = cupK = heatK = 1 - CURVES.leave(span(x, 300, 400));
            energyK = 1 - span(x, 0, 700);
            fade = 1 - span(x, 450, 250);
        }
        const holdT = ph.name === 'hold' ? ph.t : 0;
        block.pivot.rotation.y = dir * -(1 - blockK) * 80 * DEG;
        block.setOpacity(Math.min(1, blockK * 1.4) * fade);
        cup.scale.setScalar(Math.max(0.001, 0.6 + 0.4 * cupK));
        cup.material.opacity = Math.min(1, cupK * 1.3) * fade;
        cup.position.y = cupHome.y + Math.sin(holdT / 900) * 3;
        halo.material.uniforms.opacity.value = (0.4 + 0.15 * Math.sin(holdT / 700)) * Math.min(1, cupK) * fade;
        heat.scale.y = Math.max(0.001, heatK);
        heat.material.opacity = fade;
        plate.pivot.rotation.y = dir * (1 - plateK) * 80 * DEG;
        plate.setOpacity(Math.min(1, plateK * 1.4) * fade);
        plate.front.uniforms.sweep.value = side === 'right' ? 1 - sweep : sweep;
        foot.scale.x = Math.max(0.001, footK);
        if (side === 'right') foot.position.x = plateX + W * (1 - footK);
        foot.material.opacity = fade;
        if (glowPlate) glowPlate.material.uniforms.opacity.value = 0.55 * energyK * (0.85 + 0.15 * Math.sin(holdT / 1300));
        head.material.uniforms.reveal.value = k1;
        what.material.uniforms.reveal.value = k2;
        where.material.uniforms.reveal.value = k3;
        timeline.observe(seg, ph, t, meshes);
        void dt;
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
