/**
 * Background music from MIDI files, synthesised with the Web Audio API (Blockfill and Hyperbitcoinization).
 *
 * ADDING A TRACK needs no code: put the .mid file into public/music/midi/ and add one entry to
 * public/music/midi/manifest.json, `{"file": "my-track.mid", "title": "…", "author": "…", "license": "…"}`.
 * Format 0 or 1 files; General MIDI programs pick the voice, channel 10 plays the drums.
 *
 * - The tracks play in a shuffled order (playlist.js), one after the other with a short gap; the next file
 *   is fetched and parsed while the current one plays.
 * - Notes are scheduled ahead on the audio clock: a timer every INTERVAL_MS queues the notes of the next
 *   LOOKAHEAD seconds (as resources/js/stacker/sound.js does), nothing runs per frame. A note that is
 *   already late (the tab was throttled) is skipped, never played as a backlog.
 * - At most MAX_VOICES notes ring at once; a note past that is dropped.
 * - Voices by General MIDI program (voices.js: keys, bells, organ, plucked and distorted guitars, synth bass,
 *   strings, brass, square and saw leads, pads, timpani), each a few oscillators with a filter envelope;
 *   the General MIDI drum kit from noise and oscillators.
 * - Everything runs into `output` (the game's own music bus, so its switch, volume and ducking apply)
 *   through a slow level control (the files are mixed very differently: each comes out about as loud as
 *   the others) and a gentle limiter.
 * - start() resolves false when there is nothing to play (no manifest, no tracks, no file that loads):
 *   the game then plays its own music. stop() fades out what is queued and stops the timer.
 * - window.__midi is a read-only view for the browser tests: how many notes were scheduled, and what plays.
 * - Every track change is a `midi-track` event on the window, detail {title, author, license, source,
 *   genre} or null when the music stops: the games show it as their "now playing" credit line.
 */

import { parseMidi } from './parse.js';
import { createPlaylist, MANIFEST_URL, readManifest } from './playlist.js';
import { assignVoices, isDrum } from './voices.js';

export const LOOKAHEAD = 0.3;
export const INTERVAL_MS = 80;
export const MAX_VOICES = 40;

/** The level control (levelTick): its target RMS before the player's level, how long it listens, how fast and how far it moves. */
export const TARGET_RMS = 0.3;
const TARGET_SECONDS = 4;
const LEVEL_STEP_DB = 0.25;
const LEVEL_RANGE_DB = [-12, 9];

/** Silence between two tracks (s). */
export const GAP = 1.5;

/** Loudness of each voice against the others. */
const LEVEL = { keys: 0.45, bell: 0.35, organ: 0.22, pluck: 0.4, drive: 0.3, bass: 0.55, strings: 0.26, pad: 0.2, brass: 0.3, lead: 0.26, saw: 0.22, timpani: 0.5 };

/** How long each voice rings after its note ends (s). */
const RELEASE = { keys: 0.25, bell: 0.6, organ: 0.08, pluck: 0.2, drive: 0.15, bass: 0.08, strings: 0.35, pad: 0.7, brass: 0.12, lead: 0.1, saw: 0.15, timpani: 0.1 };

/** The waveshaper curve of the distorted guitars: a soft tanh clip. */
const DRIVE_K = 4;
const DRIVE_INTO = 3;

function driveCurve() {
    const curve = new Float32Array(1024);
    for (let i = 0; i < curve.length; i++) {
        const x = (i / (curve.length - 1)) * 2 - 1;
        curve[i] = Math.tanh(x * DRIVE_K) / Math.tanh(DRIVE_K);
    }

    return curve;
}

const hz = (note) => 440 * 2 ** ((note - 69) / 12);

/** The read-only window.__midi view reads the player that played last. */
let shown = null;

function expose(env) {
    if (!env || !env.document || Object.prototype.hasOwnProperty.call(env, '__midi')) {
        return;
    }
    const read = (key, fallback) => (shown ? shown()[key] : fallback);
    Object.defineProperty(env, '__midi', {
        configurable: false,
        enumerable: false,
        writable: false,
        value: Object.freeze({
            get scheduled() {
                return read('scheduled', 0);
            },
            get playing() {
                return read('playing', false);
            },
            get track() {
                return read('track', null);
            },
            get tracks() {
                return read('tracks', 0);
            },
            get dropped() {
                return read('dropped', 0);
            },
            get skipped() {
                return read('skipped', 0);
            },
        }),
    });
}

/**
 * @param {{ctx: AudioContext, output: AudioNode, manifestUrl?: string, fetch?: Function, random?: () => number, env?: object, level?: number}} options
 */
export function createMidiPlayer({ ctx, output, manifestUrl = MANIFEST_URL, fetch = globalThis.fetch?.bind(globalThis), random = Math.random, env = globalThis, level = 0.55 }) {
    const stats = { scheduled: 0, dropped: 0, skipped: 0, failed: 0 };
    const files = new Map();
    let manifest = null;
    let playlist = null;
    let tracks = [];
    let timer = null;
    let generation = 0;
    let session = null;
    let current = null;
    let pending = null;
    let starting = null;
    let voices = [];
    let misses = 0;

    // the player's own level and a gentle limiter, then the game's music bus
    // a slow level control first (the tracks are mixed up to 12 dB apart, measured 2026-10-09), then the
    // player's level and a gentle limiter, then the game's music bus
    const norm = ctx.createGain();
    norm.gain.value = 1;
    const meter = ctx.createAnalyser ? ctx.createAnalyser() : null;
    const samples = meter ? new Float32Array(1024) : null;
    if (meter) {
        meter.fftSize = 1024;
        norm.connect(meter);
    }
    const leveling = { power: 0, db: 0 };
    const bus = ctx.createGain();
    bus.gain.value = level;
    norm.connect(bus);
    if (ctx.createDynamicsCompressor) {
        const limiter = ctx.createDynamicsCompressor();
        limiter.threshold.value = -10;
        limiter.knee.value = 6;
        limiter.ratio.value = 12;
        limiter.attack.value = 0.003;
        limiter.release.value = 0.25;
        bus.connect(limiter).connect(output);
    } else {
        bus.connect(output);
    }

    const shaperCurve = driveCurve();

    // one second of noise, made once: every drum plays a slice of it
    const noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
    const data = noise.getChannelData(0);
    for (let i = 0; i < data.length; i++) {
        data[i] = Math.random() * 2 - 1;
    }

    // ---- loading ---------------------------------------------------------

    function loadManifest() {
        if (!manifest) {
            manifest = (async () => {
                try {
                    const answer = await fetch(manifestUrl, { headers: { Accept: 'application/json' } });
                    tracks = answer.ok ? readManifest(await answer.json(), manifestUrl) : [];
                } catch {
                    tracks = [];
                }
                playlist = createPlaylist(tracks, random);

                return tracks.length;
            })();
        }

        return manifest;
    }

    /** A track's notes, parsed once; null when the file does not load or parse. */
    function load(track) {
        if (!files.has(track.url)) {
            files.set(
                track.url,
                (async () => {
                    try {
                        const answer = await fetch(track.url);
                        if (!answer.ok) {
                            throw new Error(String(answer.status));
                        }
                        const song = parseMidi(await answer.arrayBuffer());
                        assignVoices(song);

                        return song.notes.length > 0 ? song : null;
                    } catch {
                        stats.failed++;

                        return null;
                    }
                })(),
            );
        }

        return files.get(track.url);
    }

    /** The next track of the playlist, fetching (the result lands in `.song`: a song, or null on failure). */
    function prefetch() {
        const track = playlist.next();
        const entry = { track, song: undefined };
        load(track).then((song) => {
            entry.song = song;
        });

        return entry;
    }

    // ---- voices ----------------------------------------------------------

    function envelope(gain, at, end, peak, attack, release, decayTo = 1, decayTime = 0.3) {
        const sustain = peak * decayTo;
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(peak, at + attack);
        if (decayTo < 1) {
            gain.gain.setTargetAtTime(sustain, at + attack, decayTime / 3);
        }
        gain.gain.setValueAtTime(sustain, Math.max(at + attack + 0.001, end));
        gain.gain.exponentialRampToValueAtTime(0.0001, end + release);
    }

    function oscillators(out, type, freq, at, stop, detunes) {
        detunes.forEach((cents) => {
            const osc = ctx.createOscillator();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, at);
            osc.detune.setValueAtTime(cents, at);
            osc.connect(out);
            osc.start(at);
            osc.stop(stop);
        });
    }

    function lowpass(at, from, to, time, q = 1) {
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.Q.value = q;
        filter.frequency.setValueAtTime(Math.min(16000, from), at);
        filter.frequency.setTargetAtTime(Math.min(16000, Math.max(40, to)), at, time / 3);

        return filter;
    }

    /** One pitched note of a voice (voices.js); returns when it has died away. */
    function pitched(voice, n, at, length, amp) {
        const f = hz(n.note);
        const end = at + Math.max(0.05, length);
        const release = RELEASE[voice];
        const stop = end + release + 0.05;
        const gain = ctx.createGain();
        // the distorted guitars run through the session's waveshaper, everything else straight to its gain
        gain.connect(voice === 'drive' ? session.drive : session.gain);
        switch (voice) {
            case 'bass': {
                // a synth bass: saw and a square an octave down through a resonant, closing filter
                const filter = lowpass(at, f * 10, f * 2.5, 0.25, 5);
                filter.connect(gain);
                oscillators(filter, 'sawtooth', f, at, stop, [0]);
                oscillators(filter, 'square', f / 2, at, stop, [3]);
                envelope(gain, at, end, amp, 0.005, release, 0.75, 0.3);
                break;
            }
            case 'lead': {
                // square lead: two detuned squares, the filter opens on the attack
                const filter = lowpass(at, f * 7, f * 3.5, 0.2, 2);
                filter.connect(gain);
                oscillators(filter, 'square', f, at, stop, [-6, 6]);
                envelope(gain, at, end, amp, 0.008, release, 0.8, 0.2);
                break;
            }
            case 'saw': {
                // saw lead, trance style: three detuned saws, bright then settling
                const filter = lowpass(at, Math.min(9000, f * 10), Math.min(6000, f * 5), 0.25, 2);
                filter.connect(gain);
                oscillators(filter, 'sawtooth', f, at, stop, [-12, 0, 12]);
                envelope(gain, at, end, amp, 0.006, release, 0.85, 0.25);
                break;
            }
            case 'drive': {
                // overdriven guitar: two saws into the waveshaper, a fixed low-pass takes the fizz off
                const filter = lowpass(at, 3800, 3000, 0.3, 0.9);
                filter.connect(gain);
                oscillators(filter, 'sawtooth', f, at, stop, [-9, 9]);
                envelope(gain, at, end, amp, 0.004, release, 0.7, 0.4);
                break;
            }
            case 'brass': {
                const filter = lowpass(at, f * 1.5, f * 6, 0.12, 1.5);
                filter.connect(gain);
                oscillators(filter, 'sawtooth', f, at, stop, [-4, 4]);
                envelope(gain, at, end, amp, 0.04, release, 0.85, 0.2);
                break;
            }
            case 'strings': {
                const filter = lowpass(at, f * 2, Math.min(5000, f * 5), 0.4, 0.7);
                filter.connect(gain);
                oscillators(filter, 'sawtooth', f, at, stop, [-8, 8]);
                envelope(gain, at, end, amp, 0.12, release);
                break;
            }
            case 'pad': {
                const filter = lowpass(at, 600, Math.min(2400, f * 4), 1.2, 0.5);
                filter.connect(gain);
                oscillators(filter, 'sawtooth', f, at, stop, [-11, 0, 11]);
                envelope(gain, at, end, amp, 0.3, release);
                break;
            }
            case 'keys': {
                const filter = lowpass(at, f * 12, f * 2.5, 0.6, 0.8);
                filter.connect(gain);
                oscillators(filter, 'triangle', f, at, stop, [0]);
                oscillators(filter, 'square', f, at, stop, [7]);
                envelope(gain, at, end, amp, 0.004, release, 0.3, 1.2);
                break;
            }
            case 'pluck': {
                const filter = lowpass(at, f * 9, f * 1.5, 0.3, 1.5);
                filter.connect(gain);
                oscillators(filter, 'sawtooth', f, at, stop, [0]);
                envelope(gain, at, end, amp, 0.003, release, 0.15, 0.6);
                break;
            }
            case 'organ': {
                // drawbars: fundamental, octave and twelfth
                oscillators(gain, 'sine', f, at, stop, [0]);
                oscillators(gain, 'triangle', f * 2, at, stop, [2]);
                oscillators(gain, 'sine', f * 3, at, stop, [-2]);
                envelope(gain, at, end, amp, 0.01, release);
                break;
            }
            case 'timpani': {
                const ring = at + 0.9;
                const osc = ctx.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(f, at);
                osc.frequency.exponentialRampToValueAtTime(f * 0.92, ring);
                osc.connect(gain);
                osc.start(at);
                osc.stop(ring + 0.05);
                gain.gain.setValueAtTime(0.0001, at);
                gain.gain.exponentialRampToValueAtTime(amp, at + 0.004);
                gain.gain.exponentialRampToValueAtTime(0.0001, ring);

                return ring;
            }
            default: {
                // bell: inharmonic partials, a long decay whatever the note's length
                const ring = Math.max(end, at + 1.2);
                oscillators(gain, 'sine', f, at, ring + release, [0]);
                const upper = ctx.createGain();
                upper.gain.value = 0.25;
                upper.connect(gain);
                oscillators(upper, 'sine', f * 2.76, at, ring + release, [0]);
                gain.gain.setValueAtTime(0.0001, at);
                gain.gain.exponentialRampToValueAtTime(amp, at + 0.003);
                gain.gain.exponentialRampToValueAtTime(0.0001, ring + release);

                return ring + release;
            }
        }

        return stop;
    }

    function burst(out, at, length, peak, type, freq, q = 0.8) {
        const source = ctx.createBufferSource();
        source.buffer = noise;
        const filter = ctx.createBiquadFilter();
        filter.type = type;
        filter.frequency.value = freq;
        filter.Q.value = q;
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(peak, at);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        source.connect(filter).connect(gain).connect(out);
        source.start(at, Math.random() * 0.7, length + 0.02);
    }

    function thump(out, at, from, to, length, peak, type = 'sine') {
        const osc = ctx.createOscillator();
        osc.type = type;
        osc.frequency.setValueAtTime(from, at);
        osc.frequency.exponentialRampToValueAtTime(to, at + length * 0.6);
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(peak, at);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        osc.connect(gain).connect(out);
        osc.start(at);
        osc.stop(at + length + 0.02);
    }

    /** A drum voice (voices.js drumOfKey) from noise and oscillators; returns when it has died away. */
    function drum(voice, key, at, amp) {
        const out = session.gain;
        switch (voice) {
            case 'kick':
                thump(out, at, 150, 45, 0.32, amp * 1.1);
                burst(out, at, 0.012, amp * 0.25, 'highpass', 3000);

                return at + 0.35;
            case 'snare':
                burst(out, at, 0.18, amp * 0.6, 'bandpass', 1800, 0.7);
                thump(out, at, 220, 160, 0.1, amp * 0.4, 'triangle');

                return at + 0.2;
            case 'clap':
                [0, 0.011, 0.023].forEach((offset) => burst(out, at + offset, 0.02, amp * 0.5, 'bandpass', 1200, 1.2));
                burst(out, at + 0.03, 0.16, amp * 0.45, 'bandpass', 1200, 1);

                return at + 0.2;
            case 'hat':
                burst(out, at, 0.05, amp * 0.3, 'highpass', 7500);

                return at + 0.06;
            case 'openhat':
                burst(out, at, 0.32, amp * 0.28, 'highpass', 7000);

                return at + 0.34;
            case 'crash':
                burst(out, at, 1.3, amp * 0.3, 'highpass', 5000);

                return at + 1.3;
            case 'ride':
                burst(out, at, 0.45, amp * 0.18, 'bandpass', 6500, 1.5);

                return at + 0.45;
            case 'tom': {
                // low floor tom (41) up to high congas (64)
                const base = 70 * 2 ** ((key - 41) / 12);
                thump(out, at, base * 1.6, base, 0.3, amp * 0.8);

                return at + 0.32;
            }
            case 'cowbell':
                thump(out, at, 540, 540, 0.12, amp * 0.2, 'square');
                thump(out, at, 800, 800, 0.12, amp * 0.15, 'square');

                return at + 0.13;
            default:
                // rim, tambourine, shaker …: a short filtered tick
                burst(out, at, 0.04, amp * 0.3, 'bandpass', 3200, 1.5);

                return at + 0.05;
        }
    }

    // ---- scheduling ------------------------------------------------------

    function play(n, at) {
        voices = voices.filter((end) => end > at);
        if (voices.length >= MAX_VOICES) {
            stats.dropped++;

            return;
        }
        const amp = (n.velocity / 127) * n.volume;
        if (amp < 0.001) {
            // a channel turned all the way down: nothing to hear (and a ramp to 0 would throw)
            return;
        }
        if (n.voice === null) {
            // a sound-effect program: not played
            return;
        }
        const end = isDrum(n.voice) ? drum(n.voice, n.note, at, amp * 0.6) : pitched(n.voice, n, at, n.duration, amp * LEVEL[n.voice]);
        voices.push(end);
        stats.scheduled++;
    }

    /** Tells the page what plays now (null: nothing). */
    function announce(track) {
        if (typeof env.dispatchEvent !== 'function' || typeof env.CustomEvent !== 'function') {
            return;
        }
        const detail = track ? { title: track.title, author: track.author, license: track.license, source: track.source, genre: track.genre } : null;
        env.dispatchEvent(new env.CustomEvent('midi-track', { detail }));
    }

    function begin(entry, at) {
        current = { track: entry.track, song: entry.song, start: at, cursor: 0 };
        pending = prefetch();
        announce(entry.track);
    }

    /** Tops the queue up to LOOKAHEAD ahead of the audio clock, and moves on to the next track at the end. */
    /**
     * The level control: the mean power after `norm` over about TARGET_SECONDS, and `norm` moved towards
     * TARGET_RMS by at most LEVEL_STEP_DB per tick, within LEVEL_RANGE_DB; silence (a gap, a rest) moves nothing.
     */
    function levelTick() {
        if (!meter) {
            return;
        }
        meter.getFloatTimeDomainData(samples);
        let sum = 0;
        for (let i = 0; i < samples.length; i++) {
            sum += samples[i] * samples[i];
        }
        leveling.power += (sum / samples.length - leveling.power) * Math.min(1, INTERVAL_MS / 1000 / TARGET_SECONDS);
        const rms = Math.sqrt(leveling.power);
        if (rms < TARGET_RMS / 20) {
            return;
        }
        const error = 20 * Math.log10(TARGET_RMS / rms);
        leveling.db = Math.max(LEVEL_RANGE_DB[0], Math.min(LEVEL_RANGE_DB[1], leveling.db + Math.max(-LEVEL_STEP_DB, Math.min(LEVEL_STEP_DB, error * 0.1))));
        norm.gain.setTargetAtTime(10 ** (leveling.db / 20), ctx.currentTime, 0.1);
    }

    function tick() {
        if (!current || !session) {
            return;
        }
        levelTick();
        const now = ctx.currentTime;
        const horizon = now + LOOKAHEAD;
        const { song } = current;
        while (current.cursor < song.notes.length && current.start + song.notes[current.cursor].time < horizon) {
            const n = song.notes[current.cursor++];
            const at = current.start + n.time;
            if (at < now - 0.02) {
                stats.skipped++;
                continue;
            }
            try {
                play(n, Math.max(at, now));
            } catch {
                // a browser without one of the nodes: this note stays silent, the music goes on
                stats.dropped++;
            }
        }
        const next = current.start + song.duration + GAP;
        if (current.cursor >= song.notes.length && next < horizon && pending && pending.song !== undefined) {
            if (pending.song === null) {
                // that file did not load: the one after it, still after the same gap; a full cycle of failures ends the music
                misses++;
                pending = tracks.length > 1 && misses < tracks.length ? prefetch() : null;

                return;
            }
            misses = 0;
            begin(pending, Math.max(next, now + 0.05));
        }
    }

    function stopTimer() {
        if (timer !== null) {
            env.clearInterval(timer);
            timer = null;
        }
    }

    const view = () => ({
        playing: timer !== null && current !== null,
        scheduled: stats.scheduled,
        dropped: stats.dropped,
        skipped: stats.skipped,
        failed: stats.failed,
        track: current ? current.track.title : null,
        tracks: tracks.length,
        voices: voices.filter((end) => end > ctx.currentTime).length,
        levelDb: Math.round(leveling.db * 10) / 10,
        rms: Math.round(Math.sqrt(leveling.power) * 10000) / 10000,
    });

    shown = view;
    expose(env);

    return {
        /** Starts the playlist (true), or false when there is nothing to play. Safe to call while playing. */
        start() {
            if (timer !== null) {
                return Promise.resolve(true);
            }
            if (starting) {
                return starting;
            }
            const mine = ++generation;
            starting = (async () => {
                if ((await loadManifest()) === 0) {
                    return false;
                }
                // the first track that loads, at most one full cycle of tries
                let entry = pending ?? prefetch();
                for (let tries = 0; tries < tracks.length; tries++) {
                    entry.song = await load(entry.track);
                    if (mine !== generation) {
                        return false;
                    }
                    if (entry.song) {
                        break;
                    }
                    entry = prefetch();
                }
                if (!entry.song) {
                    return false;
                }
                session = { gain: ctx.createGain(), drive: null };
                session.gain.connect(norm);
                if (ctx.createWaveShaper) {
                    // into the clip 3 times hotter, out at the gain a quiet note has without it: distorted, not louder
                    const into = ctx.createGain();
                    into.gain.value = DRIVE_INTO;
                    const shaper = ctx.createWaveShaper();
                    shaper.curve = shaperCurve;
                    shaper.oversample = '2x';
                    const out = ctx.createGain();
                    out.gain.value = Math.tanh(DRIVE_K) / (DRIVE_K * DRIVE_INTO);
                    into.connect(shaper).connect(out).connect(session.gain);
                    session.drive = into;
                } else {
                    session.drive = session.gain;
                }
                shown = view;
                begin(entry, ctx.currentTime + 0.1);
                tick();
                timer = env.setInterval(tick, INTERVAL_MS);

                return true;
            })().finally(() => {
                if (mine === generation) {
                    starting = null;
                }
            });

            return starting;
        },

        /** Fades out what is queued and stops; the next start() goes on with the next track. */
        stop() {
            generation++;
            starting = null;
            stopTimer();
            if (current) {
                announce(null);
            }
            current = null;
            voices = [];
            if (session) {
                const old = session.gain;
                session = null;
                try {
                    old.gain.cancelScheduledValues(ctx.currentTime);
                    old.gain.setValueAtTime(old.gain.value, ctx.currentTime);
                    old.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.15);
                } catch {
                    // a closed context: nothing to fade
                }
                env.setTimeout(() => old.disconnect(), 400);
            }
        },

        debug: view,
    };
}
