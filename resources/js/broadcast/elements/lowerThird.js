/**
 * Lower third: who and what, below the free centre and above the ticker (SLOTS.lowerThird).
 *
 * Layers, back to front: a warm halo behind the emblem; the emblem block (metal glass, 112 px); the hot edge as the
 * plate's leading edge; the plate (glass with its metal lip, chamfered top right: it travels right); the game emblem,
 * 128 px, standing on the block and breaking out over its top by 24 px with a rim light from the hot edge; the text;
 * the flare riding the lip.
 *
 * Build-in (TIMING.lowerThirdIntroMs = 1000): the hot edge draws down (0-360), the block is set (swing from 80 deg
 * about its left edge, curve `set`, 60-620), the emblem rises into place (220-760) and lands with a spark spray and a
 * flash along the hot edge (640), the plate is set (180-680) while a sweep and its flare cross it (300-760), the name
 * reveals (380-900), the line under it (480-1000). Hold: nothing on the text moves; a sweep crosses the glass and a
 * sheen the emblem every 5 s. Build-out (600): text covers (0-300), emblem sinks (100-450), plates swing away
 * (150-600), the hot edge retracts last (300-600).
 */

import { CURVES, span } from '../curves.js';
import { createFlare, createHalo, createHeat, createImage, createSlab } from '../materials.js';
import { drawMark } from '../mark.js';
import { createCanvasPlane, createLine, disposeTree, placeLine } from '../text.js';
import { COLOR, SLOTS, TYPE } from '../tokens.js';
import { TIMING, holdForTexts } from '../timing.js';

export const DEG = Math.PI / 180;

/** Place a text line inside a parent whose origin is its left top: (left, top) is the type's box without padding. */
export function place(line, left, top, z = 0.6) {
    placeLine(line, left, top, z);
}

/** A light sweep's position along a plate (0..1 crosses it) and the flare's brightness there. */
export const flareAt = (sweep) => Math.sin(Math.PI * Math.min(1, Math.max(0, sweep)));

/** An SVG document (the snapshot's QR code) as an image the 2D canvas can draw; calls back once it can. */
function svgImage(svg, onLoad) {
    const img = new Image();
    img.addEventListener('load', onLoad, { once: true });
    img.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;

    return img;
}

/**
 * `qr` (an SVG QR code) puts a scannable tile of 144 px on a wider block instead of an emblem: dark modules on white,
 * drawn without smoothing at the stage's text scale, so each module stays a hard square in 1080p and 4K. `mark` puts
 * the league's 21 mark there when there is no emblem.
 */
export function createLowerThird(stage, timeline, { start, name, line, emblem = null, qr = null, mark = false, holdMs = 0, particles = null, slot = SLOTS.lowerThird, kind = 'lowerThird' }) {
    const { THREE } = stage;
    const H = slot.h;
    const B = qr ? 160 : 112;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const plateX = B + 4;
    // A name or a tournament's title shrinks to half (22 px, the type floor) before it is cut.
    const nameLine = createLine(stage, name, TYPE.title, { color: COLOR.ink, maxWidth: slot.w - plateX - 64, minScale: 0.5 });
    const subLine = createLine(stage, line, TYPE.data, { color: COLOR.ink2, maxWidth: slot.w - plateX - 64 });
    const contentW = Math.max(nameLine.width - nameLine.pad * 2, subLine.width - subLine.pad * 2);
    const W = Math.min(slot.w - plateX, Math.max(440, Math.ceil(contentW + 64)));

    const block = createSlab(THREE, B, H, { top: COLOR.metal, bottom: COLOR.glass, spill: 'right' });
    root.add(block.group);
    let image = null;
    let halo = null;
    if (emblem) {
        halo = createHalo(THREE, 220, COLOR.btc, 0.3);
        halo.position.set(B / 2, -H / 2 + 10, 2);
        halo.renderOrder = 1.5;
        root.add(halo);
        image = createImage(THREE, emblem, 128, 128, { rim: 1.1, rimFrom: [1, 0.25] });
        root.add(image);
    }
    const at = stage.onScreen(o.x + B / 2 + 4, o.y - H / 2 + 14, 18);
    const emblemHome = { x: at.x - o.x, y: at.y - o.y, z: 18 };
    // A drawn figure on the block instead of an emblem image: the QR tile or the league mark.
    let tile = null;
    let tileHome = null;
    if (qr || (mark && !emblem)) {
        const S = qr ? 144 : 92;
        let plane = null;
        const img = qr ? svgImage(qr, () => plane && plane.redraw()) : null;
        plane = createCanvasPlane(stage, S, S, (ctx) => {
            if (!qr) {
                drawMark(ctx, 0, 0, S);

                return;
            }
            ctx.fillStyle = '#FFFFFF';
            ctx.beginPath();
            ctx.roundRect(0, 0, S, S, 6);
            ctx.fill();
            if (!img.complete || !img.naturalWidth) return;
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(img, 4, 4, S - 8, S - 8);
        });
        tile = plane;
        tile.mesh.renderOrder = 4;
        root.add(tile.mesh);
        const p = stage.onScreen(o.x + B / 2, o.y - H / 2 + (qr ? 20 : 8), 18);
        tileHome = { x: p.x - o.x, y: p.y - o.y, z: 18 };
        if (!emblem) {
            halo = createHalo(THREE, qr ? 300 : 220, COLOR.btc, 0.3);
            halo.position.set(B / 2, -H / 2 + 10, 2);
            halo.renderOrder = 1.5;
            root.add(halo);
        }
    }

    const heat = createHeat(THREE, 4, H);
    heat.position.set(B, 0, 1);
    root.add(heat);
    const flash = createFlare(THREE, 220, 26);
    flash.rotation.z = Math.PI / 2;
    flash.position.set(B + 2, -H / 2, 4);
    root.add(flash);

    // A second, fainter pane behind the plate, 16 px out to the right and 8 px down: the plate reads as one of a stack.
    const ghost = createSlab(THREE, W, H, { corner: 'tr', cover: 0.5, top: '#2C2C33', bottom: '#111114', depth: 12 });
    ghost.group.position.set(plateX + 16, -8, -26);
    root.add(ghost.group);
    const plate = createSlab(THREE, W, H, { corner: 'tr', spill: 'left' });
    plate.group.position.x = plateX;
    root.add(plate.group);
    const nameTop = 10;
    place(nameLine, 28, nameTop);
    place(subLine, 28, nameTop + TYPE.title.size * TYPE.title.leading + 2);
    plate.pivot.add(nameLine.mesh, subLine.mesh);
    const flare = createFlare(THREE, 300, 22);
    flare.position.set(plateX, -3, 3);
    root.add(flare);

    const intro = TIMING.lowerThirdIntroMs;
    const outro = TIMING.lowerThirdOutroMs;
    const seg = timeline.add({ kind, slot: kind, texts: [name, line], start, introMs: intro, holdMs: Math.max(holdMs, holdForTexts([name, line])), outroMs: outro });
    const texts = [nameLine.mesh, subLine.mesh];
    let landed = false;

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        if (!root.visible) {
            timeline.observe(seg, ph, t, texts);

            return;
        }
        let ghostK = 1, heatK = 1, blockK = 1, plateK = 1, nameK = 1, subK = 1, emblemK = 1, sweep = -1, sheen = -1, fade = 1, flashK = 0;
        if (ph.name === 'intro') {
            const x = ph.t;
            heatK = CURVES.reveal(span(x, 0, 360));
            blockK = CURVES.set(span(x, 60, 560));
            emblemK = CURVES.set(span(x, 220, 540));
            plateK = CURVES.set(span(x, 180, 500));
            ghostK = CURVES.set(span(x, 280, 520));
            nameK = CURVES.reveal(span(x, 380, 520));
            subK = CURVES.reveal(span(x, 480, 520));
            sweep = -0.4 + 1.8 * CURVES.sweep(span(x, 300, 460));
            sheen = -0.5 + 2 * CURVES.sweep(span(x, 560, 440));
            flashK = Math.exp(-Math.pow((x - 700) / 110, 2));
            if (!landed && x >= 640) {
                landed = true;
                if (particles) particles.emit(slot.x + B, slot.y + 4, 26, { w: 4, h: H - 8, speed: 150, rise: 20, lifeMs: 900, sizePx: 6, radial: true, z: 10 });
            }
        } else if (ph.name === 'hold') {
            const cycle = ph.t % 5000;
            sweep = -0.4 + 1.8 * CURVES.sweep(span(cycle, 1800, 1100));
            sheen = -0.5 + 2 * CURVES.sweep(span(cycle, 2300, 900));
        } else if (ph.name === 'outro') {
            const x = ph.t;
            nameK = subK = 1 - CURVES.leave(span(x, 0, 300));
            emblemK = 1 - CURVES.leave(span(x, 100, 350));
            plateK = blockK = 1 - CURVES.leave(span(x, 150, 450));
            ghostK = 1 - CURVES.leave(span(x, 100, 420));
            heatK = 1 - CURVES.leave(span(x, 300, 300));
            fade = 1 - span(x, 350, 250);
        }
        heat.scale.y = Math.max(0.001, heatK);
        heat.material.opacity = fade;
        flash.material.uniforms.opacity.value = flashK * 1.4;
        block.pivot.rotation.y = (1 - blockK) * 80 * DEG;
        plate.pivot.rotation.y = (1 - plateK) * 80 * DEG;
        ghost.pivot.rotation.y = (1 - ghostK) * 80 * DEG;
        ghost.setOpacity(Math.min(1, ghostK * 1.4) * fade);
        block.setOpacity(Math.min(1, blockK * 1.4) * fade);
        plate.setOpacity(Math.min(1, plateK * 1.4) * fade);
        if (image) {
            image.position.set(emblemHome.x, emblemHome.y - (1 - emblemK) * 28, emblemHome.z);
            image.scale.setScalar(Math.max(0.001, 0.72 + 0.28 * emblemK));
            image.material.uniforms.opacity.value = Math.min(1, emblemK * 1.3) * fade;
            image.material.uniforms.sheen.value = sheen;
            halo.material.uniforms.opacity.value = 0.34 * emblemK * fade;
        }
        if (tile) {
            tile.mesh.position.set(tileHome.x, tileHome.y - (1 - emblemK) * 28, tileHome.z);
            tile.mesh.scale.set(tile.width * Math.max(0.001, 0.72 + 0.28 * emblemK), tile.height * Math.max(0.001, 0.72 + 0.28 * emblemK), 1);
            tile.material.uniforms.opacity.value = Math.min(1, emblemK * 1.3) * fade;
            if (!image) halo.material.uniforms.opacity.value = 0.34 * emblemK * fade;
        }
        plate.front.uniforms.sweep.value = sweep;
        flare.position.x = plateX + sweep * W;
        flare.material.uniforms.opacity.value = flareAt(sweep) * 0.9 * fade;
        nameLine.material.uniforms.reveal.value = nameK;
        subLine.material.uniforms.reveal.value = subK;
        timeline.observe(seg, ph, t, texts);
    }

    return {
        seg,
        update,
        /** Leave from `t` on (a persistent banner whose words changed): the hold never ends under its reading rule. */
        retire(t) { timeline.endAt(seg, t); },
        dispose() {
            stage.scene.remove(root);
            disposeTree(root);
        },
    };
}
