/**
 * League sounds (P5c), synthesised with the Web Audio API: no audio files,
 * no licence questions. The character is "mempool and blocks": soft clicks
 * for moves, bell-like chimes when a block is found (match found, game start,
 * win), all short and in one key family so they sound like one set.
 *
 * Browsers only let a page play sound after the player has interacted with
 * it (autoplay policy), so the context is created and resumed on the first
 * pointer or key press; a sound asked for before that is skipped, not queued.
 *
 * Settings come from <meta name="alert-settings"> (ChessSettings `sound`,
 * `volume`); the settings page changes them live through configure().
 * `prefers-reduced-motion` does not mute anything: it is about motion, and
 * sound follows the player's own switch.
 *
 * window.esportsSounds is the one instance per page, shared by every entry
 * (alerts, live board, daily board, settings); `calls` records every request
 * so a browser test can see that a sound was asked for.
 */

const SOUNDS = {
    /* Opponent found: two quick blocks land, then an open fifth rings out. */
    matchFound(t, out) {
        click(t, out, 0, 0.5, 2400);
        click(t, out, 0.09, 0.5, 2000);
        bell(t, out, 0.16, 659.25, 0.9, 0.5);
        bell(t, out, 0.26, 987.77, 1.1, 0.45);
    },

    /* Game start, "block mined": a low thump under a clear bell. */
    gameStart(t, out) {
        tone(t, out, 0, 110, 0.35, 'triangle', 0.7, 0.004);
        bell(t, out, 0.02, 880, 1.2, 0.5);
    },

    /* A move: a short wooden tap. */
    move(t, out) {
        click(t, out, 0, 0.9, 1800);
        tone(t, out, 0, 220, 0.06, 'triangle', 0.35, 0.002);
    },

    /* A capture: a heavier double tap, lower. */
    capture(t, out) {
        click(t, out, 0, 1, 1300);
        click(t, out, 0.035, 0.7, 900);
        tone(t, out, 0, 150, 0.1, 'triangle', 0.5, 0.002);
    },

    /* Check: two bright, quick notes upwards. */
    check(t, out) {
        tone(t, out, 0, 659.25, 0.09, 'triangle', 0.5, 0.004);
        tone(t, out, 0.1, 880, 0.12, 'triangle', 0.5, 0.004);
    },

    /* Low time (10 s, once): three soft ticks. */
    lowTime(t, out) {
        [0, 0.22, 0.44].forEach((at) => tone(t, out, at, 1046.5, 0.05, 'sine', 0.45, 0.002));
    },

    /* Win: the block chime, a full major arpeggio up. */
    win(t, out) {
        tone(t, out, 0, 98, 0.4, 'triangle', 0.5, 0.004);
        [392, 493.88, 587.33, 783.99].forEach((f, i) => bell(t, out, 0.02 + i * 0.1, f, 1.1, 0.4));
    },

    /* Draw: one calm open fifth. */
    draw(t, out) {
        bell(t, out, 0, 523.25, 1, 0.35);
        bell(t, out, 0.04, 783.99, 1, 0.3);
    },

    /* Loss: three soft notes down, no bell. */
    loss(t, out) {
        [329.63, 261.63, 220].forEach((f, i) => tone(t, out, i * 0.16, f, 0.35, 'triangle', 0.4, 0.01));
    },

    /* Notification ping: one small bell. */
    ping(t, out) {
        bell(t, out, 0, 1318.51, 0.5, 0.35);
    },
};

export const SOUND_NAMES = Object.keys(SOUNDS);

/* ---------- building blocks ------------------------------------------------------------------------------ */

function envelope(ctx, at, peak, attack, length) {
    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(peak, at + attack);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + length);

    return gain;
}

function tone(t, out, offset, frequency, length, type, peak, attack) {
    const ctx = out.context;
    const at = t + offset;
    const osc = ctx.createOscillator();
    osc.type = type;
    osc.frequency.setValueAtTime(frequency, at);
    const gain = envelope(ctx, at, peak, attack, length);
    osc.connect(gain).connect(out);
    osc.start(at);
    osc.stop(at + length + 0.05);
}

/* A bell: the fundamental plus two quieter, slightly inharmonic partials. */
function bell(t, out, offset, frequency, length, peak) {
    tone(t, out, offset, frequency, length, 'sine', peak, 0.005);
    tone(t, out, offset, frequency * 2.01, length * 0.6, 'sine', peak * 0.3, 0.005);
    tone(t, out, offset, frequency * 3.02, length * 0.35, 'sine', peak * 0.12, 0.005);
}

/* A click: a few milliseconds of band-passed noise. */
function click(t, out, offset, peak, frequency) {
    const ctx = out.context;
    const at = t + offset;
    const length = 0.04;
    const buffer = ctx.createBuffer(1, Math.ceil(ctx.sampleRate * length), ctx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < data.length; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / data.length) ** 3;
    const source = ctx.createBufferSource();
    source.buffer = buffer;
    const filter = ctx.createBiquadFilter();
    filter.type = 'bandpass';
    filter.frequency.value = frequency;
    filter.Q.value = 1.2;
    const gain = envelope(ctx, at, peak, 0.001, length);
    source.connect(filter).connect(gain).connect(out);
    source.start(at);
}

/* ---------- the page's one instance ---------------------------------------------------------------------- */

function readSettings() {
    try {
        const meta = document.querySelector('meta[name="alert-settings"]');
        const settings = meta ? JSON.parse(meta.content) : {};

        return { enabled: settings.sound !== false, volume: Number.isFinite(settings.volume) ? settings.volume : 60 };
    } catch {
        return { enabled: true, volume: 60 };
    }
}

function createSounds() {
    const settings = readSettings();
    let ctx = null;
    let master = null;
    let unlocked = false;

    const api = {
        enabled: settings.enabled,
        volume: settings.volume,
        calls: [],

        configure({ enabled, volume } = {}) {
            if (typeof enabled === 'boolean') this.enabled = enabled;
            if (Number.isFinite(volume)) this.volume = Math.max(0, Math.min(100, volume));
            if (master) master.gain.value = this.gain();
        },

        gain() {
            // 100 % is loud enough; the curve keeps the low end usable.
            return (this.volume / 100) ** 2 * 0.6;
        },

        unlock() {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            if (!ctx) {
                ctx = new AudioContext();
                master = ctx.createGain();
                master.gain.value = this.gain();
                master.connect(ctx.destination);
            }
            if (ctx.state === 'suspended') ctx.resume().catch(() => {});
            unlocked = true;
        },

        sampled: 0,

        /**
         * The settings page's "Play a sound": the next sound of the set on each press.
         */
        sample() {
            this.unlock();
            this.play(SOUND_NAMES[this.sampled++ % SOUND_NAMES.length]);
        },

        /**
         * Play one sound by name. Returns true if it was scheduled.
         */
        play(name) {
            this.calls.push(name);
            const sound = SOUNDS[name];
            if (!sound || !this.enabled || this.volume <= 0 || !unlocked || !ctx || ctx.state === 'closed') return false;
            const start = () => {
                try {
                    sound(ctx.currentTime + 0.01, master);
                } catch {
                    // A browser without one of the nodes: stay silent, never throw into the page.
                }
            };
            // Unlocked, but the context is still waking up (first gesture a moment ago).
            if (ctx.state === 'suspended') {
                ctx.resume().then(start).catch(() => {});
            } else {
                start();
            }

            return true;
        },
    };

    const unlockOnce = () => {
        api.unlock();
        if (ctx?.state === 'running' || unlocked) {
            ['pointerdown', 'keydown', 'touchend'].forEach((type) => window.removeEventListener(type, unlockOnce, true));
        }
    };
    ['pointerdown', 'keydown', 'touchend'].forEach((type) => window.addEventListener(type, unlockOnce, true));

    return api;
}

export function sounds() {
    window.esportsSounds ??= createSounds();

    return window.esportsSounds;
}

export function playSound(name) {
    return sounds().play(name);
}

/**
 * The sound for a move from its SAN: check wins over capture.
 */
export function moveSound(san) {
    if (!san) return 'move';
    if (san.includes('+') || san.includes('#')) return 'check';

    return san.includes('x') ? 'capture' : 'move';
}
