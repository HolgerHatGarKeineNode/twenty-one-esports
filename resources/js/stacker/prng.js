/**
 * Blockfill random numbers: xoshiro128** seeded via splitmix32 from a 128-bit seed.
 *
 * Every operation is 32-bit integer arithmetic (`Math.imul`, shifts, `>>> 0`), so a
 * browser and the Node verifier draw the same numbers from the same seed. The seed
 * is the server's 32-character hex string; nothing here reads a clock or
 * `Math.random`.
 */

/** A 128-bit seed: exactly 32 hex digits. */
const SEED_PATTERN = /^[0-9a-f]{32}$/;

/**
 * The four 32-bit words of a seed, most significant first.
 *
 * @param {string} seed 32 lowercase hex digits
 * @returns {number[]}
 */
export function seedWords(seed) {
    if (typeof seed !== 'string' || !SEED_PATTERN.test(seed)) {
        throw new TypeError('seed must be 32 lowercase hex digits');
    }

    const words = [];
    for (let i = 0; i < 32; i += 8) {
        words.push(parseInt(seed.slice(i, i + 8), 16) >>> 0);
    }

    return words;
}

/**
 * One splitmix32 step: advances `box[0]` and returns the mixed word.
 *
 * @param {Uint32Array} box one-word state
 * @returns {number}
 */
function splitmix32(box) {
    box[0] = (box[0] + 0x9e3779b9) >>> 0;
    let z = box[0];
    z = Math.imul(z ^ (z >>> 16), 0x85ebca6b);
    z = Math.imul(z ^ (z >>> 13), 0xc2b2ae35);

    return (z ^ (z >>> 16)) >>> 0;
}

/**
 * A fresh generator state for a seed. Each seed word is folded into the splitmix32
 * state before the matching state word is drawn, so all 128 bits reach the state.
 *
 * @param {string} seed
 * @returns {Uint32Array} four-word xoshiro128** state
 */
export function createRng(seed) {
    const words = seedWords(seed);
    const mix = new Uint32Array(1);
    const state = new Uint32Array(4);
    for (let i = 0; i < 4; i++) {
        mix[0] = (mix[0] ^ words[i]) >>> 0;
        state[i] = splitmix32(mix);
    }
    if ((state[0] | state[1] | state[2] | state[3]) === 0) {
        state[0] = 1;
    }

    return state;
}

/**
 * The next 32-bit word of xoshiro128**.
 *
 * @param {Uint32Array} s generator state, advanced in place
 * @returns {number} unsigned 32-bit integer
 */
export function nextWord(s) {
    const product = Math.imul(s[1], 5);
    const result = Math.imul((product << 7) | (product >>> 25), 9) >>> 0;
    const t = s[1] << 9;

    s[2] ^= s[0];
    s[3] ^= s[1];
    s[1] ^= s[2];
    s[0] ^= s[3];
    s[2] ^= t;
    s[3] = (s[3] << 11) | (s[3] >>> 21);

    return result;
}

/**
 * An unbiased integer in [0, bound) by rejection sampling: words below
 * `2^32 mod bound` are redrawn, so the accepted range is a whole multiple of `bound`.
 *
 * @param {Uint32Array} s generator state
 * @param {number} bound integer between 1 and 2^31
 * @returns {number}
 */
export function nextBelow(s, bound) {
    if (!Number.isInteger(bound) || bound < 1 || bound > 0x80000000) {
        throw new RangeError('bound must be an integer between 1 and 2^31');
    }
    const threshold = (-bound >>> 0) % bound;
    for (;;) {
        const word = nextWord(s);
        if (word >= threshold) {
            return word % bound;
        }
    }
}
