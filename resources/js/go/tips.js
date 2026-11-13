/*
 * The tips of Go practice (DerCaddy, 2026-10-27, as in Blockli's practice: small tips by themselves, and a tip button
 * that names the kind of move first and then roughly where, never the move itself).
 *
 * autoTip() looks at the move just made and says something when it matters: stones of the player's taken or in
 * atari, a stone of the bot's to take, an atari the player walked into, an early stone on the edge, the bot's pass.
 * Each kind comes up at most twice a game (`shown`). adviceFor() turns a search into the tip button's words.
 */
import { BLACK, GoBoard, PASS } from './engine.js';

/** How often each automatic tip may come up in one game. */
const LIMIT = 2;

/** The biggest chain of `color` in atari (head and size), or null. */
function biggestInAtari(board, color) {
    let best = null;
    for (const p of board.points) {
        if (board.color[p] !== color || board.head[p] !== p || !board.inAtari(p)) continue;
        if (!best || board.stones[p] > best.size) best = { head: p, size: board.stones[p] };
    }

    return best;
}

/** The line from the edge (1 on the edge). */
function line(board, p) {
    const row = Math.floor(p / board.w);
    const file = p % board.w;

    return Math.min(row, file, board.size + 1 - row, board.size + 1 - file);
}

/**
 * A tip by itself after a move, or null: `before` and `after` are the boards around the move (`move` its name), `by`
 * who made it ('me' or 'bot'), `me` the player's colour, `shown` how often each tip came up already.
 *
 * @returns {{key: string, count?: number, point?: string}|null}
 */
export function autoTip({ before, after, move, by, me, shown = {} }) {
    const fresh = (key) => (shown[key] ?? 0) < LIMIT;
    const bot = 3 - me;
    if (by === 'bot') {
        if (move === 'pass') return fresh('botPassed') ? { key: 'botPassed' } : null;
        const lost = before.count().stones[me] - after.count().stones[me];
        if (lost > 0 && after.color[after.pointOf(move)] === bot && fresh('captured')) return { key: 'captured', count: lost };
        const danger = biggestInAtari(after, me);
        if (danger && fresh('atari')) return { key: 'atari', count: danger.size, point: after.idOf(danger.head).toUpperCase() };
        const prey = biggestInAtari(after, bot);
        if (prey && after.legal(after.atariPoint(prey.head)) && fresh('canTake')) return { key: 'canTake', count: prey.size };

        return null;
    }
    if (move === 'pass') return null;
    const p = after.pointOf(move);
    if (after.color[p] === me && after.stones[after.head[p]] > 1 && after.inAtari(after.head[p]) && fresh('selfAtari')) return { key: 'selfAtari', count: after.stones[after.head[p]] };
    if (before.plies < 10 && before.size >= 9 && line(after, p) === 1 && after.lastCaptured === 0 && fresh('firstLine')) return { key: 'firstLine' };

    return null;
}

/** Where on the board a point lies, in nine parts: the corners, the sides and the centre. */
export function areaOf(board, id) {
    const p = board.pointOf(id);
    if (p < 0) return null;
    const third = board.size / 3;
    const row = Math.floor(p / board.w) - 1;
    const file = (p % board.w) - 1;
    const vertical = row < third ? 'upper' : row >= board.size - third ? 'lower' : 'middle';
    const horizontal = file < third ? 'left' : file >= board.size - third ? 'right' : 'centre';
    const names = {
        'upper left': 'upperLeft', 'upper centre': 'upperSide', 'upper right': 'upperRight',
        'middle left': 'leftSide', 'middle centre': 'centre', 'middle right': 'rightSide',
        'lower left': 'lowerLeft', 'lower centre': 'lowerSide', 'lower right': 'lowerRight',
    };

    return names[vertical + ' ' + horizontal];
}

/**
 * The tip button's advice from a search of the position: what kind of move fits (take, save, atari, pass, or simply
 * the biggest place) and where roughly (`area`, one of nine parts of the board), never the point.
 *
 * @param {string} text the position
 * @param {{move: string, winrate: number}} result the search's answer
 */
export function adviceFor(text, result) {
    const board = GoBoard.fromText(text);
    const me = board.toMove;
    if (result.move === 'pass') return { kind: 'pass', area: null, winrate: result.winrate };
    const p = board.pointOf(result.move);
    const area = areaOf(board, result.move);
    const own = biggestInAtari(board, me);
    if (own && board.atariPoint(own.head) === p) return { kind: 'save', area, winrate: result.winrate };
    const copy = board.clone();
    const captured = copy.play(p);
    if (captured > 0) return { kind: 'capture', area, count: captured, winrate: result.winrate };
    // An atari: a chain of the other colour beside the stone has one liberty left.
    for (const d of copy.dirs) {
        const q = p + d;
        if (copy.color[q] === 3 - me && copy.inAtari(copy.head[q])) return { kind: 'atari', area, winrate: result.winrate };
    }

    return { kind: 'big', area, winrate: result.winrate };
}

export { BLACK, PASS };
