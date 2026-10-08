/**
 * The timing of Hyperbitcoinization's end-of-match statistics (plan "Hyperbitcoinization", P3b,
 * resources/js/hyper/statsPlan.js): 30 to 60 seconds whatever the number of moments, the pages in order while it
 * plays, and skipping or turning a page by hand ends the autoplay. Run by tests/Feature/Hyper/HyperStatsTest.php;
 * runnable alone with `node --test tests/js/hyperStats.test.mjs`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MAX_MS, MIN_MS, Sequence, sequencePlan } from '../../resources/js/hyper/statsPlan.js';

test('the sequence runs 30 to 60 seconds for 0 to 11 moments, charts and tiles first', () => {
    for (let moments = 0; moments <= 11; moments++) {
        const plan = sequencePlan(moments);
        const total = plan.reduce((a, b) => a + b, 0);
        assert.equal(plan.length, moments + 2);
        assert.ok(total >= MIN_MS && total <= MAX_MS, `${moments} moments: ${total} ms`);
        assert.ok(plan.every((ms) => ms >= 3200), `${moments} moments: every page stays at least 3.2 s`);
    }
});

test('while it plays the pages come in order, and after the last one it stops there', () => {
    const seq = new Sequence(sequencePlan(3));
    seq.play();
    const [charts, tiles, first] = seq.plan;
    assert.equal(seq.at(0), 0);
    assert.equal(seq.at(charts - 1), 0);
    assert.equal(seq.at(charts), 1);
    assert.equal(seq.at(charts + tiles + first / 2), 2);
    assert.equal(seq.auto, true);
    assert.equal(seq.at(seq.total + 1), 4);
    assert.equal(seq.auto, false);
});

test('skipping ends the autoplay at once; time passing turns no page afterwards', () => {
    const seq = new Sequence(sequencePlan(5));
    seq.play();
    assert.equal(seq.at(seq.plan[0] + 10), 1);
    seq.skip();
    assert.equal(seq.auto, false);
    assert.equal(seq.at(seq.total - 1), 1);
});

test('a page turned by hand ends the autoplay and is browsable both ways; out of range stays put', () => {
    const seq = new Sequence(sequencePlan(2));
    seq.play();
    assert.equal(seq.go(3), 3);
    assert.equal(seq.auto, false);
    assert.equal(seq.at(0), 3);
    assert.equal(seq.go(2), 2);
    assert.equal(seq.go(4), 2);
    assert.equal(seq.go(-1), 2);
    assert.equal(seq.go(0), 0);
});
