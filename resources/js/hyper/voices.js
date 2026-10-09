/**
 * Hyperbitcoinization's voices from the snippet library (plan "Proof of Pong", P8; resources/js/sounds/snips.js,
 * public/sounds/snips): every soundboard pool of data.js POOLS is an occasion, drawn from every snippet tagged with
 * that pool, widened by the pools of the same moment where one pool alone is thin (the snippets carry the POOLS
 * categories of their source clip as tags, tools/sound-snips/cut.py). Which event plays which pool, at what rank, and
 * that bot turns stay without clips are unchanged (sounds.js); audio.js clip() draws from here once the manifest is
 * loaded and from the whole clips (public/hyper/s) before.
 *
 * Hyperbitcoinization has no figure per seat (a faction is a side, not a speaker), so every draw is plain: no own
 * weight. Nobody is left out: the soundboard pools always held Kinski's and the politicians' clips here.
 * Pure, so tests/js/hyperVoices.test.mjs reads it under Node.
 */
import { POOLS } from './data.js';

/** The pools of the same moment a pool draws from as well, only where its own snippets are few (its own tag comes first). */
export const WIDEN = {
    start: ['turn'],
    turn: ['start'],
    conquer: ['zone', 'ath', 'hit'],
    bank: ['ezb', 'fed'],
    ezb: ['f:ezb'],
    fed: ['f:fed'],
    zone: ['ath', 'conquer'],
    swiss: ['hodl', 'held'],
    hodl: ['swiss', 'maxi', 'card:diamond'],
    held: ['hodl', 'swiss'],
    lost: ['fail', 'low'],
    low: ['lost', 'lose'],
    out: ['lose', 'low', 'lost'],
    inflation: ['card:brrrr'],
    price: ['inflation', 'card:brrrr'],
    maxi: ['hodl', 'card:diamond'],
    asic: [],
    hit: ['conquer'],
    ath: ['zone', 'conquer'],
    crash: ['lose', 'low'],
    win: ['ath', 'zone'],
    lose: ['low', 'crash'],
    'card:brrrr': ['inflation'],
    'card:attack51': [],
    'card:keys': ['card:nokeys'],
    'card:nokeys': ['card:keys'],
    'card:diamond': ['hodl'],
    'card:salvador': ['card:dip', 'ath', 'crash'],
    'card:scam': [],
    'card:pizza': ['start'],
    'card:lagarde': [],
    'card:dip': ['card:salvador', 'crash'],
};

/** Every pool of data.js POOLS as an occasion of the library: its own tag, then the pools it widens to. */
export const OCCASIONS = Object.fromEntries(Object.keys(POOLS).map((pool) => [pool, [pool, ...(WIDEN[pool] ?? [])]]));

/** The fewest snippets a pool deals before it repeats one (the old clips pools had 2 to 14). */
export const MIN_POOL = 5;
