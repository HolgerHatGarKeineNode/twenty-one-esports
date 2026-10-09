/**
 * The snippet library (resources/js/sounds/snips.js, public/sounds/snips/manifest.json; plan "Proof of Pong", P7) and
 * Pong's voices on it (resources/js/pong/voices.js, played by sound.js): every snippet file is there and within its length bounds, every
 * occasion of Pong has a pool to draw from, a draw never repeats the snippet that just played and deals a whole pool
 * before it repeats one, a figure's own snippets come with their weight, and the voices keep their quiet.
 * Run by tests/Feature/Pong/PongSnipsTest.php; runnable alone with `node --test tests/js/snips.test.mjs`.
 */
import assert from 'node:assert/strict';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { test } from 'node:test';
import { createDeck, createSnips, personsOf, speakerOf } from '../../resources/js/sounds/snips.js';
import { EXCLUDE, OCCASIONS, OWN_WEIGHT, VOICE_RULES, voiceAllowed } from '../../resources/js/pong/voices.js';

const manifest = JSON.parse(readFileSync(new URL('../../public/sounds/snips/manifest.json', import.meta.url), 'utf8'));
const cast = JSON.parse(readFileSync(new URL('../../resources/js/pong/cast.json', import.meta.url), 'utf8'));

/** A seeded random source (mulberry32), so a failing deal can be replayed. */
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

const pong = (seed = 1) => createSnips(manifest, { occasions: OCCASIONS, exclude: EXCLUDE, ownWeight: OWN_WEIGHT, ownAnyTag: ['goal', 'win', 'turm'], random: seeded(seed) });

test('every snippet of the manifest is a file of 0.5 to 4.6 seconds with a source, a person field and tags', () => {
    assert.ok(manifest.snips.length >= 200, `${manifest.snips.length} snippets`);
    const ids = new Set();
    for (const snip of manifest.snips) {
        const file = new URL(`../../public${manifest.base}${snip.file}`, import.meta.url);
        assert.ok(existsSync(file) && statSync(file).size > 1000, snip.file);
        assert.ok(snip.dur >= 0.5 && snip.dur <= 4.6, `${snip.id}: ${snip.dur} s`);
        assert.ok(existsSync(new URL(`../../public/hyper/s/${snip.source}.mp3`, import.meta.url)), snip.source);
        assert.equal(typeof snip.person, 'string');
        assert.ok(snip.tags.length > 0, snip.id);
        assert.ok(!ids.has(snip.id), snip.id);
        ids.add(snip.id);
    }
});

test('every occasion of Pong has a pool to draw from, without Kinski and the politicians', () => {
    const library = pong();
    for (const occasion of Object.keys(OCCASIONS).filter((o) => o !== 'turm')) {
        const pool = library.pool(occasion);
        assert.ok(pool.length >= 3, `${occasion}: ${pool.length}`);
        assert.ok(pool.every((snip) => !EXCLUDE.includes(snip.person) && !EXCLUDE.includes(speakerOf(snip.source))), occasion);
    }
    // Markus Turm has his own line for the Arbeitsamt's stamp.
    const turm = cast.players.find((f) => f.id === 'turm');
    assert.ok(library.draw('turm', personsOf([...turm.goal, ...turm.win], library.snips)));
});

test('a draw never repeats the snippet that just played, and deals a whole pool before it repeats one', () => {
    for (const occasion of Object.keys(OCCASIONS).filter((o) => o !== 'turm')) {
        const library = pong(7);
        const size = library.pool(occasion).length;
        const drawn = Array.from({ length: size * 6 }, () => library.draw(occasion).id);
        drawn.forEach((id, i) => assert.notEqual(id, drawn[i - 1], `${occasion} repeats ${id} at ${i}`));
        // The first deal is the whole pool once.
        assert.equal(new Set(drawn.slice(0, size)).size, size, occasion);
        assert.equal(new Set(drawn).size, size, occasion);
    }
});

test('a deck avoids the last card across a reshuffle', () => {
    for (let seed = 1; seed <= 300; seed++) {
        const deck = createDeck(['a', 'b', 'c'], seeded(seed));
        let last = null;
        for (let i = 0; i < 30; i++) {
            const card = deck.draw(last);
            assert.notEqual(card, last, `seed ${seed} draw ${i}`);
            last = card;
        }
    }
    assert.equal(createDeck([], seeded(1)).draw(), null);
    assert.equal(createDeck(['only'], seeded(1)).draw('only'), 'only');
});

test('a figure draws its own snippets with their weight and the whole library otherwise', () => {
    const library = pong(11);
    const panzer = cast.players.find((f) => f.id === 'panzerknacker');
    const persons = personsOf([...panzer.goal, ...panzer.win], library.snips);
    assert.deepEqual(persons, ['panzerknacker']);
    const pool = library.pool('conceded');
    const own = pool.filter((snip) => snip.person === 'panzerknacker');
    assert.ok(own.length > 0 && own.length < pool.length / 3, `${own.length} of ${pool.length}`);

    const n = 4000;
    const draws = Array.from({ length: n }, () => library.draw('conceded', persons));
    const share = draws.filter((snip) => snip.person === 'panzerknacker').length / n;
    // Its own with OWN_WEIGHT, plus its own share of the rest.
    const expected = OWN_WEIGHT + (1 - OWN_WEIGHT) * (own.length / pool.length);
    assert.ok(Math.abs(share - expected) < 0.04, `share ${share.toFixed(3)}, expected ${expected.toFixed(3)}`);
    // And others' snippets come too: every snippet of the occasion within the draws.
    assert.equal(new Set(draws.map((snip) => snip.id)).size, pool.length);
});

test('a figure without a snippet of the occasion speaks its own on a goal or a win, from the whole pool otherwise', () => {
    const library = pong(5);
    const goalPool = library.pool('goal');
    // A cast figure whose speakers have snippets, none of them tagged for a goal.
    const figure = cast.players.find((f) => {
        const persons = personsOf([...f.goal, ...f.win], library.snips);
        const own = library.snips.filter((snip) => persons.includes(snip.person) || persons.includes(speakerOf(snip.source)));

        return own.length > 0 && !goalPool.some((snip) => own.includes(snip));
    });
    assert.ok(figure, 'a figure without goal-tagged snippets');
    const persons = personsOf([...figure.goal, ...figure.win], library.snips);
    const isOwn = (snip) => persons.includes(snip.person) || persons.includes(speakerOf(snip.source));
    const goals = Array.from({ length: 400 }, () => library.draw('goal', persons));
    assert.ok(goals.some(isOwn), figure.id);
    assert.ok(goals.some((snip) => !isOwn(snip)), figure.id);
    // A goal against has no such fallback: the whole pool only.
    assert.ok(Array.from({ length: 200 }, () => library.draw('conceded', persons)).every((snip) => library.pool('conceded').includes(snip)));
});

test('the voices keep their quiet: the switch, a more important snippet, the gap and a hit\'s chance', () => {
    const calm = { board: true, busy: false, curPrio: 0, sinceLast: 1e9, roll: 0 };
    assert.equal(voiceAllowed('hit', calm), true);
    assert.equal(voiceAllowed('hit', { ...calm, board: false }), false);
    assert.equal(voiceAllowed('hit', { ...calm, sinceLast: VOICE_RULES.hit.gap - 1 }), false);
    assert.equal(voiceAllowed('hit', { ...calm, roll: VOICE_RULES.hit.chance }), false);
    assert.equal(voiceAllowed('goal', { ...calm, busy: true, curPrio: 3 }), false);
    assert.equal(voiceAllowed('event:tax', { ...calm, busy: true, curPrio: 3, sinceLast: 0 }), true);
    assert.equal(voiceAllowed('win', { ...calm, busy: true, curPrio: 4, sinceLast: 0 }), true);
    assert.equal(voiceAllowed('unknown', calm), false);
    // A hit speaks at most every few seconds, far less often than the ball is hit.
    assert.ok(VOICE_RULES.hit.gap >= 5000 && VOICE_RULES.hit.chance <= 0.5);
});
