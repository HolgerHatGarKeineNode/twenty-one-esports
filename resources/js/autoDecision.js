/**
 * autoDecision({ at, now, soon }) — the countdown to the league's automatic
 * decision of a tournament match (P18, slice 5; <x-tournaments.auto-decision>):
 * "04:12", "1:04:12" beyond an hour, "2 d 04:12:00" beyond a day.
 *
 * `at` and `now` are the server's clock in ms: the deadline, and the moment
 * the page was rendered. The difference to the device clock is taken once,
 * so a device that runs minutes off still counts to the server's moment.
 * The server draws the first frame, so nothing jumps.
 *
 * At zero it shows `soon` and asks the page's component for a fresh render,
 * once, and only when it counted down to zero itself: a render that finds
 * the deadline already past (the league acts within the minute) must not
 * ask again, or the two would loop.
 */

const pad = (value) => String(value).padStart(2, '0');

export function formatLeft(seconds) {
    const s = Math.max(0, seconds);
    const days = Math.floor(s / 86400);
    const hours = Math.floor((s % 86400) / 3600);
    const clock = `${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;

    if (days > 0) {
        return `${days} d ${pad(hours)}:${clock}`;
    }

    return hours > 0 ? `${hours}:${clock}` : clock;
}

export function autoDecision({ at, now, soon = '' } = {}) {
    const skew = Number(now || Date.now()) - Date.now();

    return {
        text: '',
        timer: null,
        counted: false,

        init() {
            this.text = this.$el.textContent.trim();
            this.counted = this.left() > 0;
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        left() {
            return Math.max(0, Math.ceil((Number(at) - (Date.now() + skew)) / 1000));
        },

        tick() {
            const seconds = this.left();

            if (seconds > 0) {
                this.text = formatLeft(seconds);

                return;
            }

            clearInterval(this.timer);
            this.text = soon;

            if (this.counted) {
                this.counted = false;
                this.$wire?.$refresh();
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('autoDecision', autoDecision);
    });
}
