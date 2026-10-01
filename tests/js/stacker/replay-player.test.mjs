/**
 * Blockfill replay viewer (plan "Blockfill", P5): seeking to a tick shows exactly the
 * state that playing the run from tick 0 reaches at that tick, forwards and backwards,
 * and the stored run ends on its verified hash.
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createGame, stateHash, step } from '../../../resources/js/stacker/engine.js';
import { createReplayPlayer } from '../../../resources/js/stacker/replay-player.js';

const read = (file) => readFileSync(new URL(`../../Fixtures/stacker/${file}`, import.meta.url), 'utf8');
const fixture = (name) => ({ ...JSON.parse(read(`${name}.json`)), replay: read(`${name}.replay`).trim() });

/** The hash at every tick, playing straight from tick 0 with the fixture's own log. */
function hashesByPlaying(f) {
    const game = createGame({ seed: f.seed, settings: f.settings });
    const hashes = [stateHash(game)];
    let next = 0;
    while (!game.finished && !game.toppedOut) {
        const inputs = [];
        while (next < f.inputs.length && f.inputs[next][0] === game.tick) {
            inputs.push([f.inputs[next][1], f.inputs[next][2]]);
            next++;
        }
        step(game, inputs);
        hashes.push(stateHash(game));
    }

    return hashes;
}

for (const name of ['forty-lines', 'top-out']) {
    test(`${name}: a seek renders the same state as playing to that tick, in any order`, () => {
        const f = fixture(name);
        const truth = hashesByPlaying(f);
        const player = createReplayPlayer(f.replay, { checkpointEvery: 64 });

        assert.equal(player.total, f.expected.ticks);
        assert.equal(player.result.stateHash, f.expected.stateHash);
        assert.equal(truth.length - 1, f.expected.ticks);

        // every tick backwards (each seek goes back to a checkpoint), then a scatter in both directions
        for (let tick = player.total; tick >= 0; tick--) {
            assert.equal(stateHash(player.seek(tick)), truth[tick], `backwards to tick ${tick}`);
        }
        let x = 7;
        for (let i = 0; i < 400; i++) {
            x = (x * 1103515245 + 12345) % 2147483648;
            const tick = x % (player.total + 1);
            assert.equal(stateHash(player.seek(tick)), truth[tick], `scatter to tick ${tick}`);
        }
    });
}

test('forty-lines: playing on tick by tick after a seek stays on the straight line', () => {
    const f = fixture('forty-lines');
    const truth = hashesByPlaying(f);
    const player = createReplayPlayer(f.replay);
    player.seek(500);
    for (let tick = 501; tick <= player.total; tick++) {
        player.advance(1);
        assert.equal(stateHash(player.game), truth[tick], `tick ${tick}`);
    }
    // past the end nothing moves
    assert.equal(player.advance(10), 0);
    assert.equal(player.game.tick, player.total);
    assert.equal(stateHash(player.seek(player.total + 99)), f.expected.stateHash);
    assert.equal(stateHash(player.seek(-5)), truth[0]);
});

test('forty-lines: the chain has 40 blocks, each at the tick its row was cleared', () => {
    const f = fixture('forty-lines');
    const player = createReplayPlayer(f.replay);

    assert.equal(player.blockTicks.length, 40);
    assert.equal(player.blockTicks.at(-1), player.total);
    assert.deepEqual([...player.blockTicks].sort((a, b) => a - b), player.blockTicks);
    for (const [index, tick] of player.blockTicks.entries()) {
        const before = player.seek(tick - 1).lines;
        const at = player.seek(tick).lines;
        assert.ok(before <= index && at >= index + 1, `block ${index + 1} at tick ${tick}: ${before} -> ${at}`);
    }
    assert.equal(player.clears.reduce((sum, clear) => sum + clear.count, 0), 40);
});

test('the player does not share its game with the checkpoints: changing what seek returns changes no later seek', () => {
    const f = fixture('forty-lines');
    const truth = hashesByPlaying(f);
    const player = createReplayPlayer(f.replay, { checkpointEvery: 100 });
    const game = player.seek(100);
    game.board.fill(3);
    assert.equal(stateHash(player.seek(50)), truth[50]);
    assert.equal(stateHash(player.seek(100)), truth[100]);
});
