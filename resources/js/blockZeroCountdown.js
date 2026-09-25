/**
 * Countdown to the planned Block 0 on the pre-launch home.
 *
 * The server passes the seconds left instead of a timestamp, so a wrong
 * clock on the visitor's machine cannot skew the countdown. At zero the
 * ghost cube plays the release animation and the page switches to "due":
 * the board still releases Block 0 by hand, so nothing claims it is mined.
 */
const pad = (n) => String(n).padStart(2, '0');

export default function blockZeroCountdown({ secondsLeft, labels }) {
    return {
        left: Math.max(0, Math.floor(secondsLeft)),
        target: 0,
        timer: null,
        ticks: { d: 0, h: 0, m: 0, s: 0 },
        last: null,

        init() {
            this.target = Date.now() + this.left * 1000;
            this.last = this.parts();
            if (this.left > 0) {
                this.timer = setInterval(() => this.tick(), 1000);
            }
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick() {
            this.left = Math.max(0, Math.round((this.target - Date.now()) / 1000));
            const now = this.parts();
            for (const key of ['d', 'h', 'm', 's']) {
                if (now[key] !== this.last[key]) {
                    this.ticks[key] = 1 - this.ticks[key];
                }
            }
            this.last = now;
            if (this.left === 0) {
                clearInterval(this.timer);
            }
        },

        parts() {
            const t = this.left;
            return {
                d: Math.floor(t / 86400),
                h: Math.floor((t % 86400) / 3600),
                m: Math.floor((t % 3600) / 60),
                s: t % 60,
            };
        },

        get due() {
            return this.left === 0;
        },

        value(key) {
            return pad(this.parts()[key]);
        },

        level(key) {
            const max = { d: 7, h: 24, m: 60, s: 60 }[key];
            return Math.min(100, (this.parts()[key] / max) * 100).toFixed(1) + '%';
        },

        isOn(key) {
            return this.parts()[key] > 0 ? '1' : '0';
        },

        isWide(key) {
            return key === 'd' && this.parts().d > 99 ? '1' : '0';
        },

        get barClock() {
            const p = this.parts();
            return `${p.d} ${labels.d} ${pad(p.h)}:${pad(p.m)}:${pad(p.s)}`;
        },

        get spoken() {
            if (this.due) {
                return labels.due;
            }
            const p = this.parts();
            return labels.in.replace(':time', `${p.d} ${labels.days} ${p.h} ${labels.hours} ${p.m} ${labels.minutes} ${p.s} ${labels.seconds}`);
        },
    };
}
