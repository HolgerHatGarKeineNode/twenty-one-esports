/**
 * The game page's sound as configuration (plan "Hyperbitcoinization", P6): which event plays which soundboard
 * pool and which effects, whether a clip may play now, and the viewer's sound settings. Pure: no audio, no DOM,
 * so tests/js/hyperSounds.test.mjs checks every mapping under Node; audio.js plays what this decides.
 *
 * - SOUNDS: one entry per game event. `clip` names a pool of data.js POOLS (rotated by audio.js), `prio` its rank
 *   (a higher one cuts a lower one off; data.js PRIO when left out), `fx` the synthesized effects (audio.js sfx).
 * - Bot turns play no soundboard clips, effects still sound (user, 2026-10-08); only the end of the match (prio 5)
 *   comes through, as the prototype v7 has it. A turn the server played for a seat (`source` bot) counts as a bot
 *   turn too.
 * - The viewer's settings live in localStorage `hb-settings` (per browser, so per viewer): effects, soundboard
 *   (event clips and the clips players send as emotes), music and the volume. A storage that throws or holds
 *   garbage gives the defaults; a write that throws keeps them for this visit only.
 */
import { POOLS, PRIO } from './data.js';

/** @typedef {{ clip?: string, prio?: number, fx?: string[] }} Sound */

/** Event => sound. Card events are added below from the card pools, so a new card pool is mapped by itself. */
export const SOUNDS = {
    // Battles: a heavy hit, an attack that broke on the defence (in the vault, on a maxi, anywhere else), held.
    'battle.hit': { clip: 'hit' },
    'battle.repelled.swiss': { clip: 'swiss' },
    'battle.repelled.maxi': { clip: 'hodl' },
    'battle.repelled': { clip: 'fail' },
    'battle.held': { clip: 'held' },
    // The own turn and the strong units.
    'turn.mine': { clip: 'turn', fx: ['horn'] },
    'unit.maxi': { clip: 'maxi', fx: ['power'] },
    'unit.asic': { clip: 'asic', fx: ['power'] },
    // Territories: an own one lost, one taken, down to the last three.
    'territory.lost': { clip: 'lost', prio: 3 },
    'territory.conquered': { clip: 'conquer' },
    'territory.low': { clip: 'low' },
    // Central banks: Frankfurt, New York, any other; a whole currency space.
    'bank.ezb': { clip: 'ezb', fx: ['coin'] },
    'bank.fed': { clip: 'fed', fx: ['coin'] },
    'bank.fallen': { clip: 'bank', fx: ['coin'] },
    'zone.completed': { clip: 'zone' },
    // Money: the own fiat rots, the money printer raises prices.
    'inflation.mine': { clip: 'inflation', fx: ['inflate'] },
    'price.up': { clip: 'price' },
    'player.out': { clip: 'out' },
    // El Salvador's coin toss.
    'card.salvador.up': { clip: 'ath' },
    'card.salvador.down': { clip: 'crash' },
    // The end: lost, won, watched.
    'game.lost': { clip: 'lose', fx: ['gong'] },
    'game.won': { clip: 'win', fx: ['fanfare'] },
    'game.over': { clip: 'win', fx: ['gong'] },
};

for (const pool of Object.keys(POOLS)) {
    if (pool.startsWith('card:')) SOUNDS['card.' + pool.slice(5)] = { clip: pool, prio: 3, fx: ['flip', 'power'] };
}

/** The rank of a clip: the entry's, else the pool's, else 1. */
export function prioOf(sound) {
    return sound.prio ?? PRIO[sound.clip] ?? 1;
}

/**
 * Whether a soundboard clip of this rank may start now: the soundboard on, and no bot turn unless it is the end.
 *
 * @param {number} prio
 * @param {{ board: boolean, botTurn: boolean }} state
 */
export function clipAllowed(prio, { board, botTurn }) {
    return !!board && (!botTurn || prio >= 5);
}

/**
 * What an event plays now: the pool and its rank (null when no clip may play) and the effects (none while effects
 * are off). An unknown event plays nothing.
 *
 * @param {string} event
 * @param {{ board: boolean, fx: boolean, botTurn: boolean }} state
 * @returns {{ clip: string|null, prio: number, fx: string[] }}
 */
export function decide(event, state) {
    const sound = SOUNDS[event];
    if (!sound) return { clip: null, prio: 0, fx: [] };
    const prio = prioOf(sound);

    return {
        clip: sound.clip && clipAllowed(prio, state) ? sound.clip : null,
        prio,
        fx: state.fx ? [...(sound.fx ?? [])] : [],
    };
}

/** The defaults: everything on, 80 % volume. */
export const DEFAULT_SETTINGS = { vol: 0.8, music: true, fx: true, board: true };

/**
 * The viewer's sound settings from storage, each value checked; anything unreadable is the default. An old
 * setting "all sound off" (before effects and soundboard had their own switches) is read as soundboard off,
 * effects on (prototype v10).
 *
 * @param {{ getItem(key: string): string|null }|null|undefined} storage
 * @param {string} key
 */
export function readSettings(storage, key = 'hb-settings') {
    let stored = null;
    try { stored = JSON.parse(storage?.getItem(key) ?? 'null'); } catch (e) { stored = null; }
    const st = stored && typeof stored === 'object' ? { ...stored } : {};
    if (st.board === undefined && st.fx === false) { st.board = false; st.fx = true; }
    const out = { ...DEFAULT_SETTINGS };
    if (typeof st.vol === 'number' && Number.isFinite(st.vol)) out.vol = Math.min(1, Math.max(0, st.vol));
    for (const k of ['music', 'fx', 'board']) if (typeof st[k] === 'boolean') out[k] = st[k];

    return { ...st, ...out };
}

/**
 * Writes the settings; false when the storage refused (private mode, full): they then hold for this visit only.
 *
 * @param {{ setItem(key: string, value: string): void }|null|undefined} storage
 * @param {object} settings
 */
export function writeSettings(storage, settings, key = 'hb-settings') {
    try {
        storage.setItem(key, JSON.stringify(settings));

        return true;
    } catch (e) {
        return false;
    }
}
