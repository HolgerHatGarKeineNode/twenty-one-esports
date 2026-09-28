/**
 * resources/js/moveHistory.js: stepping through a game's moves on every board (P55).
 * Run by tests/Feature/Chess/MoveHistoryTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { plyLabel, positionsFrom, withHistory } from '../../resources/js/moveHistory.js';

const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
const AFTER_E4 = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1';
const moves = (...ucis) => ucis.map((uci) => ({ uci, san: uci }));

/** A board as chessGame and dailyGame build it: the moves in `state`, positions from the replayer. */
function board(list) {
    const positions = positionsFrom(START);
    const host = withHistory({
        state: { moves: list },
        browsed: 0,
        get historyMoves() {
            return this.state.moves;
        },
        historyFen(index) {
            return positions(this.state.moves)[index];
        },
        onBrowse() {
            this.browsed += 1;
        },
    });

    return host;
}

test('the replayer gives the start and every position after it, and follows a move taken back', () => {
    const positions = positionsFrom(START);
    const fens = positions(moves('e2e4', 'e7e5', 'g1f3'));
    assert.equal(fens.length, 4);
    assert.equal(fens[0], START);
    assert.equal(fens[1], AFTER_E4);

    // An own move the server refused, then another one: the positions follow.
    const again = positions(moves('e2e4', 'e7e5', 'b1c3'));
    assert.equal(again.length, 4);
    assert.match(again[3], /2N5/);
    assert.equal(positions(moves('e2e4')).length, 2);
});

test('a promotion is replayed with its piece', () => {
    const positions = positionsFrom('8/P7/8/8/8/8/8/k6K w - - 0 1');
    assert.match(positions(moves('a7a8q'))[1], /^Q7/);
});

test('a board follows the game until someone steps back, and then stays on its position', () => {
    const b = board(moves('e2e4', 'e7e5', 'g1f3'));
    assert.equal(b.browsing, false);
    assert.equal(b.shownIndex, 3);

    b.go(1);
    assert.equal(b.browsing, true);
    assert.equal(b.shownFen, AFTER_E4);
    assert.deepEqual(b.shownSquares, ['e2', 'e4']);
    assert.equal(b.shownLabel, '1. e2e4');
    assert.equal(b.newMoves, 0);
    assert.equal(b.browsed, 1);

    // The opponent moves: the board keeps the old position and counts the new move.
    b.state.moves = moves('e2e4', 'e7e5', 'g1f3', 'b8c6');
    assert.equal(b.shownIndex, 1);
    assert.equal(b.shownFen, AFTER_E4);
    assert.equal(b.newMoves, 1);
    assert.equal(b.newestLabel, '2… b8c6');

    // Stepping onto the newest position follows the game again.
    b.go(4);
    assert.equal(b.browsing, false);
    assert.equal(b.newMoves, 0);
    b.state.moves = moves('e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5');
    assert.equal(b.shownIndex, 5);
});

test('the keys step, jump to the start and back to now, and leave every other key alone', () => {
    const b = board(moves('e2e4', 'e7e5', 'g1f3'));
    let prevented = 0;
    const event = { preventDefault: () => (prevented += 1) };

    assert.equal(b.historyKey('ArrowLeft', event), true);
    assert.equal(b.shownIndex, 2);
    assert.equal(b.historyKey('Home', event), true);
    assert.equal(b.shownIndex, 0);
    assert.equal(b.shownLabel, '');
    assert.equal(b.historyKey('ArrowLeft', event), true);
    assert.equal(b.shownIndex, 0);
    assert.equal(b.historyKey('ArrowRight', event), true);
    assert.equal(b.shownIndex, 1);
    assert.equal(b.historyKey('End', event), true);
    assert.equal(b.browsing, false);
    assert.equal(b.historyKey('ArrowRight', event), true);
    assert.equal(b.shownIndex, 3);
    assert.equal(b.historyKey('f', event), false);
    assert.equal(b.historyKey(null, event), false);
    assert.equal(prevented, 6);
});

test('a game without moves has nothing to step through', () => {
    const b = board([]);
    b.go(-3);
    assert.equal(b.browsing, false);
    assert.equal(b.shownIndex, 0);
    assert.equal(b.shownFen, START);
    assert.equal(b.newestLabel, '');
});

test('move labels number the full move and mark Black\'s ply', () => {
    assert.equal(plyLabel(1, 'e4'), '1. e4');
    assert.equal(plyLabel(2, 'e5'), '1… e5');
    assert.equal(plyLabel(23, 'Nf3'), '12. Nf3');
    assert.equal(plyLabel(24, 'Nf6'), '12… Nf6');
});
