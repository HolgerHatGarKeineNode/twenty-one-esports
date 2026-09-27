/**
 * The tournament page's live numbers (pages::tournaments.show, sign-up):
 *
 *   countdown({ at, days }) — ticks down to `at` (ms since the epoch) once a
 *     second as "2 days 04:13:22"; `days` is the translated ":count day|:count
 *     days" pair. The server draws the first frame, so nothing jumps. At zero
 *     the page is refreshed from the server, which knows what comes next.
 *   countUp(value) — counts a number up from 0 once, on load. With reduced
 *     motion the server's number simply stays.
 */

const pad = (value) => String(value).padStart(2, '0');

export function countdown({ at, days = ':count day|:count days' } = {}) {
    const [one, other] = String(days).split('|');

    return {
        text: '',
        timer: null,

        init() {
            this.text = this.$el.textContent.trim();
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick() {
            const seconds = Math.max(0, Math.floor((at - Date.now()) / 1000));
            const d = Math.floor(seconds / 86400);
            const clock = `${pad(Math.floor((seconds % 86400) / 3600))}:${pad(Math.floor((seconds % 3600) / 60))}:${pad(seconds % 60)}`;
            this.text = (d > 0 ? `${(d === 1 ? one : other ?? one).replace(':count', d)} ` : '') + clock;

            if (seconds === 0) {
                clearInterval(this.timer);
                this.$wire?.$refresh();
            }
        },
    };
}

export function countUp(value) {
    return {
        init() {
            const target = Number(value);

            if (!(target > 0) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }

            const started = performance.now();
            const duration = 900;
            const step = (now) => {
                const t = Math.min(1, (now - started) / duration);
                // ease-out, matching the seats filling underneath
                this.$el.textContent = String(Math.round(target * (1 - (1 - t) ** 3)));

                if (t < 1) {
                    requestAnimationFrame(step);
                }
            };

            this.$el.textContent = '0';
            requestAnimationFrame(step);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('countdown', countdown);
    window.Alpine.data('countUp', countUp);
});
