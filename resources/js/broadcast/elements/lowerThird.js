/**
 * Lower third: who and what, below the free centre and above the ticker (SLOTS.lowerThird).
 *
 * Build-in (TIMING.lowerThirdIntroMs = 1000): the hot edge draws down first (0-360), the emblem block is set (swing
 * from 80 deg about its left edge, curve `set`, 80-700), the text plate is set beside it (200-860) while a light
 * sweep crosses it, the name reveals (380-900), the line under it (480-1000). Hold: nothing on the text moves; one
 * light sweep passes over the glass halfway. Build-out (600): text covers (0-300), plate swings away (150-600), the
 * hot edge retracts last (300-600).
 */

import { CURVES, span } from '../curves.js';
import { createHalo, createHeat, createImage, createSlab } from '../materials.js';
import { createLine, disposeTree, placeLine } from '../text.js';
import { COLOR, SLOTS, TYPE } from '../tokens.js';
import { TIMING, holdForTexts } from '../timing.js';

export const DEG = Math.PI / 180;

/** Place a text line inside a parent whose origin is its left top: (left, top) is the type's box without padding. */
export function place(line, left, top, z = 0.6) {
    placeLine(line, left, top, z);
}

export function createLowerThird(stage, timeline, { start, name, line, emblem = null }) {
    const { THREE } = stage;
    const slot = SLOTS.lowerThird;
    const H = slot.h;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const nameLine = createLine(stage, name, TYPE.title, { color: COLOR.ink, maxWidth: slot.w - H - 8 - 48 });
    const subLine = createLine(stage, line, TYPE.data, { color: COLOR.ink2, maxWidth: slot.w - H - 8 - 48 });
    const contentW = Math.max(nameLine.width - nameLine.pad * 2, subLine.width - subLine.pad * 2);
    const W = Math.min(slot.w - H - 8, Math.max(420, Math.ceil(contentW + 48)));

    const heat = createHeat(THREE, 6, H);
    heat.position.set(-14, 0, 0);
    root.add(heat);

    const block = createSlab(THREE, H, H);
    root.add(block.group);
    let image = null;
    if (emblem) {
        // A warm light behind the emblem lifts its dark metal off the dark glass.
        const halo = createHalo(THREE, H * 1.1, COLOR.btc, 0.28);
        halo.position.set(H / 2, -H / 2, 0.5);
        halo.renderOrder = 1.5;
        block.pivot.add(halo);
        image = createImage(THREE, emblem, H - 12, H - 12);
        image.position.set(H / 2, -H / 2, 1);
        block.pivot.add(image);
    }

    const plate = createSlab(THREE, W, H);
    plate.group.position.x = H + 8;
    root.add(plate.group);
    const nameTop = 8;
    place(nameLine, 24, nameTop);
    place(subLine, 24, nameTop + TYPE.title.size * TYPE.title.leading + 4);
    plate.pivot.add(nameLine.mesh, subLine.mesh);

    const intro = TIMING.lowerThirdIntroMs;
    const outro = TIMING.lowerThirdOutroMs;
    const seg = timeline.add({ kind: 'lowerThird', slot: 'lowerThird', texts: [name, line], start, introMs: intro, holdMs: holdForTexts([name, line]), outroMs: outro });
    const texts = [nameLine.mesh, subLine.mesh];

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        if (!root.visible) {
            timeline.observe(seg, ph, t, texts);

            return;
        }
        let heatK = 1, blockK = 1, plateK = 1, nameK = 1, subK = 1, sweep = -1, fade = 1;
        if (ph.name === 'intro') {
            const x = ph.t;
            heatK = CURVES.reveal(span(x, 0, 360));
            blockK = CURVES.set(span(x, 80, 620));
            plateK = CURVES.set(span(x, 200, 660));
            nameK = CURVES.reveal(span(x, 380, 520));
            subK = CURVES.reveal(span(x, 480, 520));
            sweep = -0.4 + 1.8 * CURVES.sweep(span(x, 300, 700));
        } else if (ph.name === 'hold') {
            const mid = seg.holdMs / 2 - 600;
            sweep = -0.4 + 1.8 * CURVES.sweep(span(ph.t, mid, 1200));
        } else if (ph.name === 'outro') {
            const x = ph.t;
            nameK = subK = 1 - CURVES.leave(span(x, 0, 300));
            plateK = blockK = 1 - CURVES.leave(span(x, 150, 450));
            heatK = 1 - CURVES.leave(span(x, 300, 300));
            fade = 1 - span(x, 350, 250);
        }
        heat.scale.y = Math.max(0.001, heatK);
        heat.material.opacity = fade;
        block.pivot.rotation.y = (1 - blockK) * 80 * DEG;
        plate.pivot.rotation.y = (1 - plateK) * 80 * DEG;
        block.setOpacity(Math.min(1, blockK * 1.4) * fade);
        plate.setOpacity(Math.min(1, plateK * 1.4) * fade);
        if (image) image.material.opacity = Math.min(1, blockK * 1.2) * fade;
        plate.front.uniforms.sweep.value = sweep;
        nameLine.material.uniforms.reveal.value = nameK;
        subLine.material.uniforms.reveal.value = subK;
        timeline.observe(seg, ph, t, texts);
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
