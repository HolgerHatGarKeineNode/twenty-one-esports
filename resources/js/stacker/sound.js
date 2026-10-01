/**
 * Blockfill's sound (plan "Blockfill", P8): short effects for what the
 * player does and four chiptune loops (music.js), all synthesised with the
 * Web Audio API. No audio files, no borrowed melody, nothing in the engine.
 *
 * - Silent until a user gesture: the page calls unlock() from a pointer or
 *   key press; before that no AudioContext exists and every effect is
 *   skipped, not queued. With effects and music both off, not even a gesture
 *   creates one.
 * - Two channels, effects and music, each on/off with a volume 0-100, on
 *   their own gain node under one master, which runs through a fast
 *   compressor as a limiter. Effects start on, music off.
 * - Every player action and game event has its own cue (cuesFor() maps a
 *   session tick to them, several per tick where they belong together);
 *   the piece's cues are panned to its column, at most MAX_VOICES small
 *   cues ring at once, and auto-repeat and soft drop are thinned out.
 * - A hidden tab is silent: the context is suspended and the music
 *   scheduler stops; it picks up again when the tab is shown.
 * - prefers-reduced-motion changes nothing here (it is about motion). Where
 *   the browser offers it (navigator.audioSession, Safari), the session is
 *   "ambient", so a phone's silent switch mutes the game.
 * - The music is scheduled ahead on the audio clock: a timer every
 *   INTERVAL_MS queues the notes of the next LOOKAHEAD seconds. Nothing runs
 *   per frame, and with the music off the timer is not running at all.
 *
 * `env` is the browser (window) unless a test hands in a stand-in.
 */

import { compile, frequency, PIECES, pieceFor, stepSeconds, STEPS_PER_BAR, swingOffset, tempo, transposeFor } from './music.js';

export const SOUND_DEFAULTS = Object.freeze({ effects: 70, music: 50, effectsOn: true, musicOn: false });

/** How far ahead the music is queued (s), and how often the queue is topped up (ms). */
export const LOOKAHEAD = 0.25;
export const INTERVAL_MS = 60;

/**
 * Cues closer together than this (s) make one: a held key's auto-repeat and a
 * soft drop of 60 rows a second would otherwise rattle like a machine gun.
 */
const GAPS = { move: 0.045, repeat: 0.06, soft: 0.05 };

/** Effects sounding at once. A small cue past this is skipped; the big moments always play. */
export const MAX_VOICES = 8;

/** How long each effect rings (s), for the voice count. */
const LENGTHS = {
    move: 0.06, repeat: 0.04, rotate: 0.12, blocked: 0.08, soft: 0.03, drop: 0.3, touchdown: 0.12, lock: 0.04, hold: 0.2,
    mined: 0.4, halving: 1.6, combo: 0.4, warning: 0.5, count: 0.35, fanfare: 1.7, best: 2.6, topout: 0.75,
};

/** The cues a full voice count may skip: the player's own small actions, never a clear or the end. */
const SMALL = new Set(['move', 'repeat', 'rotate', 'blocked', 'soft', 'touchdown', 'lock', 'hold']);

/**
 * The effects for one tick of the session (session.js tick()), in the order
 * they sound; several at once where they belong together (a move and its
 * touchdown, a hard drop with its lock, the rows it mined and the combo).
 *
 * @param {object} outcome what session.tick() returned
 * @returns {Array<[string, unknown]>}
 */
export function cuesFor(outcome) {
    const cues = [];
    const where = outcome.where;
    if (outcome.moved) {
        cues.push(['move', where]);
    }
    if (outcome.repeat) {
        cues.push(['repeat', where]);
    }
    if (outcome.rotated) {
        cues.push(['rotate', { ...where, turn: outcome.turn }]);
    }
    if (outcome.blocked) {
        cues.push(['blocked', where]);
    }
    if (outcome.held) {
        cues.push(['hold', where]);
    }
    if (outcome.soft > 0) {
        cues.push(['soft', where]);
    }
    if (outcome.touchdown) {
        cues.push(['touchdown', where]);
    }
    if (outcome.dropped) {
        cues.push(['drop', where]);
    }
    if (outcome.locked > 0) {
        cues.push(['lock', where]);
    }
    if (outcome.cleared >= 4) {
        cues.push(['halving', outcome.cleared]);
    } else if (outcome.cleared > 0) {
        cues.push(['mined', outcome.cleared]);
    }
    if (outcome.combo >= 2) {
        cues.push(['combo', outcome.combo]);
    }
    if (outcome.warning) {
        cues.push(['warning', true]);
    }

    return cues;
}

/** The same shape as App\Support\Stacker\StackerSettings::normalizeSound(). */
export function normalizeSound(saved) {
    const s = saved && typeof saved === 'object' ? saved : {};
    const volume = (value, fallback) => (Number.isInteger(value) && value >= 0 && value <= 100 ? value : fallback);
    const flag = (value, fallback) => (typeof value === 'boolean' ? value : fallback);

    return {
        effects: volume(s.effects, SOUND_DEFAULTS.effects),
        music: volume(s.music, SOUND_DEFAULTS.music),
        effectsOn: flag(s.effectsOn, SOUND_DEFAULTS.effectsOn),
        musicOn: flag(s.musicOn, SOUND_DEFAULTS.musicOn),
    };
}

/** A volume 0-100 as gain: a curve, so the low end stays usable. */
function level(volume, ceiling) {
    return (volume / 100) ** 2 * ceiling;
}

/**
 * @param {{settings?: object, env?: object}} [options]
 */
export function createSound({ settings, env = globalThis } = {}) {
    let current = normalizeSound(settings);
    let scene = { mode: 'idle', kind: 'practice', remaining: 40 };
    let ctx = null;
    let buses = null;
    let noise = null;
    let waves = null;
    let unlocked = false;
    let timer = null;
    let seq = null;
    const last = {};
    let voices = [];
    const panners = new Map();
    const compiled = new Map();
    const stats = { calls: 0, totalMs: 0, maxMs: 0, notes: 0, contexts: 0 };

    const now = () => (env.performance ? env.performance.now() : Date.now());
    const hidden = () => env.document?.hidden === true;
    const effectsAudible = () => current.effectsOn && current.effects > 0;
    const musicAudible = () => current.musicOn && current.music > 0;

    function build() {
        const Context = env.AudioContext || env.webkitAudioContext;
        if (!Context) {
            return false;
        }
        try {
            // a phone's silent switch mutes an "ambient" session (Safari); elsewhere the default stays
            if (env.navigator?.audioSession && env.navigator.audioSession.type !== 'ambient') {
                env.navigator.audioSession.type = 'ambient';
            }
        } catch {
            // not offered: nothing to respect
        }
        ctx = new Context();
        stats.contexts++;
        const master = ctx.createGain();
        master.gain.value = 0.8;
        // a fast compressor as a limiter: a hard drop, its lock, a halving and the music together stay below full scale
        if (ctx.createDynamicsCompressor) {
            const limiter = ctx.createDynamicsCompressor();
            limiter.threshold.value = -12;
            limiter.knee.value = 3;
            limiter.ratio.value = 16;
            limiter.attack.value = 0.002;
            limiter.release.value = 0.12;
            master.connect(limiter).connect(ctx.destination);
        } else {
            master.connect(ctx.destination);
        }
        const effects = ctx.createGain();
        const music = ctx.createGain();
        effects.connect(master);
        music.connect(master);
        buses = { master, effects, music };
        applyLevels();

        // one second of noise, made once: every drum and click plays a slice of it
        noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
        const data = noise.getChannelData(0);
        for (let i = 0; i < data.length; i++) {
            data[i] = Math.random() * 2 - 1;
        }
        waves = { pulse25: pulseWave(0.25), pulse12: pulseWave(0.125) };

        return true;
    }

    /** A pulse wave with the given duty cycle, from its Fourier series. */
    function pulseWave(duty) {
        const harmonics = 32;
        const real = new Float32Array(harmonics);
        const imag = new Float32Array(harmonics);
        for (let n = 1; n < harmonics; n++) {
            real[n] = (2 / (n * Math.PI)) * Math.sin(n * Math.PI * duty);
        }

        return ctx.createPeriodicWave(real, imag);
    }

    function applyLevels() {
        if (!buses) {
            return;
        }
        buses.effects.gain.value = current.effectsOn ? level(current.effects, 1) : 0;
        buses.music.gain.value = current.musicOn ? level(current.music, 0.32) : 0;
    }

    // ---- building blocks -------------------------------------------------

    function oscillator(wave, hz, at) {
        const osc = ctx.createOscillator();
        if (waves[wave]) {
            osc.setPeriodicWave(waves[wave]);
        } else {
            osc.type = wave;
        }
        osc.frequency.setValueAtTime(hz, at);

        return osc;
    }

    /** One note: a quick attack, held, then a short release. */
    function note(out, wave, hz, at, length, peak) {
        const osc = oscillator(wave, hz, at);
        const gain = ctx.createGain();
        const end = at + Math.max(0.03, length);
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(peak, at + 0.005);
        gain.gain.setValueAtTime(peak, Math.max(at + 0.006, end - 0.03));
        gain.gain.exponentialRampToValueAtTime(0.0001, end + 0.04);
        osc.connect(gain).connect(out);
        osc.start(at);
        osc.stop(end + 0.06);
        stats.notes++;
    }

    /** A plucked note: straight into a decay, no hold. */
    function pluck(out, wave, hz, at, length, peak) {
        const osc = oscillator(wave, hz, at);
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(peak, at + 0.003);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        osc.connect(gain).connect(out);
        osc.start(at);
        osc.stop(at + length + 0.02);
        stats.notes++;
    }

    /** A slice of noise through a filter. */
    function burst(out, at, length, peak, type, hz) {
        const source = ctx.createBufferSource();
        source.buffer = noise;
        const filter = ctx.createBiquadFilter();
        filter.type = type;
        filter.frequency.value = hz;
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(peak, at);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        source.connect(filter).connect(gain).connect(out);
        source.start(at, Math.random() * 0.8, length + 0.02);
        stats.notes++;
    }

    /** A falling sine: kick drum and the hard drop's thump. */
    function thump(out, at, from, to, length, peak) {
        const osc = ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(from, at);
        osc.frequency.exponentialRampToValueAtTime(to, at + length);
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(peak, at);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        osc.connect(gain).connect(out);
        osc.start(at);
        osc.stop(at + length + 0.02);
        stats.notes++;
    }

    /** A plucked note that glides from one pitch to another: the clicks and turns. */
    function chirp(out, wave, from, to, at, length, peak) {
        const osc = oscillator(wave, from, at);
        osc.frequency.exponentialRampToValueAtTime(to, at + length);
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(peak, at + 0.003);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        osc.connect(gain).connect(out);
        osc.start(at);
        osc.stop(at + length + 0.02);
        stats.notes++;
    }

    /** Noise through a band-pass that slides: a whoosh, swelling in and out. */
    function sweep(out, at, length, peak, from, to) {
        const source = ctx.createBufferSource();
        source.buffer = noise;
        const filter = ctx.createBiquadFilter();
        filter.type = 'bandpass';
        filter.Q.value = 1.2;
        filter.frequency.setValueAtTime(from, at);
        filter.frequency.exponentialRampToValueAtTime(to, at + length);
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(peak, at + length * 0.45);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        source.connect(filter).connect(gain).connect(out);
        source.start(at, Math.random() * 0.7, length + 0.02);
        stats.notes++;
    }

    /** The effects bus, panned to where the piece is: column 0 a little left, 9 a little right. */
    function placed(where) {
        if (!where || typeof where.column !== 'number' || !ctx.createStereoPanner) {
            return buses.effects;
        }
        // one panner per half column, made once: an effect does not leave a node behind
        const slot = Math.max(0, Math.min(18, Math.round(where.column * 2)));
        if (!panners.has(slot)) {
            const panner = ctx.createStereoPanner();
            panner.pan.value = ((slot / 2 - 4.5) / 4.5) * 0.5;
            panner.connect(buses.effects);
            panners.set(slot, panner);
        }

        return panners.get(slot);
    }

    /** A bell: the fundamental plus two quieter, slightly inharmonic partials. */
    function bell(out, at, hz, length, peak) {
        pluck(out, 'sine', hz, at, length, peak);
        pluck(out, 'sine', hz * 2.01, at, length * 0.6, peak * 0.3);
        pluck(out, 'sine', hz * 3.02, at, length * 0.35, peak * 0.12);
    }

    // ---- effects ---------------------------------------------------------

    const A5 = 880;
    const fifth = 3 / 2;
    /* the major pentatonic over A, for anything that climbs */
    const PENTATONIC = [0, 2, 4, 7, 9];
    const climb = (base, step) => base + 12 * Math.floor(step / 5) + PENTATONIC[step % 5];
    const EFFECTS = {
        /* a dry key click with a falling body, panned to the piece */
        move(out, t) {
            chirp(out, 'triangle', A5 * 1.6, A5, t, 0.08, 1.4);
            burst(out, t, 0.015, 0.5, 'highpass', 4000);
        },
        /* auto-repeat: the same click, half as loud and shorter, so a held key purrs instead of rattling */
        repeat(out, t) {
            chirp(out, 'triangle', A5 * 1.45, A5 * 0.95, t, 0.05, 0.6);
        },
        /* a turn: a quick slide up clockwise, down counter-clockwise, up and back for the half turn */
        rotate(out, t, where = {}) {
            const low = A5 * 0.7;
            const high = low * fifth;
            if (where.turn === 3) {
                chirp(out, 'pulse25', high, low, t, 0.09, 1.1);
            } else if (where.turn === 2) {
                chirp(out, 'pulse25', low, high, t, 0.06, 1);
                chirp(out, 'pulse25', high, low, t + 0.05, 0.07, 0.95);
            } else {
                chirp(out, 'pulse25', low, high, t, 0.09, 1.1);
            }
            burst(out, t, 0.012, 0.35, 'highpass', 5000);
        },
        /* nothing moved: a dull knock on wood */
        blocked(out, t) {
            thump(out, t, 210, 120, 0.09, 1.2);
            burst(out, t, 0.04, 0.6, 'lowpass', 700);
        },
        /* one soft-drop step: a tiny grain */
        soft(out, t) {
            burst(out, t, 0.025, 0.9, 'bandpass', 4200);
            pluck(out, 'sine', 2600, t, 0.02, 0.18);
        },
        /* a hard drop: the air it falls through, then a heavy thump and the crack of the impact */
        drop(out, t) {
            sweep(out, t, 0.06, 0.3, 3200, 500);
            thump(out, t + 0.02, 200, 40, 0.24, 1);
            burst(out, t + 0.02, 0.08, 0.55, 'lowpass', 1200);
            burst(out, t + 0.02, 0.02, 0.3, 'bandpass', 2600);
        },
        /* the first rest on the floor or the stack ("Aufschlag"): a knock that sits higher the taller the stack under it */
        touchdown(out, t, where = {}) {
            const rows = Math.max(0, Math.min(18, where.stack ?? 0));
            const base = 140 * 2 ** (rows / 24);
            thump(out, t, base * 1.8, base * 0.75, 0.13, 1.4);
            burst(out, t, 0.04, 0.8, 'bandpass', 1300);
            pluck(out, 'triangle', base * 4, t, 0.05, 0.3);
        },
        /* the piece is set: a short latch click */
        lock(out, t) {
            burst(out, t, 0.035, 1.6, 'bandpass', 3300);
            thump(out, t, 620, 310, 0.05, 0.8);
            pluck(out, 'square', 1568, t, 0.03, 0.15);
        },
        /* into hold: a whoosh rising, with a faint glide under it */
        hold(out, t) {
            sweep(out, t, 0.2, 1.2, 500, 3600);
            chirp(out, 'sine', 520, 780, t + 0.02, 0.16, 0.3);
        },
        /* a block mined: an A major arpeggio up, one note longer per row, ending on a coin */
        mined(out, t, rows = 1) {
            const notes = [69, 73, 76, 81].slice(0, Math.min(4, rows + 1));
            notes.forEach((n, i) => pluck(out, 'pulse25', frequency(n + 12), t + i * 0.05, 0.16, 0.7));
            bell(out, t + notes.length * 0.05, frequency(notes[notes.length - 1] + 24), 0.3, 0.25);
        },
        /* four rows, a halving: a full chord, a deep hit under it and a bell over it */
        halving(out, t) {
            [69, 73, 76, 81].forEach((n) => note(out, 'pulse25', frequency(n), t, 0.45, 0.16));
            thump(out, t, 120, 50, 0.35, 0.6);
            [81, 85, 88, 93].forEach((n, i) => pluck(out, 'pulse12', frequency(n + 12), t + 0.06 + i * 0.04, 0.12, 0.18));
            bell(out, t + 0.12, frequency(93), 1.4, 0.4);
        },
        /* the n-th clearing piece in a row: two notes a fifth apart that climb the pentatonic with each one */
        combo(out, t, count = 2) {
            const n = climb(81, Math.max(0, Math.min(12, count - 2)));
            pluck(out, 'pulse12', frequency(n), t + 0.11, 0.12, 0.8);
            pluck(out, 'pulse12', frequency(n + 7), t + 0.17, 0.16, 0.7);
            pluck(out, 'pulse12', frequency(n + 7), t + 0.29, 0.12, 0.22);
        },
        /* ten rows left: a two-tone call, once */
        warning(out, t) {
            [88, 81, 88].forEach((n, i) => note(out, 'triangle', frequency(n), t + i * 0.12, 0.08, 0.4));
            note(out, 'pulse12', frequency(57), t, 0.36, 0.14);
        },
        /* the countdown: 3, 2, 1 on one pitch, then "go": a bright chord with a slide up */
        count(out, t, n = 1) {
            if (n > 0) {
                pluck(out, 'square', 660, t, 0.12, 0.7);
                pluck(out, 'sine', 1320, t, 0.1, 0.25);

                return;
            }
            [81, 85, 88].forEach((m) => note(out, 'pulse25', frequency(m), t, 0.22, 0.17));
            chirp(out, 'triangle', 880, 1760, t, 0.14, 0.25);
        },
        /* 40 rows: a quick triplet up and a held chord */
        fanfare(out, t) {
            [69, 73, 76].forEach((n, i) => note(out, 'sawtooth', frequency(n), t + i * 0.09, 0.08, 0.17));
            [73, 76, 81].forEach((n) => note(out, 'sawtooth', frequency(n), t + 0.27, 0.7, 0.13));
            bell(out, t + 0.27, frequency(93), 1.2, 0.35);
        },
        /* a new best, after the fanfare: a pentatonic run up two octaves and a high bell */
        best(out, t) {
            const at = t + 0.95;
            for (let i = 0; i < 10; i++) {
                pluck(out, 'pulse12', frequency(climb(69, i) + 12), at + i * 0.05, 0.12, 0.26);
            }
            bell(out, at + 0.5, frequency(88), 1.3, 0.4);
            bell(out, at + 0.5, frequency(93), 1.3, 0.22);
        },
        /* topped out: an A minor scale falling, getting quieter */
        topout(out, t) {
            [81, 79, 77, 76, 74, 72, 71, 69].forEach((n, i) => pluck(out, 'square', frequency(n), t + i * 0.075, 0.1, 0.5 * (1 - i * 0.09)));
        },
    };

    /** Effects that sound where the piece is. */
    const POSITIONAL = new Set(['move', 'repeat', 'rotate', 'blocked', 'soft', 'touchdown', 'drop', 'lock', 'hold']);

    // ---- music -----------------------------------------------------------

    function piece(id) {
        if (!compiled.has(id)) {
            compiled.set(id, compile(id));
        }

        return compiled.get(id);
    }

    function playStep(id, index, at, seconds, shift) {
        const out = buses.music;
        for (const event of piece(id).at[index]) {
            if (event.voice === 'drums') {
                const accent = event.accent ? 1.4 : 1;
                if (event.drum === 'kick') {
                    thump(out, at, 140, 42, 0.16, 0.8 * accent);
                } else if (event.drum === 'snare') {
                    burst(out, at, 0.11, 0.35 * accent, 'bandpass', 1900);
                } else if (event.drum === 'hat') {
                    burst(out, at, 0.03, 0.12 * accent, 'highpass', 7500);
                } else if (event.drum === 'tick') {
                    burst(out, at, 0.025, 0.22, 'highpass', 9000);
                } else if (event.drum === 'tock') {
                    burst(out, at, 0.035, 0.22, 'bandpass', 4200);
                }
                continue;
            }
            const part = PIECES[id].voices[event.voice];
            note(out, part.wave, frequency(event.note + shift), at, event.length * seconds * 0.92, 0.2 * part.level);
        }
    }

    /** Tops up the queue to LOOKAHEAD ahead of the audio clock. */
    function schedule() {
        const started = now();
        if (!ctx || !seq) {
            return;
        }
        if (seq.time < ctx.currentTime) {
            // behind (the tab was away): start again just ahead, never play the backlog
            seq.time = ctx.currentTime + 0.03;
        }
        const horizon = ctx.currentTime + LOOKAHEAD;
        while (seq.time < horizon) {
            if (seq.step % STEPS_PER_BAR === 0) {
                // a new bar: the place to change piece when the game asks for another one
                const wanted = pieceFor(scene);
                if (wanted !== seq.id) {
                    seq = { id: wanted, step: 0, loop: 0, time: seq.time };
                }
            }
            const seconds = stepSeconds(tempo(seq.id, scene.remaining));
            playStep(seq.id, seq.step, seq.time + swingOffset(seq.id, seq.step, seconds), seconds, transposeFor(seq.id, seq.loop));
            seq.time += seconds;
            seq.step++;
            if (seq.step >= piece(seq.id).steps) {
                seq.step = 0;
                seq.loop++;
            }
        }
        const spent = now() - started;
        stats.calls++;
        stats.totalMs += spent;
        stats.maxMs = Math.max(stats.maxMs, spent);
    }

    function startMusic() {
        if (timer !== null) {
            return;
        }
        seq = { id: pieceFor(scene), step: 0, loop: 0, time: ctx.currentTime + 0.05 };
        schedule();
        timer = env.setInterval(schedule, INTERVAL_MS);
    }

    function stopMusic() {
        if (timer !== null) {
            env.clearInterval(timer);
            timer = null;
        }
        seq = null;
    }

    /** Brings the context and the music in line with the settings and the tab. */
    function sync() {
        applyLevels();
        if (!ctx) {
            return;
        }
        if (hidden()) {
            stopMusic();
            if (ctx.state === 'running') {
                ctx.suspend().catch(() => {});
            }

            return;
        }
        if (ctx.state === 'suspended' && (effectsAudible() || musicAudible())) {
            ctx.resume().catch(() => {});
        }
        if (musicAudible()) {
            startMusic();
        } else {
            stopMusic();
        }
    }

    return {
        /** Call from a pointer or key press only: the one place a context is made. */
        unlock() {
            if (!effectsAudible() && !musicAudible()) {
                return false;
            }
            if (!ctx && !build()) {
                return false;
            }
            unlocked = true;
            sync();

            return true;
        },

        configure(next) {
            current = normalizeSound({ ...current, ...next });
            sync();

            return current;
        },

        settings() {
            return current;
        },

        /** What the page shows now: picks the piece (changed at the next bar) and its tempo. */
        setScene(next) {
            scene = { ...scene, ...next };
        },

        /** Plays one effect by name. True if it was scheduled. */
        effect(name, arg) {
            const play = EFFECTS[name];
            if (!play || !unlocked || !ctx || !effectsAudible() || hidden() || ctx.state === 'closed') {
                return false;
            }
            const t = ctx.currentTime + 0.005;
            if (GAPS[name] !== undefined) {
                if (last[name] !== undefined && t - last[name] < GAPS[name]) {
                    return false;
                }
                last[name] = t;
            }
            voices = voices.filter((end) => end > t);
            if (SMALL.has(name) && voices.length >= MAX_VOICES) {
                return false;
            }
            try {
                play(POSITIONAL.has(name) ? placed(arg) : buses.effects, t, arg);
            } catch {
                // a browser without one of the nodes: stay silent, never throw into the game
                return false;
            }
            voices.push(t + (LENGTHS[name] ?? 0.3));

            return true;
        },

        /** The tab was hidden or shown. */
        visibility() {
            sync();
        },

        /** For tests: one pass of the music scheduler, and what happened so far. */
        pump() {
            schedule();
        },

        debug() {
            return {
                unlocked,
                context: ctx !== null,
                state: ctx ? ctx.state : null,
                piece: seq ? seq.id : null,
                loop: seq ? seq.loop : 0,
                scheduling: timer !== null,
                scene,
                stats: { ...stats },
            };
        },

        destroy() {
            stopMusic();
            if (ctx && ctx.state !== 'closed') {
                ctx.close().catch(() => {});
            }
            ctx = null;
            buses = null;
            panners.clear();
            voices = [];
            unlocked = false;
        },
    };
}
