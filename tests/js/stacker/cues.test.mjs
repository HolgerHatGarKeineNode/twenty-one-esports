/**
 * Blockfill's sound cues (plan "Blockfill", P8 follow-up): the events the
 * session reads from the engine's state around each tick — touchdown, a
 * blocked input, auto-repeat, soft drop, combo, the last-ten-rows warning —
 * and the cue list the page plays for one tick, several at once where they
 * belong together. The engine is not touched: every event here is read from
 * its state before and after step().
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { HEIGHT, WIDTH } from '../../../resources/js/stacker/engine.js';
import { PIECE_CODES } from '../../../resources/js/stacker/pieces.js';
import { createSession } from '../../../resources/js/stacker/session.js';
import * as soundModule from '../../../resources/js/stacker/sound.js';

// read off the module, so a missing export fails each test on its own rather than the whole file
const { createSound, cuesFor } = soundModule;

const SEED = 'cd'.repeat(16);
const piece = (code) => PIECE_CODES.indexOf(code);

function session(settings = { das: 10, arr: 2, sdf: 20 }) {
    return createSession({ seed: SEED, settings });
}

/** Puts `code` at (x, y) in state `rot` as the falling piece, as the engine would after a spawn. */
function place(s, code, { x = 3, y = 2, rot = 0 } = {}) {
    s.game.current = { piece: piece(code), rot, x, y };
    s.game.lowestY = y;
    s.game.gravity = 0;
    s.game.lockTicks = 0;
    s.game.lockResets = 0;
}

/** A press and its release, each in its own tick; the press tick's outcome. */
function tap(s, action) {
    s.press(action, true);
    const outcome = s.tick();
    s.press(action, false);
    s.tick();

    return outcome;
}

test('touchdown: once per piece, the tick it first rests on the floor or the stack, never for a hard drop', () => {
    const s = session();
    s.press('soft', true);
    const seen = [];
    let pieces = 0;
    for (let i = 0; i < 400 && pieces < 2; i++) {
        const outcome = s.tick();
        if (outcome.touchdown) {
            seen.push({ pieces, locked: outcome.locked });
        }
        pieces += outcome.locked;
    }
    assert.equal(pieces, 2, 'two pieces locked by the lock delay');
    assert.deepEqual(seen, [{ pieces: 0, locked: 0 }, { pieces: 1, locked: 0 }], 'one touchdown for each piece, before its lock');

    // moving along the floor keeps it resting: no second touchdown
    s.press('soft', false);
    s.press('soft', true);
    for (let i = 0; i < 30; i++) {
        const outcome = s.tick();
        if (outcome.touchdown) {
            break;
        }
    }
    s.press('soft', false);
    const along = tap(s, 'left');
    assert.equal(along.moved, true);
    assert.equal(along.touchdown, false);

    // a hard drop lands and locks in one tick: the drop is its sound, not a touchdown
    const fresh = session();
    fresh.press('hard', true);
    const dropped = fresh.tick();
    assert.equal(dropped.dropped, true);
    assert.equal(dropped.touchdown, false);
});

test('blocked: only a press that changed nothing, never auto-repeat against the wall', () => {
    const s = session();
    place(s, 'T', { x: 3 });
    const lefts = [1, 2, 3, 4].map(() => tap(s, 'left'));
    assert.deepEqual(lefts.map((o) => [o.moved, o.blocked]), [[true, false], [true, false], [true, false], [false, true]]);
    assert.equal(s.game.current.x, 0);

    // held right: the press moves, the repeats move and then run into the wall without a sound
    const held = [];
    s.press('right', true);
    for (let i = 0; i < 40; i++) {
        held.push(s.tick());
    }
    s.press('right', false);
    assert.equal(held.filter((o) => o.blocked).length, 0, 'the wall under a held key is not a blocked press');
    assert.equal(s.game.current.x, 7);

    // a turn with every kick blocked
    const boxed = session();
    place(boxed, 'I', { x: 3, y: 10 });
    for (let y = 0; y < HEIGHT; y++) {
        for (let x = 0; x < WIDTH; x++) {
            if (!(y === 11 && x >= 3 && x <= 6)) {
                boxed.game.board[y * WIDTH + x] = 8;
            }
        }
    }
    const turn = tap(boxed, 'cw');
    assert.deepEqual([turn.rotated, turn.blocked], [false, true]);

    // a second hold for the same piece is refused by the engine
    const holds = session();
    const first = tap(holds, 'hold');
    const second = tap(holds, 'hold');
    assert.deepEqual([first.held, first.blocked], [true, false]);
    assert.deepEqual([second.held, second.blocked], [false, true]);

    // a turn that works says its direction
    const turns = session();
    place(turns, 'T', { x: 3, y: 5 });
    assert.deepEqual([tap(turns, 'cw').turn, tap(turns, 'ccw').turn, tap(turns, 'flip').turn], [1, 3, 2]);
});

test('repeat: auto-shift moves are their own, softer cue', () => {
    const s = session({ das: 10, arr: 2, sdf: 20 });
    place(s, 'T', { x: 0 });
    s.press('right', true);
    const ticks = Array.from({ length: 20 }, () => s.tick());
    assert.deepEqual([ticks[0].moved, ticks[0].repeat], [true, false], 'the press is a move');
    const repeats = ticks.filter((o) => o.repeat);
    assert.ok(repeats.length >= 3, `${repeats.length} repeats`);
    assert.ok(repeats.every((o) => !o.moved), 'a repeat is not a move');
    assert.deepEqual(cuesFor(ticks[0]).map(([name]) => name), ['move']);
    assert.deepEqual(cuesFor(repeats[0]).map(([name]) => name), ['repeat']);

    // softer: the loudest gain the repeat schedules is below the move's
    const { env, gains } = recorder();
    const sound = createSound({ env });
    sound.unlock();
    const peakOf = (name) => {
        const from = gains.length;
        assert.equal(sound.effect(name), true, name);

        return Math.max(...gains.slice(from).flatMap((g) => g.values));
    };
    const move = peakOf('move');
    env.clock.t += 1;
    const repeat = peakOf('repeat');
    assert.ok(repeat < move * 0.7, `repeat ${repeat} against move ${move}`);
});

test('soft drop: a step down under the held key, gravity alone is none', () => {
    const s = session();
    const plain = Array.from({ length: 70 }, () => s.tick());
    assert.equal(plain.filter((o) => o.soft > 0).length, 0);
    s.press('soft', true);
    const soft = s.tick();
    assert.equal(soft.soft, 1);
    assert.deepEqual(cuesFor(soft).map(([name]) => name), ['soft']);
});

/** Clears `rows` bottom rows with an I piece (horizontal for one row, vertical otherwise) by a hard drop. */
function clearWithI(s, rows) {
    s.game.board.fill(0);
    if (rows === 1) {
        place(s, 'I', { x: 0, y: 2 });
        for (let x = 4; x < WIDTH; x++) {
            s.game.board[(HEIGHT - 1) * WIDTH + x] = 8;
        }
    } else {
        // vertical I (state 1) sits in box column 2: drop it into column 0 of `rows` nearly full rows
        place(s, 'I', { x: -2, y: 2, rot: 1 });
        for (let y = HEIGHT - rows; y < HEIGHT; y++) {
            for (let x = 1; x < WIDTH; x++) {
                s.game.board[y * WIDTH + x] = 8;
            }
        }
    }
    s.press('hard', true);
    const outcome = s.tick();
    s.press('hard', false);
    s.tick();

    return outcome;
}

test('combo: each consecutive clearing piece counts up, a piece that clears nothing resets it', () => {
    const s = session();
    const combos = [1, 1, 2].map((rows) => clearWithI(s, rows));
    assert.deepEqual(combos.map((o) => [o.cleared, o.combo]), [[1, 1], [1, 2], [2, 3]]);

    s.press('hard', true);
    const miss = s.tick();
    s.press('hard', false);
    s.tick();
    assert.deepEqual([miss.locked, miss.cleared, miss.combo], [1, 0, 0]);
    assert.equal(clearWithI(s, 1).combo, 1, 'starts over');

    // the cue rises from the second clearing piece on
    assert.deepEqual(cuesFor(combos[0]).map(([name]) => name), ['drop', 'lock', 'mined']);
    assert.deepEqual(cuesFor(combos[2]), [['drop', combos[2].where], ['lock', combos[2].where], ['mined', 2], ['combo', 3]]);
});

test('the last-ten-rows warning sounds once, on the clear that reaches ten left', () => {
    const s = session();
    s.game.lines = 28;
    const first = clearWithI(s, 1);
    assert.equal(first.warning, false, '29 done, 11 left');
    const crossing = clearWithI(s, 1);
    assert.equal(crossing.warning, true, '30 done, 10 left');
    const after = clearWithI(s, 2);
    assert.equal(after.warning, false, 'once');
    assert.ok(cuesFor(crossing).some(([name]) => name === 'warning'));

    // jumping over the mark with a double still warns
    const jump = session();
    jump.game.lines = 29;
    assert.equal(clearWithI(jump, 2).warning, true);
});

test('several cues in one tick: move with touchdown, drop with lock and a halving and its combo', () => {
    const base = { cleared: 0, locked: 0, moved: false, repeat: false, rotated: false, turn: 0, blocked: false, dropped: false, held: false, soft: 0, touchdown: false, combo: 0, warning: false, where: { column: 4.5, stack: 0 } };
    assert.deepEqual(cuesFor({ ...base, moved: true, touchdown: true }).map(([name]) => name), ['move', 'touchdown']);
    assert.deepEqual(cuesFor({ ...base, dropped: true, locked: 1, cleared: 4, combo: 2 }).map(([name]) => name), ['drop', 'lock', 'halving', 'combo']);
    assert.deepEqual(cuesFor({ ...base, rotated: true, turn: 3, blocked: true }).map(([name]) => name), ['rotate', 'blocked']);
    assert.deepEqual(cuesFor({ ...base, held: true }).map(([name]) => name), ['hold']);
    assert.deepEqual(cuesFor({ ...base, locked: 1 }).map(([name]) => name), ['lock']);
    assert.deepEqual(cuesFor(base), []);

    // the sound plays them all in the same instant
    const { env } = recorder();
    const sound = createSound({ env });
    sound.unlock();
    for (const [name, arg] of cuesFor({ ...base, dropped: true, locked: 1, cleared: 4, combo: 2 })) {
        assert.equal(sound.effect(name, arg), true, name);
    }
});

test('a small voice limit: a flood of small cues is cut, the big moments always play', () => {
    const { env } = recorder();
    const sound = createSound({ env });
    sound.unlock();
    const played = Array.from({ length: 30 }, () => sound.effect('lock')).filter(Boolean).length;
    assert.ok(played > 0 && played <= 8, `${played} locks at once`);
    assert.equal(sound.effect('halving'), true);
    assert.equal(sound.effect('fanfare'), true);
    // the voices free up once they have rung out
    env.clock.t += 2;
    assert.equal(sound.effect('lock'), true);
});

test('every cue has a sound, and the master has a limiter in front of the speakers', () => {
    const { env, made } = recorder();
    const sound = createSound({ env });
    sound.unlock();
    for (const name of ['move', 'repeat', 'rotate', 'blocked', 'soft', 'drop', 'touchdown', 'lock', 'hold', 'mined', 'halving', 'combo', 'warning', 'count', 'fanfare', 'best', 'topout']) {
        env.clock.t += 3;
        assert.equal(sound.effect(name, 2), true, name);
    }
    assert.ok(made.some((node) => node.kind === 'compressor'), 'a compressor on the master');
});

/** A stand-in AudioContext that keeps every gain value it is given. */
function recorder() {
    const made = [];
    const gains = [];
    const clock = { t: 0 };
    const param = (values) => ({
        value: 1,
        setValueAtTime(v) {
            values?.push(v);
        },
        exponentialRampToValueAtTime(v) {
            values?.push(v);
        },
        linearRampToValueAtTime(v) {
            values?.push(v);
        },
        setTargetAtTime(v) {
            values?.push(v);
        },
    });
    const node = (kind) => {
        const values = [];
        const n = {
            kind,
            values,
            gain: param(values),
            frequency: param(),
            Q: param(),
            pan: param(),
            threshold: param(),
            knee: param(),
            ratio: param(),
            attack: param(),
            release: param(),
            connect(next) {
                return next;
            },
            setPeriodicWave() {},
            start() {},
            stop() {},
        };
        made.push(n);
        if (kind === 'gain') {
            gains.push(n);
        }

        return n;
    };
    class Context {
        constructor() {
            this.state = 'running';
            this.sampleRate = 8000;
            this.destination = node('destination');
        }
        get currentTime() {
            return clock.t;
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
        createStereoPanner() {
            return node('panner');
        }
        createDynamicsCompressor() {
            return node('compressor');
        }
        createBuffer(channels, length) {
            const data = new Float32Array(length);

            return { getChannelData: () => data };
        }
        createPeriodicWave() {
            return {};
        }
        resume() {
            return Promise.resolve();
        }
        suspend() {
            return Promise.resolve();
        }
        close() {
            return Promise.resolve();
        }
    }
    const env = {
        AudioContext: Context,
        clock,
        document: { hidden: false },
        performance: { now: () => 0 },
        setInterval: () => 1,
        clearInterval() {},
    };

    return { env, made, gains, clock };
}
