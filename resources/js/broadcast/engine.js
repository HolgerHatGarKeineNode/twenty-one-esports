/**
 * The broadcast engine: stage + timeline + embers + the elements on air, and the test surface window.broadcast.
 * Overlays (plan P3-P6) and the styleguide both start here: createBroadcast(canvas) and then schedule elements.
 *
 * window.broadcast:
 *   timeline()        { now, rules, segments[] } with planned and observed phase times per element
 *   stats()           frame-time p50/p95/p99 over the last 600 frames, tier, drawing-buffer size, GL renderer
 *   sampleAlpha(x,y)  canvas alpha at a logical frame position (0 = transparent, OBS shows the game there)
 *   setTier(name)     high | medium | low
 */

import { createLowerThird } from './elements/lowerThird.js';
import { createPride } from './elements/pride.js';
import { createStinger } from './elements/stinger.js';
import { createTicker } from './elements/ticker.js';
import { createParticles } from './particles.js';
import { createStage } from './stage.js';
import { fontsReady, wireTextScale } from './text.js';
import { createTimeline } from './timeline.js';

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
    };

    return api;
}
