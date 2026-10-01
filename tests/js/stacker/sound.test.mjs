/**
 * Blockfill's sound without a browser (plan "Blockfill", P8): the four pieces
 * are valid note data in their stated keys, the tempo never drops as rows
 * run out, the page's events come out of the session, and no AudioContext
 * exists before a user gesture (a stand-in AudioContext counts them).
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { compile, FINAL_ROWS, midi, parseDrums, parsePattern, PIECE_IDS, PIECES, pieceFor, SCALES, STEPS_PER_BAR, tempo, transposeFor } from '../../../resources/js/stacker/music.js';
import { createSession } from '../../../resources/js/stacker/session.js';
import { createSound, normalizeSound, SOUND_DEFAULTS } from '../../../resources/js/stacker/sound.js';

// ---- the pieces -----------------------------------------------------------

test('there are exactly the four pieces of the plan, each with a title, a key and a tempo', () => {
    assert.deepEqual(PIECE_IDS, ['number-go-up', 'tick-tock', 'stay-humble', 'few-understand']);
    assert.deepEqual(PIECE_IDS.map((id) => PIECES[id].title), ['Number Go Up', 'Tick Tock Next Block', 'Stay Humble, Stack Sats', 'Few Understand']);
    for (const id of PIECE_IDS) {
        const piece = PIECES[id];
        assert.ok(midi(`${piece.tonic}4`) !== null, `${id}: tonic`);
        assert.ok(SCALES[piece.scale], `${id}: scale`);
        assert.ok(piece.bpm >= 60 && piece.bpm <= 200, `${id}: bpm ${piece.bpm}`);
        assert.ok(piece.bars >= 1 && piece.bars <= 8, `${id}: bars`);
    }
});

for (const id of PIECE_IDS) {
    test(`${id}: every part fills its bars exactly, every note is in range and in the piece's key`, () => {
        const piece = PIECES[id];
        const steps = piece.bars * STEPS_PER_BAR;
        const tonic = midi(`${piece.tonic}0`) % 12;
        const scale = new Set(SCALES[piece.scale].map((degree) => (tonic + degree) % 12));
        let notes = 0;
        for (const [voice, part] of Object.entries(piece.voices)) {
            const parsed = parsePattern(part.pattern);
            assert.equal(parsed.steps, steps, `${id}/${voice}: steps`);
            // every bar marked, so a step too many or too few shows in the right bar
            assert.equal(part.pattern.split('|').length, piece.bars, `${id}/${voice}: bars`);
            for (const bar of part.pattern.split('|')) {
                assert.equal(bar.trim().split(/\s+/).length, STEPS_PER_BAR, `${id}/${voice}: a bar of ${bar.trim().split(/\s+/).length} steps`);
            }
            for (const { note, step, length } of parsed.notes) {
                assert.ok(note >= midi('A1') && note <= midi('C7'), `${id}/${voice}: note ${note} out of range`);
                assert.ok(scale.has(note % 12), `${id}/${voice}: note ${note} at step ${step} is not in ${piece.tonic} ${piece.scale}`);
                assert.ok(step + length <= steps, `${id}/${voice}: note at ${step} runs past the loop`);
            }
            assert.ok(['triangle', 'square', 'sawtooth', 'sine', 'pulse25', 'pulse12'].includes(part.wave), `${id}/${voice}: wave`);
            assert.ok(part.level > 0 && part.level <= 1, `${id}/${voice}: level`);
            notes += parsed.notes.length;
        }
        for (const [drum, line] of Object.entries(piece.drums)) {
            assert.ok(['kick', 'snare', 'hat', 'tick', 'tock'].includes(drum), `${id}: drum ${drum}`);
            assert.equal(parseDrums(line).steps, steps, `${id}/${drum}: steps`);
        }
        assert.ok(notes >= 8, `${id}: a melody, not a drone`);
        // compiled for the scheduler: one slot per step, nothing lost
        const compiled = compile(id);
        assert.equal(compiled.steps, steps);
        assert.equal(compiled.at.flat().filter((event) => event.voice !== 'drums').length, notes);
    });
}

test('a broken pattern is refused, not played', () => {
    assert.throws(() => parsePattern('- C4'), /hold without a note/);
    assert.throws(() => parsePattern('C4 H4'), /unknown token/);
    assert.throws(() => parseDrums('x.o.'), /unknown drum/);
    assert.equal(midi('A4'), 69);
    assert.equal(midi('Bb3'), 58);
    assert.equal(midi('C#5'), 73);
});

test('the four pieces are four different tunes: no two lead lines share a bar', () => {
    const bars = PIECE_IDS.flatMap((id) => PIECES[id].voices.lead.pattern.split('|').map((bar) => bar.trim()));
    assert.equal(new Set(bars).size, bars.length);
});

// ---- tempo and choice -----------------------------------------------------

test('the tempo never drops as rows run out: monotonic from 40 rows left to none, and bounded', () => {
    for (const id of PIECE_IDS) {
        let previous = -Infinity;
        for (let remaining = 40; remaining >= 0; remaining--) {
            const bpm = tempo(id, remaining);
            assert.ok(bpm >= previous, `${id}: ${bpm} bpm at ${remaining} rows left, ${previous} before`);
            previous = bpm;
        }
        assert.equal(tempo(id, 40), PIECES[id].bpm);
        assert.ok(tempo(id, 0) <= PIECES[id].bpm * 1.2, `${id}: at most 20 % faster`);
        // out-of-range input stays in the range
        assert.equal(tempo(id, 99), tempo(id, 40));
        assert.equal(tempo(id, -5), tempo(id, 0));
        assert.equal(tempo(id, Number.NaN), tempo(id, 40));
    }
    // the driving pieces really do speed up
    assert.ok(tempo('number-go-up', 0) > tempo('number-go-up', 40));
    assert.ok(tempo('few-understand', 0) > tempo('few-understand', 10));
});

test('the page picks the piece: cosy on the menu and the result, driving ranked, calm practice, dark for the last ten rows', () => {
    assert.equal(pieceFor({ mode: 'idle', kind: 'practice', remaining: 40 }), 'stay-humble');
    assert.equal(pieceFor({ mode: 'result', kind: 'ranked', remaining: 0 }), 'stay-humble');
    assert.equal(pieceFor({ mode: 'countdown', kind: 'ranked', remaining: 40 }), 'number-go-up');
    assert.equal(pieceFor({ mode: 'playing', kind: 'ranked', remaining: FINAL_ROWS + 1 }), 'number-go-up');
    assert.equal(pieceFor({ mode: 'playing', kind: 'practice', remaining: 30 }), 'tick-tock');
    assert.equal(pieceFor({ mode: 'playing', kind: 'practice', remaining: FINAL_ROWS }), 'few-understand');
    assert.equal(pieceFor({ mode: 'playing', kind: 'ranked', remaining: 1 }), 'few-understand');
});

test('Number Go Up climbs a semitone per loop and starts over after its climb', () => {
    const climbs = Array.from({ length: 8 }, (_, loop) => transposeFor('number-go-up', loop));
    assert.deepEqual(climbs, [0, 1, 2, 3, 4, 5, 0, 1]);
    assert.deepEqual([0, 1, 2].map((loop) => transposeFor('tick-tock', loop)), [0, 0, 0]);
});

// ---- the session's events ---------------------------------------------------

test('the session says what the player saw: a move, a turn, a hard drop, a hold', () => {
    const session = createSession({ seed: 'ab'.repeat(16), settings: { das: 10, arr: 2, sdf: 20 } });
    session.tick();

    session.press('left', true);
    assert.deepEqual(pick(session.tick()), { moved: true, rotated: false, dropped: false, held: false, locked: 0 });
    session.press('left', false);
    session.tick();

    session.press('cw', true);
    assert.deepEqual(pick(session.tick()), { moved: false, rotated: true, dropped: false, held: false, locked: 0 });
    session.press('cw', false);

    session.press('hold', true);
    assert.deepEqual(pick(session.tick()), { moved: false, rotated: false, dropped: false, held: true, locked: 0 });
    session.press('hold', false);

    session.press('hard', true);
    assert.deepEqual(pick(session.tick()), { moved: false, rotated: false, dropped: true, held: false, locked: 1 });
    session.press('hard', false);

    // nothing pressed: gravity alone is no sound
    assert.deepEqual(pick(session.tick()), { moved: false, rotated: false, dropped: false, held: false, locked: 0 });
});

function pick(outcome) {
    const { moved, rotated, dropped, held, locked } = outcome;

    return { moved, rotated, dropped, held, locked };
}

test('the engine stays as it was: the sound is outside it', () => {
    const engine = readFileSync(new URL('../../../resources/js/stacker/engine.js', import.meta.url), 'utf8');
    assert.ok(!/sound|audio|music/i.test(engine));
});

// ---- the gesture gate, with a stand-in AudioContext -----------------------

function fakeEnv({ hidden = false } = {}) {
    const made = [];
    const started = [];
    let intervals = 0;
    const timers = new Map();
    const delays = [];
    const param = () => ({ value: 0, setValueAtTime() {}, exponentialRampToValueAtTime() {}, linearRampToValueAtTime() {} });
    const node = (kind) => ({
        kind,
        gain: param(),
        frequency: param(),
        Q: param(),
        connect(next) {
            return next;
        },
        setPeriodicWave() {},
        start(at) {
            started.push({ kind, at });
        },
        stop() {},
    });
    class FakeAudioContext {
        constructor() {
            this.state = 'suspended';
            this.currentTime = 0;
            this.sampleRate = 8000;
            this.destination = node('destination');
            made.push(this);
        }
        createGain() {
            return node('gain');
        }
        createOscillator() {
            return node('osc');
        }
        createBufferSource() {
            return node('noise');
        }
        createBiquadFilter() {
            return node('filter');
        }
        createBuffer(channels, length) {
            const data = new Float32Array(length);

            return { getChannelData: () => data };
        }
        createPeriodicWave() {
            return {};
        }
        resume() {
            this.state = 'running';

            return Promise.resolve();
        }
        suspend() {
            this.state = 'suspended';

            return Promise.resolve();
        }
        close() {
            this.state = 'closed';

            return Promise.resolve();
        }
    }
    const env = {
        AudioContext: FakeAudioContext,
        document: { hidden },
        navigator: { audioSession: { type: 'auto' } },
        performance: { now: () => 0 },
        setInterval(fn, ms) {
            delays.push(ms);
            intervals++;
            timers.set(intervals, fn);

            return intervals;
        },
        clearInterval(id) {
            timers.delete(id);
        },
    };

    return { env, made, started, timers, delays };
}

test('no AudioContext before a gesture: effects and the music are skipped, not queued', () => {
    const { env, made, started, timers, delays } = fakeEnv();
    const sound = createSound({ settings: { ...SOUND_DEFAULTS, musicOn: true }, env });

    sound.setScene({ mode: 'playing', kind: 'ranked', remaining: 40 });
    for (const name of ['move', 'rotate', 'drop', 'lock', 'mined', 'halving', 'count', 'fanfare', 'topout']) {
        assert.equal(sound.effect(name, 2), false, name);
    }
    sound.configure({ music: 80 });
    sound.visibility();
    sound.pump();
    assert.equal(made.length, 0);
    assert.equal(started.length, 0);
    assert.equal(timers.size, 0);
    assert.deepEqual(sound.debug().stats.contexts, 0);

    // the gesture: one context, the music scheduled ahead on its clock, the effects play
    assert.equal(sound.unlock(), true);
    assert.equal(made.length, 1);
    assert.equal(made[0].state, 'running');
    assert.equal(timers.size, 1, 'one scheduler timer, not one per frame');
    assert.ok(delays.every((ms) => ms >= 50), `the timer runs every ${delays} ms, at most 20 times a second`);
    assert.ok(started.length > 0, 'the first notes are queued at once');
    assert.ok(started.every(({ at }) => at >= 0 && at <= 0.3), 'only the lookahead is queued');
    assert.equal(sound.debug().piece, 'number-go-up');
    assert.equal(sound.effect('rotate'), true);
    assert.equal(env.navigator.audioSession.type, 'ambient', 'a silent switch mutes it where the browser allows');

    // a second gesture makes no second context
    sound.unlock();
    assert.equal(made.length, 1);
});

test('defaults: effects on, music off; with music off no scheduler runs, all off makes no context at all', () => {
    assert.deepEqual(normalizeSound(null), { effects: 70, music: 50, effectsOn: true, musicOn: false });
    assert.deepEqual(normalizeSound({ effects: 101, music: -1, effectsOn: 'yes', musicOn: true }), { effects: 70, music: 50, effectsOn: true, musicOn: true });
    assert.deepEqual(normalizeSound({ effects: 0, music: 100, effectsOn: false, musicOn: false }), { effects: 0, music: 100, effectsOn: false, musicOn: false });

    const one = fakeEnv();
    const sound = createSound({ env: one.env });
    assert.equal(sound.unlock(), true);
    assert.equal(one.timers.size, 0, 'music off: no timer');
    assert.equal(sound.effect('lock'), true);
    sound.configure({ effectsOn: false });
    assert.equal(sound.effect('lock'), false, 'effects off: silent');

    const two = fakeEnv();
    const silent = createSound({ settings: { effectsOn: false, musicOn: false }, env: two.env });
    assert.equal(silent.unlock(), false);
    assert.equal(two.made.length, 0);
});

test('a hidden tab is silent: the context is suspended, the scheduler stops, and both come back when shown', () => {
    const { env, made, timers } = fakeEnv();
    const sound = createSound({ settings: { musicOn: true }, env });
    sound.unlock();
    assert.equal(timers.size, 1);

    env.document.hidden = true;
    sound.visibility();
    assert.equal(made[0].state, 'suspended');
    assert.equal(timers.size, 0);
    assert.equal(sound.effect('drop'), false);

    env.document.hidden = false;
    sound.visibility();
    assert.equal(made[0].state, 'running');
    assert.equal(timers.size, 1);
    assert.equal(sound.effect('drop'), true);
});

test('the scheduler keeps a lookahead on the audio clock and changes piece at a bar', () => {
    const { env, made, started } = fakeEnv();
    const sound = createSound({ settings: { musicOn: true }, env });
    sound.setScene({ mode: 'idle' });
    sound.unlock();
    const ctx = made[0];
    assert.equal(sound.debug().piece, 'stay-humble');

    // 30 seconds of audio clock in 60 ms passes: never more than the lookahead ahead
    sound.setScene({ mode: 'playing', kind: 'practice', remaining: 30 });
    let latest = 0;
    for (let t = 0; t < 30; t += 0.06) {
        ctx.currentTime = t;
        const before = started.length;
        sound.pump();
        for (const { at } of started.slice(before)) {
            assert.ok(at <= t + 0.25 + 0.2, `a note at ${at} queued at ${t}`);
            latest = Math.max(latest, at);
        }
    }
    assert.ok(latest > 29, 'it kept playing');
    assert.equal(sound.debug().piece, 'tick-tock');

    sound.setScene({ remaining: 5 });
    for (let t = 30; t < 35; t += 0.06) {
        ctx.currentTime = t;
        sound.pump();
    }
    assert.equal(sound.debug().piece, 'few-understand');
    assert.ok(sound.debug().stats.calls > 400);
});
