/**
 * The timing of the end-of-match statistics (stats.js, plan "Hyperbitcoinization", P3b), without the page: how long
 * each page stays while the sequence plays by itself, which page is due after some time, and how skipping or
 * turning a page by hand ends the autoplay. Pure, so tests/js/hyperStats.test.mjs runs it in Node.
 *
 * Pages: the charts, the leaderboards, then one per moment. The whole runs 30 to 60 seconds: a moment stays
 * 3.2 to 4.5 s; with few moments the charts and the tiles stay longer, so it never runs under 30 s.
 */
export const MIN_MS = 30000;
export const MAX_MS = 60000;

/** Milliseconds per page for a match with `moments` moment scenes. */
export function sequencePlan(moments) {
    const per = moments === 0 ? 0 : Math.min(4500, Math.max(3200, (52000 - 20000) / moments));
    const plan = [12000, 8000, ...Array.from({ length: moments }, () => per)];
    const sum = plan.reduce((a, b) => a + b, 0);
    if (sum < MIN_MS) { plan[0] += (MIN_MS - sum) * 0.6; plan[1] += (MIN_MS - sum) * 0.4; }

    return plan;
}

/** Where the sequence stands: playing by itself (`auto`), or browsed by hand, and on which page. */
export class Sequence {
    constructor(plan) {
        this.plan = plan;
        this.total = plan.reduce((a, b) => a + b, 0);
        this.auto = false;
        this.index = 0;
    }

    play() { this.auto = true; this.index = 0; }

    /** The page due `elapsed` ms after play(); past the last page the autoplay ends there. */
    at(elapsed) {
        if (!this.auto) return this.index;
        let end = 0;
        for (let i = 0; i < this.plan.length; i++) {
            end += this.plan[i];
            if (elapsed < end) { this.index = i; return i; }
        }
        this.auto = false;
        this.index = this.plan.length - 1;

        return this.index;
    }

    /** A page turned by hand (a tab, previous/next, a key): the autoplay stops; out of range stays where it is. */
    go(index) {
        this.auto = false;
        if (index >= 0 && index < this.plan.length) this.index = index;

        return this.index;
    }

    /** Skip or close: nothing plays on. */
    skip() { this.auto = false; }
}
