/**
 * A player's upcoming events on home, /matches, /tournaments and a game page
 * (resources/views/components/⚡upcoming-events.blade.php): every `data-tick`
 * counts down once a second from the server's timestamps, corrected by the
 * offset between the server's clock and this one. When a number reaches zero
 * a phase has ended (a check-in opened, a match started), so the component
 * renders again; there is no poll for the clock.
 */
import { formatLeft } from './matchDock.js';

export default function upcomingEvents(config) {
    return {
        offset: config.now - Date.now(),
        timer: null,
        // `endsAt` values already crossed on this page: each refreshes once.
        crossed: new Set(),

        init() {
            this.tick(true);
            this.timer = setInterval(() => {
                if (!document.hidden) this.tick(false);
            }, 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick(first) {
            const now = Date.now() + this.offset;
            let ended = false;

            this.$root.querySelectorAll('[data-tick]').forEach((el) => {
                const tick = JSON.parse(el.dataset.tick);
                const left = tick.endsAt - now;
                const text = formatLeft(left, tick.format, config.labels);
                el.textContent = el.dataset.suffix ? el.dataset.suffix.replace(':left', text) : text;

                if (left <= 0 && !this.crossed.has(tick.endsAt)) {
                    this.crossed.add(tick.endsAt);
                    // At load a past number is the server's latest word already.
                    if (!first) ended = true;
                }
            });

            if (ended) this.$wire.$refresh();
        },
    };
}
