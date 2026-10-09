/**
 * Proof of Pong's random numbers (plan "Proof of Pong", P1): xoshiro128++ seeded by splitmix32, draw for draw the
 * server's App\Support\Hyper\HyperRng. Every step is 32-bit integer arithmetic (`Math.imul`, `>>> 0`); below()
 * divides by 2^32 instead of shifting, because JavaScript's shifts cut a number to 32 bits first.
 */

const rotl = (x, k) => ((x << k) | (x >>> (32 - k))) >>> 0;

/**
 * A generator for a 32-bit unsigned seed.
 *
 * @param {number} seed
 * @returns {{ next: () => number, below: (n: number) => number, shuffle: <T>(items: T[]) => T[], state: () => number[] }}
 */
export function seeded(seed) {
    if (!Number.isInteger(seed) || seed < 0 || seed > 0xffffffff) {
        throw new RangeError('The seed is a 32-bit unsigned integer.');
    }

    let z = seed;
    const s = [0, 0, 0, 0];
    for (let k = 0; k < 4; k++) {
        z = (z + 0x9e3779b9) >>> 0;
        let v = z;
        v = Math.imul(v ^ (v >>> 16), 0x85ebca6b) >>> 0;
        v = Math.imul(v ^ (v >>> 13), 0xc2b2ae35) >>> 0;
        s[k] = (v ^ (v >>> 16)) >>> 0;
    }

    const next = () => {
        const result = (rotl((s[0] + s[3]) >>> 0, 7) + s[0]) >>> 0;
        const t = (s[1] << 9) >>> 0;
        s[2] = (s[2] ^ s[0]) >>> 0;
        s[3] = (s[3] ^ s[1]) >>> 0;
        s[1] = (s[1] ^ s[2]) >>> 0;
        s[0] = (s[0] ^ s[3]) >>> 0;
        s[2] = (s[2] ^ t) >>> 0;
        s[3] = rotl(s[3], 11);

        return result;
    };

    // 0 .. n-1 by multiply-shift; next() * n stays below 2^53 for every n the game uses.
    const below = (n) => Math.floor((next() * n) / 4294967296);

    const shuffle = (items) => {
        const out = [...items];
        for (let i = out.length - 1; i > 0; i--) {
            const j = below(i + 1);
            [out[i], out[j]] = [out[j], out[i]];
        }

        return out;
    };

    return { next, below, shuffle, state: () => [...s] };
}
