/**
 * Blockfill handling rules one by one: DAS/ARR, soft drop (SDF), gravity, lock
 * delay with its 15 resets, hold, and the turn kicks including the half turn.
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    ACTION,
    HEIGHT,
    LOCK_DELAY,
    MAX_LOCK_RESETS,
    WIDTH,
    createGame,
    dropY,
    nextPieces,
    step,
} from '../../../resources/js/stacker/engine.js';
import { kicksFor, PIECE_CODES } from '../../../resources/js/stacker/pieces.js';

const SEED = '0123456789abcdef0123456789abcdef';
const piece = (code) => PIECE_CODES.indexOf(code);

/** A game whose active piece is replaced by `code` at (x, y) in state `rot`. */
function gameWith(code, { x = 3, y = 2, rot = 0, settings = { das: 5, arr: 2, sdf: 20 } } = {}) {
    const game = createGame({ seed: SEED, settings });
    game.current = { piece: piece(code), rot, x, y };
    game.lowestY = y;

    return game;
}

function fill(game, cells) {
    for (const [x, y] of cells) {
        game.board[y * WIDTH + x] = 8;
    }
}

/** Steps through `ticks`, pressing/releasing per a {tick: inputs} map; records x after each tick. */
function play(game, ticks, inputsAt = {}, read = (g) => g.current.x) {
    const seen = [];
    for (let t = 0; t < ticks; t++) {
        step(game, inputsAt[t] ?? []);
        seen.push(read(game));
    }

    return seen;
}

test('a held key moves once, waits DAS ticks, then repeats every ARR ticks up to the wall', () => {
    const game = gameWith('T', { settings: { das: 5, arr: 2, sdf: 20 } });

    assert.deepEqual(play(game, 12, { 0: [[ACTION.RIGHT, 1]] }), [4, 4, 4, 4, 4, 5, 5, 6, 6, 7, 7, 7]);
});

test('ARR 0 slides to the wall in the tick DAS is charged', () => {
    const game = gameWith('T', { settings: { das: 3, arr: 0, sdf: 20 } });

    assert.deepEqual(play(game, 5, { 0: [[ACTION.LEFT, 1]] }), [2, 2, 2, 0, 0]);
});

test('a tap shorter than DAS moves exactly one cell', () => {
    const game = gameWith('T', { settings: { das: 5, arr: 1, sdf: 20 } });

    assert.deepEqual(play(game, 10, { 0: [[ACTION.RIGHT, 1]], 4: [[ACTION.RIGHT, 0]] }), [4, 4, 4, 4, 4, 4, 4, 4, 4, 4]);
});

test('the last pressed direction wins; releasing it hands over to the other key after a fresh DAS', () => {
    const game = gameWith('T', { settings: { das: 3, arr: 1, sdf: 20 } });
    const xs = play(game, 9, {
        0: [[ACTION.RIGHT, 1]],
        1: [[ACTION.LEFT, 1]],
        2: [[ACTION.LEFT, 0]],
    });

    // right +1, left -1 (left wins), release left: right charges again from 0
    assert.deepEqual(xs, [4, 3, 3, 3, 3, 4, 5, 6, 7]);
});

test('soft drop falls SDF steps per tick: SDF 20 one cell per tick, SDF 5 one per 4 ticks, SDF 41 to the floor', () => {
    const rows = (sdf, ticks) => play(gameWith('T', { settings: { das: 10, arr: 2, sdf } }), ticks, { 0: [[ACTION.SOFT, 1]] }, (g) => g.current.y);

    assert.deepEqual(rows(20, 4), [3, 4, 5, 6]);
    assert.deepEqual(rows(5, 8), [2, 2, 2, 3, 3, 3, 3, 4]);
    assert.deepEqual(rows(41, 2), [HEIGHT - 2, HEIGHT - 2]);
});

test('gravity without input drops one cell after 61 ticks (1092 of 65536 per tick)', () => {
    const ys = play(gameWith('T'), 62, {}, (g) => g.current.y);

    assert.equal(ys[59], 2);
    assert.equal(ys[60], 3);
});

test(`a grounded piece locks after ${LOCK_DELAY} ticks`, () => {
    const game = gameWith('T');
    game.current.y = dropY(game);
    game.lowestY = game.current.y;

    play(game, LOCK_DELAY - 1);
    assert.equal(game.pieces, 0);
    step(game);
    assert.equal(game.pieces, 1);
});

test(`moves on the ground restart the lock delay ${MAX_LOCK_RESETS} times, then it runs out`, () => {
    const game = gameWith('T');
    game.current.y = dropY(game);
    game.lowestY = game.current.y;

    // a tap every 10 ticks, alternating right and left, never stops
    const inputsAt = {};
    for (let t = 0; t < 400; t += 10) {
        const key = (t / 10) % 2 === 0 ? ACTION.RIGHT : ACTION.LEFT;
        inputsAt[t] = [[key, 1]];
        inputsAt[t + 1] = [[key, 0]];
    }
    const locked = play(game, 171, inputsAt, (g) => g.pieces);

    // reset 15 is the tap at tick 140; 30 ticks of delay end with tick 169
    assert.equal(locked.indexOf(1), 169);
});

test('reaching a new lowest row gives back all lock resets', () => {
    const game = gameWith('T', { x: 0, settings: { das: 20, arr: 5, sdf: 41 } });
    fill(game, [[0, 23], [1, 23], [2, 23]]);
    game.current.y = dropY(game);
    game.lowestY = game.current.y;
    for (const key of [ACTION.RIGHT, ACTION.LEFT, ACTION.RIGHT, ACTION.LEFT, ACTION.RIGHT, ACTION.LEFT, ACTION.RIGHT, ACTION.RIGHT]) {
        step(game, [[key, 1]]);
        step(game, [[key, 0]]);
    }
    assert.equal(game.current.x, 2);
    assert.equal(game.lockResets, 8);

    // one more step right leaves the ledge, the held soft drop takes it to the floor
    step(game, [[ACTION.RIGHT, 1], [ACTION.SOFT, 1]]);
    assert.equal(game.current.x, 3);
    assert.equal(game.current.y, HEIGHT - 2);
    assert.equal(game.lockResets, 0);
});

test('hold works once per piece and swaps back after the next lock', () => {
    const game = createGame({ seed: SEED });
    const first = game.current.piece;
    const second = nextPieces(game)[0];

    step(game, [[ACTION.HOLD, 1]]);
    assert.equal(game.hold, first);
    assert.equal(game.current.piece, second);

    step(game, [[ACTION.HOLD, 0]]);
    step(game, [[ACTION.HOLD, 1]]);
    assert.equal(game.current.piece, second, 'a second hold of the same piece is refused');

    step(game, [[ACTION.HOLD, 0], [ACTION.HARD, 1]]);
    const third = game.current.piece;
    step(game, [[ACTION.HARD, 0], [ACTION.HOLD, 1]]);
    assert.equal(game.current.piece, first);
    assert.equal(game.hold, third);
});

test('a half turn in open space turns in place', () => {
    const game = gameWith('T', { y: 10 });
    step(game, [[ACTION.FLIP, 1]]);

    assert.deepEqual(game.current, { piece: piece('T'), rot: 2, x: 3, y: 10 });
});

test('a half turn against the floor kicks one row up', () => {
    const game = gameWith('T', { y: HEIGHT - 2 });
    step(game, [[ACTION.FLIP, 1]]);

    assert.deepEqual(game.current, { piece: piece('T'), rot: 2, x: 3, y: HEIGHT - 3 });
});

test('a half turn against the left wall kicks one column right', () => {
    // vertical I in column 0 (box x -2, state 1); state 3 sits one box column further left
    const game = gameWith('I', { x: -2, y: 8, rot: 1 });
    step(game, [[ACTION.FLIP, 1]]);

    assert.deepEqual(game.current, { piece: piece('I'), rot: 3, x: -1, y: 8 });
});

test('a half turn that no kick can fit is refused', () => {
    const game = gameWith('T', { y: 10 });
    const cells = [];
    for (let x = 0; x < WIDTH; x++) {
        for (const y of [9, 12]) {
            cells.push([x, y]);
        }
    }
    fill(game, cells);
    fill(game, [[0, 11], [1, 11], [2, 11], [6, 11], [7, 11], [8, 11], [9, 11]]);
    fill(game, [[0, 10], [1, 10], [2, 10], [3, 10], [5, 10], [6, 10], [7, 10], [8, 10], [9, 10]]);
    step(game, [[ACTION.FLIP, 1]]);

    assert.equal(game.current.rot, 0);
});

test('O turns without ever moving', () => {
    const game = gameWith('O', { x: 4, y: 10 });
    for (const action of [ACTION.CW, ACTION.FLIP, ACTION.CCW]) {
        step(game, [[action, 1], [action, 0]]);
    }

    assert.equal(game.current.x, 4);
    assert.equal(game.current.y, 10);
});

test('a flat I on the floor turns clockwise with the SRS floor kick (+1 right, 2 up)', () => {
    const game = gameWith('I', { y: HEIGHT - 2 });
    step(game, [[ACTION.CW, 1]]);

    assert.deepEqual(game.current, { piece: piece('I'), rot: 1, x: 4, y: HEIGHT - 4 });
});

test('a T pointing left at the right wall turns clockwise with the SRS wall kick (one left)', () => {
    // state 3 at box x 8 covers columns 8-9; state 0 in place would reach column 10
    const game = gameWith('T', { x: 8, y: 10, rot: 3 });
    step(game, [[ACTION.CW, 1]]);

    assert.deepEqual(game.current, { piece: piece('T'), rot: 0, x: 7, y: 10 });
});

test('quarter-turn kicks are SRS-symmetric: going back tries the opposite offsets', () => {
    for (const code of ['I', 'T', 'J', 'L', 'S', 'Z']) {
        for (let from = 0; from < 4; from++) {
            for (const to of [(from + 1) & 3, (from + 3) & 3]) {
                const there = kicksFor(piece(code), from, to);
                const back = kicksFor(piece(code), to, from);
                assert.deepEqual(back.map(([x, y]) => [-x || 0, -y || 0]), there.map(([x, y]) => [x, y]), `${code} ${from}>${to}`);
            }
        }
    }
});
