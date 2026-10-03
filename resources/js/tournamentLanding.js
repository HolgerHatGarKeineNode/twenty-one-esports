/**
 * The tournament page's live numbers (pages::tournaments.show, sign-up):
 *
 *   countdown({ at, days }) — ticks down to `at` (ms since the epoch) once a
 *     second as "2 days 04:13:22"; `days` is the translated ":count day|:count
 *     days" pair. The server draws the first frame, so nothing jumps. At zero
 *     the page is refreshed from the server, which knows what comes next,
 *     and a bubbling `countdown-zero` event tells what the refresh cannot
 *     reach (the cup board's head sits in wire:ignore).
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
                // Parts of the page the refresh does not reach (wire:ignore) switch on this themselves.
                this.$el.dispatchEvent(new CustomEvent('countdown-zero', { bubbles: true }));
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
 * Zones a browser reports instead of the real one when it resists
 * fingerprinting: Firefox (and LibreWolf, Tor and Mullvad Browser) with
 * privacy.resistFingerprinting says UTC or Atlantic/Reykjavik
 * (support.mozilla.org/kb/resist-fingerprinting). A German player then read
 * "18:00 GMT+0" for a cup at 20:00 in Berlin (user, 2026-09-28). Such a zone
 * is no answer: the page keeps the time it named with a city.
 */
export const SPOOFED_ZONES = ['UTC', 'Etc/UTC', 'Etc/GMT', 'GMT', 'Etc/Universal', 'Etc/Zulu', 'Universal', 'Zulu', 'Atlantic/Reykjavik'];

/** The browser's own zone, or '' when it has none or reports a spoofed one. */
export function browserZone() {
    const own = Intl.DateTimeFormat().resolvedOptions().timeZone || '';

    return SPOOFED_ZONES.includes(own) ? '' : own;
}

/** Minutes east of UTC of `timeZone` at `at` (ms since the epoch). */
export function offsetAt(timeZone, at) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone, hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric' })
        .formatToParts(new Date(at)).map(({ type, value }) => [type, value]));

    return Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour) % 24, Number(parts.minute)) - Math.floor(at / 60000) * 60000;
}

/**
 * The cup board's start: { day, clock, city } of `at` in `timeZone`, in the
 * shape App\Support\Tournaments\CupBoard::start() writes on the server
 * ("Sat, Oct 10", "8:00 PM", "New York"; German "Sa, 10. Okt", "20:00").
 */
export function boardStart(at, timeZone, lang = 'en') {
    const german = String(lang).toLowerCase().startsWith('de');
    const part = (options) => Object.fromEntries(new Intl.DateTimeFormat(german ? 'de' : 'en', { timeZone, ...options })
        .formatToParts(new Date(at)).map(({ type, value }) => [type, value.replace(/\.$/, '')]));
    const date = part({ weekday: 'short', day: 'numeric', month: 'short' });
    const time = part(german ? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } : { hour: 'numeric', minute: '2-digit', hour12: true });
    const city = timeZone.slice(timeZone.lastIndexOf('/') + 1).replaceAll('_', ' ');

    return {
        day: german ? `${date.weekday}, ${date.day}. ${date.month}` : `${date.weekday}, ${date.month} ${date.day}`,
        clock: german ? `${time.hour}:${time.minute}` : `${time.hour}:${time.minute} ${String(time.dayPeriod || '').toUpperCase()}`,
        city,
    };
}

/**
 * cupStart({ at, zone }) — one row of the cup board: the server wrote the
 * start in `zone` (the cup's region); a browser with a real zone on another
 * offset rewrites day, clock and city to its own. Empty strings keep the
 * server's words.
 */
export function cupStart({ at, zone }) {
    return {
        day: '',
        clock: '',
        city: '',

        init() {
            const own = browserZone();

            if (!own || own === zone || offsetAt(own, at) === offsetAt(zone, at)) {
                return;
            }

            Object.assign(this, boardStart(at, own, document.documentElement.lang));
        },
    };
}

/**
 * cupBoard() — the cup board's filter bar on the tournaments page: game,
 * region, "free places only" and the order (by game or by start), all without
 * a reload. The server renders every cup; this only hides rows and reorders
 * rows and game groups in the DOM (so the focus order follows what is seen).
 * A group without a visible row hides; `shown` counts the visible cups.
 * Rows carry data-game, data-region, data-start (ms), data-free ("1"/"0")
 * and data-order (region order); groups data-order (game order).
 */
export function cupBoard() {
    return {
        game: 'all',
        region: 'all',
        freeOnly: false,
        sort: 'game',
        shown: 0,

        apply() {
            const { game, region, freeOnly, sort } = this;
            const list = this.$refs.groups;

            if (!list) {
                return;
            }

            let shown = 0;
            const groups = [...list.querySelectorAll(':scope > [data-cup-group]')];
            const byOrder = (a, b) => Number(a.dataset.order) - Number(b.dataset.order);

            for (const group of groups) {
                const rows = [...group.querySelectorAll('[data-cup-row]')];
                let visible = 0;
                let first = Infinity;

                for (const row of rows) {
                    const match = (game === 'all' || row.dataset.game === game)
                        && (region === 'all' || row.dataset.region === region)
                        && (!freeOnly || row.dataset.free === '1');
                    row.hidden = !match;

                    if (match) {
                        visible += 1;
                        first = Math.min(first, Number(row.dataset.start));
                    }
                }

                const byStart = (a, b) => Number(a.dataset.start) - Number(b.dataset.start) || byOrder(a, b);
                const rowList = rows[0]?.parentElement;
                rows.sort(sort === 'start' ? byStart : byOrder).forEach((row) => rowList.append(row));

                group.hidden = visible === 0;
                group.dataset.first = String(first);
                shown += visible;
            }

            groups.sort(sort === 'start' ? (a, b) => Number(a.dataset.first) - Number(b.dataset.first) || byOrder(a, b) : byOrder)
                .forEach((group) => list.append(group));
            this.shown = shown;
        },

        reset() {
            Object.assign(this, { game: 'all', region: 'all', freeOnly: false });
        },
    };
}

/**
 * localTime({ at, zone, label }) — the start in the viewer's own zone, only
 * when that zone runs on another UTC offset than the league's at that moment
 * (Vienna needs no second line, New York does), and never for a spoofed zone
 * (SPOOFED_ZONES). Empty otherwise; always one line, the zone's name in the title.
 */
export function localTime({ at, zone, label }) {
    return {
        text: '',
        zone: '',

        init() {
            const own = browserZone();

            if (!own || own === zone || offsetAt(own, at) === offsetAt(zone, at)) {
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

/**
 * tournamentLive({ id }) — on the Livewire root of the tournament page and its
 * sign-up page: renders the page again from the server when the tournament
 * moves (TournamentChanged on the public `tournament.{id}` channel: a sign-up,
 * a withdrawal, a result), so a casual cup's planned format and its bracket
 * preview follow every sign-up without a reload. Pushes within REFRESH_MS make
 * one render. Without a websocket (no window.Echo) the page's wire:poll is
 * the fallback.
 */
const REFRESH_MS = 300;

export function tournamentLive({ id }) {
    return {
        channel: null,
        timer: null,

        init() {
            if (!window.Echo) {
                return;
            }

            this.channel = window.Echo.channel('tournament.' + id);
            this.channel.listen('.tournament.changed', () => {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.$wire.$refresh(), REFRESH_MS);
            });
        },

        destroy() {
            clearTimeout(this.timer);

            if (this.channel) {
                this.channel.stopListening('.tournament.changed');
                this.channel = null;
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('tournamentLive', tournamentLive);
        window.Alpine.data('countdown', countdown);
        window.Alpine.data('countUp', countUp);
        window.Alpine.data('startsIn', startsIn);
        window.Alpine.data('localTime', localTime);
        window.Alpine.data('cupStart', cupStart);
        window.Alpine.data('cupBoard', cupBoard);
    });
}
