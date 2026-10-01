/**
 * The page's fixed 60 Hz clock: requestAnimationFrame only draws, this says how
 * many engine ticks are due since the last frame. A 30 Hz screen gets two ticks
 * per frame, a 144 Hz screen a tick every second or third frame; the game runs
 * at the same speed on both. After a long stall (a frozen tab) at most
 * `maxCatchUp` ticks are played at once and the rest is dropped. The engine
 * never sees a time.
 */

export const TICK_MS = 1000 / 60;

/**
 * @param {{maxCatchUp?: number}} [options]
 */
export function createTicker({ maxCatchUp = 30 } = {}) {
    let last = null;
    let pending = 0;

    return {
        /** Starts counting from `now` (ms, e.g. performance.now()). */
        reset(now) {
            last = now;
            pending = 0;
        },

        /** Ticks due at `now`. */
        due(now) {
            if (last === null) {
                last = now;

                return 0;
            }
            pending += Math.max(0, now - last);
            last = now;
            let ticks = Math.floor(pending / TICK_MS);
            if (ticks > maxCatchUp) {
                ticks = maxCatchUp;
                pending = 0;
            } else {
                pending -= ticks * TICK_MS;
            }

            return ticks;
        },
    };
}

/**
 * Ticks as the clock shows them: "0:15.96" (minutes, seconds, hundredths).
 * Milliseconds rounded down, as the league counts them (Blockfill::milliseconds()),
 * so the result screen and the weekly leaderboard show the same time.
 */
export function formatTicks(ticks, digits = 2) {
    const ms = Math.floor((ticks * 1000) / 60);
    const minutes = Math.floor(ms / 60000);
    const seconds = Math.floor((ms % 60000) / 1000);
    const fraction = String(ms % 1000).padStart(3, '0').slice(0, digits);

    return `${minutes}:${String(seconds).padStart(2, '0')}.${fraction}`;
}
