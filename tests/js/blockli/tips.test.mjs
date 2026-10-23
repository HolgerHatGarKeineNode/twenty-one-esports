/**
 * resources/js/blockli/tips.js: the small tips of Blockli practice, the ones that come up by themselves and the tip
 * button's nudge (DerCaddy, 2026-10-23). Run by tests/Unit/Board/BlockliPracticeScriptsTest.php; runnable alone with
 * `node --test tests/js/blockli`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Blockli, after, engineMove, stateOf } from '../../../resources/js/blockli/bots.js';
import { adviceFor, autoTip, blockArea, threatAgainst } from '../../../resources/js/blockli/tips.js';

/** The tip after a move: `before` is the position the move was made in, the move as the server writes it. */
function tipAfter(before, move, by, me = 0, shown = {}) {
    const s = stateOf(before);
    const engine = engineMove(move);

    return autoTip({ me, s: after(s, engine), before: s, move: engine, by, shown });
}

test('a block lies between two ranks or between two files, as the board\'s edge names them', () => {
    assert.deepEqual(blockArea(engineMove('e3h')), { dir: 'h', a: '3', b: '4' });
    assert.deepEqual(blockArea(engineMove('e3v')), { dir: 'v', a: 'e', b: 'f' });
    assert.deepEqual(blockArea(engineMove('h8v')), { dir: 'v', a: 'h', b: 'i' });
});

test('after the player\'s own move: a block that cost the player more than the bot, one worth a single step, a step away from the goal', () => {
    // a2h right above White's own pawn on the a file: two steps for White, none for Black on the i file.
    assert.deepEqual(tipAfter('a2 i9 10 10 w - 0', 'a2h', 'me'), { key: 'selfBlock', mine: 2, theirs: 0 });
    // e1h costs both a step (Black would end on e1 too): no self-harm, but a block worth one step.
    assert.deepEqual(tipAfter('e1 e9 10 10 w - 0', 'e1h', 'me'), { key: 'weakBlock' });
    // d8h in front of Black at e9 costs him one step, with nine blocks still in hand.
    assert.deepEqual(tipAfter('e1 e9 10 10 w - 0', 'd8h', 'me'), { key: 'weakBlock' });
    // Back from e2 to e1.
    assert.deepEqual(tipAfter('e2 e9 10 10 w - 0', 'e2-e1', 'me'), { key: 'away' });
    // A step forward says nothing.
    assert.equal(tipAfter('e1 e9 10 10 w - 0', 'e1-e2', 'me'), null);
});

test('after the bot\'s move: a block that hit hard, then the race about to be lost', () => {
    // The lesson's race from Black's side: White's h3h costs Black three steps.
    assert.deepEqual(tipAfter('b4 h5 9 10 w f3hw 0', 'h3h', 'bot', 1), { key: 'hit', steps: 3 });
    // Black needs two, White four and White to move: running, Black arrives first.
    assert.deepEqual(tipAfter('e5 e4 10 10 b - 0', 'e4-e3', 'bot'), { key: 'danger', steps: 2 });
    // Not with the race won: White needs two, Black four.
    assert.equal(tipAfter('e7 e6 10 10 b - 0', 'e6-e5', 'bot'), null);
});

test('out of blocks: the bot\'s and then the player\'s own, each said once a game', () => {
    assert.deepEqual(tipAfter('e5 e8 10 0 b - 0', 'e8-e7', 'bot'), { key: 'botEmpty' });
    assert.equal(tipAfter('e5 e8 10 0 b - 0', 'e8-e7', 'bot', 0, { botEmpty: 1 }), null);
    assert.deepEqual(tipAfter('e5 e8 0 3 b - 0', 'e8-e7', 'bot'), { key: 'youEmpty' });
});

test('a jump on the player\'s shortest way is pointed out once', () => {
    assert.deepEqual(tipAfter('e4 e6 10 10 b - 0', 'e6-e5', 'bot'), { key: 'jump' });
    assert.equal(tipAfter('e4 e6 10 10 b - 0', 'e6-e5', 'bot', 0, { jump: 1 }), null);
});

test('the bot\'s strongest block against the player is the one it would set, with what it costs', () => {
    const threat = threatAgainst(stateOf('b4 h5 9 10 b f3hw 0'), 1);

    assert.equal(Blockli.moveName(threat.move), 'h3h');
    assert.equal(threat.theirs, 3);
    assert.equal(threatAgainst(stateOf('e5 e8 10 0 w - 0'), 0), null, 'a bot without blocks threatens nothing');
});

test('the tip button nudges without giving the move: run at the start, a block in the lesson\'s race', () => {
    const start = stateOf('e1 e9 10 10 w - 0');
    assert.equal(adviceFor(start, Blockli.analyze(start, { time: 150 })).kind, 'run');

    // Searched to a fixed depth, so a slow machine gets the same answer (seven plies: about a second here, the page searches two).
    const race = stateOf('b4 h5 9 10 w f3hw 0');
    const advice = adviceFor(race, Blockli.analyze(race, { maxDepth: 7, time: 60000 }));
    assert.equal(advice.kind, 'block');
    assert.ok(advice.steps >= 2);
    assert.equal(advice.area.dir, 'h');
    assert.deepEqual([advice.mine, advice.theirs], [5, 4]);
    assert.equal(adviceFor(race, null), null);
});
