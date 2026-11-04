/*
 * The bots of the Blockspace practice in a worker, so the board keeps answering while the Whale thinks. One message in,
 * one out, matched by `id`: `{ id, position, level }` answers `{ id, move }`, the move in the league's text.
 */
import { moveText, rng, stateOf, think } from './engine.js';

self.onmessage = ({ data }) => {
    try {
        const move = think(stateOf(data.position), data.level, rng(data.id * 2654435761), 900);
        self.postMessage({ id: data.id, move: move ? moveText(move.i, move.cells) : 'pass' });
    } catch (error) {
        self.postMessage({ id: data.id, error: String(error?.message ?? error) });
    }
};
