/**
 * Which events the page still has to show, by ply (plan "Hyperbitcoinization", P2). Every change of a match
 * is a run of plies; the page learns about it up to three ways: its own action's answer, the table's
 * broadcast (`hyper.updated`, a whole bot turn as one), and the catch-up endpoint (`/events?after=`) after a
 * reconnect, a truncated broadcast or a gap. `ply` is the last ply handed to the animation queue: a batch
 * that ends at or before it is a duplicate and dropped; one that does not start right at it leaves a gap,
 * which only the catch-up fills. So no event is shown twice and none is skipped.
 *
 * No DOM, so tests/js/hyperSync.test.mjs runs it in Node.
 */
export class PlySync {
    constructor(ply = 0) {
        this.ply = ply;
    }

    /**
     * A batch of live events from `from` (exclusive) to `to` (inclusive): `take` it, `drop` it (seen), or
     * fetch the events after `after` first (`gap`: it starts later, overlaps, or carries no events).
     */
    offer({ from, to, events }) {
        if (!Number.isInteger(to) || to <= this.ply) {
            return { type: 'drop' };
        }

        if (from !== this.ply || !Array.isArray(events)) {
            return { type: 'gap', after: this.ply };
        }

        this.ply = to;

        return { type: 'take', batch: { from, to, events } };
    }

    /**
     * An answer of the events endpoint: the plies right after `ply`, one batch each, in order; it stops at
     * the first hole. `more` when the match is further than this page of plies reached.
     */
    catchUp({ ply, actions }) {
        const batches = [];

        for (const action of actions ?? []) {
            if (action.ply <= this.ply) continue;
            if (action.ply !== this.ply + 1) break;
            batches.push({ from: this.ply, to: action.ply, seat: action.seat, source: action.source, events: action.events ?? [] });
            this.ply = action.ply;
        }

        return { batches, more: Number.isInteger(ply) && ply > this.ply };
    }

    /** A snapshot taken without its events (too far behind to replay): the queue starts again from there. */
    reset(ply) {
        this.ply = ply;
    }
}
