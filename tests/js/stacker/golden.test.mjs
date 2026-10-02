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
 *   drops only, until the stack tops out; seven-minutes.json: a person's slow 40 lines
 *   (7:07.516) with one dropped frame that hands seven key presses to one tick.
 * - The input logs were produced once by the scripted players in
 *   tests/js/stacker/tools/build-fixtures.mjs (not part of the test run); the logs
 *   themselves are the reference, not the players.
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
import { ACTION, ENGINES, ENGINE_VERSION, run } from '../../../resources/js/stacker/engine.js';

const NAMES = ['forty-lines', 'top-out', 'hard-drops', 'seven-minutes'];

function fixture(name) {
    return JSON.parse(readFileSync(new URL(`../../Fixtures/stacker/${name}.json`, import.meta.url), 'utf8'));
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
    // input 12 starts a DAS slide to the right at tick 11; its release follows at
    // tick 20, so moving it to tick 12 keeps the log in order and changes only its timing
    assert.deepEqual(f.inputs[12], [11, ACTION.RIGHT, 1]);
    assert.equal(f.inputs[13][0], 20);
    const late = f.inputs.map((input, index) => (index === 12 ? [12, ACTION.RIGHT, 1] : input));

    const result = run(f.seed, f.settings, late);
    assert.notEqual(result.stateHash, f.expected.stateHash);
    assert.deepEqual([result.ticks, result.lines, result.toppedOut], [347, 1, true]);
});

/*
 * The difficulties of a league week (engine.js ENGINES): bf1 with only its gravity changed.
 * Each id is frozen like bf1, so the results below are pinned from the engine itself
 * (2026-10-02) and never regenerated: a red line here means an engine id changed.
 */
const DIFFICULTIES = {
    'seven-minutes': {
        bf1hard: { ticks: 4641, lines: 3, pieces: 25, finished: false, toppedOut: true, stateHash: '134e8f64' },
        bf1expert: { ticks: 1587, lines: 0, pieces: 18, finished: false, toppedOut: true, stateHash: 'c8e2f6d0' },
        bf1master: { ticks: 503, lines: 0, pieces: 15, finished: false, toppedOut: true, stateHash: '85dc9455' },
    },
    'top-out': {
        bf1hard: { ticks: 1546, lines: 0, pieces: 27, finished: false, toppedOut: true, stateHash: 'e7d705d9' },
        bf1expert: { ticks: 1577, lines: 0, pieces: 27, finished: false, toppedOut: true, stateHash: 'a7be3fa1' },
        bf1master: { ticks: 1402, lines: 1, pieces: 32, finished: false, toppedOut: true, stateHash: '7c02231f' },
    },
};

test('every difficulty replays the reference runs to its own pinned result, and bf1 is the default', () => {
    assert.deepEqual(Object.keys(ENGINES), ['bf1', 'bf1hard', 'bf1expert', 'bf1master']);

    for (const [name, byEngine] of Object.entries(DIFFICULTIES)) {
        const f = fixture(name);
        assert.deepEqual(run(f.seed, f.settings, f.inputs, { engine: 'bf1' }), f.expected, `${name} on bf1`);
        for (const [engine, expected] of Object.entries(byEngine)) {
            assert.deepEqual(run(f.seed, f.settings, f.inputs, { engine }), expected, `${name} on ${engine}`);
        }
    }

    // the 40-line program hard-drops every piece within a few ticks: up to 10 rows a second it plays the same, 20G tops it out
    const hard = fixture('forty-lines-hard');
    assert.equal(hard.engine, 'bf1hard');
    assert.deepEqual(run(hard.seed, hard.settings, hard.inputs, { engine: hard.engine }), hard.expected);
    assert.equal(run(hard.seed, hard.settings, hard.inputs, { engine: 'bf1master' }).toppedOut, true);
    assert.throws(() => run(hard.seed, hard.settings, hard.inputs, { engine: 'bf9' }), RangeError);
});
