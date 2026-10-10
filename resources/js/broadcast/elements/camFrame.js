/**
 * The camera frame (SLOTS.cam, module `cam-frame`, plan P3): a 16:9 window for the streamer's camera in the bottom
 * right, clear of the centre. Brushed metal bezel, chamfered on the corner that faces the stream, the hot edge along
 * its foot like every plate of the system; the window itself stays transparent (OBS shows the camera source there).
 * It carries no words, so the reading rule has nothing to hold; it stays on air.
 *
 * Build-in (900 ms): the foot's hot edge draws out from the right (0-400), the bezel reveals from right to left behind
 * it (150-850). Hold: still.
 */

import { CURVES, span } from '../curves.js';
import { createHeat } from '../materials.js';
import { createCanvasPlane, disposeTree, placeLine } from '../text.js';
import { COLOR, SLOTS } from '../tokens.js';

export function createCamFrame(stage, timeline, { start }) {
    const { THREE } = stage;
    const slot = SLOTS.cam;
    const B = 8;
    const W = slot.w + B * 2;
    const H = slot.h + B * 2;
    const C = 18;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x - B, slot.y - B);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const bezel = createCanvasPlane(stage, W, H, (ctx) => {
        const outline = (inset) => {
            ctx.beginPath();
            ctx.moveTo(C + inset, inset);
            ctx.lineTo(W - inset, inset);
            ctx.lineTo(W - inset, H - inset);
            ctx.lineTo(inset, H - inset);
            ctx.lineTo(inset, C + inset);
            ctx.closePath();
        };
        const g = ctx.createLinearGradient(0, 0, 0, H);
        g.addColorStop(0, '#4A4A52');
        g.addColorStop(0.5, COLOR.metal);
        g.addColorStop(1, '#1C1C21');
        outline(0);
        ctx.fillStyle = g;
        ctx.fill();
        // The window: cut out, the camera shows through.
        ctx.globalCompositeOperation = 'destination-out';
        ctx.fillRect(B, B, W - B * 2, H - B * 2);
        ctx.globalCompositeOperation = 'source-over';
        // Edge strokes: light on the outer edge, dark on the inner.
        outline(0.5);
        ctx.strokeStyle = 'rgba(255,255,255,0.22)';
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.strokeStyle = 'rgba(0,0,0,0.6)';
        ctx.strokeRect(B - 0.5, B - 0.5, W - B * 2 + 1, H - B * 2 + 1);
    });
    placeLine(bezel, 0, 0, 0.4);
    root.add(bezel.mesh);
    const foot = createHeat(THREE, W - 8, 3);
    foot.position.set(8, -H - 1, 1);
    root.add(foot);
    const side = createHeat(THREE, 3, H - C - 6);
    side.position.set(-3, -C - 4, 1);
    root.add(side);

    const seg = timeline.add({ kind: 'camFrame', slot: 'cam', texts: [], start, introMs: 900, holdMs: 3_600_000, outroMs: 600 });

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        timeline.observe(seg, ph, t, []);
        if (!root.visible) return;
        let footK = 1, bezelK = 1, sideK = 1;
        if (ph.name === 'intro') {
            footK = CURVES.reveal(span(ph.t, 0, 400));
            bezelK = CURVES.reveal(span(ph.t, 150, 700));
            sideK = CURVES.reveal(span(ph.t, 350, 450));
        } else if (ph.name === 'outro') {
            bezelK = sideK = footK = 1 - CURVES.leave(span(ph.t, 0, 600));
        }
        foot.scale.x = Math.max(0.001, footK);
        foot.position.x = 8 + (W - 8) * (1 - footK);
        side.scale.y = Math.max(0.001, sideK);
        bezel.material.uniforms.reveal.value = bezelK;
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
