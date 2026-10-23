/*
 * Blockli practice against bots (DerCaddy, 2026-10-23): the three bots, the small tips, and the bridge between the
 * server's positions and moves (App\Support\Board\BlockliRules) and the engine's (blockli/engine.js). Plain functions
 * without a page, so the worker (engineWorker.js), the page (practice.js) and the tests (tests/js/blockli) share them.
 *
 * The server writes a position as `white black left-w left-b turn blocks quiet` ("e1 e9 10 10 w - 0") and a move as
 * `e1-e2` or `e3h`; the engine counts squares 0-80 and blocks as 100 + their code. The server stays the judge of every
 * move: the bots only choose among the moves it would accept, and the page sends each one there (practice.js).
 */
import Blockli from './engine.js';

export const SIDES = ['w', 'b'];

/**
 * The three bots, weakest first, each with a way of playing a beginner can see and beat in turn: the Hodler never gives
 * a block away and only runs, the Miner sets a block whenever one costs the other side a step, the Whale searches deep
 * (the engine's strongest level, about a second and a half a move).
 */
export const BOTS = {
    hodler: { id: 'hodler', think: 600 },
    miner: { id: 'miner', think: 700 },
    whale: { id: 'whale', think: 1500 },
};

export const BOT_ORDER = ['hodler', 'miner', 'whale'];

/** The engine's state of a server position, or null for text that is none. */
export function stateOf(position) {
    const parts = String(position ?? '').trim().split(' ');
    if (parts.length !== 7) return null;
    const square = (name) => {
        const cell = Blockli.parseMove(name);

        return cell >= 0 && cell < 81 ? cell : null;
    };
    const s = Blockli.newState(0);
    const [white, black] = [square(parts[0]), square(parts[1])];
    if (white === null || black === null) return null;
    s.pawns = [white, black];
    s.walls = [Number(parts[2]), Number(parts[3])];
    s.turn = parts[4] === 'b' ? 1 : 0;
    for (const entry of parts[5] === '-' ? [] : parts[5].split(',')) {
        const block = Blockli.parseMove(entry.slice(0, 3));
        if (block < 100) return null;
        const code = block - 100;
        (code >= 64 ? s.v : s.h)[code & 63] = 1;
    }
    if (Math.floor(white / 9) === Blockli.GOAL[0]) s.winner = 0;
    else if (Math.floor(black / 9) === Blockli.GOAL[1]) s.winner = 1;

    return s;
}

/** The server's move for an engine move in a state: a pawn move from its square, a block by its name. */
export function serverMove(s, move) {
    if (move >= 100) return Blockli.moveName(move);

    return Blockli.cellName(s.pawns[s.turn]) + '-' + Blockli.cellName(move);
}

/** The engine's move for a server move, or -1. */
export function engineMove(move) {
    const text = String(move ?? '');
    const pawn = /^[a-i][1-9]-([a-i][1-9])$/.exec(text);

    return Blockli.parseMove(pawn ? pawn[1] : text);
}

/** Every legal move of the side to move as the server writes them, sorted (the tests hold them against the server's). */
export function legalServerMoves(s) {
    if (s.winner >= 0) return [];

    return Blockli.legalMoves(s).filter((move) => move !== Blockli.PASS).map((move) => serverMove(s, move)).sort();
}

/** Steps each side needs to its goal rank, pawns not counted (as BlockliRules::distance()). */
export function distances(s) {
    return [Blockli.pathLen(s, 0), Blockli.pathLen(s, 1)];
}

/** The state after a move, the given one untouched. */
export function after(s, move) {
    const next = Blockli.clone(s);
    Blockli.applyMove(next, move);

    return next;
}

function pick(list, random) {
    return list[Math.floor(random() * list.length)];
}

/** The pawn moves that bring the side to move closest to its goal, a jump included. */
export function bestSteps(s) {
    const side = s.turn;
    let best = Infinity;
    let moves = [];
    for (const move of Blockli.pawnMoves(s, side)) {
        const next = Blockli.clone(s);
        next.pawns[side] = move;
        const left = Math.floor(move / 9) === Blockli.GOAL[side] ? 0 : Blockli.pathLen(next, side);
        if (left < best) [best, moves] = [left, [move]];
        else if (left === best) moves.push(move);
    }

    return moves;
}

/**
 * What a block does in a state: how many steps it costs the other side (`gain`, its detour minus the setter's own),
 * and both detours. Only for a legal block.
 */
export function blockEffect(s, move) {
    const side = s.turn;
    const before = distances(s);
    const next = after(s, move);
    const now = distances(next);

    return { move, theirs: now[1 - side] - before[1 - side], mine: now[side] - before[side], gain: now[1 - side] - before[1 - side] - (now[side] - before[side]) };
}

/** The legal blocks of the side to move with their effects, the strongest first. */
export function blockEffects(s) {
    if (s.walls[s.turn] <= 0) return [];

    return Blockli.legalMoves(s).filter((move) => move >= 100).map((move) => blockEffect(s, move)).sort((a, b) => b.gain - a.gain || a.mine - b.mine);
}

/**
 * The Miner's move: a block whenever one costs the other side at least two steps more than itself, or one step while
 * it is not ahead in the race; else a step along its shortest way. So it spends its blocks early and then has none.
 */
export function minerMove(s, random = Math.random) {
    const side = s.turn;
    const [mine, theirs] = [Blockli.pathLen(s, side), Blockli.pathLen(s, 1 - side)];
    const blocks = blockEffects(s);
    const top = blocks.length ? blocks[0].gain : 0;
    // Its race: the side to move arrives first at equal steps.
    const ahead = mine <= theirs;
    if (top >= 2 || (top >= 1 && !ahead)) return pick(blocks.filter((b) => b.gain === top), random).move;

    return pick(bestSteps(s), random);
}

/** The Hodler's move: never a block, always a step along its shortest way (one of them at random). */
export function hodlerMove(s, random = Math.random) {
    return pick(bestSteps(s), random);
}

/**
 * The bot's move in a state, as the engine counts it. `history` is the game's moves from `start` (engine moves), so
 * the Whale keeps clear of repeating itself. The Hodler and the Miner take no time; the Whale searches for `time` ms
 * (its own pace unless the page runs the engine itself, without a worker).
 */
export function botMove(bot, s, { start = null, history = [], random = Math.random, time = BOTS.whale.think } = {}) {
    if (bot === 'hodler') return hodlerMove(s, random);
    if (bot === 'miner') return minerMove(s, random);
    Blockli.LEVELS.hard.time = time;

    return Blockli.chooseMove(s, 'hard', { start, history });
}

export { Blockli };
