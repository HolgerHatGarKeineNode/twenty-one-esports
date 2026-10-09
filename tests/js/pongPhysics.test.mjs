/**
 * Proof of Pong's physics in the browser (resources/js/pong/*.js) against the server's golden runs
 * (tests/Fixtures/pong/golden.json, written by tests/Support/PongGolden.php): the same draws, the same 50 rallies
 * (every serve, hit and goal in the same tick, the same last ball and paddles), the same events and whole games.
 * Run by tests/Feature/Pong/PongPhysicsTest.php; runnable alone with `node --test tests/js/pongPhysics.test.mjs`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { seeded } from '../../resources/js/pong/rng.js';
import { PADDLE_HALF, POW, POW_GROW, POW_MAX_HALF, createRally, rallyHalf, rallyToArray, stepRally } from '../../resources/js/pong/physics.js';
import { botSpeed, createBot } from '../../resources/js/pong/bot.js';
import { DEFAULT_RULES, EVENTS, eventOf, playBots, rallySeed } from '../../resources/js/pong/rules.js';

const golden = JSON.parse(readFileSync(new URL('../Fixtures/pong/golden.json', import.meta.url), 'utf8'));

test('the generator draws the server\'s numbers', () => {
    for (const { seed, next, below9 } of golden.rng) {
        const rng = seeded(seed);
        assert.deepEqual(next.map(() => rng.next()), next, `seed ${seed}`);
        assert.deepEqual(below9.map(() => rng.below(9)), below9, `seed ${seed}`);
    }
});

test('50 golden rallies: every serve, hit and goal in the same tick, the same last state', () => {
    assert.equal(golden.rallies.length, 50);

    for (const { seed, rally: number, event, levels, rally_seed: expectedSeed, result } of golden.rallies) {
        const s = rallySeed(seed, number);
        assert.equal(s, expectedSeed, `rally seed of ${seed}/${number}`);

        const rally = createRally(s, event, [botSpeed(levels[0]), botSpeed(levels[1])]);
        const bots = [createBot(levels[0], 0, s), createBot(levels[1], 1, s)];
        while (!rally.over) stepRally(rally, [bots[0].target(rally), bots[1].target(rally)]);

        assert.deepEqual(rally.events, result.events, `events of ${seed}/${number} (${event})`);
        assert.deepEqual(rallyToArray(rally), result, `state of ${seed}/${number} (${event})`);
    }
});

test('the events of every 21st rally', () => {
    for (const { seed, events } of golden.events) {
        assert.deepEqual(events.map((_, k) => eventOf(DEFAULT_RULES, seed, 21 * (k + 1))), events, `seed ${seed}`);
    }
});

test('whole games between two bots end with the server\'s score, rallies, ticks and goals', () => {
    for (const { seed, levels, result } of golden.games) {
        assert.deepEqual(playBots(seed, levels), result, `seed ${seed}, levels ${levels}`);
    }
});

test('Proof of Work grows a paddle per own hit up to the server\'s cap, the other paddle unchanged', () => {
    assert.equal(POW_GROW, golden.pow.grow);
    assert.equal(POW_MAX_HALF, golden.pow.max);
    const rally = createRally(5, POW, [botSpeed(1), botSpeed(1)]);
    for (const { hits, half, other } of golden.pow.halves) {
        rally.sideHits = [hits, 0];
        assert.deepEqual([rallyHalf(rally, 0), rallyHalf(rally, 1)], [half, other], `${hits} hits`);
    }
    // The cap is reached and held: never longer than POW_MAX_HALF, however many hits.
    assert.ok(golden.pow.halves.some((h) => h.half === POW_MAX_HALF && h.hits * POW_GROW + PADDLE_HALF > POW_MAX_HALF));
    for (let hits = 0; hits <= 200; hits++) {
        rally.sideHits = [hits, hits];
        assert.equal(rallyHalf(rally, 0), Math.min(PADDLE_HALF + hits * POW_GROW, POW_MAX_HALF));
    }
});

test('the fixture is worth comparing: hits, goals and every event are in it', () => {
    const kinds = new Set(golden.rallies.flatMap((r) => r.result.events.map((e) => e[0])));
    assert.ok(kinds.has('hit') && kinds.has('goal') && !kinds.has('void'));
    assert.deepEqual(new Set(golden.rallies.map((r) => r.event)), new Set([null, ...EVENTS]));
    assert.equal(EVENTS.length, 9);
    assert.ok(golden.games.every((g) => g.result.winner !== null));
});
