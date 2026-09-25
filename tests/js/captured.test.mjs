/**
 * resources/js/captured.js: the pieces each side has taken, read from a FEN.
 * Run by tests/Feature/Chess/CapturedPiecesTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { capturedBy, capturedSummary } from '../../resources/js/captured.js';

const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

test('the starting position has nothing captured and no lead', () => {
    assert.deepEqual(capturedBy(START, 'w'), []);
    assert.deepEqual(capturedBy(START, 'b'), []);
    assert.deepEqual(capturedSummary(START, 'w'), { glyphs: '', lead: '', label: '' });
});

test('a pawn trade and a won knight: strongest first, lead on the side ahead', () => {
    // Black is missing both knights and the d-pawn, White one pawn.
    const fen = 'r1bqkb1r/ppp1pppp/8/8/2PP4/8/PP3PPP/RNBQKBNR b KQkq - 0 5';
    assert.deepEqual(capturedBy(fen, 'w'), ['n', 'n', 'p']);
    assert.deepEqual(capturedBy(fen, 'b'), ['p']);
    const white = capturedSummary(fen, 'w', { n: 'knight', p: 'pawn' });
    assert.equal(white.lead, '+6');
    assert.equal(white.label, 'knight, knight, pawn');
    assert.equal(white.glyphs, '\u2658\uFE0E\u2658\uFE0E\u2659\uFE0E');
    assert.equal(capturedSummary(fen, 'b').lead, '');
});

test('a promoted pawn is not shown as a captured piece', () => {
    // White has two queens (one promoted) and seven pawns; black lost nothing.
    const fen = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPP1/RNBQKBNQ w Qkq - 0 30';
    assert.deepEqual(capturedBy(fen, 'b'), ['r']);
    assert.equal(capturedSummary(fen, 'w').lead, '+3');
});
