/**
 * Blockfill cheat hints (plan "Blockfill", P5): each hint fires on what it describes and
 * stays quiet otherwise; the verifier answers them with a verified run.
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { hintsFor } from '../../../resources/js/stacker/hints.js';
import { decodeReplay } from '../../../resources/js/stacker/replay.js';
import { verify } from '../../../resources/js/stacker/verify.mjs';

const read = (file) => readFileSync(new URL(`../../Fixtures/stacker/${file}`, import.meta.url), 'utf8');
const fixture = (name) => ({ ...JSON.parse(read(`${name}.json`)), replay: read(`${name}.replay`).trim() });

test('the reference 40-line run is a program at 6.45 pieces per second: pps, nothing else', () => {
    const f = fixture('forty-lines');
    const hints = hintsFor(f.seed, f.settings, f.inputs);

    assert.deepEqual(hints.flags, ['pps']);
    assert.equal(hints.pps, 6.45);
    assert.equal(hints.maxPressesPerTick, 1);
    assert.ok(hints.finesse.of >= 20 && hints.finesse.perfect < hints.finesse.of, JSON.stringify(hints.finesse));
});

test('a slow run with an uneven rhythm has no hint', () => {
    const f = fixture('top-out');
    assert.deepEqual(hintsFor(f.seed, f.settings, f.inputs).flags, []);
});

test('the bounds can be moved: the same run is clean under a higher pps bound', () => {
    const f = fixture('forty-lines');
    assert.deepEqual(hintsFor(f.seed, f.settings, f.inputs, { pps: 7 }).flags, []);
    assert.deepEqual(hintsFor(f.seed, f.settings, f.inputs, { pps: 'x' }).flags, ['pps']);
});

test('a person\'s slow 40 lines with one dropped frame of seven keys has no hint', () => {
    const f = fixture('seven-minutes');
    const hints = hintsFor(f.seed, f.settings, f.inputs);

    assert.ok(f.expected.ticks >= 7 * 60 * 60 && f.expected.finished, JSON.stringify(f.expected));
    assert.equal(hints.maxPressesPerTick, 7);
    assert.equal(hints.pps, 0.24);
    assert.deepEqual(hints.flags, []);
    assert.equal(hints.sameTickBursts, 1);
});

test('more than 3 presses in one tick mark same-tick only when it repeats, or in a fast run', () => {
    const settings = { das: 10, arr: 2, sdf: 20 };
    const seed = '0123456789abcdef0123456789abcdef';
    // four turns pressed in tick 5, released in tick 6: one dropped frame says nothing
    const burst = [[5, 4, 1], [5, 5, 1], [5, 4, 0], [5, 6, 1], [5, 5, 0], [5, 4, 1], [6, 4, 0], [6, 6, 0]];
    assert.ok(!hintsFor(seed, settings, burst).flags.includes('same-tick'));
    assert.equal(hintsFor(seed, settings, burst).maxPressesPerTick, 4);
    assert.equal(hintsFor(seed, settings, burst).sameTickBursts, 1);

    // the same four presses in ten ticks: a program
    const repeated = [];
    for (let i = 0; i < 10; i++) {
        const tick = 5 + i * 3;
        repeated.push([tick, 4, 1], [tick, 4, 0], [tick, 5, 1], [tick, 5, 0], [tick, 4, 1], [tick, 4, 0], [tick, 5, 1], [tick + 1, 4, 0], [tick + 1, 5, 0]);
    }
    const ten = hintsFor(seed, settings, repeated);
    assert.equal(ten.sameTickBursts, 10, JSON.stringify(ten));
    assert.ok(ten.flags.includes('same-tick'));
    assert.ok(!hintsFor(seed, settings, repeated.slice(0, 9 * 9)).flags.includes('same-tick'), 'nine ticks: no hint');

    // the 40-line program at 6.45 pieces per second with 1,200 holds in its finishing tick: one burst is enough
    const finishing = decodeReplay(read('forty-lines-finishing-tick.replay').trim());
    const fast = hintsFor(finishing.header.seed, finishing.header.settings, finishing.inputs);
    assert.equal(fast.sameTickBursts, 1);
    assert.deepEqual(fast.flags, ['pps', 'same-tick']);
    assert.deepEqual(hintsFor(finishing.header.seed, finishing.header.settings, finishing.inputs, { pps: 7 }).flags, ['same-tick']);
});

test('an even rhythm marks timing', () => {
    const settings = { das: 10, arr: 2, sdf: 20 };
    const seed = '0123456789abcdef0123456789abcdef';

    // a hard drop every 12 ticks, 60 times: a metronome
    const metronome = [];
    for (let i = 0; i < 60; i++) {
        metronome.push([i * 12, 3, 1], [i * 12 + 1, 3, 0]);
    }
    const even = hintsFor(seed, settings, metronome);
    assert.ok(even.flags.includes('timing'), JSON.stringify(even));
    assert.equal(even.timingCv, 0);

    // the same drops with a person's wobble
    const wobble = [];
    let tick = 0;
    for (let i = 0; i < 60; i++) {
        tick += 6 + ((i * 7) % 13) * 2;
        wobble.push([tick, 3, 1], [tick + 1, 3, 0]);
    }
    assert.ok(!hintsFor(seed, settings, wobble).flags.includes('timing'));
});

test('hard drops straight from the spawn are perfect finesse: enough of them mark it', () => {
    const settings = { das: 10, arr: 2, sdf: 20 };
    const seed = '0123456789abcdef0123456789abcdef';
    const drops = [];
    let tick = 0;
    for (let i = 0; i < 22; i++) {
        tick += 9 + (i % 5) * 7;
        drops.push([tick, 3, 1], [tick + 2, 3, 0]);
    }
    // the stack in the middle tops out after 12 pieces: judged against a bound of 10
    const bounds = { finesseMinPieces: 10 };
    const hints = hintsFor(seed, settings, drops, bounds);
    assert.equal(hints.finesse.of, 12, JSON.stringify(hints));
    assert.equal(hints.finesse.perfect, hints.finesse.of);
    assert.ok(hints.flags.includes('finesse'));
    assert.ok(!hintsFor(seed, settings, drops).flags.includes('finesse'), 'under 20 judged pieces: no hint');

    // one extra tap left and back on the first piece spoils it
    const spoiled = [[1, 0, 1], [2, 0, 0], [3, 1, 1], [4, 1, 0], ...drops];
    const fault = hintsFor(seed, settings, spoiled, bounds);
    assert.equal(fault.finesse.perfect, fault.finesse.of - 1);
    assert.ok(!fault.flags.includes('finesse'));
});

test('the verifier answers the hints with a verified run', async () => {
    const f = fixture('forty-lines');
    const verdict = await verify({
        replay: f.replay,
        seed: f.seed,
        engine: f.engine,
        claimed: { ticks: f.expected.ticks, hash: f.expected.stateHash },
        limits: { ticks: 36000, inputs: 20000, bytes: 65536, inputsPerTick: 1, inputSlack: 64 },
    });
    assert.equal(verdict.ok, true);
    assert.deepEqual(verdict.hints.flags, ['pps']);

    const relaxed = await verify({
        replay: f.replay,
        seed: f.seed,
        engine: f.engine,
        claimed: { ticks: f.expected.ticks, hash: f.expected.stateHash },
        limits: { ticks: 36000, inputs: 20000, bytes: 65536, inputsPerTick: 1, inputSlack: 64 },
        hints: { pps: 7 },
    });
    assert.deepEqual(relaxed.hints.flags, []);
});
