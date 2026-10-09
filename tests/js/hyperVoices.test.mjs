/**
 * Hyperbitcoinization's voices from the snippet library (resources/js/hyper/voices.js, played by audio.js clip(); plan
 * "Proof of Pong", P8): every soundboard pool and every event's clip has a pool of snippets to draw from, a pool draws
 * its own tag plus only the pools it widens to, a draw deals the whole pool before it repeats and never plays the same
 * snippet twice in a row (also across pools), nobody is left out, and the rules of when a clip may play are unchanged:
 * bot turns stay without clips except the end. Run by tests/Feature/Hyper/HyperSoundTest.php; alone with
 * `node --test tests/js/hyperVoices.test.mjs`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { POOLS } from '../../resources/js/hyper/data.js';
import { decide, SOUNDS } from '../../resources/js/hyper/sounds.js';
import { MIN_POOL, OCCASIONS, WIDEN } from '../../resources/js/hyper/voices.js';
import { createSnips } from '../../resources/js/sounds/snips.js';

const manifest = JSON.parse(readFileSync(new URL('../../public/sounds/snips/manifest.json', import.meta.url), 'utf8'));

/** mulberry32: a seeded random in [0, 1), so a failing deal can be replayed. */
function seeded(seed) {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const hyper = (seed = 1) => createSnips(manifest, { occasions: OCCASIONS, random: seeded(seed) });

test('every soundboard pool and every event’s clip is an occasion with at least MIN_POOL snippets', () => {
    const library = hyper();
    assert.deepEqual(Object.keys(OCCASIONS).sort(), Object.keys(POOLS).sort());
    for (const pool of Object.keys(POOLS)) {
        assert.ok(library.pool(pool).length >= MIN_POOL, `${pool}: ${library.pool(pool).length}`);
    }
    for (const [event, sound] of Object.entries(SOUNDS)) {
        if (sound.clip) assert.ok(OCCASIONS[sound.clip], `${event} → ${sound.clip}`);
    }
});

test('a pool draws its own tag first and only the pools it widens to, all of them real pools', () => {
    for (const [pool, tags] of Object.entries(OCCASIONS)) {
        assert.equal(tags[0], pool);
        assert.deepEqual(tags.slice(1), WIDEN[pool] ?? []);
        tags.forEach((tag) => assert.ok(POOLS[tag], `${pool} widens to unknown ${tag}`));
    }
    const library = hyper();
    // the snippets of a pool are exactly those carrying one of its tags
    for (const [pool, tags] of Object.entries(OCCASIONS)) {
        const expected = manifest.snips.filter((snip) => snip.tags.some((tag) => tags.includes(tag))).map((snip) => snip.id).sort();
        assert.deepEqual(library.pool(pool).map((snip) => snip.id).sort(), expected, pool);
    }
});

test('nobody is left out: every snippet of the library belongs to some pool, Kinski and the politicians included', () => {
    const library = hyper();
    const reachable = new Set(Object.keys(OCCASIONS).flatMap((pool) => library.pool(pool).map((snip) => snip.id)));
    assert.equal(library.snips.length, manifest.snips.length);
    assert.equal(reachable.size, manifest.snips.length);
    assert.ok(library.pool('fail').some((snip) => snip.source.startsWith('kinski')));
});

test('a pool deals all its snippets before it repeats one, never the same twice in a row', () => {
    for (const pool of Object.keys(OCCASIONS)) {
        const library = hyper(11);
        const size = library.pool(pool).length;
        const drawn = Array.from({ length: size * 5 }, () => library.draw(pool).id);
        drawn.forEach((id, i) => assert.notEqual(id, drawn[i - 1], `${pool} repeats ${id} at ${i}`));
        for (let deal = 0; deal < 5; deal++) {
            assert.equal(new Set(drawn.slice(deal * size, (deal + 1) * size)).size, size, `${pool} deal ${deal}`);
        }
    }
});

test('across pools of a match the snippet that just played never comes again next', () => {
    const pools = Object.keys(OCCASIONS);
    for (let seed = 1; seed <= 40; seed++) {
        const random = seeded(seed * 97);
        const library = hyper(seed);
        let last = null;
        for (let i = 0; i < 400; i++) {
            const snip = library.draw(pools[Math.floor(random() * pools.length)]);
            assert.notEqual(snip.id, last, `seed ${seed} draw ${i}`);
            last = snip.id;
        }
    }
});

test('the rules of when a clip plays are unchanged: bot turns only the end, a muted soundboard nothing', () => {
    const on = { board: true, fx: true, botTurn: false };
    assert.equal(decide('territory.conquered', on).clip, 'conquer');
    assert.equal(decide('territory.conquered', { ...on, botTurn: true }).clip, null);
    assert.equal(decide('card.lagarde', { ...on, botTurn: true }).clip, null);
    assert.equal(decide('game.won', { ...on, botTurn: true }).clip, 'win');
    assert.equal(decide('game.lost', { ...on, botTurn: true }).clip, 'lose');
    assert.equal(decide('game.won', { ...on, board: false }).clip, null);
});
