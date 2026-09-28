/**
 * casualClock(at, now) — the countdown of a casual 1v1 step in the match room
 * (pages/matches/partials/casual-steps): "04:12", "1:04:12" beyond an hour.
 *
 * `at` is the deadline in unix seconds, `now` the server's clock in ms when
 * the page was rendered. As in autoDecision.js, the difference to the device
 * clock is taken once, so a device that runs minutes off still counts to the
 * server's deadline. Without `now` it counts on the device clock.
 */

const pad = (value) => String(value).padStart(2, '0');

export function clockText(seconds) {
    const s = Math.max(0, seconds);
    const hours = Math.floor(s / 3600);
    const rest = `${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;

    return hours > 0 ? `${hours}:${rest}` : rest;
}

export function casualClock(at, now) {
    const skew = Number(now || Date.now()) - Date.now();

    return {
        left: '',
        timer: null,

        init() {
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        seconds() {
            return Math.max(0, Math.ceil((Number(at) * 1000 - (Date.now() + skew)) / 1000));
        },

        tick() {
            this.left = clockText(this.seconds());
        },
    };
}
