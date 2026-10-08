/**
 * The pacing of Hyperbitcoinization's end-of-match statistics (plan "Hyperbitcoinization", P3b, reworked in P4,
 * resources/js/hyper/statsPlan.js): no page turns without input, Next first finishes a running animation, the
 * animations are calm, and Skip all ends it. Run by tests/Feature/Hyper/HyperStatsTest.php; runnable alone with
 * `node --test tests/js/hyperStats.test.mjs`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { COUNT_MS, LINE_MS, SCENE_MS, Sequence, readableMs } from '../../resources/js/hyper/statsPlan.js';

test('no page turns without input, however long the page stays and once its animation is over', () => {
    const seq = new Sequence(5);
    seq.begin(0);
    seq.finished();
    // Nothing in the plan reads a clock: the page stays put until next() is called.
    assert.equal(seq.index, 0);
    assert.equal(typeof seq.at, 'undefined', 'no time-driven page lookup is left');
    assert.equal(typeof seq.play, 'undefined', 'no autoplay is left');
    assert.equal(seq.index, 0);
    assert.equal(seq.ready, true);
});

test('Next during an animation finishes it and stays; the next Next turns the page', () => {
    const seq = new Sequence(3);
    seq.begin(0);
    assert.deepEqual(seq.next(), { action: 'finish', index: 0 });
    assert.equal(seq.index, 0);
    assert.deepEqual(seq.next(), { action: 'show', index: 1 });
    assert.equal(seq.ready, false, 'the new page animates first');
    seq.finished();
    assert.deepEqual(seq.next(), { action: 'show', index: 2 });
    seq.finished();
    assert.deepEqual(seq.next(), { action: 'end', index: 2 });
    assert.equal(seq.index, 2);
});

test('Skip all ends it: Next does nothing afterwards', () => {
    const seq = new Sequence(4);
    seq.begin(1);
    seq.finished();
    seq.skip();
    assert.deepEqual(seq.next(), { action: 'none', index: 1 });
    assert.equal(seq.index, 1);
});

test('pages are browsable by hand both ways; out of range stays put', () => {
    const seq = new Sequence(4);
    assert.equal(seq.go(3), 3);
    assert.equal(seq.go(2), 2);
    assert.equal(seq.go(4), 2);
    assert.equal(seq.go(-1), 2);
    assert.equal(seq.go(0), 0);
});

test('the animations are calm and a caption stays readable', () => {
    assert.ok(LINE_MS >= 2500, 'a line draws for 2.5 s at least');
    assert.ok(COUNT_MS >= 1500, 'a number counts up for 1.5 s at least');
    assert.ok(SCENE_MS >= 1500);
    assert.equal(readableMs('GG'), 3000);
    assert.equal(readableMs('x'.repeat(100)), 6000);
    assert.equal(readableMs(''), 3000);
});
