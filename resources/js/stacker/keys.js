/**
 * Blockfill controls: which keys (KeyboardEvent.code) drive which action, and
 * the player's handling settings. Shared by the game page and the settings
 * form; the server checks the same shape (App\Support\Stacker\StackerSettings).
 */

import { ACTIONS, DEFAULT_SETTINGS, normalizeSettings } from './engine.js';

/** The engine's eight actions plus `restart`, which only the page knows. */
export const BINDABLE = Object.freeze([...ACTIONS, 'restart']);

export const DEFAULT_KEYS = Object.freeze({
    left: ['ArrowLeft'],
    right: ['ArrowRight'],
    soft: ['ArrowDown'],
    hard: ['Space'],
    cw: ['ArrowUp', 'KeyX'],
    ccw: ['KeyZ'],
    flip: ['KeyA'],
    hold: ['KeyC', 'ShiftLeft'],
    restart: ['KeyR'],
});

const CODE = /^[A-Za-z0-9]{1,24}$/;

/** At most this many keys per action. */
export const KEYS_PER_ACTION = 2;

/**
 * Key bindings checked: every action present with one or two valid codes, no
 * code bound twice. Anything off falls back to the defaults as a whole, so a
 * broken saved map never leaves an action without a key.
 *
 * @param {unknown} keys
 * @returns {Record<string, string[]>}
 */
export function normalizeKeys(keys) {
    if (!keys || typeof keys !== 'object') {
        return structuredClone(DEFAULT_KEYS);
    }
    const seen = new Set();
    const out = {};
    for (const action of BINDABLE) {
        const codes = keys[action];
        if (!Array.isArray(codes) || codes.length < 1 || codes.length > KEYS_PER_ACTION) {
            return structuredClone(DEFAULT_KEYS);
        }
        for (const code of codes) {
            if (typeof code !== 'string' || !CODE.test(code) || seen.has(code)) {
                return structuredClone(DEFAULT_KEYS);
            }
            seen.add(code);
        }
        out[action] = [...codes];
    }

    return out;
}

/**
 * Handling and keys together; out-of-range handling falls back to the defaults.
 *
 * @param {unknown} saved {das, arr, sdf, keys}
 */
export function normalizeControls(saved) {
    const source = saved && typeof saved === 'object' ? saved : {};
    let handling;
    try {
        handling = normalizeSettings({ das: source.das, arr: source.arr, sdf: source.sdf });
    } catch {
        handling = DEFAULT_SETTINGS;
    }

    return { das: handling.das, arr: handling.arr, sdf: handling.sdf, keys: normalizeKeys(source.keys) };
}

/**
 * KeyboardEvent.code to action name.
 *
 * @param {Record<string, string[]>} keys
 * @returns {Map<string, string>}
 */
export function keyMap(keys) {
    const map = new Map();
    for (const [action, codes] of Object.entries(keys)) {
        for (const code of codes) {
            map.set(code, action);
        }
    }

    return map;
}

/** A key code as the page shows it: "ArrowLeft" as "←", "KeyZ" as "Z". */
export function keyLabel(code) {
    const arrows = { ArrowLeft: '←', ArrowRight: '→', ArrowUp: '↑', ArrowDown: '↓' };
    if (arrows[code]) {
        return arrows[code];
    }
    if (/^Key[A-Z]$/.test(code)) {
        return code.slice(3);
    }
    if (/^Digit[0-9]$/.test(code)) {
        return code.slice(5);
    }

    return code.replace(/(Left|Right)$/, ' $1').trim();
}
