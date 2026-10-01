/**
 * Blockfill replay codec: header and input log as one base64url string.
 *
 * Layout (all integers unsigned LEB128 varints unless noted):
 *   version | engine length | engine (ASCII) | seed (16 raw bytes)
 *   | das | arr | sdf | input count
 *   | per input: (tick delta << 4) | (down << 3) | action
 *
 * The tick delta is the distance to the previous input (the first one counts from
 * tick 0), the action its 3-bit code from ACTIONS, down 1 for press and 0 for release.
 */

import { normalizeSettings, validateLog } from './engine.js';
import { seedWords } from './prng.js';

/** Replay format version. */
export const REPLAY_VERSION = 1;

/** Longest delta between two inputs the format carries (fits 27 bits with the flags). */
const MAX_DELTA = 0x7fffff;

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

/**
 * @typedef {{v: number, engine: string, seed: string, settings: {das: number, arr: number, sdf: number}}} ReplayHeader
 */

function pushVarint(bytes, value) {
    let rest = value;
    while (rest > 0x7f) {
        bytes.push((rest & 0x7f) | 0x80);
        rest >>>= 7;
    }
    bytes.push(rest);
}

function toBase64Url(bytes) {
    let out = '';
    let i = 0;
    for (; i + 2 < bytes.length; i += 3) {
        const n = (bytes[i] << 16) | (bytes[i + 1] << 8) | bytes[i + 2];
        out += ALPHABET[n >>> 18] + ALPHABET[(n >>> 12) & 63] + ALPHABET[(n >>> 6) & 63] + ALPHABET[n & 63];
    }
    const left = bytes.length - i;
    if (left === 1) {
        const n = bytes[i] << 16;
        out += ALPHABET[n >>> 18] + ALPHABET[(n >>> 12) & 63];
    } else if (left === 2) {
        const n = (bytes[i] << 16) | (bytes[i + 1] << 8);
        out += ALPHABET[n >>> 18] + ALPHABET[(n >>> 12) & 63] + ALPHABET[(n >>> 6) & 63];
    }

    return out;
}

function fromBase64Url(text) {
    if (typeof text !== 'string' || (text.length & 3) === 1) {
        throw new TypeError('replay is not base64url');
    }
    const bytes = [];
    let buffer = 0;
    let bits = 0;
    for (let i = 0; i < text.length; i++) {
        const value = ALPHABET.indexOf(text[i]);
        if (value < 0) {
            throw new TypeError('replay is not base64url');
        }
        buffer = ((buffer << 6) | value) & 0xffffff;
        bits += 6;
        if (bits >= 8) {
            bits -= 8;
            bytes.push((buffer >>> bits) & 0xff);
        }
    }
    if ((buffer & ((1 << bits) - 1)) !== 0) {
        throw new TypeError('replay has stray bits at the end');
    }

    return bytes;
}

/**
 * Encodes a header and its input log.
 *
 * @param {ReplayHeader} header
 * @param {Array<[number, number, number]>} inputs [tick, action, down]
 * @returns {string} base64url without padding
 */
export function encodeReplay(header, inputs) {
    const { engine, seed } = header;
    if (header.v !== REPLAY_VERSION) {
        throw new RangeError(`replay version must be ${REPLAY_VERSION}`);
    }
    if (typeof engine !== 'string' || !/^[a-z0-9]{1,16}$/.test(engine)) {
        throw new TypeError('engine must be 1-16 lowercase letters or digits');
    }
    const words = seedWords(seed);
    const settings = normalizeSettings(header.settings);
    const log = validateLog(inputs);

    const bytes = [];
    pushVarint(bytes, REPLAY_VERSION);
    pushVarint(bytes, engine.length);
    for (let i = 0; i < engine.length; i++) {
        bytes.push(engine.charCodeAt(i));
    }
    for (const w of words) {
        bytes.push(w >>> 24, (w >>> 16) & 0xff, (w >>> 8) & 0xff, w & 0xff);
    }
    pushVarint(bytes, settings.das);
    pushVarint(bytes, settings.arr);
    pushVarint(bytes, settings.sdf);
    pushVarint(bytes, log.length);
    let previous = 0;
    for (const [tick, action, down] of log) {
        const delta = tick - previous;
        if (delta > MAX_DELTA) {
            throw new RangeError('gap between two inputs is too long');
        }
        pushVarint(bytes, ((delta << 4) | (down << 3) | action) >>> 0);
        previous = tick;
    }

    return toBase64Url(bytes);
}

/**
 * Decodes a replay; throws on anything malformed, truncated or with trailing bytes.
 *
 * @param {string} text
 * @returns {{header: ReplayHeader, inputs: Array<[number, number, number]>}}
 */
export function decodeReplay(text) {
    const bytes = fromBase64Url(text);
    let at = 0;
    const byte = () => {
        if (at >= bytes.length) {
            throw new RangeError('replay ends early');
        }

        return bytes[at++];
    };
    const varint = () => {
        let value = 0;
        for (let shift = 0; shift < 28; shift += 7) {
            const b = byte();
            value |= (b & 0x7f) << shift;
            if ((b & 0x80) === 0) {
                return value >>> 0;
            }
        }
        throw new RangeError('replay varint too long');
    };

    const v = varint();
    if (v !== REPLAY_VERSION) {
        throw new RangeError(`unknown replay version ${v}`);
    }
    const engineLength = varint();
    if (engineLength < 1 || engineLength > 16) {
        throw new RangeError('engine name has a bad length');
    }
    let engine = '';
    for (let i = 0; i < engineLength; i++) {
        engine += String.fromCharCode(byte());
    }
    if (!/^[a-z0-9]{1,16}$/.test(engine)) {
        throw new TypeError('engine must be 1-16 lowercase letters or digits');
    }
    let seed = '';
    for (let i = 0; i < 16; i++) {
        seed += byte().toString(16).padStart(2, '0');
    }
    const settings = normalizeSettings({ das: varint(), arr: varint(), sdf: varint() });
    const count = varint();
    if (count > bytes.length - at) {
        throw new RangeError('replay ends early');
    }
    const inputs = [];
    let tick = 0;
    for (let i = 0; i < count; i++) {
        const packed = varint();
        tick += packed >>> 4;
        inputs.push([tick, packed & 7, (packed >>> 3) & 1]);
    }
    if (at !== bytes.length) {
        throw new RangeError('replay has trailing bytes');
    }

    return { header: { v, engine, seed, settings: { ...settings } }, inputs };
}
