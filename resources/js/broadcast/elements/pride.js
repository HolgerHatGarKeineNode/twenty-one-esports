/**
 * Pride moment: the biggest thing an overlay says about a player. An L that hugs one top corner of the live picture:
 * the trophy column stands in the free side strip (SLOTS.pillar*, 264 x 470), the name plate runs from it along the
 * top band (SLOTS.corner*, 592 x 168, ending at y 222 above the centre), and one hot edge traces the inside of the L,
 * the stream's corner, so the moment frames the game instead of covering it.
 *
 * Layers, back to front: the column's glass, lit by the hot edge beside it, and the line of light it stands on; light rays turning slowly behind the trophy and a
 * warm halo; the trophy, 236 x 360, rim-lit from the hot edge, standing 50 px in front of the glass (it parallaxes
 * against the column as both swing in); the name plate with its lip; text; flares and the shock ring on top.
 *
 * Total 8-12 s (TIMING.prideTargetMs = 10000, clamped by RULES): build-in 1200, hold at least the reading rule and
 * filled up to the target, build-out 700. Build-in: hot edge down the column (0-420), column set about its outer edge
 * (0-700), rays open (150-900), trophy rises onto the ledge (260-860) and lands at 760 with a shock ring, a radial
 * burst of embers and a sheen; hot edge runs along the plate's foot (300-700), plate set about the column (320-860)
 * with a sweep and its flare (500-1100); name (450-1050), line (550-1150), context (600-1200). Hold: text still; the
 * trophy floats 4 px, rays turn, halo breathes, embers rise from the cup, a sheen crosses the trophy every 3.6 s and a
 * sweep the glass every 4 s. Build-out: text covers (0-350), plate (200-600), trophy sinks (250-600), column (300-700).
 */

import { CURVES, span } from '../curves.js';
import { createEnergy, createFlare, createHalo, createHeat, createImage, createShock, createSlab } from '../materials.js';
import { createLine, disposeTree } from '../text.js';
import { COLOR, SLOTS, TYPE } from '../tokens.js';
import { RULES, TIMING, holdForTexts } from '../timing.js';
import { DEG, flareAt, place } from './lowerThird.js';

export function createPride(stage, timeline, particles, { start, side = 'right', name, line, context, trophy, rays = null }) {
    const { THREE } = stage;
    const right = side === 'right';
    const slot = right ? SLOTS.cornerRight : SLOTS.cornerLeft;
    const pillarSlot = right ? SLOTS.pillarRight : SLOTS.pillarLeft;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const PW = pillarSlot.w;
    const PH = pillarSlot.h;
    const PX = pillarSlot.x - slot.x;
    const H = slot.h;
    const W = slot.w - PW - 8;
    const plateX = right ? 0 : PW + 8;
    const cx = PX + PW / 2;

    // The column: glass, chamfered on the corner that faces the stream, swinging about its outer edge.
    const pillar = createSlab(THREE, PW, PH, { depth: 40, corner: right ? 'bl' : 'br', top: COLOR.metal, bottom: COLOR.ground, spill: right ? 'left' : 'right' });
    if (right) {
        pillar.group.position.x = PX + PW;
        pillar.box.position.x = -PW;
    } else {
        pillar.group.position.x = PX;
    }
    root.add(pillar.group);
    // The ledge the trophy stands on: a line of light across the column and a pool of it on the glass beneath.
    const ledgeY = -(PH - 62);
    const ledgeHeat = createHeat(THREE, PW - 40, 3);
    ledgeHeat.position.set(right ? 20 - PW : 20, ledgeY, 3);
    pillar.pivot.add(ledgeHeat);
    const pool = createHalo(THREE, PW * 1.1, COLOR.btc, 0.5);
    pool.scale.y = 0.22;
    pool.position.set(right ? -PW / 2 : PW / 2, ledgeY - 2, 2);
    pool.renderOrder = 1.6;
    pillar.pivot.add(pool);

    let rayPlate = null;
    if (rays) {
        rayPlate = createEnergy(THREE, rays, 470, 470, { round: true });
        rayPlate.position.set(cx, -232, 10);
        rayPlate.renderOrder = 1.5;
        root.add(rayPlate);
    }
    const halo = createHalo(THREE, 320, COLOR.btc, 0.5);
    halo.position.set(cx, -250, 12);
    halo.renderOrder = 1.6;
    root.add(halo);
    // The trophy stands 50 px in front of the glass; placed so that on screen it sits centred in the column.
    const cupZ = 50;
    const cupScreen = stage.onScreen(o.x + cx, o.y + ledgeY + 176, cupZ);
    const cupHome = { x: cupScreen.x - o.x, y: cupScreen.y - o.y, z: cupZ };
    const cup = createImage(THREE, trophy, 220, 350, { rim: 1.3, rimFrom: [right ? -1 : 1, 0.35] });
    cup.position.set(cupHome.x, cupHome.y, cupHome.z);
    root.add(cup);
    const shock = createShock(THREE, 560);
    shock.position.set(cx, cupHome.y + 20, 54);
    root.add(shock);

    // The hot edge traces the inside of the L: down the column's inner side, then along the plate's foot.
    const innerX = right ? PX - 4 : PX + PW;
    const heatV = createHeat(THREE, 4, PH - 28);
    heatV.position.set(innerX, 0, 1);
    root.add(heatV);
    const chamfer = 28;
    const footW = W + 4 - chamfer;
    const heatH = createHeat(THREE, footW, 4);
    heatH.position.set(right ? chamfer : plateX - 4, -H + 4, 1);
    root.add(heatH);

    // A fainter pane behind the plate, 16 px further out and 10 px up: the plate reads as the front of a stack.
    const ghost = createSlab(THREE, W, H, { corner: right ? 'bl' : 'br', cover: 0.5, top: '#2C2C33', bottom: '#111114', depth: 12 });
    if (right) {
        ghost.group.position.set(plateX + W - 16, 10, -26);
        ghost.box.position.x = -W;
    } else {
        ghost.group.position.set(plateX + 16, 10, -26);
    }
    root.add(ghost.group);
    // The plate swings about its edge next to the column, so it opens away from it.
    const plate = createSlab(THREE, W, H, { corner: right ? 'bl' : 'br', spill: 'bottom' });
    if (right) {
        plate.group.position.x = plateX + W;
        plate.box.position.x = -W;
    } else {
        plate.group.position.x = plateX;
    }
    root.add(plate.group);
    const maxText = W - 32 - 28;
    const head = createLine(stage, name, TYPE.name, { color: COLOR.ink, maxWidth: maxText });
    const what = createLine(stage, line, TYPE.line, { color: COLOR.ink, maxWidth: maxText });
    const where = createLine(stage, context, TYPE.data, { color: COLOR.ink2, maxWidth: maxText });
    const top0 = 14;
    const y1 = top0 + TYPE.name.size * TYPE.name.leading + 8;
    const y2 = y1 + TYPE.line.size * TYPE.line.leading + 4;
    const textX = right ? 32 - W : 32;
    place(head, textX, top0);
    place(what, textX, y1);
    place(where, textX, y2);
    plate.pivot.add(head.mesh, what.mesh, where.mesh);
    const flare = createFlare(THREE, 340, 24);
    flare.position.set(plateX, -3, 3);
    root.add(flare);

    const intro = TIMING.prideIntroMs;
    const outro = TIMING.prideOutroMs;
    const texts = [name, line, context];
    const minHold = holdForTexts(texts);
    const hold = Math.min(RULES.prideMaxMs - intro - outro, Math.max(minHold, TIMING.prideTargetMs - intro - outro));
    const seg = timeline.add({ kind: 'pride', slot: right ? 'cornerRight' : 'cornerLeft', texts, start, introMs: intro, holdMs: Math.max(hold, minHold), outroMs: outro });
    const meshes = [head.mesh, what.mesh, where.mesh];
    const dir = right ? 1 : -1;
    let burst = false;
    let lastEmber = 0;

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        if (!root.visible) {
            timeline.observe(seg, ph, t, meshes);

            return;
        }
        let ghostK = 1, heatVK = 1, heatHK = 1, pillarK = 1, rayK = 1, cupK = 1, plateK = 1, k1 = 1, k2 = 1, k3 = 1;
        let sweep = -1, sheen = -1, fade = 1, shockP = 1, flash = 0;
        const holdT = ph.name === 'hold' ? ph.t : 0;
        if (ph.name === 'intro') {
            const x = ph.t;
            heatVK = CURVES.reveal(span(x, 0, 420));
            pillarK = CURVES.set(span(x, 0, 700));
            rayK = CURVES.drift(span(x, 150, 750));
            cupK = CURVES.set(span(x, 260, 600));
            heatHK = CURVES.reveal(span(x, 300, 400));
            plateK = CURVES.set(span(x, 320, 540));
            ghostK = CURVES.set(span(x, 420, 560));
            k1 = CURVES.reveal(span(x, 450, 600));
            k2 = CURVES.reveal(span(x, 550, 600));
            k3 = CURVES.reveal(span(x, 600, 600));
            sweep = -0.4 + 1.8 * CURVES.sweep(span(x, 500, 600));
            const land = TIMING.prideLandMs;
            sheen = -0.5 + 2 * CURVES.sweep(span(x, land, 400));
            shockP = span(x, land, 700);
            flash = Math.exp(-Math.pow((x - land - 30) / 120, 2));
            if (!burst && x >= land) {
                burst = true;
                particles.emit(pillarSlot.x + PW / 2 - 30, pillarSlot.y + 150, 120, { w: 60, h: 60, speed: 300, rise: 20, lifeMs: 1500, sizePx: 8, radial: true, inner: 110, z: 60 });
            }
        } else if (ph.name === 'hold') {
            sweep = -0.4 + 1.8 * CURVES.sweep(span(holdT % 4000, 1600, 1200));
            sheen = -0.5 + 2 * CURVES.sweep(span(holdT % 3600, 900, 900));
            if (t - lastEmber > 160) {
                lastEmber = t;
                // Embers rise from the cup, inside the column, never across the text being read.
                particles.emit(pillarSlot.x + PW * 0.3, pillarSlot.y + 120, 2, { w: PW * 0.4, h: 60, speed: 16, rise: 34, lifeMs: 2400, sizePx: 6, z: 56 });
            }
        } else if (ph.name === 'outro') {
            const x = ph.t;
            k1 = k2 = k3 = 1 - CURVES.leave(span(x, 0, 350));
            plateK = 1 - CURVES.leave(span(x, 200, 400));
            ghostK = 1 - CURVES.leave(span(x, 150, 400));
            heatHK = 1 - CURVES.leave(span(x, 200, 300));
            cupK = 1 - CURVES.leave(span(x, 250, 350));
            pillarK = 1 - CURVES.leave(span(x, 300, 400));
            heatVK = 1 - CURVES.leave(span(x, 350, 350));
            rayK = 1 - span(x, 0, 500);
            fade = 1 - span(x, 450, 250);
        }
        pillar.pivot.rotation.y = dir * (1 - pillarK) * 80 * DEG;
        pillar.setOpacity(Math.min(1, pillarK * 1.4) * fade);
        ledgeHeat.material.opacity = pillarK * fade;
        pool.material.uniforms.opacity.value = (0.45 + 0.35 * flash) * cupK * fade;
        pillar.front.uniforms.sweep.value = sweep;
        if (rayPlate) {
            rayPlate.material.uniforms.opacity.value = (0.75 + 0.6 * flash) * rayK * fade;
            rayPlate.material.uniforms.spin.value = t * 0.00007;
            rayPlate.scale.setScalar(0.6 + 0.4 * rayK);
        }
        halo.material.uniforms.opacity.value = (0.42 + 0.1 * Math.sin(holdT / 700) + 0.5 * flash) * Math.min(1, cupK) * fade;
        cup.position.set(cupHome.x, cupHome.y - (1 - cupK) * 90 + Math.sin(holdT / 900) * 4, cupHome.z);
        cup.scale.setScalar(Math.max(0.001, 0.8 + 0.2 * cupK));
        cup.material.uniforms.opacity.value = Math.min(1, cupK * 1.3) * fade;
        cup.material.uniforms.sheen.value = sheen;
        shock.material.uniforms.progress.value = shockP;
        shock.material.uniforms.opacity.value = shockP < 1 ? 1.2 : 0;
        heatV.scale.y = Math.max(0.001, heatVK);
        heatV.material.opacity = fade;
        heatH.scale.x = Math.max(0.001, heatHK);
        // The foot's light runs out from the corner of the L.
        heatH.position.x = right ? chamfer + footW * (1 - heatHK) : plateX - 4;
        heatH.material.opacity = fade;
        plate.pivot.rotation.y = dir * -(1 - plateK) * 80 * DEG;
        ghost.pivot.rotation.y = dir * -(1 - ghostK) * 80 * DEG;
        ghost.setOpacity(Math.min(1, ghostK * 1.4) * fade);
        plate.setOpacity(Math.min(1, plateK * 1.4) * fade);
        // The sweep runs away from the column.
        const s = right ? 1 - sweep : sweep;
        plate.front.uniforms.sweep.value = s;
        flare.position.x = plateX + s * W;
        flare.material.uniforms.opacity.value = flareAt(sweep) * fade;
        head.material.uniforms.reveal.value = k1;
        what.material.uniforms.reveal.value = k2;
        where.material.uniforms.reveal.value = k3;
        timeline.observe(seg, ph, t, meshes);
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
