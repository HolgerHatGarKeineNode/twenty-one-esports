/**
 * Proof of Pong's sound (plan "Proof of Pong", P3) on Hyperbitcoinization's audio engine (resources/js/hyper/audio.js):
 * the same context, buses, music (P8: the MIDI playlist, below) and soundboard files (public/hyper/s); the figures' voices are only those
 * clips (resources/js/pong/cast.json), never anything else. On top a few synthesized sounds of the game itself: the
 * paddle's blip (higher with each hit of the rally), the wall, the goal, the ratchet of a Difficulty Adjustment, the
 * printer of Brrr.
 *
 * Four switches like Hyper's, kept in localStorage `pong-sound`: volume, effects, voices (soundboard), music; and (P8) the
 * music's own volume `musicVol` (0..1) as on Blockfill.
 *
 * The music (P8) is Blockfill's shuffled MIDI playlist (resources/js/midi/player.js, public/music/midi/manifest.json) on
 * the audio engine's music bus: it starts on the first gesture where the browser blocks autoplay, ducks under every
 * snippet (audio.js duck()), stops while the page is hidden, and falls back to the ambient score without tracks. Each
 * track is announced as a `midi-track` event, shown by show.js as the "now playing" credit line.
 *
 * The voices (P7) come from the snippet library (resources/js/sounds/snips.js, public/sounds/snips): each occasion of
 * the game (a hit, a goal, a goal against, a streak, each meme event, the win, the loss) draws without repetition from
 * every snippet tagged for it, a figure's own snippets first with the weight OWN_WEIGHT. Kinski and the politicians'
 * clips stay out (plan, Besetzung: "Nicht gewählt"). Voices never chatter: say() keeps a gap after the last snippet per
 * kind of occasion (voices.js VOICE_RULES), lets a hit speak only now and then, and lets only a more important moment cut a
 * snippet short. Until the manifest has loaded (or when it cannot), a figure falls back to its cast clips (voice()).
 */
import { AUD, ctx, emoteClip, musicLevel, playUrl, setAudioHooks, sfx, startMusic, stopMusic } from '../hyper/audio.js';
import { readSettings, writeSettings } from '../hyper/sounds.js';
import { createSnips, personsOf } from '../sounds/snips.js';
import { EXCLUDE, OCCASIONS, OWN_WEIGHT, VOICE_RULES, voiceAllowed } from './voices.js';

const KEY = 'pong-sound';
const QUIET_MS = 3500;

export const settings = readSettings(globalThis.localStorage, KEY);

setAudioHooks({ playlist: true });

function apply() {
    AUD.vol = settings.vol;
    AUD.fx = settings.fx;
    AUD.board = settings.board;
    AUD.music = settings.music;
    AUD.musicVol = settings.musicVol;
    if (AUD.master) AUD.master.gain.value = settings.vol;
    if (!AUD.ctx) return;
    if (settings.music) startMusic();
    else {
        AUD.musicBus?.gain.setTargetAtTime(musicLevel(), AUD.ctx.currentTime, 0.3);
        stopMusic();
    }
}
apply();

/** The music's state for the browser test (pongGame.state().music): switch, volume, ducked, the bus's gain now. */
export function musicState() {
    return { on: AUD.music, vol: AUD.musicVol, ducked: AUD.ducked, gain: AUD.musicBus ? Math.round(AUD.musicBus.gain.value * 1000) / 1000 : null };
}

/** Changes a switch (`vol`, `fx`, `board`, `music`, `musicVol`) and keeps it. */
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
    /** The tax block or the border wall: a dull thud. */
    block() {
        if (AUD.ctx) tone('square', 110, now(), 0.09, 0.1, { slide: -40, filter: 900 });
    },
    /** The Arbeitsamt's rubber stamp: a thump and a short ding of the ticket machine. */
    stamp() {
        const at = now();
        if (!AUD.ctx) return;
        click(at, 0.9, 380);
        tone('sine', 1320, at + 0.12, 0.16, 0.07);
    },
    tax() {
        sfx.coin();
    },
    controls() {
        if (AUD.ctx) tone('sawtooth', 160, now(), 0.35, 0.06, { slide: -60, filter: 800 });
    },
    few() {
        sfx.laser();
    },
    pow() {
        const at = now();
        if (!AUD.ctx) return;
        for (let i = 0; i < 4; i++) click(at + i * 0.11, 0.6, 1600 + i * 200);
    },
    arbeitsamt() {
        play.stamp();
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

/* ---------- Voices from the snippet library (P7) ---------- */

let library = null;
let lastSnipAt = -1e9;

/** The library, loaded once; null until it is there (and for good if it cannot be read). */
function loadLibrary() {
    if (library !== null || !globalThis.fetch) return;
    library = false;
    fetch('/sounds/snips/manifest.json')
        .then((response) => (response.ok ? response.json() : null))
        .then((manifest) => {
            if (manifest?.snips?.length) library = createSnips(manifest, { occasions: OCCASIONS, exclude: EXCLUDE, ownWeight: OWN_WEIGHT, ownAnyTag: ['goal', 'win', 'turm'] });
        })
        .catch(() => {});
}
loadLibrary();

/**
 * A voice for `occasion` from the library, the figure's own snippets preferred (its cast clips name its speakers).
 * Without the library a figure's goal or win falls back to its cast clips; true when something plays.
 */
export function say(occasion, figure = null) {
    const clips = figure ? [...(figure.goal ?? []), ...(figure.win ?? [])] : [];
    if (!library) {
        if (!figure) return false;
        const fallback = occasion === 'win' || occasion === 'lose' ? figure.win : figure.goal;

        return voice(fallback, Math.floor(performance.now()), occasion === 'win' || occasion === 'lose');
    }
    const busy = !!(AUD.cur && !AUD.cur.paused && !AUD.cur.ended);
    const sinceLast = performance.now() - Math.max(AUD.endedAt, lastSnipAt);
    if (!voiceAllowed(occasion, { board: AUD.board, busy, curPrio: AUD.curPrio, sinceLast, roll: Math.random() })) return false;
    const snip = library.draw(occasion, clips.length ? personsOf(clips, library.snips) : []);
    if (!snip) return false;
    lastSnipAt = performance.now();
    playUrl(library.url(snip), VOICE_RULES[occasion.split(':')[0]].prio, snip.id);
    document.body.dataset.voice = snip.id;

    return true;
}
