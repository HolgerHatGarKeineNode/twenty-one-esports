/**
 * The browser engine replays the games the server is held to (tests/Fixtures/blockspace-games.json), move by move.
 * Run by tests/Unit/Board/BlockspaceScriptsTest.php; runnable alone with `node --test tests/js/blockspace/games.test.mjs`.
 */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { applyMove, moveText, moves, positionOf, stateOf, winner } from '../../../resources/js/blockspace/engine.js';

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
