/*
 * The worker of Blockli practice (practice.js): the bots' moves and the tip button's analysis, off the page's thread,
 * so the board keeps answering while the Whale searches. One message in, one out, matched by `id`:
 * `{ id, type: 'bot', bot, position, start, history }` answers `{ id, move }` (the server's notation, e.g. `e1-e2`);
 * `{ id, type: 'advice', position, start, history, time }` answers `{ id, advice }` (tips.js adviceFor). Positions are
 * the server's text, `history` the game's moves since `start` as the server wrote them.
 */
import { botMove, engineMove, serverMove, stateOf, Blockli } from './bots.js';
import { adviceFor } from './tips.js';

function game(data) {
    const state = stateOf(data.position);
    if (!state) throw new Error('No Blockli position: ' + data.position);
    const start = data.start ? stateOf(data.start) : null;
    const history = (data.history ?? []).map(engineMove).filter((move) => move >= 0);

    return { state, start, history };
}

self.onmessage = ({ data }) => {
    try {
        const { state, start, history } = game(data);
        if (data.type === 'bot') {
            self.postMessage({ id: data.id, move: serverMove(state, botMove(data.bot, state, { start, history })) });
        } else if (data.type === 'advice') {
            const analysis = Blockli.analyze(state, { start, history, time: data.time ?? 1000 });
            self.postMessage({ id: data.id, advice: adviceFor(state, analysis) });
        } else {
            self.postMessage({ id: data.id, error: 'Unknown request: ' + data.type });
        }
    } catch (error) {
        self.postMessage({ id: data.id, error: String(error?.message ?? error) });
    }
};
