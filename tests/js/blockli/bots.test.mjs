/**
 * resources/js/blockli/bots.js: the practice bots and the bridge between the server's positions and moves and the
 * engine's (DerCaddy, 2026-10-23). Run by tests/Unit/Board/BlockliPracticeScriptsTest.php; runnable alone with
 * `node --test tests/js/blockli`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Blockli, after, bestSteps, blockEffect, botMove, distances, engineMove, hodlerMove, legalServerMoves, minerMove, serverMove, stateOf } from '../../../resources/js/blockli/bots.js';

const START = 'e1 e9 10 10 w - 0';

/** A seeded random, so a failing case can be replayed. */
function seeded(seed) {
    let x = seed;

    return () => ((x = (x * 1103515245 + 12345) % 2147483648) / 2147483648);
}

test('a server position reads into the engine: pawns, blocks left, the side to move and the blocks on the board', () => {
    const s = stateOf('e6 i9 10 7 w c6hb,e6hb,g6hb 0');

    assert.deepEqual(s.pawns, [Blockli.parseMove('e6'), Blockli.parseMove('i9')]);
    assert.deepEqual(s.walls, [10, 7]);
    assert.equal(s.turn, 0);
    // c6h lies above c6 and d6: row 5 (rank 6), file 2.
    assert.equal(s.h[5 * 8 + 2], 1);
    assert.equal(s.h.reduce((a, b) => a + b, 0), 3);
    assert.deepEqual(distances(s), [6, 8]);
    assert.equal(stateOf('b4 h5 9 10 b f3hw 0').turn, 1);
    assert.equal(stateOf('e9 e5 10 10 b - 0').winner, 0);
});

test('text that is no position reads as none', () => {
    for (const text of ['', 'e1 e9 10 10 w -', 'z1 e9 10 10 w - 0', 'e1 e9 10 10 w x9h 0', null]) assert.equal(stateOf(text), null, String(text));
});

test('moves cross between the server\'s notation and the engine\'s both ways', () => {
    const s = stateOf(START);

    assert.equal(engineMove('e1-e2'), Blockli.parseMove('e2'));
    assert.equal(serverMove(s, engineMove('e1-e2')), 'e1-e2');
    assert.equal(serverMove(s, engineMove('e3h')), 'e3h');
    assert.equal(serverMove(s, engineMove('a8v')), 'a8v');
    assert.equal(engineMove('x9'), -1);
    assert.equal(engineMove('i9h'), -1);
});

test('the start allows three pawn moves and all 128 blocks, written as the server writes them', () => {
    const legal = legalServerMoves(stateOf(START));

    assert.equal(legal.length, 131);
    assert.deepEqual(legal.filter((move) => move.includes('-')), ['e1-d1', 'e1-e2', 'e1-f1']);
    assert.ok(legal.includes('h8v') && legal.includes('a1h'));
    assert.deepEqual(legalServerMoves(stateOf('e9 e5 10 10 b - 0')), [], 'a decided game has no moves');
});

test('a block\'s effect counts the other side\'s detour against the setter\'s own', () => {
    // Black on e2 needs one step; e1h under him makes it two, White's way stays as it was.
    const s = stateOf('e5 e2 10 10 w - 0');

    assert.deepEqual(blockEffect(s, engineMove('e1h')), { move: engineMove('e1h'), theirs: 1, mine: 0, gain: 1 });
    // Right above White's own pawn: it costs White, not Black.
    assert.equal(blockEffect(stateOf(START), engineMove('e1h')).mine, 1);
});

test('the Hodler never sets a block, and no step of his leaves him further from his goal', () => {
    const random = seeded(7);
    for (let game = 0; game < 20; game++) {
        let s = stateOf(START);
        for (let ply = 0; ply < 60 && s.winner < 0; ply++) {
            // His opponent plays at random, blocks included, so the Hodler meets walls and the other pawn.
            const legal = Blockli.legalMoves(s);
            const move = s.turn === 0 ? hodlerMove(s, random) : legal[Math.floor(random() * legal.length)];
            if (s.turn === 0) {
                assert.ok(move < 100, 'a pawn move');
                assert.ok(Blockli.isLegal(s, move), 'a legal move');
                const next = after(s, move);
                assert.ok(next.winner === 0 || Blockli.pathLen(next, 0) <= Blockli.pathLen(s, 0), 'never further from the goal');
            }
            s = after(s, move);
        }
    }
});

test('the Hodler steps along a shortest way, a jump over the other pawn included', () => {
    // Face to face on the e file: the jump to e6 is the shortest way.
    assert.deepEqual(bestSteps(stateOf('e4 e5 10 10 w - 0')), [Blockli.parseMove('e6')]);
    assert.equal(hodlerMove(stateOf('e4 e5 10 10 w - 0')), Blockli.parseMove('e6'));
});

test('the Miner blocks when he is behind and a block costs the other side a step, and runs when he is ahead', () => {
    // Black (the Miner) needs four, White one: e8h above White costs White a step.
    const behind = stateOf('e8 e5 10 10 b - 0');
    const block = minerMove(behind, seeded(1));
    assert.ok(block >= 100, 'a block');
    assert.ok(blockEffect(behind, block).theirs >= 1);
    // Black needs seven, White eight, and no block costs White more than a step: he runs.
    const ahead = stateOf('e1 e8 10 10 b - 0');
    assert.ok(minerMove(ahead, seeded(1)) < 100);
    // A block worth three steps he sets even so (White here, Black needs four, White five).
    const strong = minerMove(stateOf('b4 h5 9 10 w f3hw 0'), seeded(1));
    assert.equal(Blockli.moveName(strong), 'h3h');
});

test('the Miner spends his blocks and then only runs', () => {
    const random = seeded(3);
    let s = stateOf(START);
    for (let ply = 0; ply < 160 && s.winner < 0; ply++) s = after(s, s.turn === 1 ? minerMove(s, random) : hodlerMove(s, random));
    assert.ok(s.winner >= 0, 'the game ends');
    assert.ok(s.walls[1] < 10, 'the Miner set blocks');
});

test('the Whale answers with a legal move within his search time', () => {
    const s = stateOf('e2 e8 10 10 w - 0');
    const started = performance.now();
    const move = botMove('whale', s, { time: 150 });

    assert.ok(Blockli.isLegal(s, move));
    assert.ok(performance.now() - started < 3000);
    assert.ok(Blockli.isLegal(s, botMove('hodler', s)) && Blockli.isLegal(s, botMove('miner', s)));
});
