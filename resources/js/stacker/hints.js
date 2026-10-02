/**
 * Blockfill cheat hints (plan "Blockfill", P5): what in a verified run looks
 * more like a program than a person. A hint only marks a run for an admin's
 * look (App\Support\Stacker\StackerRuns::finish()); it never rejects one, and
 * a run without hints is not proven human (bots and tool-assisted runs cannot
 * be ruled out, the rules say so).
 *
 * Four hints, each against a fixed bound:
 * - `pps`: more than 5 pieces per second over the whole run;
 * - `same-tick`: more than 3 key presses in one tick (1/60 s), in at least
 *   10 ticks of a 40-line run (a longer run of a week's rules in as many more:
 *   25 for 100 lines), or in any tick of a run above 3 pieces per second.
 *   One such tick alone is no hint: a browser that drops a frame hands the
 *   keys pressed meanwhile to the next tick together, so a person's slow run
 *   shows one now and then;
 * - `timing`: the gaps between key presses hardly vary (coefficient of
 *   variation below 0.25 over at least 50 presses): a person's rhythm wobbles;
 * - `finesse`: every judged piece placed with the fewest possible presses
 *   (at least 20 judged; a short week's run at least about half its pieces,
 *   1.25 per block, and never fewer than 12). A piece is judged when its placement is clear
 *   (one piece locked in its tick, no row cleared) and reachable from the
 *   spawn on an empty well; the fewest presses come from a search over taps,
 *   auto-shift to a wall and turns, with the engine's own kicks.
 *
 * Reads the engine, never changes it; runs in the verifier (verify.mjs) after
 * the run is replayed. Numbers are rounded to two decimals so one replay
 * always gives the same answer.
 */

import { ACTION, GOAL_LINES, HEIGHT, WIDTH, createGame, fits, isOver, step } from './engine.js';
import { SHAPES, SPAWN_X, SPAWN_Y, kicksFor } from './pieces.js';

export const LIMITS = Object.freeze({
    pps: 5,
    pressesPerTick: 3,
    sameTickRepeat: 10,
    sameTickPps: 3,
    timingCv: 0.25,
    timingMinPresses: 50,
    finesseMinPieces: 20,
});

/** The fewest judged pieces a finesse hint ever needs, however short the run. */
const FINESSE_FLOOR = 12;

const MOVES = new Set([ACTION.LEFT, ACTION.RIGHT, ACTION.CW, ACTION.CCW, ACTION.FLIP]);

const round2 = (value) => Math.round(value * 100) / 100;

/** The placement key of a set of cells: their leftmost column and their shape. */
function footprint(cells) {
    const minX = Math.min(...cells.map(([x]) => x));
    const minY = Math.min(...cells.map(([, y]) => y));

    return `${minX}|${cells.map(([x, y]) => `${x - minX},${y - minY}`).sort().join(';')}`;
}

const fewestCache = new Map();

/**
 * The fewest presses that bring `piece` from its spawn to each footprint on an
 * empty well: taps (one cell), auto-shift (to the wall), quarter and half turns.
 *
 * @returns {Map<string, number>}
 */
function fewestPresses(piece) {
    if (fewestCache.has(piece)) {
        return fewestCache.get(piece);
    }
    const empty = { board: new Uint8Array(WIDTH * HEIGHT) };
    const start = { rot: 0, x: SPAWN_X[piece], y: SPAWN_Y[piece] };
    const seen = new Map([[`${start.rot},${start.x},${start.y}`, 0]]);
    const best = new Map();
    const queue = [start];
    const cellsOf = ({ rot, x, y }) => SHAPES[piece][rot].map(([dx, dy]) => [x + dx, y + dy]);
    while (queue.length > 0) {
        const state = queue.shift();
        const cost = seen.get(`${state.rot},${state.x},${state.y}`);
        const key = footprint(cellsOf(state));
        if (!best.has(key)) {
            best.set(key, cost);
        }
        const next = [];
        for (const dx of [-1, 1]) {
            if (fits(empty, piece, state.rot, state.x + dx, state.y)) {
                next.push({ ...state, x: state.x + dx });
            }
            let wall = state.x;
            while (fits(empty, piece, state.rot, wall + dx, state.y)) {
                wall += dx;
            }
            next.push({ ...state, x: wall });
        }
        for (const turn of [1, 3, 2]) {
            const to = (state.rot + turn) & 3;
            for (const [kx, ky] of kicksFor(piece, state.rot, to)) {
                if (fits(empty, piece, to, state.x + kx, state.y + ky)) {
                    next.push({ rot: to, x: state.x + kx, y: state.y + ky });
                    break;
                }
            }
        }
        for (const candidate of next) {
            const id = `${candidate.rot},${candidate.x},${candidate.y}`;
            if (!seen.has(id)) {
                seen.set(id, cost + 1);
                queue.push(candidate);
            }
        }
    }
    fewestCache.set(piece, best);

    return best;
}

/**
 * The hints of one finished run.
 *
 * @param {string} seed
 * @param {{das: number, arr: number, sdf: number}} settings
 * @param {Array<[number, number, number]>} inputs [tick, action, down], in order
 * @param {Partial<typeof LIMITS>} [bounds] other bounds than LIMITS (finite numbers only)
 * @param {string} [engine] the engine id the run was played on (engine.js ENGINES or a rules id; bf1 when not given)
 * @returns {{flags: string[], pps: number, maxPressesPerTick: number, sameTickBursts: number, timingCv: number|null, finesse: {perfect: number, of: number}}}
 */
export function hintsFor(seed, settings, inputs, bounds = {}, engine = undefined) {
    const limit = { ...LIMITS };
    for (const key of Object.keys(LIMITS)) {
        if (bounds !== null && typeof bounds === 'object' && Number.isFinite(bounds[key])) {
            limit[key] = bounds[key];
        }
    }
    const game = createGame({ seed, settings, engine });
    // a longer run has more ticks a dropped frame can bunch keys into: the bound grows with its lines
    limit.sameTickRepeat = Math.max(limit.sameTickRepeat, Math.ceil((limit.sameTickRepeat * game.goal) / GOAL_LINES));
    // a short run has fewer pieces to judge: about half of them (2.5 pieces a block), at least 12 (a person can place 12 perfectly)
    limit.finesseMinPieces = Math.min(limit.finesseMinPieces, Math.max(FINESSE_FLOOR, Math.ceil((game.goal * 5) / 4)));
    const presses = inputs.filter(([, , down]) => down === 1);

    // presses per tick, and the ticks with more than the bound
    let maxPressesPerTick = 0;
    let sameTickBursts = 0;
    for (let i = 0, j = 0; i < presses.length; i = j) {
        while (j < presses.length && presses[j][0] === presses[i][0]) {
            j++;
        }
        maxPressesPerTick = Math.max(maxPressesPerTick, j - i);
        if (j - i > limit.pressesPerTick) {
            sameTickBursts++;
        }
    }

    // rhythm: gaps between presses in different ticks
    const gaps = [];
    for (let i = 1; i < presses.length; i++) {
        if (presses[i][0] > presses[i - 1][0]) {
            gaps.push(presses[i][0] - presses[i - 1][0]);
        }
    }
    let timingCv = null;
    if (gaps.length > 0) {
        const mean = gaps.reduce((sum, gap) => sum + gap, 0) / gaps.length;
        const variance = gaps.reduce((sum, gap) => sum + (gap - mean) ** 2, 0) / gaps.length;
        timingCv = mean > 0 ? round2(Math.sqrt(variance) / mean) : 0;
    }

    // finesse: replay tick by tick and judge every clear placement
    let perfect = 0;
    let judged = 0;
    let piecePresses = 0;
    let at = 0;
    while (!isOver(game)) {
        const tickInputs = [];
        while (at < inputs.length && inputs[at][0] === game.tick) {
            tickInputs.push([inputs[at][1], inputs[at][2]]);
            at++;
        }
        const piece = game.current?.piece ?? -1;
        const board = game.board.slice();
        const pieces = game.pieces;
        const lines = game.lines;
        let carried = 0;
        let afterDrop = false;
        for (const [action, down] of tickInputs) {
            if (down !== 1) {
                continue;
            }
            if (action === ACTION.HOLD) {
                piecePresses = 0;
            } else if (action === ACTION.HARD) {
                afterDrop = true;
            } else if (MOVES.has(action)) {
                if (afterDrop) {
                    carried++;
                } else {
                    piecePresses++;
                }
            }
        }
        step(game, tickInputs);
        if (game.pieces === pieces) {
            piecePresses += carried;
            continue;
        }
        if (game.pieces === pieces + 1 && game.lines === lines && piece >= 0 && !tickInputs.some(([action, down]) => action === ACTION.HOLD && down === 1)) {
            const cells = [];
            for (let i = 0; i < board.length; i++) {
                // the board holds locked cells only: the next piece lives in game.current
                if (board[i] === 0 && game.board[i] !== 0) {
                    cells.push([i % WIDTH, Math.floor(i / WIDTH)]);
                }
            }
            const fewest = cells.length === 4 ? fewestPresses(piece).get(footprint(cells)) : undefined;
            if (fewest !== undefined) {
                judged++;
                if (piecePresses <= fewest) {
                    perfect++;
                }
            }
        }
        piecePresses = carried;
    }

    const pps = game.tick > 0 ? round2((game.pieces * 60) / game.tick) : 0;
    const flags = [];
    if (pps > limit.pps) {
        flags.push('pps');
    }
    if (sameTickBursts >= limit.sameTickRepeat || (sameTickBursts > 0 && pps > limit.sameTickPps)) {
        flags.push('same-tick');
    }
    if (timingCv !== null && presses.length >= limit.timingMinPresses && timingCv < limit.timingCv) {
        flags.push('timing');
    }
    if (judged >= limit.finesseMinPieces && perfect === judged) {
        flags.push('finesse');
    }

    return { flags, pps, maxPressesPerTick, sameTickBursts, timingCv, finesse: { perfect, of: judged } };
}
