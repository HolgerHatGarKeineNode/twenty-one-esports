/**
 * The game page's sound (plan "Hyperbitcoinization", P2; the prototype v21's audio, unchanged in how it
 * sounds): soundboard clips in themed pools, synthesized effects, and a generative ambient score.
 *
 * - Clips rotate with memory (localStorage `hb-clip-history`): never one of the last 30 plays.
 * - Bot turns play no soundboard clips (user, 2026-10-08), effects still sound; only priority 5 (win, lose)
 *   comes through. The page tells which turn it is through setTurnInfo().
 * - Audio starts only after the first touch: a context created before stays locked on some browsers.
 * - Three switches, kept in `hb-settings`: effects, soundboard, music.
 */
import { POOLS, PRIO } from './data.js';

export const AUD = { board: true, curPrio: 0, curName: '', fx: true, music: true, vol: 0.8, ctx: null, cur: null, master: null, sfxBus: null, musicBus: null, verb: null, intensity: 0, endedAt: -1e9 };

let base = '/hyper/';
let isBotTurn = () => false;
let mood = () => ({ tense: false, standing: 'even' });

/** Where the clips are, whether the seat to move is a bot, and how the game stands (for the score). */
export function setAudioHooks({ assets, botTurn, musicMood }) {
    if (assets) base = assets;
    if (botTurn) isBotTurn = botTurn;
    if (musicMood) mood = musicMood;
}

let touched = false;
['pointerdown', 'keydown'].forEach((ev) => addEventListener(ev, () => { touched = true; if (AUD.ctx && AUD.ctx.state === 'suspended') AUD.ctx.resume(); }, { capture: true }));

export function ctx() {
    if (!AUD.ctx && !touched) return null;
    if (!AUD.ctx) {
        try {
            const c = new (window.AudioContext || window.webkitAudioContext)();
            AUD.ctx = c;
            AUD.master = c.createGain(); AUD.master.gain.value = AUD.vol;
            const comp = c.createDynamicsCompressor(); comp.threshold.value = -16; comp.ratio.value = 4;
            AUD.master.connect(comp); comp.connect(c.destination);
            AUD.sfxBus = c.createGain(); AUD.sfxBus.gain.value = 0.9; AUD.sfxBus.connect(AUD.master);
            AUD.musicBus = c.createGain(); AUD.musicBus.gain.value = AUD.music ? 0.42 : 0; AUD.musicBus.connect(AUD.master);
            // Generated reverb: a short noise tail.
            const len = c.sampleRate * 2.2; const ir = c.createBuffer(2, len, c.sampleRate);
            for (let ch = 0; ch < 2; ch++) { const d = ir.getChannelData(ch); for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 2.6); }
            AUD.verb = c.createConvolver(); AUD.verb.buffer = ir; const vg = c.createGain(); vg.gain.value = 0.32; AUD.verb.connect(vg); vg.connect(AUD.master);
        } catch (e) { return null; }
    }
    if (AUD.ctx.state === 'suspended') AUD.ctx.resume();

    return AUD.ctx;
}

/*
 * Rotation with memory: every play is remembered (across matches, in this browser). A pool hands out the clip
 * that has rested longest and never one of the last RECENT plays; a small pool with nothing rested borrows
 * from the pools of the same mood.
 */
const RECENT = 30;
const MOOD = {
    good: ['conquer', 'zone', 'ath', 'hit', 'win', 'maxi', 'asic', 'hodl', 'held', 'swiss', 'card:diamond', 'card:attack51'],
    bad: ['fail', 'lost', 'low', 'crash', 'lose', 'out'],
    money: ['inflation', 'price', 'bank', 'ezb', 'fed', 'card:brrrr', 'card:scam', 'card:nokeys', 'card:keys', 'card:dip', 'card:salvador', 'card:pizza', 'card:lagarde'],
    turn: ['turn', 'start'],
    bots: Object.keys(POOLS).filter((k) => k.startsWith('f:')),
};
const moodOf = (pool) => Object.keys(MOOD).find((m) => MOOD[m].includes(pool)) ?? 'good';
const HIST = (() => { try { return JSON.parse(localStorage.getItem('hb-clip-history')) ?? { n: 0, last: {} }; } catch (e) { return { n: 0, last: {} }; } })();
let lastClip = '';
function remember(name) {
    HIST.n++; HIST.last[name] = HIST.n; lastClip = name;
    try { localStorage.setItem('hb-clip-history', JSON.stringify(HIST)); } catch (e) { /* memory only for this visit */ }
}
function pick(pool) {
    const age = (name) => (HIST.last[name] === undefined ? Infinity : HIST.n - HIST.last[name]);
    const rested = (list) => list.filter((n) => age(n) >= RECENT);
    let cands = rested(POOLS[pool]);
    if (!cands.length) cands = rested([...new Set(MOOD[moodOf(pool)].flatMap((k) => POOLS[k] ?? []))]);
    if (!cands.length) cands = POOLS[pool].filter((n) => n !== lastClip);
    if (!cands.length) cands = POOLS[pool];
    const fresh = cands.filter((n) => age(n) === Infinity);

    return fresh.length ? fresh[Math.floor(Math.random() * fresh.length)] : cands.sort((a, b) => age(b) - age(a))[0];
}

function playFile(name, prio) {
    try {
        if (AUD.cur) AUD.cur.pause();
        const a = new Audio(base + 's/' + name + '.mp3'); a.volume = Math.min(1, AUD.vol * 1.1);
        AUD.cur = a; AUD.curPrio = prio; AUD.curName = name;
        duck(true); a.onended = () => { duck(false); AUD.curPrio = 0; AUD.endedAt = performance.now(); };
        a.play().catch(() => duck(false));
    } catch (e) { /* silence is fine */ }
}

/** A clip from a pool. It plays to its end unless something more important comes (a conquest beats a turn horn). */
export function clip(pool, prio) {
    if (!AUD.board || !POOLS[pool]) return;
    prio = prio ?? PRIO[pool] ?? (pool.startsWith('card:') ? 3 : 1);
    if (isBotTurn() && prio < 5) return;
    if (AUD.cur && !AUD.cur.paused && !AUD.cur.ended && prio <= AUD.curPrio) return;
    // Small moments stay quiet for a while after a clip, so the soundboard never chatters.
    if (prio <= 2 && performance.now() - AUD.endedAt < 6000) return;
    const name = pick(pool); remember(name);
    playFile(name, prio);
}

/** A soundboard clip a player sent as an emote: plays unless the soundboard is muted, over anything but a 5. */
export function emoteClip(name) {
    if (!AUD.board || !/^[a-zA-Z0-9_-]+$/.test(name)) return;
    if (AUD.cur && !AUD.cur.paused && !AUD.cur.ended && AUD.curPrio >= 5) return;
    playFile(name, 4);
}

export function duck(on) { if (AUD.musicBus && AUD.music) AUD.musicBus.gain.setTargetAtTime(on ? 0.12 : 0.42, AUD.ctx.currentTime, on ? 0.08 : 0.5); }
function env(g, t, a, peak, d, sustain = 0.0001) { g.gain.cancelScheduledValues(t); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(peak, t + a); g.gain.exponentialRampToValueAtTime(Math.max(0.0001, sustain), t + a + d); }
function osc(type, freq, t, dur, vol, bus, { slide = 0, verb = 0, attack = 0.005, detune = 0, filter = 0 } = {}) {
    const c = AUD.ctx; if (!c) return;
    const o = c.createOscillator(); const g = c.createGain(); o.type = type; o.frequency.setValueAtTime(freq, t); o.detune.value = detune;
    if (slide) o.frequency.exponentialRampToValueAtTime(Math.max(20, freq + slide), t + dur);
    env(g, t, attack, vol, dur);
    let node = o;
    if (filter) { const f = c.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = filter; o.connect(f); node = f; }
    node.connect(g); g.connect(bus);
    if (verb) { const s = c.createGain(); s.gain.value = verb; g.connect(s); s.connect(AUD.verb); }
    o.start(t); o.stop(t + attack + dur + 0.05);
}
function noise(t, dur, vol, bus, { type = 'bandpass', freq = 1800, q = 0.8, verb = 0 } = {}) {
    const c = AUD.ctx; if (!c) return;
    const n = Math.floor(c.sampleRate * dur); const b = c.createBuffer(1, n, c.sampleRate); const d = b.getChannelData(0);
    for (let i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
    const s = c.createBufferSource(); s.buffer = b; const f = c.createBiquadFilter(); f.type = type; f.frequency.value = freq; f.Q.value = q;
    const g = c.createGain(); env(g, t, 0.002, vol, dur);
    s.connect(f); f.connect(g); g.connect(bus);
    if (verb) { const sg = c.createGain(); sg.gain.value = verb; g.connect(sg); sg.connect(AUD.verb); }
    s.start(t); s.stop(t + dur + 0.02);
}

export const sfx = new Proxy({
    hover: (t) => osc('sine', 1700, t, 0.035, 0.05, AUD.sfxBus),
    click: (t) => { osc('triangle', 900, t, 0.05, 0.22, AUD.sfxBus, { slide: 300 }); noise(t, 0.02, 0.12, AUD.sfxBus, { freq: 4000 }); },
    select: (t) => { osc('square', 660, t, 0.06, 0.08, AUD.sfxBus, { filter: 2400 }); osc('square', 990, t + 0.05, 0.08, 0.08, AUD.sfxBus, { filter: 2400 }); },
    lock: (t) => { [880, 1175, 1568].forEach((f, i) => osc('triangle', f, t + i * 0.045, 0.09, 0.12, AUD.sfxBus, { verb: 0.2 })); },
    error: (t) => { osc('sawtooth', 160, t, 0.12, 0.12, AUD.sfxBus, { filter: 900 }); osc('sawtooth', 120, t + 0.1, 0.16, 0.12, AUD.sfxBus, { filter: 900 }); },
    place: (t) => { osc('sine', 180, t, 0.16, 0.5, AUD.sfxBus, { slide: -90 }); noise(t, 0.08, 0.25, AUD.sfxBus, { freq: 700 }); osc('triangle', 1400, t + 0.03, 0.08, 0.08, AUD.sfxBus); },
    coin: (t) => { osc('sine', 1568, t, 0.1, 0.18, AUD.sfxBus, { verb: 0.25 }); osc('sine', 2093, t + 0.07, 0.18, 0.16, AUD.sfxBus, { verb: 0.25 }); },
    power: (t) => { osc('sawtooth', 220, t, 0.45, 0.16, AUD.sfxBus, { slide: 660, filter: 2600, verb: 0.3 }); noise(t, 0.4, 0.08, AUD.sfxBus, { type: 'highpass', freq: 3000 }); },
    shake: (t) => { for (let i = 0; i < 9; i++) noise(t + i * 0.045, 0.035, 0.22, AUD.sfxBus, { freq: 2200 + Math.random() * 1600, q: 2 }); },
    land: (t) => { osc('sine', 140, t, 0.09, 0.35, AUD.sfxBus, { slide: -60 }); noise(t, 0.05, 0.25, AUD.sfxBus, { freq: 1200 }); },
    clash: (t) => { noise(t, 0.25, 0.35, AUD.sfxBus, { type: 'highpass', freq: 2500, verb: 0.4 }); osc('square', 1250, t, 0.12, 0.06, AUD.sfxBus, { slide: -500 }); },
    boom: (t) => { osc('sine', 110, t, 0.8, 0.9, AUD.sfxBus, { slide: -75 }); noise(t, 0.6, 0.5, AUD.sfxBus, { type: 'lowpass', freq: 500, verb: 0.5 }); },
    whoosh: (t) => noise(t, 0.35, 0.18, AUD.sfxBus, { type: 'bandpass', freq: 900, q: 0.6, verb: 0.2 }),
    flip: (t) => { noise(t, 0.06, 0.2, AUD.sfxBus, { freq: 3000 }); noise(t + 0.08, 0.05, 0.15, AUD.sfxBus, { freq: 2200 }); },
    sting: (t) => { [392, 494, 587, 784].forEach((f, i) => osc('sawtooth', f, t + i * 0.06, 0.5, 0.07, AUD.sfxBus, { filter: 3000, verb: 0.45 })); },
    horn: (t) => { [220, 330, 440].forEach((f) => osc('sawtooth', f, t, 0.9, 0.08, AUD.sfxBus, { attack: 0.06, filter: 1600, verb: 0.5 })); },
    gong: (t) => { [98, 196, 294, 415].forEach((f, i) => osc('sine', f, t, 1.6 - i * 0.2, 0.22 / (i + 1), AUD.sfxBus, { verb: 0.6 })); },
    inflate: (t) => osc('sawtooth', 600, t, 0.7, 0.12, AUD.sfxBus, { slide: -480, filter: 1800, verb: 0.3 }),
    paper: (t) => { for (let i = 0; i < 14; i++) noise(t + i * 0.035 + Math.random() * 0.02, 0.05, 0.12, AUD.sfxBus, { freq: 2500 + Math.random() * 3000, q: 1.2 }); osc('triangle', 300, t, 0.25, 0.1, AUD.sfxBus, { slide: 500 }); },
    laser: (t) => { osc('sawtooth', 1800, t, 0.4, 0.12, AUD.sfxBus, { slide: -1500, filter: 4000, verb: 0.3 }); osc('square', 2400, t, 0.3, 0.05, AUD.sfxBus, { slide: -2000 }); },
    fall: (t) => osc('sine', 1400, t, 0.7, 0.12, AUD.sfxBus, { slide: -1200, verb: 0.3 }),
    fanfare: (t) => [523, 659, 784, 1047, 1319].forEach((f, i) => osc('sawtooth', f, t + i * 0.11, 0.55, 0.09, AUD.sfxBus, { filter: 3200, verb: 0.5 })),
}, { get: (o, k) => (...a) => { const c = AUD.fx && ctx(); if (!c || !o[k]) return; try { o[k](c.currentTime + 0.005, ...a); } catch (e) { /* ignore */ } } });

addEventListener('arena-land', () => sfx.land());
addEventListener('arena-paper', () => sfx.paper());
addEventListener('arena-laser', () => sfx.laser());
addEventListener('arena-up', () => sfx.power());
addEventListener('arena-down', () => sfx.inflate());
addEventListener('arena-fall', () => sfx.fall());
addEventListener('arena-impact', () => { sfx.boom(); sfx.coin(); sfx.paper(); });

/*
 * Generative ambient score for long strategy sessions (prototype v9/v10): short fragments in loops of
 * incommensurable length (Eno, Music for Airports; reverbmachine.com/blog/deconstructing-brian-eno-music-for-airports),
 * vertical layering against fatigue (gamedeveloper.com/audio/soundscapes-what-sound-does-to-games-and-brains).
 * The phase sets the tension, the standing the colour; changes land on the next chord boundary.
 */
const mtof = (m) => 440 * Math.pow(2, (m - 69) / 12);
const AMB = {
    timer: 0, key: 0, nextChord: 0, chord: 0, nextKey: 0, pulseNext: 0, pulseStep: 0,
    chords: [[38, [57, 60, 64, 65]], [34, [53, 57, 58, 62]], [41, [57, 60, 64, 67]], [36, [55, 60, 62, 64]], [43, [55, 58, 62, 65]], [45, [55, 57, 60, 64]]],
    moves: { 0: [1, 2, 4, 5], 1: [0, 2, 3], 2: [0, 1, 3, 5], 3: [2, 4, 0], 4: [0, 1, 5], 5: [0, 2, 4] },
    keys: [0, 5, -2, 3],
    loops: [[19.7, [[0, 69], [2.3, 72]]], [23.5, [[0, 74], [1.6, 65]]], [29.9, [[0, 67]]], [34.3, [[0, 60], [3.1, 62], [5.4, 69]]]],
    loopNext: [], mood: null,
};
const MAJOR = new Set([1, 2, 3]);
function nextChord(from, m) {
    const opts = AMB.moves[from].map((i) => {
        let w = 1; const major = MAJOR.has(i);
        if (m.tense) w *= major ? 0.4 : 3;
        if (m.standing === 'ahead') w *= major ? 2.5 : 0.6;
        if (m.standing === 'behind') w *= major ? 0.4 : 2.5;
        return [i, w];
    });
    let r = Math.random() * opts.reduce((a, [, w]) => a + w, 0);
    for (const [i, w] of opts) { r -= w; if (r <= 0) return i; }
    return opts[0][0];
}
function padNote(m, t, dur, vol, bright = 1) {
    const c = AUD.ctx; const f = c.createBiquadFilter(); f.type = 'lowpass'; f.Q.value = 0.4;
    f.frequency.setValueAtTime(500, t); f.frequency.linearRampToValueAtTime((1100 + Math.random() * 500) * bright, t + dur * 0.5); f.frequency.linearRampToValueAtTime(600, t + dur + 5);
    const g = c.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.linearRampToValueAtTime(vol, t + 3.5); g.gain.setValueAtTime(vol, t + dur); g.gain.linearRampToValueAtTime(0.0001, t + dur + 6);
    [-7, 7].forEach((det) => { const o = c.createOscillator(); o.type = 'sawtooth'; o.frequency.value = mtof(m); o.detune.value = det + (Math.random() - 0.5) * 4; o.connect(f); o.start(t); o.stop(t + dur + 6.2); });
    f.connect(g); g.connect(AUD.musicBus); const s = c.createGain(); s.gain.value = 0.7; g.connect(s); s.connect(AUD.verb);
}
function droneNote(m, t, dur) {
    const c = AUD.ctx; const o = c.createOscillator(); o.type = 'triangle'; o.frequency.value = mtof(m);
    const g = c.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.linearRampToValueAtTime(0.12, t + 4); g.gain.setValueAtTime(0.12, t + dur); g.gain.linearRampToValueAtTime(0.0001, t + dur + 5);
    o.connect(g); g.connect(AUD.musicBus); o.start(t); o.stop(t + dur + 5.2);
}
function bellNote(m, t, vol = 0.05) {
    const c = AUD.ctx; const g = c.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 5);
    [[1, 1], [2, 0.18], [3.01, 0.05]].forEach(([mul, amp]) => { const o = c.createOscillator(); o.type = 'sine'; o.frequency.value = mtof(m) * mul; const a = c.createGain(); a.gain.value = amp; o.connect(a); a.connect(g); o.start(t); o.stop(t + 5.1); });
    g.connect(AUD.musicBus); const s = c.createGain(); s.gain.value = 1.2; g.connect(s); s.connect(AUD.verb);
}
function ambientTick() {
    const c = AUD.ctx; const now = c.currentTime; const ahead = now + 1.2;
    if (!AUD.music) return;
    if (now >= AMB.nextKey) { AMB.key = AMB.keys[(AMB.keys.indexOf(AMB.key) + 1) % AMB.keys.length]; AMB.nextKey = now + 180 + Math.random() * 60; }
    if (AMB.nextChord < ahead) {
        const m = mood(); AMB.mood = m;
        const t = Math.max(AMB.nextChord, now + 0.05); const dur = m.tense ? 10 + Math.random() * 4 : 12 + Math.random() * 8;
        const [root, voicing] = AMB.chords[AMB.chord];
        const bright = m.tense ? 0.6 : m.standing === 'ahead' ? 1.35 : m.standing === 'behind' ? 0.75 : 1;
        const breathe = !m.tense && Math.random() < 0.15;
        if (!breathe) voicing.forEach((n, i) => padNote(n + AMB.key, t + i * 0.25, dur, 0.031, bright));
        droneNote(root + AMB.key - (m.standing === 'behind' ? 12 : 0), t, dur);
        AMB.chord = nextChord(AMB.chord, m);
        AMB.nextChord = t + dur;
    }
    AMB.loops.forEach(([period, notes], i) => {
        if (AMB.loopNext[i] === undefined) AMB.loopNext[i] = now + 4 + i * 3.7;
        if (AMB.loopNext[i] < ahead) {
            const t = AMB.loopNext[i];
            const m = AMB.mood ?? { tense: false, standing: 'even' }; const lift = m.standing === 'ahead' && Math.random() < 0.5 ? 12 : 0;
            if (Math.random() > (m.tense ? 0.45 : 0.22)) notes.forEach(([off, n]) => bellNote(n + AMB.key + lift, t + off, 0.035 + Math.random() * 0.02));
            AMB.loopNext[i] = t + period;
        }
    });
    if (AUD.intensity > 0.5) {
        const beat = 60 / 76;
        if (AMB.pulseNext < now) AMB.pulseNext = now + 0.05;
        while (AMB.pulseNext < ahead) {
            const t = AMB.pulseNext; const s = AMB.pulseStep++ % 8;
            if (s % 2 === 0) osc('sine', 62, t, 0.45, 0.22, AUD.musicBus, { slide: -22 });
            const [, voicing] = AMB.chords[AMB.chord]; osc('triangle', mtof(voicing[s % voicing.length] + 12 + AMB.key), t, 0.35, 0.02, AUD.musicBus, { filter: 1800, verb: 0.4 });
            AMB.pulseNext += beat / 2;
        }
    }
}
export function startMusic() {
    const c = ctx(); if (!c || AMB.timer) return;
    AMB.nextChord = c.currentTime + 0.2; AMB.nextKey = c.currentTime + 180;
    AMB.timer = setInterval(ambientTick, 250);
}
export function setIntensity(v) { AUD.intensity = v; }

/** Every button clicks; hovering ticks quietly. */
let lastHover = 0;
export function hoverTick() { if (performance.now() - lastHover > 60) { lastHover = performance.now(); sfx.hover(); } }
document.addEventListener('pointerdown', (e) => { if (e.target.closest?.('button, input[type=range]')) sfx.click(); }, true);
document.addEventListener('pointerover', (e) => { const b = e.target.closest?.('button:not(:disabled)'); if (b && performance.now() - lastHover > 70) { lastHover = performance.now(); sfx.hover(); } }, true);
