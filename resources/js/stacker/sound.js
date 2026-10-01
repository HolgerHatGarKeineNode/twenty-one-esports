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
 *   their own gain node under one master. Effects start on, music off.
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

/** Moves closer together than this (s) make one tick, so auto-repeat does not rattle. */
const MOVE_GAP = 0.045;

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
    let lastMove = -1;
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
        master.connect(ctx.destination);
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
        buses.effects.gain.value = current.effectsOn ? level(current.effects, 0.6) : 0;
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

    /** A bell: the fundamental plus two quieter, slightly inharmonic partials. */
    function bell(out, at, hz, length, peak) {
        pluck(out, 'sine', hz, at, length, peak);
        pluck(out, 'sine', hz * 2.01, at, length * 0.6, peak * 0.3);
        pluck(out, 'sine', hz * 3.02, at, length * 0.35, peak * 0.12);
    }

    // ---- effects ---------------------------------------------------------

    const A5 = 880;
    const third = 5 / 4;
    const EFFECTS = {
        /* a dry tick */
        move(out, t) {
            pluck(out, 'triangle', A5, t, 0.035, 0.3);
        },
        /* the same tick, a major third higher */
        rotate(out, t) {
            pluck(out, 'triangle', A5 * third, t, 0.04, 0.3);
        },
        /* a low thump with a little grit */
        drop(out, t) {
            thump(out, t, 170, 48, 0.14, 0.9);
            burst(out, t, 0.05, 0.25, 'lowpass', 500);
        },
        /* a short click */
        lock(out, t) {
            burst(out, t, 0.025, 0.35, 'bandpass', 2600);
        },
        /* a block mined: an A major arpeggio up, one note longer per row */
        mined(out, t, rows = 1) {
            const notes = [69, 73, 76, 81].slice(0, Math.min(4, rows + 1));
            notes.forEach((n, i) => pluck(out, 'pulse25', frequency(n + 12), t + i * 0.045, 0.14, 0.22));
        },
        /* four rows, a halving: a full chord and a bell over it */
        halving(out, t) {
            [69, 73, 76, 81].forEach((n) => note(out, 'pulse25', frequency(n), t, 0.45, 0.12));
            bell(out, t + 0.05, frequency(93), 1.4, 0.35);
        },
        /* the countdown: 3, 2, 1 on one pitch, the start an octave up */
        count(out, t, n = 1) {
            pluck(out, 'square', n > 0 ? 660 : 1320, t, n > 0 ? 0.09 : 0.18, 0.18);
        },
        /* 40 rows: a quick triplet up and a held chord */
        fanfare(out, t) {
            [69, 73, 76].forEach((n, i) => note(out, 'sawtooth', frequency(n), t + i * 0.09, 0.08, 0.12));
            [73, 76, 81].forEach((n) => note(out, 'sawtooth', frequency(n), t + 0.27, 0.7, 0.1));
            bell(out, t + 0.27, frequency(93), 1.2, 0.25);
        },
        /* topped out: an A minor scale falling, getting quieter */
        topout(out, t) {
            [81, 79, 77, 76, 74, 72, 71, 69].forEach((n, i) => pluck(out, 'square', frequency(n), t + i * 0.075, 0.09, 0.16 * (1 - i * 0.09)));
        },
    };

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
            if (name === 'move') {
                if (lastMove >= 0 && t - lastMove < MOVE_GAP) {
                    return false;
                }
                lastMove = t;
            }
            try {
                play(buses.effects, t, arg);
            } catch {
                // a browser without one of the nodes: stay silent, never throw into the game
                return false;
            }

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
            unlocked = false;
        },
    };
}
