/**
 * Blockfill replay player (plan "Blockfill", P5): a stored run as a game you
 * can stand at any tick. Pure: no DOM, no clock; the viewer page
 * (replay-page.js) draws what it returns with the game's own renderer.
 *
 * On creation the run is played once to its end on the engine, keeping a
 * copy of the game every `checkpointEvery` ticks and the tick of every
 * cleared row (the 40 blocks of the chain). seek(t) starts from the last
 * copy at or before t and steps on with the logged inputs, so the state at t
 * is exactly the one playing from tick 0 reaches (tests/js/stacker/
 * replay-player.test.mjs). advance() plays on from where the player stands.
 *
 * "The game at tick t" is the game after t ticks were played (game.tick === t):
 * tick 0 is the start, `total` the finished run.
 */

import { createGame, isOver, result, step } from './engine.js';
import { decodeReplay } from './replay.js';

/** structuredClone where there is one (browsers, Node 17+); the game is typed arrays and plain data. */
const clone = (game) => structuredClone(game);

/**
 * @param {string} text the base64url replay
 * @param {{checkpointEvery?: number}} [options]
 */
export function createReplayPlayer(text, { checkpointEvery = 300 } = {}) {
    const { header, inputs } = decodeReplay(text);
    /** @type {Map<number, Array<[number, number]>>} the inputs of each tick */
    const byTick = new Map();
    for (const [tick, action, down] of inputs) {
        if (!byTick.has(tick)) {
            byTick.set(tick, []);
        }
        byTick.get(tick).push([action, down]);
    }

    // the engine id of its header: a week's difficulty replays on its own gravity
    const fresh = () => createGame({ seed: header.seed, settings: header.settings, engine: header.engine });
    const play = (game) => step(game, byTick.get(game.tick) ?? []);

    // one pass to the end: checkpoints, the cleared rows, the result
    const checkpoints = [clone(fresh())];
    /** @type {Array<{tick: number, lines: number, count: number}>} one entry per lock that cleared rows */
    const clears = [];
    const game = fresh();
    while (!isOver(game)) {
        const before = game.lines;
        play(game);
        if (game.lines > before) {
            clears.push({ tick: game.tick, lines: game.lines, count: game.lines - before });
        }
        if (game.tick % checkpointEvery === 0) {
            checkpoints.push(clone(game));
        }
    }
    const total = game.tick;
    const final = result(game);

    /** The tick each block (row 1..lines) was mined at; blockTicks[n - 1] for block n. */
    const blockTicks = [];
    for (const clear of clears) {
        for (let i = 0; i < clear.count; i++) {
            blockTicks.push(clear.tick);
        }
    }

    let current = clone(checkpoints[0]);

    return {
        header,
        inputs,
        total,
        result: final,
        clears,
        blockTicks,

        /** The game where the player stands (read it, do not change it). */
        get game() {
            return current;
        },

        /** Stands at tick `tick` (clamped to 0..total) and returns that game. */
        seek(tick) {
            const target = Math.max(0, Math.min(total, Math.floor(Number(tick) || 0)));
            if (target < current.tick || target - current.tick > checkpointEvery) {
                const index = Math.min(checkpoints.length - 1, Math.floor(target / checkpointEvery));
                current = clone(checkpoints[index]);
            }
            while (current.tick < target && !isOver(current)) {
                play(current);
            }

            return current;
        },

        /** Plays `ticks` more ticks (stops at the end); returns the rows cleared on the way. */
        advance(ticks = 1) {
            const before = current.lines;
            for (let i = 0; i < ticks && current.tick < total && !isOver(current); i++) {
                play(current);
            }

            return current.lines - before;
        },

        /** The inputs held down at the current tick, by action (0..7). */
        held() {
            return Array.from(current.held);
        },
    };
}
