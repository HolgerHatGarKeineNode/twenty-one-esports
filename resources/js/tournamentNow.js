/**
 * tournamentNow({ id, state, go, seconds }) — the "What to do now" hero at the
 * top of a running tournament page (App\Support\Tournaments\TournamentNow).
 *
 * The hero carries its state in its wire:key, so a render that changes the
 * state (the tournament's push or the page's poll) builds a new hero and this
 * runs again. When the state turns into "play" on an open page (not on the
 * first load), the hero flips once, a short chime sounds and the player's game
 * opens after `seconds`; "Stay here" stops that.
 */
const seen = {};

export function tournamentNow({ id, state, go = null, seconds = 5 } = {}) {
    return {
        flipped: false,
        stopped: false,
        left: Math.max(1, Number(seconds) || 5),
        timer: null,

        init() {
            const before = seen[id];
            seen[id] = state;

            if (before === undefined || before === state || state !== 'play' || !go) {
                return;
            }

            this.flipped = true;
            this.chime();
            this.$el.scrollIntoView({ block: 'nearest' });
            this.timer = setInterval(() => this.tick(), 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        stay() {
            this.stopped = true;
            clearInterval(this.timer);
        },

        tick() {
            this.left = Math.max(0, this.left - 1);

            if (this.left === 0 && !this.stopped) {
                clearInterval(this.timer);
                window.location.assign(go);
            }
        },

        /** Two short rising notes; a browser that blocks audio before a click stays silent. */
        chime() {
            try {
                const Context = window.AudioContext || window.webkitAudioContext;

                if (!Context) {
                    return;
                }

                const audio = new Context();
                [[660, 0], [880, 0.14]].forEach(([frequency, offset]) => {
                    const tone = audio.createOscillator();
                    const gain = audio.createGain();
                    const at = audio.currentTime + offset;
                    tone.frequency.value = frequency;
                    gain.gain.setValueAtTime(0.0001, at);
                    gain.gain.exponentialRampToValueAtTime(0.12, at + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.13);
                    tone.connect(gain).connect(audio.destination);
                    tone.start(at);
                    tone.stop(at + 0.14);
                });
                setTimeout(() => audio.close().catch(() => {}), 600);
            } catch {
                // No sound is fine: the flip and the countdown carry the change.
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('tournamentNow', tournamentNow);
    });
}
