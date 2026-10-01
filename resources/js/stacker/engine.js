/**
 * Blockfill engine: a deterministic stacking game on fixed 60 Hz integer ticks.
 *
 * The same module runs in the browser and in the Node verifier. It reads no clock,
 * no `Math.random` and no DOM, and uses integers only, so a run is fully defined by
 * its seed, its settings and its input log. Engine versions are frozen: a change to
 * any rule below ships as a new `ENGINE_VERSION`, and the old one stays in the repo.
 *
 * One tick, in this order:
 * 1. the inputs of the tick, in log order (moves, rotations, hard drop, hold);
 * 2. auto-shift of a held left/right key (DAS, then ARR);
 * 3. gravity, or soft drop while held (SDF);
 * 4. lock delay of a grounded piece; a lock clears lines and spawns the next piece
 *    in the same tick (no entry delay, no line-clear delay).
 *
 * Board: 10 columns, 20 visible rows plus 4 hidden rows on top; row 0 is the top.
 */

import { createRng } from './prng.js';
import { SHAPES, SPAWN_X, SPAWN_Y, kicksFor, nextBag } from './pieces.js';

/** Frozen engine id; a replay names the engine it was played on. */
export const ENGINE_VERSION = 'bf1';

export const WIDTH = 10;
export const VISIBLE_ROWS = 20;
export const HIDDEN_ROWS = 4;
export const HEIGHT = VISIBLE_ROWS + HIDDEN_ROWS;

/** Lines that finish a run (the "40 lines" mode). */
export const GOAL_LINES = 40;

/** Pieces shown in the next queue. */
export const NEXT_COUNT = 5;

/** Ticks a grounded piece waits before it locks. */
export const LOCK_DELAY = 30;

/** Moves or turns on the ground that restart the lock delay, per piece and lowest row. */
export const MAX_LOCK_RESETS = 15;

/** One cell in gravity fixed-point units. */
export const CELL = 65536;

/** Gravity per tick: 1092 of 65536, about one cell per 60 ticks (one per second), all run long. */
export const GRAVITY = 1092;

/** Soft drop per tick and SDF step: 3277 of 65536, so SDF 20 drops one cell per tick. */
export const SOFT_DROP_STEP = 3277;

/** SDF value that drops a soft-dropped piece to the floor within the tick. */
export const SDF_INSTANT = 41;

/** Hard upper bound on the ticks of one run (10 minutes). */
export const MAX_TICKS = 36000;

/** Input actions; the index is the 3-bit action code of the replay format. */
export const ACTIONS = Object.freeze(['left', 'right', 'soft', 'hard', 'cw', 'ccw', 'flip', 'hold']);

export const ACTION = Object.freeze({ LEFT: 0, RIGHT: 1, SOFT: 2, HARD: 3, CW: 4, CCW: 5, FLIP: 6, HOLD: 7 });

/** Inclusive bounds of the player settings, in ticks (SDF: multiple of the soft drop step). */
export const SETTING_LIMITS = Object.freeze({
    das: Object.freeze([1, 20]),
    arr: Object.freeze([0, 5]),
    sdf: Object.freeze([5, 41]),
});

export const DEFAULT_SETTINGS = Object.freeze({ das: 10, arr: 2, sdf: 20 });

/**
 * @typedef {{das: number, arr: number, sdf: number}} Settings
 * @typedef {{piece: number, rot: number, x: number, y: number}} ActivePiece
 * @typedef {[number, number]} TickInput [action, down (1) or up (0)]
 * @typedef {[number, number, number]} LoggedInput [tick, action, down (1) or up (0)]
 * @typedef {{ticks: number, lines: number, pieces: number, finished: boolean, toppedOut: boolean, stateHash: string}} RunResult
 */

/**
 * Settings checked against SETTING_LIMITS; anything else throws.
 *
 * @param {Partial<Settings>|undefined} settings
 * @returns {Readonly<Settings>}
 */
export function normalizeSettings(settings) {
    const source = settings ?? DEFAULT_SETTINGS;
    const out = {};
    for (const key of ['das', 'arr', 'sdf']) {
        const value = source[key];
        const [low, high] = SETTING_LIMITS[key];
        if (!Number.isInteger(value) || value < low || value > high) {
            throw new RangeError(`setting ${key} must be an integer from ${low} to ${high}`);
        }
        out[key] = value;
    }

    return Object.freeze(out);
}

/**
 * A new game at tick 0 with the first piece spawned.
 *
 * @param {{seed: string, settings?: Partial<Settings>}} options
 */
export function createGame({ seed, settings }) {
    const game = {
        engine: ENGINE_VERSION,
        seed,
        settings: normalizeSettings(settings),
        rng: createRng(seed),
        queue: [],
        board: new Uint8Array(WIDTH * HEIGHT),
        /** @type {ActivePiece|null} */
        current: null,
        hold: -1,
        holdUsed: false,
        held: new Uint8Array(ACTIONS.length),
        shiftDir: 0,
        shiftFresh: false,
        dasTicks: 0,
        arrTicks: 0,
        gravity: 0,
        lockTicks: 0,
        lockResets: 0,
        lowestY: 0,
        tick: 0,
        lines: 0,
        pieces: 0,
        finished: false,
        toppedOut: false,
    };
    spawn(game, takeNext(game));

    return game;
}

/** True once the run is finished or topped out; `step` does nothing afterwards. */
export function isOver(game) {
    return game.finished || game.toppedOut;
}

/**
 * Whether `piece` in state `rot` with its box at (x, y) lies on the board without overlap.
 *
 * @returns {boolean}
 */
export function fits(game, piece, rot, x, y) {
    for (const [dx, dy] of SHAPES[piece][rot]) {
        const cx = x + dx;
        const cy = y + dy;
        if (cx < 0 || cx >= WIDTH || cy < 0 || cy >= HEIGHT || game.board[cy * WIDTH + cx] !== 0) {
            return false;
        }
    }

    return true;
}

/**
 * Board cells covered by the active piece.
 *
 * @returns {number[][]} [x, y] pairs
 */
export function activeCells(game) {
    const c = game.current;
    if (c === null) {
        return [];
    }

    return SHAPES[c.piece][c.rot].map(([dx, dy]) => [c.x + dx, c.y + dy]);
}

/** Row the active piece would land on with a hard drop (the ghost). */
export function dropY(game) {
    const c = game.current;
    let y = c.y;
    while (fits(game, c.piece, c.rot, c.x, y + 1)) {
        y++;
    }

    return y;
}

/** The pieces shown in the next queue. */
export function nextPieces(game) {
    return game.queue.slice(0, NEXT_COUNT);
}

function takeNext(game) {
    if (game.queue.length <= NEXT_COUNT) {
        game.queue.push(...nextBag(game.rng));
    }

    return game.queue.shift();
}

function spawn(game, piece) {
    game.current = { piece, rot: 0, x: SPAWN_X[piece], y: SPAWN_Y[piece] };
    game.gravity = 0;
    game.lockTicks = 0;
    game.lockResets = 0;
    game.lowestY = game.current.y;
    if (!fits(game, piece, 0, game.current.x, game.current.y)) {
        game.toppedOut = true;
    }
}

function isGrounded(game) {
    const c = game.current;

    return !fits(game, c.piece, c.rot, c.x, c.y + 1);
}

/** A new lowest row gives the piece a fresh lock delay and fresh resets. */
function noteDescent(game) {
    if (game.current.y > game.lowestY) {
        game.lowestY = game.current.y;
        game.lockTicks = 0;
        game.lockResets = 0;
    }
}

/** A move or turn on the ground restarts the lock delay while resets are left. */
function moveReset(game, wasGrounded) {
    if ((wasGrounded || isGrounded(game)) && game.lockResets < MAX_LOCK_RESETS) {
        game.lockResets++;
        game.lockTicks = 0;
    }
}

function shift(game, dx) {
    const c = game.current;
    if (!fits(game, c.piece, c.rot, c.x + dx, c.y)) {
        return false;
    }
    const wasGrounded = isGrounded(game);
    c.x += dx;
    moveReset(game, wasGrounded);

    return true;
}

function rotate(game, turn) {
    const c = game.current;
    const to = (c.rot + turn) & 3;
    for (const [dx, dy] of kicksFor(c.piece, c.rot, to)) {
        if (fits(game, c.piece, to, c.x + dx, c.y + dy)) {
            const wasGrounded = isGrounded(game);
            c.rot = to;
            c.x += dx;
            c.y += dy;
            noteDescent(game);
            moveReset(game, wasGrounded);

            return true;
        }
    }

    return false;
}

function holdPiece(game) {
    if (game.holdUsed) {
        return;
    }
    const piece = game.current.piece;
    const next = game.hold < 0 ? takeNext(game) : game.hold;
    game.hold = piece;
    spawn(game, next);
    game.holdUsed = true;
}

function hardDrop(game) {
    game.current.y = dropY(game);
    lock(game);
}

function lock(game) {
    let aboveVisible = true;
    for (const [x, y] of activeCells(game)) {
        game.board[y * WIDTH + x] = game.current.piece + 1;
        if (y >= HIDDEN_ROWS) {
            aboveVisible = false;
        }
    }
    game.current = null;
    game.pieces++;
    game.holdUsed = false;
    if (aboveVisible) {
        game.toppedOut = true;

        return;
    }

    game.lines += clearLines(game.board);
    if (game.lines >= GOAL_LINES) {
        game.finished = true;

        return;
    }
    spawn(game, takeNext(game));
}

/** Removes full rows, moves the rest down; returns the number removed. */
function clearLines(board) {
    let write = HEIGHT - 1;
    for (let read = HEIGHT - 1; read >= 0; read--) {
        let full = true;
        for (let x = 0; x < WIDTH; x++) {
            if (board[read * WIDTH + x] === 0) {
                full = false;
                break;
            }
        }
        if (full) {
            continue;
        }
        if (write !== read) {
            board.copyWithin(write * WIDTH, read * WIDTH, read * WIDTH + WIDTH);
        }
        write--;
    }
    const cleared = write + 1;
    board.fill(0, 0, cleared * WIDTH);

    return cleared;
}

/** A well-formed tick input: [action 0..7, down 0 or 1]. */
export function isTickInput(input) {
    return Array.isArray(input)
        && input.length === 2
        && Number.isInteger(input[0]) && input[0] >= 0 && input[0] < ACTIONS.length
        && (input[1] === 0 || input[1] === 1);
}

function applyInput(game, action, down) {
    if ((game.held[action] === 1) === (down === 1)) {
        return;
    }
    game.held[action] = down;

    if (action === ACTION.LEFT || action === ACTION.RIGHT) {
        const dir = action === ACTION.LEFT ? -1 : 1;
        if (down === 1) {
            game.shiftDir = dir;
            game.dasTicks = 0;
            game.arrTicks = 0;
            game.shiftFresh = true;
            shift(game, dir);
        } else if (game.shiftDir === dir) {
            const other = action === ACTION.LEFT ? ACTION.RIGHT : ACTION.LEFT;
            game.shiftDir = game.held[other] === 1 ? -dir : 0;
            game.dasTicks = 0;
            game.arrTicks = 0;
            game.shiftFresh = game.shiftDir !== 0;
        }

        return;
    }
    if (down === 0) {
        return;
    }
    if (action === ACTION.HARD) {
        hardDrop(game);
    } else if (action === ACTION.CW) {
        rotate(game, 1);
    } else if (action === ACTION.CCW) {
        rotate(game, 3);
    } else if (action === ACTION.FLIP) {
        rotate(game, 2);
    } else if (action === ACTION.HOLD) {
        holdPiece(game);
    }
}

function autoMove(game) {
    if (game.settings.arr === 0) {
        while (shift(game, game.shiftDir)) {
            // to the wall within the tick
        }
    } else {
        shift(game, game.shiftDir);
    }
}

function autoShift(game) {
    if (game.shiftDir === 0) {
        return;
    }
    if (game.shiftFresh) {
        game.shiftFresh = false;

        return;
    }
    const { das, arr } = game.settings;
    if (game.dasTicks < das) {
        game.dasTicks++;
        if (game.dasTicks === das) {
            game.arrTicks = 0;
            autoMove(game);
        }

        return;
    }
    game.arrTicks++;
    if (game.arrTicks >= arr) {
        game.arrTicks = 0;
        autoMove(game);
    }
}

function fall(game) {
    const c = game.current;
    const soft = game.held[ACTION.SOFT] === 1;
    const sdf = game.settings.sdf;
    if (soft && sdf === SDF_INSTANT) {
        c.y = dropY(game);
        game.gravity = 0;
        noteDescent(game);

        return;
    }
    const softStep = SOFT_DROP_STEP * sdf;
    game.gravity += soft && softStep > GRAVITY ? softStep : GRAVITY;
    while (game.gravity >= CELL) {
        if (!fits(game, c.piece, c.rot, c.x, c.y + 1)) {
            game.gravity = 0;
            break;
        }
        game.gravity -= CELL;
        c.y++;
        noteDescent(game);
    }
}

function lockDelay(game) {
    if (!isGrounded(game)) {
        return;
    }
    game.lockTicks++;
    if (game.lockTicks >= LOCK_DELAY) {
        lock(game);
    }
}

/**
 * Advances the game by one tick with the inputs of that tick. Malformed inputs are
 * skipped, a press of a key that is already down and a release of a key that is up
 * change nothing. A finished or topped-out game is left as it is.
 *
 * @param {ReturnType<typeof createGame>} game mutated in place
 * @param {TickInput[]} [inputs]
 */
export function step(game, inputs = []) {
    if (isOver(game)) {
        return game;
    }
    if (Array.isArray(inputs)) {
        for (const input of inputs) {
            if (isOver(game)) {
                break;
            }
            if (isTickInput(input)) {
                applyInput(game, input[0], input[1]);
            }
        }
    }
    if (!isOver(game)) {
        autoShift(game);
        fall(game);
        lockDelay(game);
    }
    game.tick++;

    return game;
}

/**
 * The input log checked strictly: [tick, action, down] entries, ticks whole,
 * non-negative and in order. A log that fails is rejected as a whole.
 *
 * @param {unknown} log
 * @returns {LoggedInput[]}
 */
export function validateLog(log) {
    if (!Array.isArray(log)) {
        throw new TypeError('input log must be an array');
    }
    let previous = 0;
    log.forEach((entry, index) => {
        if (!Array.isArray(entry) || entry.length !== 3 || !isTickInput([entry[1], entry[2]])) {
            throw new TypeError(`input ${index} is not [tick, action 0-7, down 0/1]`);
        }
        const tick = entry[0];
        if (!Number.isInteger(tick) || tick < 0 || tick < previous) {
            throw new RangeError(`input ${index} has tick ${tick}, expected a whole tick from ${previous} on`);
        }
        previous = tick;
    });

    return log;
}

/** FNV-1a (32-bit) over board, next queue, hold, active piece and counters. */
export function stateHash(game) {
    let hash = 0x811c9dc5;
    const byte = (value) => {
        hash = Math.imul(hash ^ (value & 0xff), 0x01000193) >>> 0;
    };
    const word = (value) => {
        byte(value);
        byte(value >>> 8);
        byte(value >>> 16);
        byte(value >>> 24);
    };

    for (let i = 0; i < game.board.length; i++) {
        byte(game.board[i]);
    }
    for (const piece of nextPieces(game)) {
        byte(piece + 1);
    }
    byte(game.hold + 1);
    const c = game.current;
    if (c === null) {
        word(0);
    } else {
        byte(c.piece + 1);
        byte(c.rot);
        byte(c.x);
        byte(c.y);
    }
    word(game.tick);
    word(game.lines);
    word(game.pieces);
    byte((game.finished ? 1 : 0) | (game.toppedOut ? 2 : 0));

    return hash.toString(16).padStart(8, '0');
}

/** @returns {RunResult} */
export function result(game) {
    return {
        ticks: game.tick,
        lines: game.lines,
        pieces: game.pieces,
        finished: game.finished,
        toppedOut: game.toppedOut,
        stateHash: stateHash(game),
    };
}

/**
 * Replays a whole run: from tick 0 until it finishes, tops out or reaches
 * `maxTicks`. Inputs after the end are not applied.
 *
 * @param {string} seed
 * @param {Partial<Settings>} settings
 * @param {LoggedInput[]} inputLog
 * @param {{maxTicks?: number}} [options]
 * @returns {RunResult}
 */
export function run(seed, settings, inputLog, { maxTicks = MAX_TICKS } = {}) {
    if (!Number.isInteger(maxTicks) || maxTicks < 1 || maxTicks > MAX_TICKS) {
        throw new RangeError(`maxTicks must be a whole number from 1 to ${MAX_TICKS}`);
    }
    const log = validateLog(inputLog);
    const game = createGame({ seed, settings });
    let next = 0;
    while (!isOver(game) && game.tick < maxTicks) {
        const inputs = [];
        while (next < log.length && log[next][0] === game.tick) {
            inputs.push([log[next][1], log[next][2]]);
            next++;
        }
        step(game, inputs);
    }

    return result(game);
}
