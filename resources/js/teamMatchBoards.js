/**
 * The live view of a chess or Blockli team match (plan "Schach Rapid und Clan", P5; NIP rev. 9.25;
 * resources/views/components/⚡team-match-boards.blade.php).
 *
 * Live: the public watch channel of every board (`game.{id}.watch` for a chess
 * board, `board.{id}.watch` for a Blockli board: the channel spectators of a
 * single game hear) asks for a render after a move,
 * a clock event or the end of a board, debounced. The start of the boards and
 * the team result have no push for spectators: a render shortly after the
 * agreed start, and a slow poll while the match runs (a faster one without a
 * websocket). Between renders the running clocks and first-move windows count
 * down here, from the values and the server time of the last render.
 */

const REFRESH_DEBOUNCE_MS = 250;

/** m:ss, rounded up; the same as the server's render. */
export function formatClock(ms) {
    const total = Math.max(0, Math.ceil(ms / 1000));

    return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
}

export default function teamMatchBoards(config) {
    return {
        listened: new Map(),
        timers: [],
        debounce: null,
        busy: false,
        pending: false,
        lastRender: Date.now(),
        // The server time of the render the clocks count from, and when this browser first saw it.
        renderedAt: null,
        renderedLocal: 0,

        init() {
            this.timers.push(setInterval(() => {
                if (!document.hidden) this.tick();
            }, 1000));
            this.watch();

            if (config.running) {
                const pollMs = (window.Echo ? config.poll * 3 : config.poll) * 1000;
                this.timers.push(setInterval(() => {
                    if (!document.hidden && Date.now() - this.lastRender >= pollMs) this.requestRefresh();
                }, 5000));

                // The boards start at the agreed time: render just after it.
                const untilStart = config.startAt === null ? null : config.startAt - Number(this.$root.dataset.serverNow || Date.now());
                if (untilStart !== null && untilStart > 0 && untilStart < 2 * 3600 * 1000) {
                    this.timers.push(setTimeout(() => this.requestRefresh(), untilStart + 3000));
                }
            }
        },

        destroy() {
            this.timers.forEach((timer) => {
                clearInterval(timer);
                clearTimeout(timer);
            });
            clearTimeout(this.debounce);
            // Stop listening, never leave: the dock may watch the same game.
            this.listened.forEach(({ channel, event, handler }) => window.Echo?.channel(channel).stopListening(event, handler));
            this.listened.clear();
        },

        /** The boards on the page now, each heard on its watch channel once: a chess game's, or a board game's (`data-kind="board"`). */
        watch() {
            if (!window.Echo) return;
            this.$root.querySelectorAll('[data-game]').forEach((el) => {
                const board = el.dataset.kind === 'board';
                const channel = (board ? 'board.' : 'game.') + el.dataset.game + '.watch';
                if (this.listened.has(channel)) return;
                const event = board ? '.board.updated' : '.game.updated';
                const handler = () => this.requestRefresh();
                window.Echo.channel(channel).listen(event, handler);
                this.listened.set(channel, { channel, event, handler });
            });
        },

        requestRefresh() {
            this.pending = true;
            clearTimeout(this.debounce);
            this.debounce = setTimeout(() => this.flush(), REFRESH_DEBOUNCE_MS);
        },

        async flush() {
            if (!this.pending || this.busy) return;
            this.pending = false;
            this.busy = true;
            try {
                await this.$wire.$refresh();
            } finally {
                this.busy = false;
                this.lastRender = Date.now();
            }
            await this.$nextTick();
            this.watch();
            this.tick();
            if (this.pending) this.flush();
        },

        /** Each running clock from its rendered value and the time since that render. */
        tick() {
            const serverNow = Number(this.$root.dataset.serverNow || 0);
            const rendered = this.renderedAt === serverNow ? this.renderedLocal : (this.renderedLocal = Date.now());
            this.renderedAt = serverNow;
            const elapsed = Date.now() - rendered;
            this.$root.querySelectorAll('[data-clock]').forEach((el) => {
                const clock = JSON.parse(el.dataset.clock);
                if (clock.running) el.textContent = formatClock(clock.ms - elapsed);
            });
        },
    };
}
