/**
 * tournamentNow({ id, state }) — the "What to do now" hero at the top of a
 * running tournament page (App\Support\Tournaments\TournamentNow).
 *
 * The hero carries its state in its wire:key, so a render that changes the
 * state (the tournament's push or the page's poll) builds a new hero and this
 * runs again. When the state turns into "play" on an open page (not on the
 * first load), the hero flips once and a short chime sounds. It never opens
 * the game on its own (user, 2026-10-03: "Das bitte ausmachen"): the big
 * "Go to your game" button does.
 */
const seen = {};

export function tournamentNow({ id, state } = {}) {
    return {
        flipped: false,

        init() {
            const before = seen[id];
            seen[id] = state;

            if (before === undefined || before === state || state !== 'play') {
                return;
            }

            this.flipped = true;
            this.chime();
            this.$el.scrollIntoView({ block: 'nearest' });
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
                // No sound is fine: the flip carries the change.
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('tournamentNow', tournamentNow);
    });
}
