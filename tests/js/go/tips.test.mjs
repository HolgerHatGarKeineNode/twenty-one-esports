/**
 * resources/js/go/tips.js: the tips of Go practice (DerCaddy, 2026-10-27): by themselves after a move that matters,
 * and the tip button's advice from a search (the kind of move, then roughly where, never the point). Run by
 * tests/Unit/Board/GoPracticeScriptsTest.php; runnable alone with `node --test tests/js/go`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BLACK, GoBoard, WHITE } from '../../../resources/js/go/engine.js';
import { adviceFor, areaOf, autoTip } from '../../../resources/js/go/tips.js';

/** The boards before and after a move. */
function around(text, move) {
    const before = GoBoard.fromText(text);
    const after = GoBoard.fromText(text);
    after.playId(move);

    return { before, after, move };
}

test('the bot took stones of yours: the tip says how many', () => {
    // White (the bot) takes Black's stone on e5 with e4.
    const tip = autoTip({ ...around('9/9/9/4o4/3oxo3/9/9/9/9 o - 0', 'e4'), by: 'bot', me: BLACK });
    assert.deepEqual(tip, { key: 'captured', count: 1 });
});

test('the bot put your group in atari: the tip names where it stands, not the move', () => {
    // White plays e4: Black's d5 and e5 have only e6 left.
    const tip = autoTip({ ...around('9/9/9/3o5/2oxxo3/3o5/9/9/9 o - 0', 'e4'), by: 'bot', me: BLACK });
    assert.equal(tip.key, 'atari');
    assert.equal(tip.count, 2);
    assert.match(tip.point, /^[DE]5$/);
});

test('the bot left a group of its own in atari: you can take it', () => {
    const tip = autoTip({ ...around('9/9/9/4x4/3x1x3/9/9/9/9 o - 0', 'e5'), by: 'bot', me: BLACK });
    assert.deepEqual(tip, { key: 'canTake', count: 1 });
});

test('your own move took your group\'s last liberty but one; a stone on the edge early on; the bot\'s pass', () => {
    // Black's f5 joins e5 and e4 but takes their liberty f5: only e6 is left.
    const selfAtari = autoTip({ ...around('9/9/9/5o3/3ox1o2/3oxo3/4o4/9/9 x - 0', 'f5'), by: 'me', me: BLACK });
    assert.deepEqual(selfAtari, { key: 'selfAtari', count: 3 });
    assert.equal(autoTip({ ...around('9/9/9/9/9/9/9/9/9 x - 0', 'a5'), by: 'me', me: BLACK })?.key, 'firstLine');
    assert.equal(autoTip({ ...around('9/9/9/9/9/9/9/9/9 x - 0', 'e5'), by: 'me', me: BLACK }), null);
    assert.deepEqual(autoTip({ ...around('9/9/9/9/4x4/9/9/9/9 o - 0', 'pass'), by: 'bot', me: BLACK }), { key: 'botPassed' });
});

test('each tip comes up at most twice a game', () => {
    const args = { ...around('9/9/9/9/9/9/9/9/9 x - 0', 'a5'), by: 'me', me: BLACK };
    assert.equal(autoTip({ ...args, shown: { firstLine: 1 } })?.key, 'firstLine');
    assert.equal(autoTip({ ...args, shown: { firstLine: 2 } }), null);
});

test('the board in nine parts: corners, sides and centre', () => {
    const board = GoBoard.fromText('9/9/9/9/9/9/9/9/9 x - 0');
    assert.equal(areaOf(board, 'a9'), 'upperLeft');
    assert.equal(areaOf(board, 'e9'), 'upperSide');
    assert.equal(areaOf(board, 'j9'), 'upperRight');
    assert.equal(areaOf(board, 'a5'), 'leftSide');
    assert.equal(areaOf(board, 'e5'), 'centre');
    assert.equal(areaOf(board, 'j5'), 'rightSide');
    assert.equal(areaOf(board, 'c3'), 'lowerLeft');
    assert.equal(areaOf(board, 'e1'), 'lowerSide');
    assert.equal(areaOf(board, 'g1'), 'lowerRight');
});

test('the advice names the kind of the best move and its part of the board, never the point', () => {
    assert.deepEqual(adviceFor('9/9/9/3xxx3/2xooox2/3xx4/9/9/9 x - 0', { move: 'f4', winrate: 0.9 }), { kind: 'capture', area: 'centre', count: 3, winrate: 0.9 });
    assert.equal(adviceFor('9/9/9/4o4/3oxo3/9/9/9/9 x - 0', { move: 'e4', winrate: 0.6 }).kind, 'save');
    assert.equal(adviceFor('9/9/9/4x4/3x1x3/4o4/9/9/9 x - 0', { move: 'e5', winrate: 0.6 }).kind, 'big');
    assert.equal(adviceFor('9/9/9/4x4/3xo4/9/9/9/9 x - 0', { move: 'f5', winrate: 0.6 }).kind, 'atari');
    assert.deepEqual(adviceFor('9/9/9/9/9/9/9/9/9 x - 1', { move: 'pass', winrate: 1 }), { kind: 'pass', area: null, winrate: 1 });
    for (const advice of [adviceFor('9/9/9/9/9/9/9/9/9 x - 0', { move: 'c3', winrate: 0.5 })]) assert.ok(!JSON.stringify(advice).includes('c3'));
});

test('the advice works for White too', () => {
    const advice = adviceFor('9/9/9/3ooo3/2oxxxo2/3oo4/9/9/9 o - 0', { move: 'f4', winrate: 0.9 });
    assert.equal(advice.kind, 'capture');
    assert.equal(WHITE, 2);
});
