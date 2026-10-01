/**
 * Blockfill replay codec: header and input log survive encode/decode unchanged, and
 * anything malformed is refused instead of half-read.
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { ENGINE_VERSION, run } from '../../../resources/js/stacker/engine.js';
import { decodeReplay, encodeReplay, REPLAY_VERSION } from '../../../resources/js/stacker/replay.js';

function fixture(name) {
    return JSON.parse(readFileSync(new URL(`../../Fixtures/stacker/${name}.json`, import.meta.url), 'utf8'));
}

const header = (f) => ({ v: REPLAY_VERSION, engine: f.engine, seed: f.seed, settings: f.settings });

for (const name of ['forty-lines', 'top-out', 'hard-drops']) {
    test(`the ${name} run round-trips through the codec and replays to the same result`, () => {
        const f = fixture(name);
        const text = encodeReplay(header(f), f.inputs);
        const decoded = decodeReplay(text);

        assert.match(text, /^[A-Za-z0-9_-]+$/);
        assert.deepEqual(decoded.header, header(f));
        assert.deepEqual(decoded.inputs, f.inputs);
        assert.deepEqual(run(decoded.header.seed, decoded.header.settings, decoded.inputs), f.expected);
        assert.ok(text.length < JSON.stringify(f.inputs).length / 3, `${text.length} chars`);
    });
}

test('edge values survive: every action, both directions, same-tick inputs and long gaps', () => {
    const inputs = [];
    let tick = 0;
    for (let action = 0; action < 8; action++) {
        inputs.push([tick, action, 1], [tick, action, 0]);
        tick += action * 997;
    }
    inputs.push([35999, 3, 1]);
    const h = { v: 1, engine: ENGINE_VERSION, seed: 'ffffffffffffffffffffffffffffffff', settings: { das: 1, arr: 0, sdf: 41 } };

    assert.deepEqual(decodeReplay(encodeReplay(h, inputs)), { header: h, inputs });
    assert.deepEqual(decodeReplay(encodeReplay(h, [])).inputs, []);
});

test('malformed replays are refused', () => {
    const f = fixture('hard-drops');
    const good = encodeReplay(header(f), f.inputs);
    const bad = [
        '',
        good.slice(0, -3),
        `${good}A`,
        `${good}AA`,
        good.replace(/^./, '!'),
        `${good.slice(0, 10)}+${good.slice(11)}`,
        encodeReplay(header(f), f.inputs).replace(/^A/, 'C'),
        42,
    ];
    for (const text of bad) {
        assert.throws(() => decodeReplay(text), Error, String(text).slice(0, 20));
    }
});

const replayFile = (name) => readFileSync(new URL(`../../Fixtures/stacker/${name}.replay`, import.meta.url), 'utf8').trim();

/** The inputs a run actually used: none after its last tick. */
const used = (f) => f.inputs.filter(([tick]) => tick < f.expected.ticks);

test('the committed .replay files are the reference runs as the verifier stores them (the PHP tests submit them)', () => {
    for (const name of ['forty-lines', 'top-out']) {
        const f = fixture(name);
        assert.equal(replayFile(name), encodeReplay(header(f), used(f)), name);
    }
    assert.ok(replayFile('forty-lines').length < 1200);
});

test('the padded and overlong .replay files are the 40-line run plus padding', () => {
    const f = fixture('forty-lines');
    const padded = decodeReplay(replayFile('forty-lines-padded'));
    assert.deepEqual(padded.inputs.slice(0, used(f).length), used(f));
    assert.ok(padded.inputs.slice(used(f).length).every(([tick]) => tick >= f.expected.ticks));
    assert.ok(padded.inputs.length > used(f).length + 1000);

    assert.throws(() => decodeReplay(replayFile('forty-lines-overlong')), /varint/);
});

test('a varint written longer than it needs to be is refused', () => {
    const canonical = Buffer.from(encodeReplay({ v: 1, engine: 'bf1', seed: '00'.repeat(16), settings: { das: 10, arr: 2, sdf: 20 } }, []), 'base64url');
    assert.equal(canonical[0], 1);
    const overlong = Buffer.concat([Buffer.from([0x81, 0x00]), canonical.subarray(1)]).toString('base64url');

    assert.throws(() => decodeReplay(overlong), /varint/);
});

test('an encoder refuses what a run would refuse', () => {
    const f = fixture('hard-drops');
    assert.throws(() => encodeReplay({ ...header(f), v: 2 }, f.inputs), RangeError);
    assert.throws(() => encodeReplay({ ...header(f), engine: 'BF 1' }, f.inputs), TypeError);
    assert.throws(() => encodeReplay({ ...header(f), seed: 'abc' }, f.inputs), TypeError);
    assert.throws(() => encodeReplay({ ...header(f), settings: { das: 0, arr: 0, sdf: 41 } }, f.inputs), RangeError);
    assert.throws(() => encodeReplay(header(f), [[2, 0, 1], [1, 0, 0]]), RangeError);
    assert.throws(() => encodeReplay(header(f), [[1, 9, 1]]), TypeError);
});
