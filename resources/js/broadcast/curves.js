/**
 * The broadcast's motion curves. Each kind of movement has its own curve, so nothing runs on a default ease:
 *   set    a block being placed: fast start, long settle, a 2 % overshoot it takes back (plates, chips, emblems)
 *   reveal text uncovering behind its mask: steady, no overshoot (never wobble a word someone is about to read)
 *   leave  build-out: accelerates away, shorter than the way in
 *   sweep  the light passing over glass and metal: symmetric, soft at both ends
 *   drift  background motion only (particles, light plates): barely perceptible change of speed
 * Cubic-bezier values are given so the CSS side of the styleguide shows the same curves (resources/css/broadcast.css).
 */

function bezier(x1, y1, x2, y2) {
    const cx = 3 * x1, bx = 3 * (x2 - x1) - cx, ax = 1 - cx - bx;
    const cy = 3 * y1, by = 3 * (y2 - y1) - cy, ay = 1 - cy - by;
    const sx = (t) => ((ax * t + bx) * t + cx) * t;
    const sy = (t) => ((ay * t + by) * t + cy) * t;
    const dx = (t) => (3 * ax * t + 2 * bx) * t + cx;

    return (x) => {
        if (x <= 0) return 0;
        if (x >= 1) return 1;
        let t = x;
        for (let i = 0; i < 6; i++) {
            const d = dx(t);
            if (Math.abs(d) < 1e-6) break;
            t -= (sx(t) - x) / d;
        }
        t = Math.min(1, Math.max(0, t));

        return sy(t);
    };
}

export const BEZIER = Object.freeze({
    set: [0.12, 0.9, 0.24, 1.02],
    reveal: [0.33, 0.0, 0.12, 1.0],
    leave: [0.55, 0.0, 0.85, 0.36],
    sweep: [0.45, 0.05, 0.55, 0.95],
    drift: [0.37, 0.0, 0.63, 1.0],
});

export const CURVES = Object.freeze(Object.fromEntries(Object.entries(BEZIER).map(([name, v]) => [name, bezier(...v)])));

export const clamp01 = (v) => Math.min(1, Math.max(0, v));

/** Progress of `t` inside [start, start + duration], 0..1. */
export const span = (t, start, duration) => clamp01((t - start) / Math.max(1, duration));
