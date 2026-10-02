/**
 * One Blockfill game as the page plays it: the engine plus the input log.
 *
 * Key events are queued as they arrive and applied at the next tick, in
 * arrival order; every input that changes a key's state is logged with the
 * tick it was applied in, so the log replays to the same game on the server
 * (run() in engine.js). A press of a key already down or a release of a key
 * already up is dropped here, which keeps the log short. No clock in here:
 * the page's ticker decides when a tick happens.
 */

import { ACTION, ACTIONS, GOAL_LINES, HEIGHT, MAX_TICKS, activeCells, createGame, fits, isOver, result, step } from './engine.js';
import { kicksFor } from './pieces.js';

const HARD = ACTION.HARD;

/** Rows left at which the game warns once that the end is near. */
export const WARNING_ROWS = 10;

/** The turn of each rotate action (quarter turns clockwise). */
const TURNS = { [ACTION.CW]: 1, [ACTION.CCW]: 3, [ACTION.FLIP]: 2 };

/**
 * Which presses of this tick will do nothing, read before the tick with the
 * engine's own fits() and kicks: a move into a wall or the stack, a turn with
 * every kick blocked, a second hold for the same piece. The inputs are
 * followed in order on a copy of the piece; a hard drop or a hold ends it
 * (the piece after that is another one).
 */
function refusedPresses(game, inputs) {
    if (game.current === null) {
        return 0;
    }
    const c = { ...game.current };
    let refused = 0;
    for (const [code, down] of inputs) {
        if (down !== 1) {
            continue;
        }
        if (code === ACTION.LEFT || code === ACTION.RIGHT) {
            const dx = code === ACTION.LEFT ? -1 : 1;
            if (fits(game, c.piece, c.rot, c.x + dx, c.y)) {
                c.x += dx;
            } else {
                refused++;
            }
        } else if (TURNS[code] !== undefined) {
            const to = (c.rot + TURNS[code]) & 3;
            const kick = kicksFor(c.piece, c.rot, to).find(([dx, dy]) => fits(game, c.piece, to, c.x + dx, c.y + dy));
            if (kick) {
                c.rot = to;
                c.x += kick[0];
                c.y += kick[1];
            } else {
                refused++;
            }
        } else if (code === ACTION.HOLD) {
            if (game.holdUsed) {
                refused++;
            }
            break;
        } else if (code === HARD) {
            break;
        }
    }

    return refused;
}

/** Where a piece is, for the sound: its middle column 0-9 and the rows between its lowest cell and the floor (0 on the floor; resting, the height of the stack under it). */
function whereOf(game) {
    const cells = activeCells(game);
    if (cells.length === 0) {
        return { column: 4.5, stack: 0 };
    }
    const column = cells.reduce((sum, [x]) => sum + x, 0) / cells.length;
    const lowest = Math.max(...cells.map(([, y]) => y));

    return { column, stack: HEIGHT - 1 - lowest };
}

function isResting(game) {
    const c = game.current;

    return c !== null && !fits(game, c.piece, c.rot, c.x, c.y + 1);
}

/**
 * @param {{seed: string, settings: {das: number, arr: number, sdf: number}, engine?: string}} options `engine`: the week's difficulty (engine.js ENGINES; bf1 when not given)
 */
export function createSession({ seed, settings, engine }) {
    const game = createGame({ seed, settings, engine });
    /** @type {Array<[number, number, number]>} */
    const log = [];
    const down = new Set();
    let queued = [];
    /** The piece now falling (counts locks and holds), the one that last touched down, and the run of clearing pieces. */
    let serial = 0;
    let rested = -1;
    let combo = 0;

    return {
        game,
        log,

        /** Queues a press or release of an action by name ("left", "hard", ...). */
        press(action, isDown) {
            const code = ACTIONS.indexOf(action);
            if (code < 0 || isOver(game) || down.has(code) === isDown) {
                return;
            }
            if (isDown) {
                down.add(code);
            } else {
                down.delete(code);
            }
            queued.push([code, isDown ? 1 : 0]);
        },

        /** Releases every key still down (the window lost focus). */
        releaseAll() {
            for (const code of [...down]) {
                this.press(ACTIONS[code], false);
            }
        },

        /**
         * Plays one tick with the queued inputs. Besides the cleared rows and
         * locked pieces it says what the player saw and heard happen, read
         * from the state before and after the tick (for the sound; the engine
         * knows nothing of it):
         * - moved / rotated (turn 1 cw, 3 ccw, 2 half): a press did it;
         *   repeat: the held key's auto-shift moved the piece;
         * - blocked: a press that changed nothing (a wall, the stack, every
         *   kick taken, a second hold); auto-shift against a wall is not one;
         * - soft: rows the piece went down under a held soft drop;
         * - touchdown: the falling piece rests for the first time (once per
         *   piece; a hard drop lands and locks at once and is not one);
         * - dropped, held; combo: the how-many-th clearing piece in a row
         *   (0 when this tick cleared nothing); warning: the clear that
         *   reached WARNING_ROWS left, once a game;
         * - where: column and stack height of the piece that acted.
         *
         * @returns {{cleared: number, locked: number, moved: boolean, repeat: boolean, rotated: boolean, turn: number, blocked: boolean, dropped: boolean, held: boolean, soft: number, touchdown: boolean, combo: number, warning: boolean, where: {column: number, stack: number}}|null} null once the game is over
         */
        tick() {
            if (isOver(game)) {
                return null;
            }
            const lines = game.lines;
            const pieces = game.pieces;
            const hold = game.hold;
            const before = game.current ? { piece: game.current.piece, x: game.current.x, y: game.current.y, rot: game.current.rot } : null;
            const beforeWhere = whereOf(game);
            const inputs = queued;
            queued = [];
            for (const [code, state] of inputs) {
                log.push([game.tick, code, state]);
            }
            const refused = refusedPresses(game, inputs);
            const pressedMove = inputs.some(([code, state]) => state === 1 && (code === ACTION.LEFT || code === ACTION.RIGHT));
            step(game, inputs);

            const locked = game.pieces - pieces;
            const held = game.hold !== hold;
            const cleared = game.lines - lines;
            // the same piece still falling: compare where it is now with where it was
            const same = locked === 0 && !held && before !== null && game.current !== null && game.current.piece === before.piece;
            const rotated = same && game.current.rot !== before.rot;
            const shifted = same && !rotated && game.current.x !== before.x;
            const moved = shifted && pressedMove;
            const soft = same && game.held[ACTION.SOFT] === 1 ? Math.max(0, game.current.y - before.y) : 0;

            if (locked > 0 || held) {
                serial++;
            }
            if (locked > 0) {
                combo = cleared > 0 ? combo + 1 : 0;
            }
            const touchdown = !isOver(game) && rested !== serial && isResting(game) && !(locked > 0 && game.current === null);
            if (touchdown) {
                rested = serial;
            }
            const left = GOAL_LINES - WARNING_ROWS;

            return {
                cleared,
                locked,
                moved,
                repeat: shifted && !pressedMove,
                rotated,
                turn: rotated ? (game.current.rot - before.rot) & 3 : 0,
                blocked: refused > 0 && !moved && !rotated && !held,
                dropped: locked > 0 && inputs.some(([code, state]) => code === HARD && state === 1),
                held,
                soft,
                touchdown,
                combo: cleared > 0 ? combo : 0,
                warning: lines < left && game.lines >= left,
                where: locked > 0 ? beforeWhere : whereOf(game),
            };
        },

        /**
         * Plays a whole recorded log at once (the test hook): each input in its
         * tick, until the game is over or MAX_TICKS.
         *
         * @param {Array<[number, number, number]>} inputs
         */
        play(inputs) {
            let next = 0;
            while (!isOver(game) && game.tick < MAX_TICKS) {
                while (next < inputs.length && inputs[next][0] === game.tick) {
                    this.press(ACTIONS[inputs[next][1]], inputs[next][2] === 1);
                    next++;
                }
                this.tick();
            }

            return result(game);
        },

        over() {
            return isOver(game);
        },

        result() {
            return result(game);
        },
    };
}
