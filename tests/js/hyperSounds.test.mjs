/**
 * The game page's sound as configuration (plan "Hyperbitcoinization", P6, resources/js/hyper/sounds.js): every
 * event the page cues has an entry and every entry is cued, every entry names a pool and effects that exist, a bot
 * turn plays no soundboard clip (only the end comes through), the switches silence what they say, and the viewer's
 * settings survive a broken storage. Run by tests/Feature/Hyper/HyperSoundTest.php; runnable alone with
 * `node --test tests/js/hyperSounds.test.mjs`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { CARDS, POOLS, PRIO } from '../../resources/js/hyper/data.js';
import { DEFAULT_SETTINGS, SOUNDS, clipAllowed, decide, prioOf, readSettings, writeSettings } from '../../resources/js/hyper/sounds.js';

const source = (file) => readFileSync(new URL('../../resources/js/hyper/' + file, import.meta.url), 'utf8');

/** The effects audio.js can play: the keys of its `sfx` table. */
const EFFECTS = new Set([...source('audio.js').matchAll(/^ {4}(\w+): \(t\) =>/gm)].map((m) => m[1]));

/** Every event game.js cues: the literal names, and the dynamic `unit.`/`card.` ones expanded. */
function cuedEvents() {
    const game = source('game.js');
    const names = new Set();
    for (const call of game.matchAll(/cue\(([^;]*?)\);/g)) {
        for (const literal of call[1].matchAll(/'([a-z]+(?:\.[a-z]+)+)'/g)) names.add(literal[1]);
    }
    if (game.includes("cue('unit.' + e.unit)")) ['unit.maxi', 'unit.asic'].forEach((n) => names.add(n));
    if (game.includes("cue('card.' + e.card)")) Object.keys(CARDS).forEach((card) => names.add('card.' + card));

    return names;
}

const ON = { board: true, fx: true, botTurn: false };

test('every event the page cues has a sound, and every sound is cued by the page', () => {
    const cued = cuedEvents();
    assert.ok(cued.size >= 30, `found only ${cued.size} cued events`);
    assert.deepEqual([...cued].filter((e) => !(e in SOUNDS)), [], 'cued without an entry');
    assert.deepEqual(Object.keys(SOUNDS).filter((e) => !cued.has(e)), [], 'an entry no event cues');
    // No soundboard pool is played around the map any more: game.js has no direct clip() call left.
    assert.doesNotMatch(source('game.js'), /[^.\w]clip\(/);
});

test('every mapping names a soundboard pool with clips and effects audio.js has', () => {
    assert.ok(EFFECTS.size >= 20, 'the sfx table was read');
    for (const [event, sound] of Object.entries(SOUNDS)) {
        assert.ok(sound.clip || sound.fx?.length, `${event} plays nothing`);
        if (sound.clip) assert.ok((POOLS[sound.clip] ?? []).length > 0, `${event}: pool ${sound.clip} is empty or missing`);
        for (const fx of sound.fx ?? []) assert.ok(EFFECTS.has(fx), `${event}: no effect ${fx}`);
    }
});

test('each mapping plays its clip and effects with everything on, at its rank', () => {
    for (const [event, sound] of Object.entries(SOUNDS)) {
        const played = decide(event, ON);
        assert.equal(played.clip, sound.clip ?? null, event);
        assert.deepEqual(played.fx, sound.fx ?? [], event);
        assert.equal(played.prio, sound.prio ?? PRIO[sound.clip] ?? 1, event);
    }
    // The ranks the page relied on before the map: a lost territory and the cards are 3, the end is 5.
    assert.equal(prioOf(SOUNDS['territory.lost']), 3);
    assert.equal(prioOf(SOUNDS['card.brrrr']), 3);
    assert.equal(prioOf(SOUNDS['game.won']), 5);
    assert.equal(prioOf(SOUNDS['turn.mine']), 2);
});

test('a bot turn plays no soundboard clip for any mapping but the end of the match; its effects still sound', () => {
    const bot = { ...ON, botTurn: true };
    const through = [];
    for (const [event, sound] of Object.entries(SOUNDS)) {
        const played = decide(event, bot);
        assert.deepEqual(played.fx, sound.fx ?? [], `${event}: effects stay in a bot turn`);
        if (played.clip !== null) through.push(event);
    }
    assert.deepEqual(through.sort(), ['game.lost', 'game.over', 'game.won']);
    assert.equal(clipAllowed(4, { board: true, botTurn: true }), false);
    assert.equal(clipAllowed(5, { board: true, botTurn: true }), true);
});

test('the soundboard switch silences every clip and leaves the effects; the effects switch the other way round', () => {
    for (const [event, sound] of Object.entries(SOUNDS)) {
        const noBoard = decide(event, { ...ON, board: false });
        assert.equal(noBoard.clip, null, event);
        assert.deepEqual(noBoard.fx, sound.fx ?? [], event);
        const noFx = decide(event, { ...ON, fx: false });
        assert.equal(noFx.clip, sound.clip ?? null, event);
        assert.deepEqual(noFx.fx, [], event);
    }
    // The soundboard switch holds for the end of the match too.
    assert.equal(clipAllowed(5, { board: false, botTurn: false }), false);
    assert.deepEqual(decide('no.such.event', ON), { clip: null, prio: 0, fx: [] });
});

test('every event card has its own sound', () => {
    for (const card of Object.keys(CARDS)) assert.ok(SOUNDS['card.' + card]?.clip === 'card:' + card, card);
});

test('the viewer’s settings: stored values are read and checked, a broken storage gives the defaults, a refused write says so', () => {
    const store = (value) => ({ getItem: () => value });
    assert.deepEqual(readSettings(null), DEFAULT_SETTINGS);
    assert.deepEqual(readSettings(store('{not json')), DEFAULT_SETTINGS);
    assert.deepEqual(readSettings({ getItem: () => { throw new Error('SecurityError'); } }), DEFAULT_SETTINGS);
    assert.deepEqual(readSettings(store('"a string"')), DEFAULT_SETTINGS);

    const read = readSettings(store(JSON.stringify({ vol: 0.35, music: false, fx: true, board: false, speed: 3 })));
    assert.deepEqual([read.vol, read.music, read.fx, read.board, read.speed], [0.35, false, true, false, 3]);
    // A volume out of range is clamped, a wrong type ignored.
    assert.equal(readSettings(store(JSON.stringify({ vol: 7 }))).vol, 1);
    assert.equal(readSettings(store(JSON.stringify({ vol: -1 }))).vol, 0);
    assert.equal(readSettings(store(JSON.stringify({ vol: '0.2', fx: 'no' }))).vol, 0.8);
    assert.equal(readSettings(store(JSON.stringify({ fx: 'no' }))).fx, true);
    // Before effects and soundboard had their own switches, "sound off" meant both: now soundboard off, effects on.
    const legacy = readSettings(store(JSON.stringify({ fx: false })));
    assert.deepEqual([legacy.fx, legacy.board], [true, false]);

    const saved = {};
    assert.equal(writeSettings({ setItem: (k, v) => { saved[k] = v; } }, { vol: 0.5, board: false }), true);
    assert.deepEqual(readSettings(store(saved['hb-settings'])).vol, 0.5);
    assert.equal(writeSettings({ setItem: () => { throw new Error('QuotaExceededError'); } }, { vol: 0.5 }), false);
    assert.equal(writeSettings(null, { vol: 0.5 }), false);
});
