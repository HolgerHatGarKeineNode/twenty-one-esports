/**
 * The pacing of the end-of-match statistics (stats.js, plan "Hyperbitcoinization", P3b; reworked in P4 on the user's
 * direction 2026-10-09: "Nichts darf zu schnell laufen oder verschwinden. Man muss folgen und LESEN können."), without
 * the page. Pure, so tests/js/hyperStats.test.mjs runs it in Node.
 *
 * No page turns by itself: every page stays until the player asks for the next (the Next button, → / Enter /
 * Space), and "Skip all" closes the whole. A page's own animation (lines drawing, numbers counting up, a scene
 * arriving) runs at a calm pace; until it has finished, Next first finishes it at once, and only the next Next
 * turns the page. The page itself says when its animation is over (finished()).
 */

/** A line chart draws for at least this long. */
export const LINE_MS = 2600;

/** A number counts up for at least this long. */
export const COUNT_MS = 1600;

/** A moment scene arrives (art, title, line, chips) in this time. */
export const SCENE_MS = 1800;

/** How long a caption with `text` stays at least: 3 s, or 60 ms per character for a long one. */
export function readableMs(text) {
    return Math.max(3000, 60 * [...String(text ?? '')].length);
}

/** Where the sequence stands: which page, whether its animation finished, whether it was closed. */
export class Sequence {
    constructor(count) {
        this.count = count;
        this.index = 0;
        this.ready = false;
        this.done = false;
    }

    /** A page is shown (by hand or by Next): its animation runs, Next waits for it. */
    begin(index) {
        if (index < 0 || index >= this.count) return this.index;
        this.index = index;
        this.ready = false;

        return this.index;
    }

    /** The page's animation is over: Next may turn the page now. */
    finished() { this.ready = true; }

    /**
     * The player asked for the next page: `finish` (the animation ends at once, the page stays), `show` (the next
     * page, its index in `index`), or `end` on the last page with its animation over.
     */
    next() {
        if (this.done) return { action: 'none', index: this.index };
        if (!this.ready) { this.ready = true; return { action: 'finish', index: this.index }; }
        if (this.index >= this.count - 1) return { action: 'end', index: this.index };

        return { action: 'show', index: this.begin(this.index + 1) };
    }

    /** Back one page, or to a page picked by its tab; out of range stays where it is. */
    go(index) { return this.begin(index); }

    /** Skip all, or close: nothing turns any more. */
    skip() { this.done = true; }
}
