/**
 * Who a moment of the game page waits for (resources/js/hyper/pace.js, user 2026-10-09): a multiplayer table never
 * waits for a click, and a battle without a human on either side runs without animation. Run by
 * tests/Feature/Hyper/HyperSoundTest.php; runnable alone with `node --test tests/js/hyperPace.test.mjs`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MULTIPLAYER_GRACE_MS, TAP_GRACE_MS, isHumanSeat, isMultiplayer, movesOnByItself, quietBattle, tapGraceMs } from '../../resources/js/hyper/pace.js';

const human = (userId) => ({ userId, bot: false });
const bot = { userId: null, bot: true };
const takenOver = { userId: 7, bot: true };

test('a human plays the seat themselves; a bot and a taken-over seat are not human', () => {
    assert.equal(isHumanSeat(human(1)), true);
    assert.equal(isHumanSeat(bot), false);
    assert.equal(isHumanSeat(takenOver), false);
    assert.equal(isHumanSeat(null), false);
});

test('two humans make a multiplayer table: every moment moves on by itself, after a short wait', () => {
    const multi = [human(1), bot, human(2)];
    const solo = [human(1), bot, takenOver];

    assert.equal(isMultiplayer(multi), true);
    assert.equal(isMultiplayer(solo), false);
    assert.equal(movesOnByItself({ seats: multi, playing: true, myTurnRuns: false }), true);
    assert.equal(movesOnByItself({ seats: solo, playing: true, myTurnRuns: false }), false);
    assert.equal(movesOnByItself({ seats: solo, playing: false, myTurnRuns: false }), true);
    assert.equal(movesOnByItself({ seats: solo, playing: true, myTurnRuns: true }), true);
    assert.equal(tapGraceMs(multi), MULTIPLAYER_GRACE_MS);
    assert.equal(tapGraceMs(solo), TAP_GRACE_MS);
});

test('a battle runs quietly only when no human fights on either side', () => {
    const seats = [human(1), bot, takenOver];

    assert.equal(quietBattle(seats, 1, 2), true);
    assert.equal(quietBattle(seats, 1, null), true);
    assert.equal(quietBattle(seats, 1, 0), false);
    assert.equal(quietBattle(seats, 0, 1), false);
});

test('the game page asks these rules: every big moment\'s wait and every battle it shows', async () => {
    const { readFileSync } = await import('node:fs');
    const game = readFileSync(new URL('../../resources/js/hyper/game.js', import.meta.url), 'utf8');

    assert.match(game, /const grace = \(\) => movesOnByItself\(\{ seats: G\.seats,/);
    assert.match(game, /since >= tapGraceMs\(G\.seats\)/);
    assert.match(game, /if \(quietBattle\(G\.seats, attacker, defender\)\) \{/);
    assert.doesNotMatch(game, /const TAP_GRACE_MS/);
});
