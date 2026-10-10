/**
 * The timeline: every element on air is a segment with a planned start, build-in, hold and build-out (ms on the stage
 * clock), and the frame loop records what actually happened: when each phase began, and how far any text of the
 * element moved while it was held (logical px; the reading rule wants 0). window.broadcast.timeline() hands this out
 * for tests/Browser/BroadcastStyleguideTest.php, which judges the real values against RULES, not the code.
 */

import { RULES, holdForTexts, wordsIn } from './timing.js';

export function createTimeline(stage) {
    const segments = [];
    let seq = 0;

    function add({ kind, slot, texts = [], start, introMs, holdMs, outroMs, extra = {} }) {
        const words = texts.reduce((n, t) => n + wordsIn(t), 0);
        const seg = {
            id: `${kind}-${++seq}`,
            kind,
            slot,
            texts,
            words,
            start: Math.round(start),
            introMs,
            holdMs: Math.round(holdMs),
            outroMs,
            end: Math.round(start + introMs + holdMs + outroMs),
            holdRuleMs: texts.length ? holdForTexts(texts) : 0,
            observed: { introStart: null, holdStart: null, outroStart: null, end: null },
            holdMotionPx: 0,
            ...extra,
        };
        segments.push(seg);

        return seg;
    }

    /** Phase of a segment at time t: before, intro, hold, outro, after; with progress p (0..1) inside the phase. */
    function phase(seg, t) {
        const a = seg.start;
        const b = a + seg.introMs;
        const c = b + seg.holdMs;
        const d = c + seg.outroMs;
        if (t < a) return { name: 'before', p: 0, t: 0 };
        if (t < b) return { name: 'intro', p: (t - a) / seg.introMs, t: t - a };
        if (t < c) return { name: 'hold', p: (t - b) / Math.max(1, seg.holdMs), t: t - b };
        if (t < d) return { name: 'outro', p: (t - c) / seg.outroMs, t: t - c };

        return { name: 'after', p: 1, t: t - d };
    }

    const marks = { intro: 'introStart', hold: 'holdStart', outro: 'outroStart', after: 'end' };

    /**
     * Called by an element every frame with its phase and the world positions of its text meshes: stamps phase starts
     * on the first frame that shows them, and accumulates movement of any text during the hold.
     */
    function observe(seg, ph, t, textMeshes = []) {
        const key = marks[ph.name];
        if (key && seg.observed[key] === null) seg.observed[key] = Math.round(t);
        if (ph.name === 'hold') {
            const now = textMeshes.map((m) => {
                // This frame's pose, not the last render's: the element has set its transforms already.
                m.updateWorldMatrix(true, false);
                const v = new stage.THREE.Vector3();
                m.getWorldPosition(v);
                const s = new stage.THREE.Vector3();
                m.getWorldScale(s);

                return [v.x, v.y, v.z, s.x, s.y];
            });
            if (seg._ref) {
                now.forEach((p, i) => {
                    const r = seg._ref[i];
                    if (!r) return;
                    const moved = Math.max(Math.hypot(p[0] - r[0], p[1] - r[1], p[2] - r[2]), Math.abs(p[3] - r[3]), Math.abs(p[4] - r[4]));
                    seg.holdMotionPx = Math.max(seg.holdMotionPx, +moved.toFixed(3));
                });
            } else {
                seg._ref = now;
            }
        }
    }

    /** End a segment's hold at `t` (an element with an open-ended hold), never under its reading rule. */
    function endAt(seg, t) {
        seg.holdMs = Math.round(Math.max(seg.holdRuleMs, Math.min(seg.holdMs, t - seg.start - seg.introMs)));
        seg.end = Math.round(seg.start + seg.introMs + seg.holdMs + seg.outroMs);
    }

    function snapshot() {
        return {
            now: Math.round(stage.now()),
            rules: RULES,
            segments: segments.map(({ _ref, ...s }) => ({ ...s, observed: { ...s.observed } })),
        };
    }

    return { add, phase, observe, endAt, snapshot, segments };
}
