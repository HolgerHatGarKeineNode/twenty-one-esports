/*
 * The worker of Go practice (resources/js/go/practice.js): a bot's move or the tip button's search, off the page's
 * thread, so the board stays responsive while the Whale thinks for two and a half seconds.
 */
import { botMove } from './bots.js';
import { GoBoard, search } from './engine.js';
import { adviceFor } from './tips.js';

/** How long the tip button's search runs, and how many random games at most. */
const ADVICE = { time: 1500, playouts: 30000 };

self.addEventListener('message', ({ data }) => {
    try {
        if (data.type === 'bot') {
            self.postMessage({ id: data.id, move: botMove(data.bot, data.position) });
        } else {
            const result = search(GoBoard.fromText(data.position), ADVICE);
            self.postMessage({ id: data.id, advice: adviceFor(data.position, result) });
        }
    } catch (error) {
        self.postMessage({ id: data.id, error: String(error?.message ?? error) });
    }
});
