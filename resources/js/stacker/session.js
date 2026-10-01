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

import { ACTIONS, MAX_TICKS, createGame, isOver, result, step } from './engine.js';

/**
 * @param {{seed: string, settings: {das: number, arr: number, sdf: number}}} options
 */
export function createSession({ seed, settings }) {
    const game = createGame({ seed, settings });
    /** @type {Array<[number, number, number]>} */
    const log = [];
    const down = new Set();
    let queued = [];

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
         * Plays one tick with the queued inputs.
         *
         * @returns {{cleared: number, locked: number}|null} null once the game is over
         */
        tick() {
            if (isOver(game)) {
                return null;
            }
            const lines = game.lines;
            const pieces = game.pieces;
            const inputs = queued;
            queued = [];
            for (const [code, state] of inputs) {
                log.push([game.tick, code, state]);
            }
            step(game, inputs);

            return { cleared: game.lines - lines, locked: game.pieces - pieces };
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
