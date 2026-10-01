/**
 * Blockfill pieces: shapes, rotation states, kick table and the 7-bag.
 *
 * Coordinates are board cells with x to the right and y DOWNWARD (row 0 is the top
 * hidden row). Rotation states are 0 (spawn), 1 (clockwise), 2 (half turn) and
 * 3 (counter-clockwise). The kick data is SRS-compatible: the quarter-turn offsets
 * match the published SRS values, written here with y pointing down.
 */

import { nextBelow } from './prng.js';

/** Piece codes in bag order; a piece is stored as its index into this list. */
export const PIECE_CODES = Object.freeze(['I', 'O', 'T', 'S', 'Z', 'J', 'L']);

export const PIECE_I = 0;
export const PIECE_O = 1;

/**
 * Spawn-state cells inside each piece's bounding box, and the box size.
 *
 * @type {ReadonlyArray<{size: number, cells: number[][]}>}
 */
const SPAWN_SHAPES = [
    { size: 4, cells: [[0, 1], [1, 1], [2, 1], [3, 1]] },
    { size: 2, cells: [[0, 0], [1, 0], [0, 1], [1, 1]] },
    { size: 3, cells: [[1, 0], [0, 1], [1, 1], [2, 1]] },
    { size: 3, cells: [[1, 0], [2, 0], [0, 1], [1, 1]] },
    { size: 3, cells: [[0, 0], [1, 0], [1, 1], [2, 1]] },
    { size: 3, cells: [[0, 0], [0, 1], [1, 1], [2, 1]] },
    { size: 3, cells: [[2, 0], [0, 1], [1, 1], [2, 1]] },
];

/**
 * Cells per piece and rotation state: `SHAPES[piece][rot]` is a list of [x, y]
 * offsets from the box's top-left corner. A clockwise quarter turn inside a box of
 * size n maps (x, y) to (n - 1 - y, x).
 */
export const SHAPES = Object.freeze(
    SPAWN_SHAPES.map(({ size, cells }) => {
        const states = [cells];
        for (let rot = 1; rot < 4; rot++) {
            states.push(states[rot - 1].map(([x, y]) => [size - 1 - y, x]));
        }

        return Object.freeze(states.map((state) => Object.freeze(state.map((cell) => Object.freeze(cell)))));
    }),
);

/** Box column of every piece at spawn: centred on a 10-wide board, odd widths to the left. */
export const SPAWN_X = Object.freeze([3, 4, 3, 3, 3, 3, 3]);

/** Box row at spawn: the lowest cell of the spawn state sits in the last hidden row (3). */
export const SPAWN_Y = Object.freeze([2, 2, 2, 2, 2, 2, 2]);

/**
 * Quarter-turn kicks for J, L, S, T and Z, keyed "from>to". Each entry is [dx, dy],
 * tried in order; dy is positive DOWN.
 */
const KICKS_JLSTZ = {
    '0>1': [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
    '1>0': [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
    '1>2': [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
    '2>1': [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
    '2>3': [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
    '3>2': [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
    '3>0': [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
    '0>3': [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
};

/** Quarter-turn kicks for I. */
const KICKS_I = {
    '0>1': [[0, 0], [-2, 0], [1, 0], [-2, 1], [1, -2]],
    '1>0': [[0, 0], [2, 0], [-1, 0], [2, -1], [-1, 2]],
    '1>2': [[0, 0], [-1, 0], [2, 0], [-1, -2], [2, 1]],
    '2>1': [[0, 0], [1, 0], [-2, 0], [1, 2], [-2, -1]],
    '2>3': [[0, 0], [2, 0], [-1, 0], [2, -1], [-1, 2]],
    '3>2': [[0, 0], [-2, 0], [1, 0], [-2, 1], [1, -2]],
    '3>0': [[0, 0], [1, 0], [-2, 0], [1, 2], [-2, -1]],
    '0>3': [[0, 0], [-1, 0], [2, 0], [-1, -2], [2, 1]],
};

/**
 * Half-turn kicks, the same for every piece but O and for every start state: in
 * place, one up, right, left, up-right, up-left. Our own choice; SRS itself has no
 * half turn.
 */
const KICKS_HALF = [[0, 0], [0, -1], [1, 0], [-1, 0], [1, -1], [-1, -1]];

/** O turns in place and never kicks. */
const KICKS_NONE = [[0, 0]];

/**
 * The kicks to try when `piece` turns from state `from` to state `to`.
 *
 * @param {number} piece
 * @param {number} from
 * @param {number} to
 * @returns {ReadonlyArray<ReadonlyArray<number>>}
 */
export function kicksFor(piece, from, to) {
    if (piece === PIECE_O) {
        return KICKS_NONE;
    }
    if (((from + 2) & 3) === to) {
        return KICKS_HALF;
    }

    return (piece === PIECE_I ? KICKS_I : KICKS_JLSTZ)[`${from}>${to}`];
}

/**
 * Seven pieces, one of each, shuffled with Fisher–Yates on unbiased draws.
 *
 * @param {Uint32Array} rng generator state, advanced in place
 * @returns {number[]}
 */
export function nextBag(rng) {
    const bag = [0, 1, 2, 3, 4, 5, 6];
    for (let i = 6; i > 0; i--) {
        const j = nextBelow(rng, i + 1);
        const swap = bag[i];
        bag[i] = bag[j];
        bag[j] = swap;
    }

    return bag;
}
