/**
 * Blockfill randomness: xoshiro128** draws, unbiased bounded draws and the 7-bag.
 * Run by tests/Unit/StackerEngineTest.php; runnable alone with `node --test tests/js/stacker`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createGame, nextPieces } from '../../../resources/js/stacker/engine.js';
import { nextBag } from '../../../resources/js/stacker/pieces.js';
import { createRng, nextBelow, nextWord, seedWords } from '../../../resources/js/stacker/prng.js';

const SEEDS = 200;
const BAGS = 100;

/** 200 distinct seeds, spread over all four seed words. */
const seeds = Array.from({ length: SEEDS }, (_, i) => {
    const word = (i * 0x9e3779b1 >>> 0).toString(16).padStart(8, '0');

    return `${word}${i.toString(16).padStart(8, '0')}c0ffee00${word}`;
});

function sequence(seed) {
    const rng = createRng(seed);
    const pieces = [];
    for (let b = 0; b < BAGS; b++) {
        pieces.push(...nextBag(rng));
    }

    return pieces;
}

/** Reference xoshiro128** in BigInt, written straight from the published algorithm. */
function referenceWords(state, count) {
    const mask = 0xffffffffn;
    const rotl = (x, k) => ((x << BigInt(k)) | (x >> BigInt(32 - k))) & mask;
    const s = state.map(BigInt);
    const out = [];
    for (let i = 0; i < count; i++) {
        out.push(Number((rotl((s[1] * 5n) & mask, 7) * 9n) & mask));
        const t = (s[1] << 9n) & mask;
        s[2] ^= s[0];
        s[3] ^= s[1];
        s[1] ^= s[2];
        s[0] ^= s[3];
        s[2] ^= t;
        s[3] = rotl(s[3], 11);
    }

    return out;
}

test('the generator matches the published xoshiro128** algorithm', () => {
    const state = Uint32Array.from([1, 2, 3, 4]);
    const words = Array.from({ length: 1000 }, () => nextWord(state));

    assert.equal(words[0], 11520);
    assert.deepEqual(words, referenceWords([1, 2, 3, 4], 1000));
});

test(`every bag is a permutation of the seven pieces, ${SEEDS} seeds x ${BAGS} bags`, () => {
    for (const seed of seeds) {
        const pieces = sequence(seed);
        assert.equal(pieces.length, BAGS * 7);
        for (let at = 0; at < pieces.length; at += 7) {
            assert.deepEqual([...pieces.slice(at, at + 7)].sort(), [0, 1, 2, 3, 4, 5, 6], `${seed} bag ${at / 7}`);
        }
    }
});

test('the same seed always deals the same pieces, different seeds deal different ones', () => {
    const dealt = new Set();
    for (const seed of seeds) {
        const pieces = sequence(seed);
        assert.deepEqual(sequence(seed), pieces);
        dealt.add(pieces.join(''));
    }

    assert.equal(dealt.size, SEEDS);
});

test('every piece opens a bag about equally often', () => {
    const counts = new Array(7).fill(0);
    for (const seed of seeds) {
        const pieces = sequence(seed);
        for (let at = 0; at < pieces.length; at += 7) {
            counts[pieces[at]]++;
        }
    }

    const expected = (SEEDS * BAGS) / 7;
    for (const count of counts) {
        assert.ok(Math.abs(count - expected) < expected * 0.1, `${counts}`);
    }
});

test('the game deals from the bag: first piece and next queue come from the first bag', () => {
    const seed = seeds[7];
    const game = createGame({ seed });
    const firstBag = sequence(seed).slice(0, 7);

    assert.deepEqual([game.current.piece, ...nextPieces(game)], firstBag.slice(0, 6));
});

test('bounded draws stay in range and redraw the biased low words', () => {
    const rng = createRng(seeds[0]);
    for (const bound of [1, 2, 3, 7, 1000, 0x80000000]) {
        for (let i = 0; i < 50; i++) {
            const value = nextBelow(rng, bound);
            assert.ok(Number.isInteger(value) && value >= 0 && value < bound);
        }
    }

    // bound 0x60000000: 2^32 mod bound = 0x40000000, so a quarter of all words is
    // redrawn; the accepted words cover exactly two full multiples of the bound.
    const bound = 0x60000000;
    const drawn = createRng(seeds[1]);
    const manual = Uint32Array.from(drawn);
    let redrawn = 0;
    for (let i = 0; i < 200; i++) {
        let word = nextWord(manual);
        while (word < 0x40000000) {
            redrawn++;
            word = nextWord(manual);
        }
        assert.equal(nextBelow(drawn, bound), word % bound);
    }
    assert.ok(redrawn > 20, `only ${redrawn} redraws`);

    assert.throws(() => nextBelow(rng, 0), RangeError);
    assert.throws(() => nextBelow(rng, 2.5), RangeError);
});

test('a seed is exactly 32 lowercase hex digits', () => {
    assert.deepEqual(seedWords('0123456789abcdef0011223344556677'), [0x01234567, 0x89abcdef, 0x00112233, 0x44556677]);
    for (const bad of ['', '0123', '0123456789ABCDEF0011223344556677', 'g123456789abcdef0011223344556677', 42, null]) {
        assert.throws(() => createRng(bad), TypeError);
    }
});
