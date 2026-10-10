/**
 * The broadcast engine: stage + timeline + embers + the elements on air, and the test surface window.broadcast.
 * Overlays (plan P3-P6) and the styleguide both start here: createBroadcast(canvas) and then schedule elements.
 *
 * window.broadcast:
 *   timeline()        { now, rules, segments[] } with planned and observed phase times per element
 *   stats()           frame-time p50/p95/p99 over the last 600 frames, tier, drawing-buffer size, GL renderer
 *   sampleAlpha(x,y)  canvas alpha at a logical frame position (0 = transparent, OBS shows the game there)
 *   setTier(name)     high | medium | low
 *   layout()          every visible text, drawn plane and the 21 mark as a box in viewport px (see layoutOf)
 */

import { createBoardPage } from './elements/board.js';
import { createCamFrame } from './elements/camFrame.js';
import { createLowerThird } from './elements/lowerThird.js';
import { createPride } from './elements/pride.js';
import { createStinger } from './elements/stinger.js';
import { createTicker } from './elements/ticker.js';
import { createParticles } from './particles.js';
import { createStage } from './stage.js';
import { fontsReady, wireTextScale } from './text.js';
import { createTimeline } from './timeline.js';
import { GUARD } from './guard.js';
import { COLOR } from './tokens.js';

export function webglAvailable() {
    try {
        const c = document.createElement('canvas');

        return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch {
        return false;
    }
}

export async function createBroadcast(canvas, { tier = null } = {}) {
    const THREE = window.THREE;
    await fontsReady();
    const stage = createStage(canvas, { THREE, tier });
    wireTextScale(stage);
    const timeline = createTimeline(stage);
    const particles = createParticles(stage);
    const live = new Set();
    let lastT = null;

    stage.onFrame((t) => {
        const dt = lastT === null ? 16 : Math.min(100, t - lastT);
        lastT = t;
        live.forEach((el) => {
            el.update(t);
            if (t > el.seg.end + 200) {
                el.dispose();
                live.delete(el);
            }
        });
        particles.update(dt);
    });

    const add = (el) => { live.add(el); return el; };

    const api = {
        stage,
        timeline,
        particles,
        lowerThird: (spec) => add(createLowerThird(stage, timeline, { particles, ...spec })),
        pride: (spec) => add(createPride(stage, timeline, particles, spec)),
        ticker: (spec) => add(createTicker(stage, timeline, spec)),
        stinger: (spec) => add(createStinger(stage, timeline, particles, spec)),
        board: (spec) => add(createBoardPage(stage, timeline, spec)),
        camFrame: (spec) => add(createCamFrame(stage, timeline, spec)),
        /** A variant's own element ({ seg, update(t), dispose() }); it leaves the stage after its segment ends. */
        element: (el) => add(el),
        /** A full-screen scene (break, bracket): opaque ground, no centre to keep free. */
        fullScreen() {
            GUARD.fullScreen = true;
            GUARD.guardOn.value = 0;
            stage.setBackdrop(COLOR.ground);
            // The bars beside a fitted 16:9 frame (an ultrawide or square source) are ground as well.
            document.documentElement.style.background = COLOR.ground;
        },
        start: () => stage.start(),
    };

    window.broadcast = {
        webgl: true,
        timeline: () => timeline.snapshot(),
        stats: () => stage.stats(),
        resetStats: () => stage.resetStats(),
        sampleAlpha: (x, y) => stage.sampleAlpha(x, y),
        setTier: (name) => stage.setTier(name),
        live: () => [...live].map((el) => el.seg.id),
        layout: () => layoutOf(stage),
    };

    return api;
}

/**
 * The layout probe: what is on the screen now, as boxes in viewport px (x0, y0, x1, y1), plus the frame's own box.
 *
 * - kind `text`: a line (createLine) with its type box (the padding left out), `text` as asked, `shown` as drawn
 *   (an ellipsis when it was cut), `ink` and `room` in logical px (the drawn width and what its box gives it).
 *   A drawn plane (createCanvasPlane: the clock, ticker chips) is a `text` too, with `plane: true`.
 * - kind `mark`: the 21 mark of a hero, its projected bounds.
 * - `clipped`: drawn through a moving clip (the ticker's crawl), so it may leave its box on purpose.
 * - `layer`: `frame` (the 1920x1080 plane) or `world` (a 3D scene under it, the bracket); a world text carries
 *   `onPage` when a variant names the meshes of the page the camera stands on (stage.layoutScope()).
 *
 * Hidden things are left out: a mesh or a parent invisible, or opacity / reveal under 0.02.
 */
function layoutOf(stage) {
    const { THREE, canvas } = stage;
    const r = canvas.getBoundingClientRect();
    const v = new THREE.Vector3();
    const scope = stage.layoutScope ? stage.layoutScope() : null;
    const out = [];
    const shown = (o) => {
        for (let p = o; p; p = p.parent) if (!p.visible) return false;

        return true;
    };
    const project = (points, camera) => {
        let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
        points.forEach((p) => {
            v.copy(p).project(camera);
            const x = r.left + ((v.x + 1) / 2) * r.width;
            const y = r.top + ((1 - v.y) / 2) * r.height;
            x0 = Math.min(x0, x); x1 = Math.max(x1, x); y0 = Math.min(y0, y); y1 = Math.max(y1, y);
        });

        return { x0: +x0.toFixed(1), y0: +y0.toFixed(1), x1: +x1.toFixed(1), y1: +y1.toFixed(1) };
    };
    stage.views().forEach(([scene, camera, layer]) => {
        scene.updateMatrixWorld();
        camera.updateMatrixWorld();
        scene.traverse((o) => {
            if (o.userData.layout === 'mark' && shown(o)) {
                const box = new THREE.Box3().setFromObject(o);
                if (box.isEmpty()) return;
                const pts = [];
                [box.min.x, box.max.x].forEach((x) => [box.min.y, box.max.y].forEach((y) => [box.min.z, box.max.z].forEach((z) => pts.push(new THREE.Vector3(x, y, z)))));
                out.push({ kind: 'mark', layer, ...project(pts, camera) });

                return;
            }
            const line = o.userData.line;
            if (!line || !shown(o)) return;
            const u = o.material.uniforms;
            if (u.opacity.value < 0.02 || u.reveal.value < 0.02) return;
            // The type box: the plane less its padding, in the mesh's unit plane.
            const px = line.pad / Math.max(1, line.width);
            const py = line.pad / Math.max(1, line.height);
            const pts = [[-0.5 + px, -0.5 + py], [0.5 - px, -0.5 + py], [-0.5 + px, 0.5 - py], [0.5 - px, 0.5 - py]].map(([x, y]) => new THREE.Vector3(x, y, 0).applyMatrix4(o.matrixWorld));
            out.push({
                kind: 'text',
                layer,
                text: String(line.text ?? ''),
                shown: String(line.shown ?? line.text ?? ''),
                plane: !!line.plane,
                clock: !!line.clock,
                ink: +(line.ink ?? 0).toFixed(1),
                room: +(line.room ?? 0).toFixed(1),
                size: line.size,
                clipped: u.clip.value.z > u.clip.value.x,
                onPage: scope ? scope.has(o) : null,
                ...project(pts, camera),
            });
        });
    });

    return { frame: { x0: r.left, y0: r.top, x1: r.right, y1: r.bottom }, viewport: { w: innerWidth, h: innerHeight }, boxes: out };
}
