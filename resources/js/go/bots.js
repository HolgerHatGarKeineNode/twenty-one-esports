/*
 * The three bots of Go practice (DerCaddy, 2026-10-27), named as Blockli's are, each with a character:
 * - the Hodler never lets a stone go: he takes whatever stands in atari and saves his own, and otherwise plays where
 *   it looks reasonable (never into his own eye, never into an atari of his own, early on not on the edge). No search.
 * - the Miner digs: a short Monte Carlo search (a few thousand random games a move).
 * - the Whale thinks long: up to forty thousand random games or two and a half seconds a move.
 * Each answers with a point name or `pass`, as the server names moves (GoRules); the server still judges every move.
 */
import { GoBoard, PASS, search, rng } from './engine.js';

export const BOTS = {
    hodler: { think: 600 },
    miner: { think: 700, playouts: 2500 },
    whale: { think: 900, playouts: 40000, time: 2500 },
};

/** The lines from the edge (1 on the edge). */
function line(board, p) {
    const row = Math.floor(p / board.w);
    const file = p % board.w;

    return Math.min(row, file, board.size + 1 - row, board.size + 1 - file);
}

/** A chain's liberties after a stone of the side to move goes on `p` (counted in a copy). */
function libertiesAfter(board, p) {
    const copy = board.clone();
    copy.play(p);

    return copy.liberties(p).length;
}

/**
 * The Hodler's move: pass when the other side passed and he leads on the count; else the biggest chain he can take,
 * else the biggest chain of his own in atari that running saves, else a reasonable point at random, else pass.
 */
export function hodlerMove(board, random = rng()) {
    const c = board.toMove;
    const o = 3 - c;
    if (board.passes === 1) {
        const count = board.count();
        if ((c === 1 ? count.margin : -count.margin) > 0) return 'pass';
    }
    let take = null;
    let takeSize = 0;
    let save = null;
    let saveSize = 0;
    for (const p of board.points) {
        if (board.head[p] !== p || !board.inAtari(p)) continue;
        const at = board.atariPoint(p);
        if (!board.legal(at)) continue;
        if (board.color[p] === o && board.stones[p] > takeSize) [take, takeSize] = [at, board.stones[p]];
        if (board.color[p] === c && board.stones[p] > saveSize && libertiesAfter(board, at) >= 2) [save, saveSize] = [at, board.stones[p]];
    }
    if (take) return board.idOf(take);
    if (save) return board.idOf(save);
    const opening = board.plies < 12;
    const candidates = board.points.filter((p) => board.legal(p) && !board.eyeOf(p, c) && libertiesAfter(board, p) >= 2 && (!opening || (line(board, p) >= 3 && line(board, p) <= 4)));
    const fallback = candidates.length ? candidates : board.points.filter((p) => board.legal(p) && !board.eyeOf(p, c) && libertiesAfter(board, p) >= 2);
    if (!fallback.length) return 'pass';

    return board.idOf(fallback[Math.floor(random() * fallback.length)]);
}

/** A bot's move in a position given as the server's text. */
export function botMove(bot, text, { random = rng(), clock } = {}) {
    const board = GoBoard.fromText(text);
    if (bot === 'hodler') return hodlerMove(board, random);
    const settings = BOTS[bot] ?? BOTS.miner;
    const result = search(board, { playouts: settings.playouts, time: settings.time ?? Infinity, random, ...(clock ? { clock } : {}) });

    // The other side passed and nothing wins any more: the bot passes too and takes the count, rather than playing on
    // inside its own area.
    return board.passes === 1 && result.winrate < 0.1 ? 'pass' : result.move;
}

export { PASS };
