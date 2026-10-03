/**
 * tournamentGameEnd({ seconds, fallback, reveal }) — the countdown of the panel a
 * player sees when a tournament game ends (<x-tournaments.game-end>,
 * App\Support\Tournaments\TournamentGameEnd).
 *
 * It counts `seconds` down once a second; "Stay here" stops it. At zero it
 * asks the page's component where to go (`tournamentNext()`: the next game
 * of the same pairing once it exists, else the tournament page) and goes
 * there. If that answer fails, it goes to `fallback`, the tournament page:
 * a player is never left on a finished game by a failed request.
 */
export function tournamentGameEnd({ seconds = 10, fallback = '', reveal = false } = {}) {
    return {
        left: Math.max(1, Number(seconds) || 10),
        stopped: false,
        timer: null,

        init() {
            this.timer = setInterval(() => this.tick(), 1000);

            // A live board's end card: on a phone it reaches under the chat bar, so the countdown is scrolled into view.
            if (reveal) {
                this.$nextTick(() => this.$el.scrollIntoView({ block: 'nearest' }));
            }
        },

        destroy() {
            clearInterval(this.timer);
        },

        stay() {
            this.stopped = true;
            clearInterval(this.timer);
        },

        tick() {
            if (this.stopped) {
                return;
            }

            this.left = Math.max(0, this.left - 1);

            if (this.left === 0) {
                clearInterval(this.timer);
                this.go();
            }
        },

        async go() {
            let url = fallback;

            try {
                const answer = await this.$wire?.tournamentNext();

                if (typeof answer === 'string' && answer !== '') {
                    url = answer;
                }
            } catch {
                // The tournament page, as promised.
            }

            if (!this.stopped && url) {
                window.location.assign(url);
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('tournamentGameEnd', tournamentGameEnd);
    });
}
