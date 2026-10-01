/**
 * Blockfill reference runs: each fixture in tests/Fixtures/stacker replays to the
 * exact tick count, line count and state hash it was recorded with.
 *
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with
 * `node --test tests/js/stacker`.
 *
 * About the fixtures
 * - forty-lines.json: a full 40-line run (taps, DAS slides, all three turns, hold,
 *   hard drops); top-out.json: a run without hard drops (soft drop, gravity, lock
 *   delay, DAS to the walls) that tops out at an exact tick; hard-drops.json: hard
 *   drops only, until the stack tops out.
 * - The input logs were produced once by a scripted player that is not part of the
 *   repo; the logs themselves are the reference, not the player.
 * - Regenerate ONLY on purpose. The bf1 fixtures never change: engine versions are
 *   frozen, so a red test here means the engine changed, not the fixture. A rule
 *   change ships as a new ENGINE_VERSION with its own fixtures next to these.
 * - To pin a NEW fixture: write {name, engine, seed, settings, inputs} and take
 *   `expected` from the engine itself, e.g.
 *   node -e "import('./resources/js/stacker/engine.js').then(e => { const f = JSON.parse(require('fs').readFileSync('tests/Fixtures/stacker/<name>.json')); console.log(e.run(f.seed, f.settings, f.inputs)) })"
 *   then review the numbers (does a 40-line run say finished: true?) before committing.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { ACTION, ENGINE_VERSION, run } from '../../../resources/js/stacker/engine.js';

const NAMES = ['forty-lines', 'top-out', 'hard-drops'];

function fixture(name) {
    return JSON.parse(readFileSync(new URL(`../../Fixtures/stacker/${name}.json`, import.meta.url), 'utf8'));
}

/** The log with input `index` moved `by` ticks, still in tick order. */
function shifted(inputs, index, by) {
    const moved = inputs.map((input) => [...input]);
    moved[index][0] += by;
    const [entry] = moved.splice(index, 1);
    let at = moved.findIndex(([tick]) => tick > entry[0]);
    if (at < 0) {
        at = moved.length;
    }
    moved.splice(at, 0, entry);

    return moved;
}

for (const name of NAMES) {
    test(`the ${name} reference run replays to its recorded ticks, lines and hash`, () => {
        const f = fixture(name);
        assert.equal(f.engine, ENGINE_VERSION);
        assert.deepEqual(run(f.seed, f.settings, f.inputs), f.expected);
    });
}

test('the reference runs end the way their names say', () => {
    const forty = fixture('forty-lines').expected;
    assert.equal(forty.finished, true);
    assert.equal(forty.lines, 40);
    assert.equal(forty.toppedOut, false);

    for (const name of ['top-out', 'hard-drops']) {
        const { expected } = fixture(name);
        assert.equal(expected.toppedOut, true, name);
        assert.equal(expected.finished, false, name);
    }

    const hardOnly = fixture('hard-drops').inputs.every(([, action]) => action === ACTION.HARD);
    const noHard = fixture('top-out').inputs.every(([, action]) => action !== ACTION.HARD);
    assert.ok(hardOnly && noHard);
});

test('one input a tick later gives a different run', () => {
    const f = fixture('forty-lines');
    const index = f.inputs.findIndex(([, action, down]) => action === ACTION.HARD && down === 1);
    const late = run(f.seed, f.settings, shifted(f.inputs, index, 1));

    assert.notEqual(late.stateHash, f.expected.stateHash);
});
