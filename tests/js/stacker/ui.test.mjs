/**
 * Blockfill page logic without a browser: the session the page plays (keys to
 * logged inputs), the fixed 60 Hz ticker, the key bindings and the fee colours.
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { DEFAULT_KEYS, keyLabel, keyMap, normalizeControls, normalizeKeys } from '../../../resources/js/stacker/keys.js';
import { FEE_COLORS, FEE_SCALE, WELL } from '../../../resources/js/stacker/palette.js';
import { createSession } from '../../../resources/js/stacker/session.js';
import { createTicker, formatTicks } from '../../../resources/js/stacker/ticker.js';

function fixture(name) {
    return JSON.parse(readFileSync(new URL(`../../Fixtures/stacker/${name}.json`, import.meta.url), 'utf8'));
}

for (const name of ['forty-lines', 'top-out', 'hard-drops']) {
    test(`the page's session plays the ${name} log to the reference result and logs what it played`, () => {
        const f = fixture(name);
        const session = createSession({ seed: f.seed, settings: f.settings });

        assert.deepEqual(session.play(f.inputs), f.expected);
        // inputs after the end of the game are not played, so not logged
        assert.deepEqual(session.log, f.inputs.filter(([tick]) => tick < f.expected.ticks));
    });
}

test('a key pressed twice or released while up is logged once', () => {
    const f = fixture('forty-lines');
    const session = createSession({ seed: f.seed, settings: f.settings });

    session.press('left', true);
    session.press('left', true);
    session.press('right', false);
    session.press('nonsense', true);
    session.tick();
    session.press('left', false);
    session.tick();

    assert.deepEqual(session.log, [[0, 0, 1], [1, 0, 0]]);
});

test('the ticker runs 60 ticks a second at any frame rate and drops a long stall', () => {
    for (const fps of [30, 60, 144]) {
        const ticker = createTicker();
        ticker.reset(0);
        let ticks = 0;
        for (let frame = 1; frame <= fps * 2; frame++) {
            ticks += ticker.due((frame * 1000) / fps);
        }
        assert.ok(Math.abs(ticks - 120) <= 1, `${fps} fps: ${ticks} ticks in 2 s`);
    }

    const stalled = createTicker({ maxCatchUp: 30 });
    stalled.reset(0);
    assert.equal(stalled.due(5000), 30);
    assert.equal(stalled.due(5000 + 1000 / 60 + 0.01), 1);
});

test('times read as minutes, seconds and hundredths or thousandths', () => {
    assert.equal(formatTicks(0), '0:00.00');
    // rounded down like Blockfill::milliseconds(): 958 ticks are 15966.67 ms
    assert.equal(formatTicks(958, 3), '0:15.966');
    assert.equal(formatTicks(2, 3), '0:00.033');
    assert.equal(formatTicks(958), '0:15.96');
    assert.equal(formatTicks(36000), '10:00.00');
});

test('key bindings fall back to the defaults when an action is unbound or a key is used twice', () => {
    assert.deepEqual(normalizeKeys(DEFAULT_KEYS), DEFAULT_KEYS);
    assert.deepEqual(normalizeKeys({ ...DEFAULT_KEYS, hard: ['ArrowLeft'] }), DEFAULT_KEYS);
    assert.deepEqual(normalizeKeys({ ...DEFAULT_KEYS, hold: [] }), DEFAULT_KEYS);
    assert.deepEqual(normalizeKeys({ ...DEFAULT_KEYS, hold: ['KeyQ', 'KeyW', 'KeyE'] }), DEFAULT_KEYS);
    const custom = { ...DEFAULT_KEYS, hard: ['KeyJ'] };
    assert.deepEqual(normalizeKeys(custom), custom);
    assert.equal(keyMap(custom).get('KeyJ'), 'hard');

    assert.deepEqual(normalizeControls({ das: 99, arr: 1, sdf: 20 }).das, 10);
    assert.deepEqual(normalizeControls({ das: 7, arr: 0, sdf: 41, keys: custom }), { das: 7, arr: 0, sdf: 41, keys: custom });
    assert.deepEqual(['ArrowLeft', 'KeyZ', 'Digit4', 'ShiftLeft', 'Space'].map(keyLabel), ['←', 'Z', '4', 'Shift Left', 'Space']);
});

/** WCAG relative luminance of a #RRGGBB colour. */
function luminance(hex) {
    const [r, g, b] = [1, 3, 5].map((i) => {
        const c = parseInt(hex.slice(i, i + 2), 16) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

test('every fee colour keeps at least 4.5:1 against the well, and the scale holds each colour once', () => {
    for (const color of FEE_COLORS) {
        const ratio = (luminance(color) + 0.05) / (luminance(WELL) + 0.05);
        assert.ok(ratio >= 4.5, `${color}: ${ratio.toFixed(2)}:1`);
    }
    assert.deepEqual([...FEE_SCALE].sort(), [...FEE_COLORS].sort());
});
