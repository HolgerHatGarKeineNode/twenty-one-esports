/**
 * Blockfill invariants under seeded random play, and what happens to bad input.
 * Bounded: 12 seeds x 2,000 ticks, the inputs drawn from the engine's own seeded
 * generator, so every run of this file plays the same games.
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    ACTIONS,
    HEIGHT,
    LOCK_DELAY,
    MAX_LOCK_RESETS,
    NEXT_COUNT,
    WIDTH,
    activeCells,
    createGame,
    isOver,
    run,
    stateHash,
    step,
} from '../../../resources/js/stacker/engine.js';
import { createRng, nextBelow } from '../../../resources/js/stacker/prng.js';

const SEEDS = 12;
const TICKS = 2000;

function seedFor(i) {
    return `${((0xa5a5a5a5 ^ Math.imul(i + 1, 0x01000193)) >>> 0).toString(16).padStart(8, '0')}${'5'.repeat(24)}`;
}

function assertSound(game, label) {
    for (let i = 0; i < game.board.length; i++) {
        assert.ok(game.board[i] >= 0 && game.board[i] <= 7, `${label}: cell value ${game.board[i]}`);
    }
    for (let y = 0; y < HEIGHT; y++) {
        let filled = 0;
        for (let x = 0; x < WIDTH; x++) {
            filled += game.board[y * WIDTH + x] === 0 ? 0 : 1;
        }
        assert.ok(filled < WIDTH, `${label}: row ${y} full and not cleared`);
    }
    if (game.current !== null && !game.toppedOut) {
        for (const [x, y] of activeCells(game)) {
            assert.ok(x >= 0 && x < WIDTH && y >= 0 && y < HEIGHT, `${label}: piece outside the board at ${x},${y}`);
            assert.equal(game.board[y * WIDTH + x], 0, `${label}: piece overlaps the stack at ${x},${y}`);
        }
        assert.ok(game.lockTicks < LOCK_DELAY, `${label}: lock delay ran over`);
        assert.ok(game.lockResets <= MAX_LOCK_RESETS, `${label}: too many lock resets`);
    }
    assert.ok(game.queue.length >= NEXT_COUNT, `${label}: next queue short`);
    assert.ok(game.hold >= -1 && game.hold < 7, `${label}: hold ${game.hold}`);
}

/**
 * Plays one seed with random inputs; a game that ends early is followed by the
 * next one, until TICKS ticks are played. Returns the games with their logs.
 */
function fuzz(seedIndex) {
    const dice = createRng(seedFor(seedIndex + 1000));
    const settings = { das: 1 + nextBelow(dice, 20), arr: nextBelow(dice, 6), sdf: 5 + nextBelow(dice, 37) };
    const games = [];
    let played = 0;
    let round = 0;
    while (played < TICKS) {
        const seed = seedFor(seedIndex * 100 + round++);
        const game = createGame({ seed, settings });
        const log = [];
        while (!isOver(game) && played < TICKS) {
            const inputs = [];
            const count = nextBelow(dice, 8) < 5 ? 0 : 1 + nextBelow(dice, 2);
            for (let i = 0; i < count; i++) {
                const action = nextBelow(dice, 16) === 0 ? 3 : nextBelow(dice, ACTIONS.length);
                const down = action === 3 ? 1 - game.held[3] : nextBelow(dice, 2);
                inputs.push([action, down]);
                log.push([game.tick, action, down]);
            }
            const before = { tick: game.tick, lines: game.lines, pieces: game.pieces };
            step(game, inputs);
            played++;
            const label = `seed ${seed} tick ${before.tick}`;
            assert.equal(game.tick, before.tick + 1, label);
            assert.ok(game.lines >= before.lines, `${label}: lines went down`);
            assert.ok(game.pieces >= before.pieces, `${label}: pieces went down`);
            assertSound(game, label);
        }
        games.push({ seed, settings, log, game });
    }

    return games;
}

test(`random legal play keeps the board sound, ${SEEDS} seeds x ${TICKS} ticks`, () => {
    let locked = 0;
    let cleared = 0;
    for (let s = 0; s < SEEDS; s++) {
        for (const { game } of fuzz(s)) {
            locked += game.pieces;
            cleared += game.lines;
        }
    }

    // the fuzz must actually lock pieces and clear lines, or it checks nothing
    assert.ok(locked > 200, `only ${locked} pieces locked`);
    assert.ok(cleared > 0, 'no line cleared');
});

test('a random game replays from its log to the same state', () => {
    for (const { seed, settings, log, game } of fuzz(3)) {
        const replayed = run(seed, settings, log, { maxTicks: game.tick });
        assert.equal(replayed.stateHash, stateHash(game));
        assert.equal(replayed.ticks, game.tick);
    }
});

test('a log out of order, with a negative or broken tick or an unknown action is rejected', () => {
    const seed = seedFor(1);
    const settings = { das: 8, arr: 1, sdf: 20 };
    const bad = [
        [[5, 0, 1], [4, 0, 0]],
        [[-1, 0, 1]],
        [[1.5, 0, 1]],
        [['3', 0, 1]],
        [[3, 8, 1]],
        [[3, -1, 1]],
        [[3, 0, 2]],
        [[3, 0, true]],
        [[3, 0]],
        [[3, 0, 1, 0]],
        [null],
        'not a log',
    ];
    for (const log of bad) {
        assert.throws(() => run(seed, settings, log), /input|log/, JSON.stringify(log));
    }

    // the same tick twice is fine, as two inputs of one tick
    assert.doesNotThrow(() => run(seed, settings, [[3, 0, 1], [3, 0, 0]], { maxTicks: 10 }));
});

test('malformed tick inputs are skipped without touching the game', () => {
    const seed = seedFor(2);
    const clean = createGame({ seed });
    const dirty = createGame({ seed });
    const garbage = [[8, 1], [-1, 1], [0, 2], [1.5, 1], [0, 1, 9], [0], 'x', null, undefined, {}, [NaN, 1]];

    for (let t = 0; t < 90; t++) {
        step(clean, t === 10 ? [[1, 1]] : []);
        step(dirty, t === 10 ? [...garbage, [1, 1], ...garbage] : garbage);
    }
    step(dirty, 'not a list');
    step(clean, []);

    assert.equal(stateHash(dirty), stateHash(clean));
    assert.equal(dirty.current.x, clean.current.x);
});

test('pressing a key that is down or releasing one that is up changes nothing', () => {
    const seed = seedFor(3);
    const once = createGame({ seed, settings: { das: 20, arr: 5, sdf: 5 } });
    const twice = createGame({ seed, settings: { das: 20, arr: 5, sdf: 5 } });

    step(once, [[0, 1]]);
    step(twice, [[0, 1], [0, 1], [1, 0], [4, 0]]);

    assert.equal(stateHash(twice), stateHash(once));
});

test('a finished or topped-out game ignores further ticks', () => {
    const game = createGame({ seed: seedFor(4) });
    for (let i = 0; i < 200 && !isOver(game); i++) {
        step(game, [[3, 1]]);
        step(game, [[3, 0]]);
    }
    assert.ok(game.toppedOut, 'hard drops in place top out');
    const hash = stateHash(game);
    step(game, [[3, 1], [7, 1], [0, 1]]);

    assert.equal(stateHash(game), hash);
});

test('settings outside their bounds and malformed seeds are refused', () => {
    const seed = seedFor(5);
    for (const settings of [{ das: 0, arr: 2, sdf: 20 }, { das: 21, arr: 2, sdf: 20 }, { das: 10, arr: 6, sdf: 20 }, { das: 10, arr: -1, sdf: 20 }, { das: 10, arr: 2, sdf: 4 }, { das: 10, arr: 2, sdf: 42 }, { das: 10, arr: 2 }, { das: '10', arr: 2, sdf: 20 }]) {
        assert.throws(() => createGame({ seed, settings }), RangeError, JSON.stringify(settings));
    }
    assert.throws(() => createGame({ seed: 'ABC' }), TypeError);
    assert.throws(() => run(seed, undefined, [], { maxTicks: 0 }), RangeError);
});
