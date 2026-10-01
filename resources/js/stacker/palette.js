/**
 * Blockfill colours: every piece is a transaction, coloured by its fee rate on
 * our own scale from low (slate) to high (pink). Indexed like PIECE_CODES
 * (I O T S Z J L). UI only; the engine knows no colours.
 */

/** Fee colour per piece, in PIECE_CODES order. */
export const FEE_COLORS = Object.freeze([
    '#F7931A', // I: orange
    '#7383A6', // O: slate
    '#F2D45C', // T: yellow
    '#3B82E0', // S: blue
    '#0FA394', // Z: teal
    '#F9A8D4', // J: pink
    '#5AAE3C', // L: green
]);

/** The fee scale from low to high, for the legend. */
export const FEE_SCALE = Object.freeze(['#7383A6', '#3B82E0', '#0FA394', '#5AAE3C', '#F2D45C', '#F7931A', '#F9A8D4']);

/** The well behind the stack; every fee colour keeps at least 4.5:1 against it. */
export const WELL = '#0E0E11';
export const WELL_LINE = '#16161A';

/** A mined block (a cleared row) while it flashes. */
export const MINED = '#F9B25F';
export const MINED_EDGE = '#F7931A';
