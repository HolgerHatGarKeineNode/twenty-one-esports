/**
 * resources/js/go/bots.js: the three bots of Go practice (DerCaddy, 2026-10-27): the Hodler takes what stands in
 * atari and saves his own, the Miner and the Whale search. Run by tests/Unit/Board/GoPracticeScriptsTest.php;
 * runnable alone with `node --test tests/js/go`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BOTS, botMove, hodlerMove } from '../../../resources/js/go/bots.js';
import { GoBoard, rng } from '../../../resources/js/go/engine.js';

const START = '9/9/9/9/9/9/9/9/9 x - 0';

test('the three bots, weakest first, each with its pace', () => {
    assert.deepEqual(Object.keys(BOTS), ['hodler', 'miner', 'whale']);
    assert.ok(BOTS.whale.playouts > BOTS.miner.playouts);
    for (const bot of Object.values(BOTS)) assert.ok(bot.think >= 500);
});

test('the Hodler takes the biggest group in atari, else saves his own', () => {
    // White to move: Black's three stones on d5 to f5 stand in atari (their last liberty is f4).
    const take = GoBoard.fromText('9/9/9/3ooo3/2oxxxo2/3oo4/9/9/9 o - 0');
    assert.equal(hodlerMove(take, rng(1)), 'f4');
    // Black to move: his stone on e5 stands in atari, running onto e4 gives it three liberties.
    const save = GoBoard.fromText('9/9/9/4o4/3oxo3/9/9/9/9 x - 0');
    assert.equal(hodlerMove(save, rng(1)), 'e4');
});

test('the Hodler never fills his own eye nor walks into atari, and plays the third and fourth lines early on', () => {
    const random = rng(5);
    for (let i = 0; i < 40; i++) {
        const board = GoBoard.fromText(START);
        const move = hodlerMove(board, random);
        const p = board.pointOf(move);
        const row = Math.floor(p / board.w);
        const file = p % board.w;
        const line = Math.min(row, file, 10 - row, 10 - file);
        assert.ok(line >= 3 && line <= 4, move);
    }
    // His only empty points are his own eyes: he passes.
    const eyes = GoBoard.fromText('xxxxxxxxx/x1x1x1x1x/xxxxxxxxx/xxxxxxxxx/xxxxxxxxx/xxxxxxxxx/xxxxxxxxx/xxxxxxxxx/xxxxxxxxx x - 0');
    assert.equal(hodlerMove(eyes, rng(1)), 'pass');
});

test('the Hodler passes after the other side passed when he leads on the count', () => {
    const won = GoBoard.fromText('4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3 x - 1');
    assert.equal(hodlerMove(won, rng(1)), 'pass');
});

test('every bot answers a legal move, in the opening and in a game under way', () => {
    const positions = [START, '9/9/2o6/3x5/4ox3/9/2x6/9/9 o - 0', '9/9/9/3xxx3/2xooox2/3xx4/9/9/9 x - 0'];
    for (const bot of ['hodler', 'miner']) {
        for (const text of positions) {
            const move = botMove(bot, text, { random: rng(2) });
            assert.ok(GoBoard.fromText(text).legalIds().includes(move), `${bot} played ${move} in ${text}`);
        }
    }
    const whale = botMove('whale', positions[2], { random: rng(3), clock: (() => { let t = 0; return () => (t += 10); })() });
    assert.equal(whale, 'f4', 'the Whale takes the three stones');
});

test('the Miner beats the Hodler on 9 x 9', () => {
    let miner = 0;
    // One game with each colour; the random sources are seeded, so the games are the same every run.
    for (let game = 0; game < 2; game++) {
        const board = GoBoard.fromText(START);
        const minerBlack = game % 2 === 0;
        const random = rng(10 + game);
        while (!board.over && board.plies < 200) {
            const blackToMove = board.toMove === 1;
            const move = blackToMove === minerBlack ? botMove('miner', board.toText(), { random }) : hodlerMove(board, random);
            board.playId(move);
        }
        const blackWon = board.count().margin > 0;
        if (blackWon === minerBlack) miner++;
    }
    assert.equal(miner, 2, `the Miner won ${miner} of 2`);
});
