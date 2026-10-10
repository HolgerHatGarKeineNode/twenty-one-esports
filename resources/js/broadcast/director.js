/**
 * The overlays' scheduling (plan P3, P4): who goes on air when, so nothing two elements say ever overlaps and the
 * stream gets its breath back between them. No regie: everything here runs on its own from the feed and snapshot.
 *
 * - Corner moments (pride): one at a time, queued by priority (a champion first) and then by arrival, on alternating
 *   corners (or one corner where the other is taken), each starting at least TIMING.prideGapMs after the last one has
 *   left, decided TIMING.prideLeadMs ahead so the riser can play into it; the shimmer plays when the figure lands.
 * - The lower third: one card at a time, in arrival order, at most one every TIMING.rotationMs (start to start),
 *   never before the last one has left plus TIMING.slotGapMs, and never next to a corner moment.
 *
 * Both queues are bounded: in a burst the oldest low-priority entries drop (the ticker still carries them).
 */

import { TIMING } from './timing.js';

const MAX_QUEUE = 8;
// The longest a lower third card stays on air (build-in, the QR card's hold, build-out), with a breath after it.
const CARD_MS = 12000;

export function createDirector(b, sound) {
    const later = (at, fn) => setTimeout(fn, Math.max(0, at - b.stage.now()));

    function corners({ sides = ['right', 'left'] } = {}) {
        const queue = [];
        let seq = 0;
        let nextFree = 0;
        let lastEnd = 0;
        let turn = 0;
        let started = 0;

        function pump() {
            if (queue.length === 0) return;
            const now = b.stage.now();
            if (now + TIMING.prideLeadMs < nextFree) return;
            queue.sort((x, y) => y.priority - x.priority || x.seq - y.seq);
            // A moment that waited past its shelf life (a round-1 win once the final is set) is old news: dropped.
            for (let i = queue.length - 1; i >= 0; i--) if (now - queue[i].at > queue[i].maxWaitMs) queue.splice(i, 1);
            if (queue.length === 0) return;
            const { moment } = queue.shift();
            const start = Math.max(now + TIMING.prideLeadMs, nextFree);
            const side = sides[turn++ % sides.length];
            const { landSound = 'shimmer', ...spec } = moment;
            const el = b.pride({ ...spec, start, side, onLand: () => sound.play(landSound) });
            later(start - 1200, () => sound.play('riser'));
            nextFree = el.seg.end + TIMING.prideGapMs;
            lastEnd = el.seg.end;
            started++;
        }
        const timer = setInterval(pump, 200);

        return {
            push(moment, priority = 1, maxWaitMs = Infinity) {
                queue.push({ moment, priority, seq: ++seq, at: b.stage.now(), maxWaitMs });
                if (queue.length > MAX_QUEUE) {
                    queue.sort((x, y) => y.priority - x.priority || x.seq - y.seq);
                    queue.pop();
                }
                pump();
            },
            /** How long the corners have been empty with nothing waiting (0 while busy). */
            idleMs() {
                const now = b.stage.now();

                return queue.length === 0 && now > lastEnd ? now - Math.max(lastEnd, 0) : 0;
            },
            state: () => ({ queued: queue.length, nextFree: Math.round(nextFree), started }),
            /** Until when the corners are taken: the end of the moment on air or scheduled, or the next one's start. */
            busyUntil(within) {
                const now = b.stage.now();
                if (lastEnd > now) return lastEnd;
                const next = queue.length > 0 ? Math.max(now + TIMING.prideLeadMs, nextFree) : Infinity;

                return next < now + within ? next + 12000 : 0;
            },
            /** Drop what waits and no longer holds (a place in the final, once the champion is known). */
            drop(test) {
                for (let i = queue.length - 1; i >= 0; i--) if (test(queue[i].moment)) queue.splice(i, 1);
            },
            stop: () => clearInterval(timer),
        };
    }

    /**
     * `yieldTo` (a corners queue): a card never starts while a corner moment is on air or about to start, so the
     * viewer reads one thing at a time; a moment never waits for a card (a live event shows within seconds).
     */
    function lowerThirds({ yieldTo = null } = {}) {
        const queue = [];
        let nextSlot = 0;
        let lastEnd = 0;

        function pump() {
            if (queue.length === 0) return;
            const now = b.stage.now();
            if (now + 300 < nextSlot) return;
            if (yieldTo && yieldTo.busyUntil(CARD_MS) > now) return;
            const spec = queue.shift();
            const start = Math.max(now + 300, nextSlot);
            const el = b.lowerThird({ ...spec, start });
            nextSlot = Math.max(start + TIMING.rotationMs, el.seg.end + TIMING.slotGapMs);
            lastEnd = el.seg.end;
        }
        setInterval(pump, 200);

        return {
            push(spec) {
                queue.push(spec);
                if (queue.length > MAX_QUEUE) queue.shift();
                pump();
            },
            /** Whether the slot is free now and nothing waits for it. */
            free: () => queue.length === 0 && b.stage.now() + 300 >= nextSlot && !(yieldTo && yieldTo.busyUntil(CARD_MS) > b.stage.now()),
            idleMs: () => (queue.length === 0 ? Math.max(0, b.stage.now() - lastEnd) : 0),
        };
    }

    return { corners, lowerThirds, later };
}
