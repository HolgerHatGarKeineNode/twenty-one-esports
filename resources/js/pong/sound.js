/**
 * Proof of Pong's sound (plan "Proof of Pong", P3) on Hyperbitcoinization's audio engine (resources/js/hyper/audio.js):
 * the same context, buses, ambient score and soundboard files (public/hyper/s); the figures' voices are only those
 * clips (resources/js/pong/cast.json), never anything else. On top a few synthesized sounds of the game itself: the
 * paddle's blip (higher with each hit of the rally), the wall, the goal, the ratchet of a Difficulty Adjustment, the
 * printer of Brrr.
 *
 * Four switches like Hyper's, kept in localStorage `pong-sound`: volume, effects, voices (soundboard), music. Voices
 * never chatter: a clip waits until the last one has ended and a few seconds passed, except at the end of a game.
 */
import { AUD, ctx, emoteClip, sfx, startMusic } from '../hyper/audio.js';
import { readSettings, writeSettings } from '../hyper/sounds.js';

const KEY = 'pong-sound';
const QUIET_MS = 3500;

export const settings = readSettings(globalThis.localStorage, KEY);

function apply() {
    AUD.vol = settings.vol;
    AUD.fx = settings.fx;
    AUD.board = settings.board;
    AUD.music = settings.music;
    if (AUD.master) AUD.master.gain.value = settings.vol;
    if (AUD.ctx) startMusic();
}
apply();

/** Changes a switch (`vol`, `fx`, `board`, `music`) and keeps it. */
export function set(key, value) {
    settings[key] = value;
    writeSettings(globalThis.localStorage, settings, KEY);
    apply();
}

/** The first touch unlocks the audio (a context made before stays mute on some browsers) and starts the score. */
['pointerdown', 'keydown'].forEach((name) => addEventListener(name, () => { if (ctx()) startMusic(); }, { capture: true }));

function tone(type, freq, at, dur, vol, { slide = 0, filter = 0 } = {}) {
    const c = AUD.ctx;
    if (!c || !AUD.fx) return;
    const o = c.createOscillator();
    const g = c.createGain();
    o.type = type;
    o.frequency.setValueAtTime(freq, at);
    if (slide) o.frequency.exponentialRampToValueAtTime(Math.max(30, freq + slide), at + dur);
    g.gain.setValueAtTime(0.0001, at);
    g.gain.exponentialRampToValueAtTime(vol, at + 0.004);
    g.gain.exponentialRampToValueAtTime(0.0001, at + dur);
    let node = o;
    if (filter) {
        const f = c.createBiquadFilter();
        f.type = 'lowpass';
        f.frequency.value = filter;
        o.connect(f);
        node = f;
    }
    node.connect(g);
    g.connect(AUD.sfxBus);
    o.start(at);
    o.stop(at + dur + 0.03);
}

function click(at, vol, freq) {
    const c = AUD.ctx;
    if (!c || !AUD.fx) return;
    const n = Math.floor(c.sampleRate * 0.018);
    const buffer = c.createBuffer(1, n, c.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < n; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / n);
    const s = c.createBufferSource();
    const f = c.createBiquadFilter();
    const g = c.createGain();
    s.buffer = buffer;
    f.type = 'bandpass';
    f.frequency.value = freq;
    f.Q.value = 3;
    g.gain.value = vol;
    s.connect(f);
    f.connect(g);
    g.connect(AUD.sfxBus);
    s.start(at);
}

const now = () => (AUD.ctx ? AUD.ctx.currentTime + 0.005 : 0);

export const play = {
    /** A paddle's hit: a square blip, a semitone higher per hit of the rally (an octave at most). */
    hit(hits, side) {
        if (!AUD.ctx) return;
        const base = side === 0 ? 440 : 392;
        tone('square', base * 2 ** (Math.min(hits, 12) / 12), now(), 0.07, 0.11, { filter: 3200 });
        tone('sine', base / 2, now(), 0.06, 0.12);
    },
    wall() {
        if (AUD.ctx) tone('triangle', 260, now(), 0.04, 0.06);
    },
    goal(mine) {
        sfx.boom();
        if (mine) sfx.coin();
    },
    serve() {
        if (AUD.ctx) tone('sine', 880, now(), 0.09, 0.08);
    },
    /** Difficulty Adjustment: a ratchet, eight clicks falling. */
    ratchet() {
        const at = now();
        if (!AUD.ctx) return;
        for (let i = 0; i < 8; i++) click(at + i * 0.07, 0.5, 2400 - i * 180);
        tone('sawtooth', 300, at + 0.56, 0.18, 0.06, { slide: -120, filter: 1400 });
    },
    /** Brrr: the printer and the paper. */
    brrr() {
        const at = now();
        if (!AUD.ctx) return;
        for (let i = 0; i < 18; i++) click(at + i * 0.03, 0.28, 900 + (i % 3) * 300);
        sfx.paper();
    },
    halving() {
        sfx.laser();
    },
    pizza() {
        sfx.sting();
    },
    fanfare() {
        sfx.fanfare();
    },
};

let lastVoiceAt = -1e9;

/**
 * A figure's voice: one of its clips (cast.json), if the voices are on, nothing is playing and the last one ended a
 * while ago. `force` (the end of a game) only waits for a clip of the same moment.
 */
export function voice(clips, seed, force = false) {
    if (!clips?.length || !AUD.board) return false;
    const busy = AUD.cur && !AUD.cur.paused && !AUD.cur.ended;
    if (!force && (busy || performance.now() - Math.max(AUD.endedAt, lastVoiceAt) < QUIET_MS)) return false;
    lastVoiceAt = performance.now();
    emoteClip(clips[Math.abs(seed) % clips.length]);

    return true;
}
