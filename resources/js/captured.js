/**
 * Captured pieces per side, read from a position (FEN) alone: what is missing
 * of the opponent's starting set. A promoted pawn is counted as the piece it
 * became, so an extra queen means one pawn fewer is missing, not a capture.
 */
const START = { q: 1, r: 2, b: 2, n: 2, p: 8 };
const VALUE = { q: 9, r: 5, b: 3, n: 3, p: 1 };
const ORDER = ['q', 'r', 'b', 'n', 'p'];
// Outline glyphs in the text colour: readable on the dark ground for both
// sides (solid black glyphs vanish there). The row belongs to one side, so the
// colour of the taken pieces is clear from its place.
const GLYPH = { q: '\u2655', r: '\u2656', b: '\u2657', n: '\u2658', p: '\u2659' };
const TEXT = '︎';

function counts(fen) {
    const found = { w: { q: 0, r: 0, b: 0, n: 0, p: 0 }, b: { q: 0, r: 0, b: 0, n: 0, p: 0 } };
    for (const ch of (fen || '').split(' ')[0]) {
        const lower = ch.toLowerCase();
        if (START[lower] === undefined) continue;
        found[ch === lower ? 'b' : 'w'][lower]++;
    }

    return found;
}

/**
 * The pieces `color` has taken, strongest first, e.g. ['q', 'n', 'p', 'p'].
 *
 * @param {string} fen
 * @param {'w'|'b'} color the capturing side
 */
export function capturedBy(fen, color) {
    const victim = counts(fen)[color === 'w' ? 'b' : 'w'];
    let promoted = 0;
    const missing = {};
    for (const piece of ['q', 'r', 'b', 'n']) {
        promoted += Math.max(0, victim[piece] - START[piece]);
        missing[piece] = Math.max(0, START[piece] - victim[piece]);
    }
    missing.p = Math.max(0, START.p - victim.p - promoted);

    return ORDER.flatMap((piece) => Array(missing[piece]).fill(piece));
}

/**
 * Display data for one side: the captured glyphs and the material lead.
 *
 * @returns {{ glyphs: string, lead: string, label: string }}
 */
export function capturedSummary(fen, color, names = {}) {
    const taken = capturedBy(fen, color);
    const other = color === 'w' ? 'b' : 'w';
    // Lead as on the board (a promotion counts), like the kit's material().
    const onBoard = counts(fen);
    const score = (side) => ORDER.reduce((sum, piece) => sum + VALUE[piece] * onBoard[side][piece], 0);
    const lead = score(color) - score(other);

    return {
        glyphs: taken.map((piece) => GLYPH[piece] + TEXT).join(''),
        lead: lead > 0 ? '+' + lead : '',
        label: taken.map((piece) => names[piece] ?? piece).join(', '),
    };
}

if (typeof window !== 'undefined') {
    window.chessCaptured = capturedSummary;
}
