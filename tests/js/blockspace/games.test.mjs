/**
 * The browser engine replays the games the server is held to (tests/Fixtures/blockspace-games.json), move by move.
 * Run by tests/Unit/Board/BlockspaceScriptsTest.php; runnable alone with `node --test tests/js/blockspace/games.test.mjs`.
 */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { applyMove, cellOf, moveText, moves, newGame, notationOf, positionOf, reasonOf, stateOf, winner } from '../../../resources/js/blockspace/engine.js';

const games = JSON.parse(readFileSync(new URL('../../Fixtures/blockspace-games.json', import.meta.url), 'utf8'));
const md5 = (text) => createHash('md5').update(text).digest('hex');

test('the fixture holds 24 games', () => assert.equal(games.length, 24));

test('every game replays to the same positions, legal moves and winner', () => {
    for (const game of games) {
        const g = stateOf(game.start);
        for (const step of game.steps) {
            const legal = moves(g, g.side).map((m) => moveText(m.i, m.cells)).concat('pass').sort();
            assert.equal(legal.length, step.legal);
            assert.equal(legal.join('|').length, step.sum);
            assert.ok(applyMove(g, step.move), step.move);
            assert.equal(md5(positionOf(g)), step.after);
        }
        assert.equal(winner(g), game.winner);
    }
});

test('the record notes every turn sat out, as the server writes it', () => {
    // Blue's only corners are taken: after Orange's move Blue sits out both its turns (BlockspaceRulesTest).
    const board = new Array(189).fill('.');
    for (const [name, side] of [['a1', 'w'], ['e20', 'b'], ['d19', 'w'], ['f19', 'w'], ['d21', 'w'], ['f21', 'w']]) board[cellOf(name)] = side;
    const g = stateOf(['n', 0, 0, 0, '11', 0, '-', 'aaaaa', 'bbbbb', 'aabbccddeeffgghhiijjkkllmmnnooppqqrrssttuu', board.join('')].join(' '));

    assert.equal(notationOf(g, '0:b2'), '₿ I1 b2 +5 · ⚡ – · ⚡ –');
    assert.ok(applyMove(g, '0:b2'));
    assert.equal(g.last, '-');
    assert.equal(g.ti, 3);
    assert.equal(g.side, 0);
    // Nothing fell out: the ladder took the next transaction, the queue lost only that one.
    assert.equal(g.pool.length, 5);
    assert.equal(g.queue.length, 4);
    assert.equal(g.passes, 0);
    assert.equal(notationOf(g, '0:c3'), '₿ I1 c3 +5');

    // Neither side has anything left: the block is closed at once.
    const full = new Array(189).fill('b');
    full[cellOf('a1')] = 'w';
    full[cellOf('b2')] = '.';
    const closed = stateOf(['n', 0, 0, 0, '11', 0, '-', 'aaaaa', 'bbbbb', 'aabbccddeeffgghhiijjkkllmmnnooppqqrrssttuu', full.join('')].join(' '));
    assert.equal(notationOf(closed, '0:b2'), '₿ I1 b2 +5 · ⚡ – · ₿ –');
    assert.ok(applyMove(closed, '0:b2'));
    assert.equal(closed.last, 'x');
    assert.ok(closed.over);

    // A position written before the last field still reads.
    assert.equal(stateOf(['n', 0, 0, 0, '11', 0, 'aaaaa', 'bbbbb', 'aabbccddeeffgghhiijjkkllmmnnooppqqrrssttuu', board.join('')].join(' ')).last, '-');
});

test('a pass notes the turns sat out after it too', () => {
    // The position of the case above: Orange passes, and Blue sits out both its turns (BlockspaceRulesTest).
    const board = new Array(189).fill('.');
    for (const [name, side] of [['a1', 'w'], ['e20', 'b'], ['d19', 'w'], ['f19', 'w'], ['d21', 'w'], ['f21', 'w']]) board[cellOf(name)] = side;
    const g = stateOf(['n', 0, 0, 0, '11', 0, '-', 'aaaaa', 'bbbbb', 'aabbccddeeffgghhiijjkkllmmnnooppqqrrssttuu', board.join('')].join(' '));

    assert.equal(notationOf(g, 'pass'), '₿ – · ⚡ – · ⚡ –');
});

test('fourteen passes in a row end the game, and the end names its reason as the server does', () => {
    const g = newGame(7, false);
    for (let n = 1; n <= 14; n++) {
        assert.equal(g.over, false, 'still running before pass ' + n);
        assert.ok(applyMove(g, 'pass'));
    }
    assert.ok(g.over);
    assert.ok(g.pool.length > 0);
    assert.equal(reasonOf(g), 'passes');
    assert.equal(applyMove(g, 'pass'), false);
    assert.equal(stateOf(positionOf(g)).over, true);

    const closed = stateOf(positionOf(g).replace(/^(\S+ \S+ \S+ \S+ \S+) 14 -/, '$1 0 x'));
    assert.equal(reasonOf(closed), 'block_full');
    assert.equal(reasonOf({ last: '-', pool: [] }), 'mempool_empty');
});
