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

/**
 * startsIn({ at, labels }) — the coarse "starts in 5 d 3 h" under the start,
 * refreshed every 30 s; the same arithmetic as TournamentLanding::startsInText().
 */
export function startsInText(seconds, labels) {
    const s = Math.max(0, seconds);
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    const fill = (key) => labels[key].replace(':d', d).replace(':h', h).replace(':m', m);

    return d > 0 ? fill('dh') : h > 0 ? fill('hm') : m > 0 ? fill('m') : labels.soon;
}

export function startsIn({ at, labels }) {
    return {
        text: '',
        timer: null,

        init() {
            this.text = this.$el.textContent.trim();
            this.tick();
            this.timer = setInterval(() => this.tick(), 30_000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick() {
            this.text = startsInText(Math.floor((at - Date.now()) / 1000), labels);
        },
    };
}

/**
 * localTime({ at, zone, label }) — the start in the viewer's own zone, only
 * when that zone runs on another UTC offset than the league's at that moment
 * (Vienna needs no second line, New York does). Empty otherwise; always
 * one line, the zone's name in the title.
 */
export function localTime({ at, zone, label }) {
    return {
        text: '',
        zone: '',

        init() {
            const own = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const offset = (timeZone) => {
                const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone, hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric' })
                    .formatToParts(new Date(at)).map(({ type, value }) => [type, value]));

                return Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour) % 24, Number(parts.minute)) - Math.floor(at / 60000) * 60000;
            };

            if (!own || own === zone || offset(own) === offset(zone)) {
                return;
            }

            const time = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
            }).format(new Date(at));
            this.zone = own.replaceAll('_', ' ');
            this.text = label.replace(':time', time);
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('countdown', countdown);
        window.Alpine.data('countUp', countUp);
        window.Alpine.data('startsIn', startsIn);
        window.Alpine.data('localTime', localTime);
    });
}
