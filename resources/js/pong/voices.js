/**
 * Proof of Pong's voices as configuration (plan "Proof of Pong", P7), played by sound.js say() from the snippet library
 * (resources/js/sounds/snips.js): the occasions as the library's tags, the speakers Pong leaves out, a figure's own
 * weight, and the quiet each kind of occasion keeps. Pure, so tests/js/snips.test.mjs reads it under Node.
 */

/** The game's occasions as the library's tags (the Hyperbitcoinization POOLS categories). */
export const OCCASIONS = {
    hit: ['hit', 'turn'],
    goal: ['conquer', 'hit', 'ath', 'zone'],
    conceded: ['fail', 'lost', 'low', 'held'],
    streak: ['zone', 'ath', 'maxi', 'hodl', 'conquer'],
    win: ['win', 'ath', 'conquer', 'zone'],
    lose: ['lose', 'low', 'lost', 'crash'],
    'event:halving': ['ath', 'maxi', 'hodl'],
    'event:brrr': ['inflation', 'card:brrrr', 'f:ezb'],
    'event:pizza': ['card:pizza', 'start'],
    'event:difficulty': ['asic'],
    'event:tax': ['bank', 'inflation', 'f:fed', 'f:goldbug'],
    'event:controls': ['bank', 'card:nokeys', 'held', 'swiss'],
    'event:few': ['maxi', 'zone', 'card:diamond'],
    'event:pow': ['asic', 'hodl'],
    'event:arbeitsamt': ['out', 'low', 'lost', 'f:nocoiner'],
    // Markus Turm's own line at the Arbeitsamt's stamp: his snippets only.
    turm: [],
};

/** Speakers left out of Pong (plan, Besetzung: Kinski and the politicians' clips are not cast). */
export const EXCLUDE = ['kinski', 'politik'];

export const OWN_WEIGHT = 0.4;

/** Per kind of occasion: its priority, the quiet gap before it (ms since the last snippet), its chance to speak. */
export const VOICE_RULES = {
    hit: { prio: 1, gap: 6000, chance: 0.3 },
    goal: { prio: 3, gap: 1800, chance: 0.85 },
    conceded: { prio: 3, gap: 1800, chance: 0.6 },
    streak: { prio: 3, gap: 0, chance: 1 },
    event: { prio: 4, gap: 0, chance: 1 },
    turm: { prio: 4, gap: 0, chance: 1 },
    win: { prio: 5, gap: 0, chance: 1 },
    lose: { prio: 5, gap: 0, chance: 1 },
};

/**
 * Whether a voice for `occasion` may speak now: the voices on, nothing more important playing, the gap since the last
 * snippet kept, and its chance drawn (`roll` in 0..1).
 */
export function voiceAllowed(occasion, { board, busy, curPrio, sinceLast, roll }) {
    const rule = VOICE_RULES[occasion.split(':')[0]];
    if (!rule || !board) return false;
    if (busy && rule.prio <= curPrio) return false;

    return sinceLast >= rule.gap && roll < rule.chance;
}
