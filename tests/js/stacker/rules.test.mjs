/**
 * Blockfill week rules (admin page "League weeks"): how many lines finish a run,
 * every how many lines the level goes up, and the gravity curve the levels climb.
 * The rules are the engine id itself (a canonical string like t60e5g1s1c9), so a
 * replay's header names them and the verifier replays exactly those.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    CELL,
    ENGINES,
    GRAVITY,
    GRAVITY_LEVELS,
    HEIGHT,
    WIDTH,
    createGame,
    isEngine,
    parseRules,
    rulesId,
    rulesOf,
    run,
    step,
} from '../../../resources/js/stacker/engine.js';
import { PIECE_I } from '../../../resources/js/stacker/pieces.js';

const SEED = '0123456789abcdef0123456789abcdef';

function fixture(name) {
    return JSON.parse(readFileSync(new URL(`../../Fixtures/stacker/${name}.json`, import.meta.url), 'utf8'));
}

/** Fills the bottom `rows` rows except column 0 and drops a vertical I into the gap: clears min(rows, 4) lines. */
function clearWithI(game, rows) {
    game.board.fill(0);
    for (let y = HEIGHT - rows; y < HEIGHT; y++) {
        for (let x = 1; x < WIDTH; x++) {
            game.board[y * WIDTH + x] = 8;
        }
    }
    game.current = { piece: PIECE_I, rot: 1, x: -2, y: 4 };
    game.lowestY = 4;
    step(game, [[3, 1]]);
    step(game, [[3, 0]]);
}

test('the gravity table is the guideline curve of Tetris Worlds, (0.8 - (level - 1) * 0.007) ^ (level - 1) seconds per row, capped at 20G', () => {
    assert.equal(GRAVITY_LEVELS.length, 19);
    GRAVITY_LEVELS.forEach((gravity, index) => {
        const seconds = (0.8 - index * 0.007) ** index;
        assert.equal(gravity, Math.min(20 * CELL, Math.floor(CELL / (60 * seconds))), `level ${index + 1}`);
    });
    // level 1 is the gravity every run before the week rules was played on
    assert.equal(GRAVITY_LEVELS[0], GRAVITY);
    assert.equal(GRAVITY_LEVELS[18], 20 * CELL);
});

test('a rule set has exactly one id: today\'s rules are bf1, everything else a canonical string', () => {
    assert.equal(rulesId({ goal: 40, every: 0, start: 1 }), 'bf1');
    assert.equal(rulesId({ goal: 60, every: 0, start: 1 }), 't60g1');
    assert.equal(rulesId({ goal: 60, every: 5, start: 1, step: 1, cap: 9 }), 't60e5g1s1c9');
    assert.equal(rulesId({ goal: 100, every: 20, start: 18, step: 3, cap: 19 }), 't100e20g18s3c19');

    assert.deepEqual({ ...parseRules('t60e5g1s1c9') }, { goal: 60, every: 5, start: 1, step: 1, cap: 9 });
    assert.deepEqual({ ...parseRules('t20g19') }, { goal: 20, every: 0, start: 19, step: 0, cap: 19 });
    for (const bad of ['t40g1', 't060g1', 't60e5g1s1c1', 't60e0g1s0c1', 't9g1', 't101g1', 't60g20', 't60e21g1s1c9', 't60e5g1s4c9', 't60e5g9s1c8', 'T60g1', 't60g1 ', 'bf2', '']) {
        assert.equal(parseRules(bad), null, bad);
        assert.equal(isEngine(bad), false, bad);
    }
    for (const bad of [{ goal: 60, every: 5, start: 1, step: 1, cap: 1 }, { goal: 9, every: 0, start: 1 }, { goal: 60, every: 5, start: 1, step: 0, cap: 9 }]) {
        assert.throws(() => rulesId(bad), RangeError);
    }
    assert.ok(isEngine('t60e5g1s1c9') && isEngine('bf1') && isEngine('bf1hard'));
    assert.ok('t100e20g18s3c19'.length <= 16, 'fits the replay header and the engine column');
});

test('the old ids keep their own rules: 40 lines, no level-ups, their frozen gravity', () => {
    assert.deepEqual(Object.keys(ENGINES), ['bf1', 'bf1hard', 'bf1expert', 'bf1master']);
    assert.equal(rulesOf('bf1').goal, 40);
    assert.equal(rulesOf('bf1hard').every, 0);
    assert.equal(createGame({ seed: SEED, engine: 'bf1hard' }).gravityStep, 3277);
    assert.throws(() => rulesOf('t40g1'), RangeError);
});

test('the level goes up at the set line counts and every level falls faster, up to the cap', () => {
    const game = createGame({ seed: SEED, engine: 't40e3g2s2c7' });
    assert.equal(game.goal, 40);
    assert.equal(game.level, 1);
    assert.equal(game.gravityStep, GRAVITY_LEVELS[1]);

    clearWithI(game, 2);
    assert.deepEqual([game.lines, game.level, game.gravityStep], [2, 1, GRAVITY_LEVELS[1]]);
    clearWithI(game, 1);
    // 3 lines: level 2, two steps up the table (2 -> 4)
    assert.deepEqual([game.lines, game.level, game.gravityStep], [3, 2, GRAVITY_LEVELS[3]]);
    clearWithI(game, 4);
    // 7 lines: level 3 (table 6)
    assert.deepEqual([game.lines, game.level, game.gravityStep], [7, 3, GRAVITY_LEVELS[5]]);
    clearWithI(game, 4);
    // 11 lines: level 4, the table would say 8, the cap holds it at 7
    assert.deepEqual([game.lines, game.level, game.gravityStep], [11, 4, GRAVITY_LEVELS[6]]);
});

test('a new piece falls at its level\'s gravity', () => {
    const slow = createGame({ seed: SEED, engine: 't40e1g1s1c19' });
    clearWithI(slow, 4);
    assert.equal(slow.level, 5);
    const y0 = slow.current.y;
    // level 5: 3075 of 65536 per tick, a row every 22 ticks (level 1 would need 61)
    for (let t = 0; t < 22; t++) {
        step(slow, []);
    }
    assert.equal(slow.current.y, y0 + 1);

    const steady = createGame({ seed: SEED, engine: 'bf1' });
    clearWithI(steady, 4);
    assert.deepEqual([steady.level, steady.gravityStep], [1, GRAVITY]);
});

test('the run ends at the target lines', () => {
    const short = createGame({ seed: SEED, engine: 't10g1' });
    short.lines = 8;
    clearWithI(short, 2);
    assert.deepEqual([short.lines, short.finished], [10, true]);

    const today = createGame({ seed: SEED });
    today.lines = 8;
    clearWithI(today, 2);
    assert.deepEqual([today.lines, today.finished], [10, false]);

    // the 40-line reference run, on 10 lines at the same gravity: the same game, over at its tenth line
    const f = fixture('forty-lines');
    const ten = run(f.seed, f.settings, f.inputs, { engine: 't10g1' });
    assert.equal(ten.finished, true);
    assert.ok(ten.lines >= 10 && ten.lines < 14, `lines ${ten.lines}`);
    assert.ok(ten.ticks < f.expected.ticks);
    assert.deepEqual(run(f.seed, f.settings, f.inputs, { engine: 'bf1' }), f.expected);
});

test('the verifier replays a run on the rules of its header: rules A verify under A, never under B', async () => {
    const f = fixture('sixty-lines-rules');
    const replay = readFileSync(new URL('../../Fixtures/stacker/sixty-lines-rules.replay', import.meta.url), 'utf8').trim();
    const { verify } = await import('../../../resources/js/stacker/verify.mjs');
    const request = (engine, claimed = f.expected) => ({
        replay,
        seed: f.seed,
        engine,
        claimed: { ticks: claimed.ticks, hash: claimed.stateHash },
        limits: { ticks: 36000, inputs: 20000, bytes: 65536, inputsPerTick: 1, inputSlack: 64 },
    });

    const verdict = await verify(request('t60e5g1s1c9'));
    assert.equal(verdict.ok, true, JSON.stringify(verdict));
    assert.equal(verdict.lines, 61);

    // the same board on a curve the run never felt: the header says A, and the hash binds A
    const b = run(f.seed, f.settings, f.inputs, { engine: 't60e5g1s2c9' });
    assert.equal(b.ticks, f.expected.ticks);
    assert.notEqual(b.stateHash, f.expected.stateHash);
    assert.deepEqual(await verify(request('t60e5g1s2c9')), { ok: false, reason: 'engine' });
    assert.deepEqual(await verify(request('t60e5g1s2c9', b)), { ok: false, reason: 'engine' });
    assert.deepEqual(await verify(request('bf1')), { ok: false, reason: 'engine' });
    // an id that is no rule set at all, and a non-canonical spelling of one
    assert.deepEqual(await verify(request('t60e5g1s1c99')), { ok: false, reason: 'engine' });
    assert.deepEqual(await verify(request('t40g1')), { ok: false, reason: 'engine' });
});
