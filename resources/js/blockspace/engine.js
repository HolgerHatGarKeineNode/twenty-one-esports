/*
 * Blockspace in the browser: the rules of App\Support\Board\BlockspaceRules once more, for the board's own refusals
 * (the page says why a stone may not lie there before anything is sent), the lessons and the bots of the practice page.
 * The server judges every league move; tests/Fixtures/blockspace-games.json, written from this file, holds both to the
 * same games (tests/Unit/Board/BlockspaceRulesTest.php).
 *
 * A game is the object newGame() and stateOf() give; a position is the text of BlockspaceRules (see there). Cells count
 * row by row from the top left: 0 = a21, 188 = i1. Sides are 0 (White, ₿) and 1 (Black, ⚡).
 */
export const R = 21;
export const C = 9;
export const N = R * C;
export const M = 5;
export const BONUS = [4, 3, 2, 1, 0];
export const ORDER = [0, 1, 1, 0];
export const PASS_LIMIT = 14;

const SHAPES = {
    I1: [[0, 0]], I2: [[0, 0], [0, 1]], I3: [[0, 0], [0, 1], [0, 2]], L3: [[0, 0], [1, 0], [1, 1]],
    I4: [[0, 0], [0, 1], [0, 2], [0, 3]], O4: [[0, 0], [0, 1], [1, 0], [1, 1]], T4: [[0, 0], [0, 1], [0, 2], [1, 1]], L4: [[0, 0], [1, 0], [2, 0], [2, 1]], S4: [[0, 1], [0, 2], [1, 0], [1, 1]],
    F5: [[0, 1], [0, 2], [1, 0], [1, 1], [2, 1]], I5: [[0, 0], [0, 1], [0, 2], [0, 3], [0, 4]], L5: [[0, 0], [1, 0], [2, 0], [3, 0], [3, 1]], N5: [[0, 1], [1, 1], [2, 0], [2, 1], [3, 0]], P5: [[0, 0], [0, 1], [1, 0], [1, 1], [2, 0]],
    T5: [[0, 0], [0, 1], [0, 2], [1, 1], [2, 1]], U5: [[0, 0], [0, 2], [1, 0], [1, 1], [1, 2]], V5: [[0, 0], [1, 0], [2, 0], [2, 1], [2, 2]], W5: [[0, 0], [1, 0], [1, 1], [2, 1], [2, 2]], X5: [[0, 1], [1, 0], [1, 1], [1, 2], [2, 1]], Y5: [[0, 1], [1, 0], [1, 1], [2, 1], [3, 1]], Z5: [[0, 0], [0, 1], [1, 1], [2, 1], [2, 2]],
};

export const NAMES = Object.keys(SHAPES);
export const SIZE = NAMES.map((name) => SHAPES[name].length);

const norm = (c) => {
    const a = Math.min(...c.map((p) => p[0]));
    const b = Math.min(...c.map((p) => p[1]));

    return c.map(([r, x]) => [r - a, x - b]).sort((p, q) => p[0] - q[0] || p[1] - q[1]);
};

export const key = (c) => c.join(';');
export const rot = (c) => norm(c.map(([r, x]) => [x, -r]));
export const flip = (c) => norm(c.map(([r, x]) => [r, -x]));

/** Every orientation of every form, a form's own shape first. */
export const ORI = NAMES.map((name) => {
    const seen = new Map();
    let shape = norm(SHAPES[name]);
    for (let f = 0; f < 2; f++) {
        for (let i = 0; i < 4; i++) {
            if (!seen.has(key(shape))) seen.set(key(shape), shape);
            shape = rot(shape);
        }
        shape = flip(shape);
    }

    return [...seen.values()];
});

/** The start square of each side: e2 for White, e20 for Black. */
export const START = [(R - 2) * C + (C >> 1), C + (C >> 1)];

const NEI = [];
const DIA = [];
for (let i = 0; i < N; i++) {
    const r = (i / C) | 0;
    const c = i % C;
    const around = (list) => list.map(([a, b]) => [r + a, c + b]).filter(([y, x]) => y >= 0 && y < R && x >= 0 && x < C).map(([y, x]) => y * C + x);
    NEI.push(around([[1, 0], [-1, 0], [0, 1], [0, -1]]));
    DIA.push(around([[1, 1], [1, -1], [-1, 1], [-1, -1]]));
}

const PLS = [];
const PL = [];
NAMES.forEach((_, f) => {
    const list = [];
    const by = Array.from({ length: N }, () => []);
    for (const o of ORI[f]) {
        const h = Math.max(...o.map((p) => p[0])) + 1;
        const w = Math.max(...o.map((p) => p[1])) + 1;
        for (let r = 0; r <= R - h; r++) {
            for (let c = 0; c <= C - w; c++) {
                const cells = o.map(([a, b]) => (r + a) * C + c + b);
                const id = list.length;
                list.push(cells);
                for (const x of cells) by[x].push(id);
            }
        }
    }
    PLS.push(list);
    PL.push(by);
});

/** A seeded generator (mulberry32): the same seed deals the same game, for the lessons and the tests. */
export function rng(seed) {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const letter = (f) => String.fromCharCode(97 + f);
const formOf = (char) => char.charCodeAt(0) - 97;

export function newGame(seed, fuse) {
    const r = rng(seed);
    const deck = [];
    let id = 0;
    NAMES.forEach((_, f) => {
        deck.push({ id: id++, f });
        deck.push({ id: id++, f });
    });
    for (let i = deck.length - 1; i > 0; i--) {
        const j = Math.floor(r() * (i + 1));
        [deck[i], deck[j]] = [deck[j], deck[i]];
    }
    const g = { fuse: !!fuse, deck: deck.map((tx) => letter(tx.f)).join(''), board: new Array(N).fill(0), pool: deck.slice(0, M), queue: deck.slice(M), score: [0, 0], played: [false, false], ti: 0, side: 0, moves: 0, evicted: 0, passes: 0, over: false, log: [] };
    settle(g);

    return g;
}

export const bonus = (i) => BONUS[i] ?? 0;
export const fee = (g, i) => SIZE[g.pool[i].f] + bonus(i);

function marks(g, s) {
    const own = s + 1;
    const edge = new Uint8Array(N);
    const anc = new Uint8Array(N);
    for (let i = 0; i < N; i++) {
        if (g.board[i] === own) {
            for (const j of NEI[i]) edge[j] = 1;
            for (const j of DIA[i]) anc[j] = 1;
        }
    }
    if (!g.played[s]) anc[START[s]] = 1;

    return { edge, anc };
}

/** Why these cells may not be set for side `s`: 'taken', 'edge', 'corner' or 'start'; null when they may. */
export function refuse(g, s, cells) {
    const m = marks(g, s);
    let touch = false;
    for (const x of cells) {
        if (g.board[x]) return 'taken';
        if (m.edge[x]) return 'edge';
        if (m.anc[x]) touch = true;
    }
    if (touch) return null;

    return g.played[s] ? 'corner' : 'start';
}

const stamp = new Int32Array(1 << 13);
let sn = 0;

export function moves(g, s, m, first) {
    m = m || marks(g, s);
    const out = [];
    const anchors = [];
    const b = g.board;
    for (let i = 0; i < N; i++) if (m.anc[i] && !m.edge[i] && !b[i]) anchors.push(i);
    for (let i = 0; i < g.pool.length; i++) {
        const f = g.pool[i].f;
        const by = PL[f];
        const list = PLS[f];
        sn++;
        for (const a of anchors) {
            for (const id of by[a]) {
                if (stamp[id] === sn) continue;
                stamp[id] = sn;
                const cells = list[id];
                let ok = true;
                for (let k = 0; k < cells.length; k++) {
                    const x = cells[k];
                    if (b[x] || m.edge[x]) {
                        ok = false;
                        break;
                    }
                }
                if (ok) {
                    const move = { i, cells, fee: SIZE[f] + bonus(i) };
                    if (first) return [move];
                    out.push(move);
                }
            }
        }
    }

    return out;
}

const refill = (g) => {
    if (g.queue.length) g.pool.push(g.queue.shift());
};

function drop(g, why) {
    const tx = g.pool.shift();
    if (tx) {
        g.evicted++;
        g.log.push({ t: why, tx: tx.id });
        refill(g);
    }
}

function next(g) {
    g.ti = (g.ti + 1) % 4;
    g.side = ORDER[g.ti];
    if (g.ti % 2 === 0 && g.fuse && g.pool.length) drop(g, 'expire');
}

function skip(g) {
    g.passes++;
    g.log.push({ t: 'pass', side: g.side });
    drop(g, 'evict');
    next(g);
}

/** With nothing to place the oldest transaction drops out and the turn moves on. */
function settle(g) {
    while (!g.over) {
        if (!g.pool.length || g.passes >= PASS_LIMIT) {
            g.over = true;

            return;
        }
        if (moves(g, g.side, null, true).length) return;
        skip(g);
    }
}

export function play(g, i, cells) {
    const s = g.side;
    const tx = g.pool[i];
    const gain = fee(g, i);
    for (const x of cells) g.board[x] = s + 1;
    g.score[s] += gain;
    g.played[s] = true;
    g.moves++;
    g.passes = 0;
    g.pool.splice(i, 1);
    refill(g);
    g.log.push({ t: 'move', side: s, tx: tx.id, f: tx.f, cells: cells.slice(), gain, slot: i });
    next(g);
    settle(g);
}

export function pass(g) {
    skip(g);
    settle(g);
}

export const winner = (g) => (g.score[0] > g.score[1] ? 0 : g.score[1] > g.score[0] ? 1 : -1);
export const clone = (g) => ({ ...g, board: g.board.slice(), pool: g.pool.slice(), queue: g.queue.slice(), score: g.score.slice(), played: g.played.slice(), log: [] });

/* The position text and the moves of BlockspaceRules. */

export const cellName = (i) => 'abcdefghi'[i % C] + (R - ((i / C) | 0));
export const cellOf = (name) => (R - Number(name.slice(1))) * C + 'abcdefghi'.indexOf(name[0]);

/** A placement as the league writes it: the slot, then its cells from the top left. */
export const moveText = (i, cells) => i + ':' + [...cells].sort((a, b) => a - b).map(cellName).join(',');

export function positionOf(g) {
    const text = (list) => list.map((tx) => letter(tx.f)).join('') || '-';
    const board = g.board.map((v) => '.wb'[v]).join('');

    return [g.fuse ? 'z' : 'n', g.ti, g.score[0], g.score[1], (g.played[0] ? '1' : '0') + (g.played[1] ? '1' : '0'), g.passes, text(g.pool), text(g.queue), g.deck, board].join(' ');
}

export function stateOf(position) {
    const [fuse, ti, white, black, played, passes, pool, queue, deck, board] = position.split(' ');
    let id = 0;
    const list = (text) => (text === '-' ? [] : [...text].map((char) => ({ id: id++, f: formOf(char) })));
    const g = { fuse: fuse === 'z', deck, board: [...board].map((char) => '.wb'.indexOf(char)), pool: list(pool), queue: list(queue), score: [Number(white), Number(black)], played: [played[0] === '1', played[1] === '1'], ti: Number(ti), side: ORDER[Number(ti)], moves: 0, evicted: 0, passes: Number(passes), over: false, log: [] };
    g.over = !g.pool.length || g.passes >= PASS_LIMIT;

    return g;
}

/* The bots: Hodler (`leicht`), Miner (`mittel`), Whale (`stark`). */

function both(g) {
    const a = marks(g, 0);
    const b = marks(g, 1);

    return { edge: [a.edge, b.edge], anc: [a.anc, b.anc] };
}

function hval(g, m, mk, s, r) {
    const b = g.board;
    const e = mk.edge[s];
    const o = 1 - s;
    let gain = 0;
    let block = 0;
    for (const c of m.cells) {
        for (const d of DIA[c]) if (!b[d] && !e[d]) gain++;
        for (const d of NEI[c]) if (!b[d]) gain -= 0.45;
        if (mk.anc[o][c] && !mk.edge[o][c]) block++;
    }

    return m.fee + 0.6 * gain + 1.2 * block + m.cells.length + r() * 0.05;
}

function pickFast(g, ms, mk, r) {
    let best = null;
    let value = -1e9;
    const n = Math.min(ms.length, 12);
    for (let i = 0; i < n; i++) {
        const m = ms[Math.floor(r() * ms.length)];
        const v = hval(g, m, mk, g.side, r);
        if (v > value) [value, best] = [v, m];
    }

    return best;
}

function rollout(g, r) {
    let depth = 0;
    while (!g.over && depth++ < 60) {
        const mk = both(g);
        const ms = moves(g, g.side, { edge: mk.edge[g.side], anc: mk.anc[g.side] });
        if (!ms.length) {
            pass(g);
            continue;
        }
        const m = pickFast(g, ms, mk, r);
        play(g, m.i, m.cells);
    }
}

const util = (g, me) => {
    const d = g.score[me] - g.score[1 - me];

    return (d > 0 ? 1 : d === 0 ? 0.5 : 0) + 0.002 * d;
};

export function think(g, level, r, budget) {
    const s = g.side;
    const mk = both(g);
    const ms = moves(g, s, { edge: mk.edge[s], anc: mk.anc[s] });
    if (!ms.length) return null;
    if (level === 'leicht') {
        let best = null;
        let value = -1e9;
        for (const m of ms) {
            const v = m.fee + r() * 6;
            if (v > value) [value, best] = [v, m];
        }

        return best;
    }
    const scored = ms.map((m) => [hval(g, m, mk, s, r), m]).sort((a, b) => b[0] - a[0]);
    if (level === 'mittel') return scored[0][1];
    const top = scored.slice(0, 6).map(([, m]) => ({ m, t: 0, n: 0 }));
    if (top.length === 1) return top[0].m;
    const end = Date.now() + (budget || 450);
    let i = 0;
    while (Date.now() < end || i < top.length) {
        const q = top[i++ % top.length];
        const h = clone(g);
        // What comes next is hidden from the bot as well: every look ahead gets an order of its own.
        for (let k = h.queue.length - 1; k > 0; k--) {
            const j = Math.floor(r() * (k + 1));
            [h.queue[k], h.queue[j]] = [h.queue[j], h.queue[k]];
        }
        play(h, q.m.i, q.m.cells);
        rollout(h, r);
        q.t += util(h, s);
        q.n++;
    }
    top.sort((a, b) => b.t / b.n - a.t / a.n);

    return top[0].m;
}

/* The board's language: the pieces of BlockspaceRules::view() and the notation, for a game kept in the browser. */

const SIGNS = ['₿', '⚡'];

export function piecesOf(g) {
    const pieces = {};
    g.board.forEach((stone, i) => {
        if (stone) pieces[cellName(i)] = { side: stone === 1 ? 'w' : 'b', kind: 's' };
    });
    g.pool.forEach((tx, rung) => {
        pieces['tx' + rung] = { side: 'w', kind: NAMES[tx.f] };
    });
    pieces.state = { side: g.side === 0 ? 'w' : 'b', kind: [g.ti, g.score[0], g.score[1], g.queue.length, g.fuse ? 1 : 0, (g.played[0] ? '1' : '0') + (g.played[1] ? '1' : '0'), g.passes].join(':') };

    return pieces;
}

/** What the board needs to know of a position it got as pieces, in the shape refuse() and fee() read. */
export function readPieces(pieces) {
    const board = new Array(N).fill(0);
    for (const [id, piece] of Object.entries(pieces ?? {})) {
        if (piece.kind === 's') board[cellOf(id)] = piece.side === 'w' ? 1 : 2;
    }
    const pool = [];
    for (let rung = 0; rung < M; rung++) {
        const piece = pieces?.['tx' + rung];
        if (piece) pool.push({ f: NAMES.indexOf(piece.kind) });
    }
    const [ti, white, black, queue, fuse, played, passes] = (pieces?.state?.kind ?? '0:0:0:0:0:00:0').split(':');

    return { board, pool, score: [Number(white), Number(black)], ti: Number(ti), side: ORDER[Number(ti)], queue: Number(queue), fuse: fuse === '1', played: [played[0] === '1', played[1] === '1'], passes: Number(passes) };
}

/** Plays a move in the league's text; false for text that is none, or a placement the rules refuse. */
export function applyMove(g, text) {
    if (g.over) return false;
    if (text === 'pass') {
        pass(g);

        return true;
    }
    const [slot, names] = text.split(':');
    const cells = (names ?? '').split(',').filter(Boolean).map(cellOf);
    const tx = g.pool[Number(slot)];
    if (!tx || !cells.length || cells.length !== SIZE[tx.f] || refuse(g, g.side, cells)) return false;
    play(g, Number(slot), cells);

    return true;
}

/** The move as the game record writes it, for the position before it. */
export function notationOf(g, text) {
    const sign = SIGNS[g.side];
    if (text === 'pass') return sign + ' –';
    const [slot, names] = text.split(':');

    return sign + ' ' + NAMES[g.pool[Number(slot)].f] + ' ' + names.split(',')[0] + ' +' + fee(g, Number(slot));
}
