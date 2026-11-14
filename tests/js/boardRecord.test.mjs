/**
 * resources/js/boardRecord.js: a state without the record joined to the record the page holds.
 * Run by tests/Unit/Board/BoardRecordScriptsTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { followRecord } from '../../resources/js/boardRecord.js';

const move = (ply, id) => ({ ply, move: id, notation: id, path: [id], side: ply % 2 ? 'w' : 'b' });
// The page after `a1 b2`: two plies, version 3 (a game starts at version 1, every change adds one).
const held = () => ({ version: 3, ply: 2, moves: [move(1, 'a1'), move(2, 'b2')], pieces: {} });
const names = (state) => state.moves.map((entry) => entry.move);

test('the next move of the next version is added to the record', () => {
    const state = followRecord({ version: 4, ply: 3, lastMove: move(3, 'a3'), pieces: {} }, held());
    assert.deepEqual(names(state), ['a1', 'b2', 'a3']);
});

test('a move taken back and replaced while the page missed it: the record is asked for, not joined (security N4)', () => {
    // The server took b2 back (version 4), played c2 (5) and a3 (6); only the last state reaches the page. Its ply is the
    // page's plus one, so it looked like the next move, and the list read a1 b2 a3 while the game is a1 c2 a3.
    assert.equal(followRecord({ version: 6, ply: 3, lastMove: move(3, 'a3'), pieces: {} }, held()), null);
});

test('the same move again (a draw offer, a clock) keeps the record, a move taken back cuts it', () => {
    assert.deepEqual(names(followRecord({ version: 4, ply: 2, lastMove: move(2, 'b2') }, held())), ['a1', 'b2']);
    assert.deepEqual(names(followRecord({ version: 4, ply: 1, lastMove: move(1, 'a1') }, held())), ['a1']);
    // Taken back and played anew at the same ply: another move stands there now.
    assert.equal(followRecord({ version: 5, ply: 2, lastMove: move(2, 'c2') }, held()), null);
});

test('a state with its record passes through, and a stone board\'s push names what was taken', () => {
    const whole = { version: 9, ply: 1, moves: [move(1, 'c3')] };
    assert.equal(followRecord(whole, held()), whole);

    const before = { version: 3, ply: 2, moves: [move(1, 'a1'), move(2, 'b2')], pieces: { a1: {}, b2: {} } };
    const state = followRecord({ version: 4, ply: 3, lastMove: move(3, 'a2'), pieces: { a1: {}, a2: {} } }, before, true);
    assert.deepEqual(state.moves[2].taken, ['b2']);
});
