/*
 * Go for the browser (DerCaddy, 2026-10-27): the league's rules (App\Support\Board\GoRules) for the practice page, its
 * bots and its tips. The server judges every move of a practice game (App\Support\Board\GoPractice), so these rules
 * must be the server's to the letter: the same legal moves (suicide forbidden unless the stone captures, the simple
 * ko), the same captures, the same text of a position and the same count by area (tests/Unit/Board/GoPracticeScripts
 * holds both to the same answers in a thousand positions of random games).
 *
 * The board keeps its chains the way fast go programs do (libego, Pachi): every stone points to its chain's head, the
 * stones of a chain form a ring, and a chain counts its pseudo-liberties (each empty neighbour of each stone, counted
 * once per stone), with their sum and the sum of their squares: a chain stands in atari exactly when all its
 * pseudo-liberties are one point. So a move, a capture and a legality test cost a few array reads, and a search plays
 * tens of thousands of games a second on 9 x 9.
 *
 * The search (search()) is Monte Carlo tree search with RAVE: random games from each position, with three habits
 * that make them less random (take a chain the last move left in atari, save one's own, never fill one's own eye).
 * It knows nothing of go but the rules and the count; enough to beat a newcomer on 9 x 9 and to give a fair game to a
 * club player at a few seconds a move, never more (a strong program needs a neural network, DerCaddy chose none).
 */

export const FILES = 'abcdefghjklmnopqrst';
export const KOMI = 7.5;
/** The pass as a point: cell 0 is the corner of the frame, never a point of the board. */
export const PASS = 0;
export const EMPTY = 0;
export const BLACK = 1;
export const WHITE = 2;
const EDGE = 3;

/** The side the server names first (`w`, Black) and second (`b`, White). */
export const sideOf = (color) => (color === BLACK ? 'w' : 'b');
export const colorOf = (side) => (side === 'w' ? BLACK : WHITE);

/** A small seeded random source (mulberry32), so a test or a bot's game can be played again. */
export function rng(seed = Date.now()) {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

export class GoBoard {
    constructor(size = 9) {
        this.size = size;
        const w = size + 2;
        this.w = w;
        this.cells = w * w;
        this.color = new Uint8Array(this.cells).fill(EDGE);
        this.head = new Int32Array(this.cells);
        this.next = new Int32Array(this.cells);
        this.libs = new Int32Array(this.cells);
        this.libSum = new Int32Array(this.cells);
        this.libSq = new Int32Array(this.cells);
        this.stones = new Int32Array(this.cells);
        this.points = [];
        for (let row = 0; row < size; row++) {
            for (let file = 0; file < size; file++) {
                const p = (row + 1) * w + file + 1;
                this.color[p] = EMPTY;
                this.points.push(p);
            }
        }
        this.dirs = [-w, -1, 1, w];
        this.diagonals = [-w - 1, -w + 1, w - 1, w + 1];
        this.toMove = BLACK;
        this.ko = 0;
        this.passes = 0;
        this.last = 0;
        this.plies = 0;
    }

    clone() {
        const copy = Object.create(GoBoard.prototype);
        Object.assign(copy, this);
        for (const key of ['color', 'head', 'next', 'libs', 'libSum', 'libSq', 'stones']) copy[key] = this[key].slice();

        return copy;
    }

    /* ---- points and text ------------------------------------------------------------------------- */

    /** The cell of a point name (`d4`), or -1 for a name that is no point of this board. */
    pointOf(id) {
        const match = /^([a-hj-t])(\d{1,2})$/.exec(id);
        if (!match) return -1;
        const file = FILES.indexOf(match[1]);
        const rank = Number(match[2]);
        if (file < 0 || file >= this.size || rank < 1 || rank > this.size || String(rank) !== match[2]) return -1;

        return (this.size - rank + 1) * this.w + file + 1;
    }

    /** The name of a cell's point, `pass` for the pass. */
    idOf(p) {
        if (p === PASS) return 'pass';

        return FILES[(p % this.w) - 1] + (this.size - Math.floor(p / this.w) + 1);
    }

    /** A position from the server's text (rows from the top, `x`, `o` and runs of empty points; the colour to move, the ko point, the passes). */
    static fromText(text) {
        const [rows, turn, ko, passes] = text.split(' ');
        const lines = rows.split('/');
        const board = new GoBoard(lines.length);
        lines.forEach((line, row) => {
            let file = 0;
            for (const run of line.match(/\d+|[xo]/g) ?? []) {
                if (run === 'x' || run === 'o') {
                    board.put((row + 1) * board.w + file + 1, run === 'x' ? BLACK : WHITE);
                    file++;
                } else {
                    file += Number(run);
                }
            }
        });
        board.toMove = turn === 'o' ? WHITE : BLACK;
        board.ko = ko === '-' ? 0 : board.pointOf(ko);
        board.passes = Number(passes) || 0;

        return board;
    }

    toText() {
        const rows = [];
        for (let row = 0; row < this.size; row++) {
            let text = '';
            let empty = 0;
            for (let file = 0; file < this.size; file++) {
                const c = this.color[(row + 1) * this.w + file + 1];
                if (c === EMPTY) {
                    empty++;
                    continue;
                }
                if (empty) text += empty;
                empty = 0;
                text += c === BLACK ? 'x' : 'o';
            }
            if (empty) text += empty;
            rows.push(text);
        }

        return rows.join('/') + ' ' + (this.toMove === BLACK ? 'x' : 'o') + ' ' + (this.ko ? this.idOf(this.ko) : '-') + ' ' + this.passes;
    }

    /* ---- chains ---------------------------------------------------------------------------------- */

    addLib(h, p) {
        this.libs[h]++;
        this.libSum[h] += p;
        this.libSq[h] += p * p;
    }

    removeLib(h, p) {
        this.libs[h]--;
        this.libSum[h] -= p;
        this.libSq[h] -= p * p;
    }

    /** Whether the chain of this head has exactly one liberty (all its pseudo-liberties are one point). */
    inAtari(h) {
        const n = this.libs[h];

        return n > 0 && n * this.libSq[h] === this.libSum[h] * this.libSum[h];
    }

    /** The one liberty of a chain in atari. */
    atariPoint(h) {
        return this.libSum[h] / this.libs[h];
    }

    /** The real liberties of a chain (for the tips, the lessons and the bots' thinking; the search never needs them). */
    liberties(p) {
        const h = this.head[p];
        const seen = new Set();
        let s = h;
        do {
            for (const d of this.dirs) if (this.color[s + d] === EMPTY) seen.add(s + d);
            s = this.next[s];
        } while (s !== h);

        return [...seen];
    }

    /** The stones of the chain on this point. */
    chain(p) {
        const h = this.head[p];
        const stones = [];
        let s = h;
        do {
            stones.push(s);
            s = this.next[s];
        } while (s !== h);

        return stones;
    }

    /** Merges the chain of head `b` into the chain of head `a` (the smaller into the larger). */
    merge(a, b) {
        if (this.stones[a] < this.stones[b]) [a, b] = [b, a];
        let s = b;
        do {
            this.head[s] = a;
            s = this.next[s];
        } while (s !== b);
        const nextA = this.next[a];
        this.next[a] = this.next[b];
        this.next[b] = nextA;
        this.stones[a] += this.stones[b];
        this.libs[a] += this.libs[b];
        this.libSum[a] += this.libSum[b];
        this.libSq[a] += this.libSq[b];

        return a;
    }

    /** Puts a stone without asking whose move it is (a position read from text); returns the stones it captured. */
    put(p, c) {
        const color = this.color;
        const o = 3 - c;
        color[p] = c;
        this.head[p] = p;
        this.next[p] = p;
        this.stones[p] = 1;
        this.libs[p] = 0;
        this.libSum[p] = 0;
        this.libSq[p] = 0;
        for (const d of this.dirs) if (color[p + d] === EMPTY) this.addLib(p, p + d);
        let captured = 0;
        let taken = 0;
        for (const d of this.dirs) {
            const q = p + d;
            const cq = color[q];
            if (cq === c) {
                const h = this.head[q];
                this.removeLib(h, p);
                if (h !== this.head[p]) this.merge(this.head[p], h);
            } else if (cq === o) {
                const h = this.head[q];
                this.removeLib(h, p);
                if (this.libs[h] === 0) {
                    captured += this.stones[h];
                    taken = h;
                    this.removeChain(h);
                }
            }
        }
        this.lastCaptured = captured;
        this.lastTaken = taken;

        return captured;
    }

    removeChain(h) {
        const stones = this.chain(h);
        for (const s of stones) this.color[s] = EMPTY;
        for (const s of stones) {
            for (const d of this.dirs) {
                const t = s + d;
                const ct = this.color[t];
                if (ct === BLACK || ct === WHITE) this.addLib(this.head[t], s);
            }
        }
    }

    /* ---- the rules ------------------------------------------------------------------------------- */

    get over() {
        return this.passes >= 2;
    }

    /** Whether the side to move may put a stone here: an empty point, not the ko, and a liberty, a chain kept alive or a capture. */
    legal(p) {
        const color = this.color;
        if (color[p] !== EMPTY || p === this.ko) return false;
        for (const d of this.dirs) if (color[p + d] === EMPTY) return true;
        const c = this.toMove;
        for (const d of this.dirs) {
            const cq = color[p + d];
            if (cq === c) {
                if (!this.inAtari(this.head[p + d])) return true;
            } else if (cq === 3 - c && this.inAtari(this.head[p + d])) {
                return true;
            }
        }

        return false;
    }

    /** The legal moves as the server names them, a1 first and rank by rank, the pass last (GoRules::legalMoves()). */
    legalIds() {
        if (this.over) return [];
        const ids = [];
        for (let rank = 1; rank <= this.size; rank++) {
            for (let file = 0; file < this.size; file++) {
                const p = (this.size - rank + 1) * this.w + file + 1;
                if (this.legal(p)) ids.push(FILES[file] + rank);
            }
        }
        ids.push('pass');

        return ids;
    }

    /** Plays a legal move of the side to move (PASS or a cell); returns the stones it captured. */
    play(p) {
        this.plies++;
        this.last = p;
        if (p === PASS) {
            this.passes++;
            this.ko = 0;
            this.toMove = 3 - this.toMove;
            this.lastCaptured = 0;

            return 0;
        }
        const captured = this.put(p, this.toMove);
        const h = this.head[p];
        // A single stone that took a single stone and has that point as its only liberty: retaking at once is ko.
        this.ko = captured === 1 && this.stones[h] === 1 && this.libs[h] === 1 ? this.lastTaken : 0;
        this.passes = 0;
        this.toMove = 3 - this.toMove;

        return captured;
    }

    playId(id) {
        return this.play(id === 'pass' ? PASS : this.pointOf(id));
    }

    /**
     * The count as it stands (GoRules::areaCount()): each colour's stones and the empty points that touch only its stones,
     * the komi for White. `owners` holds each empty point's colour (0 for nobody's).
     */
    count() {
        const color = this.color;
        const owners = new Uint8Array(this.cells);
        const seen = new Uint8Array(this.cells);
        const stack = [];
        const area = [0, 0, 0];
        const stones = [0, 0, 0];
        for (const p of this.points) {
            const c = color[p];
            if (c !== EMPTY) {
                stones[c]++;
                continue;
            }
            if (seen[p]) continue;
            const region = [];
            let touches = 0;
            seen[p] = 1;
            stack.push(p);
            while (stack.length) {
                const q = stack.pop();
                region.push(q);
                for (const d of this.dirs) {
                    const t = q + d;
                    const ct = color[t];
                    if (ct === EMPTY) {
                        if (!seen[t]) {
                            seen[t] = 1;
                            stack.push(t);
                        }
                    } else if (ct !== EDGE) {
                        touches |= ct;
                    }
                }
            }
            if (touches === BLACK || touches === WHITE) {
                area[touches] += region.length;
                for (const q of region) owners[q] = touches;
            }
        }
        const black = stones[BLACK] + area[BLACK];
        const white = stones[WHITE] + area[WHITE] + KOMI;

        return { black, white, margin: black - white, stones, area, owners };
    }

    /** The winner of the count as it stands: BLACK or WHITE (the half point of the komi rules out a tie). */
    winner() {
        return this.count().margin > 0 ? BLACK : WHITE;
    }

    /* ---- habits of the random games -------------------------------------------------------------- */

    /** An eye of this colour: every neighbour its stone (or the edge), at most one diagonal the other's (none on the edge). */
    eyeOf(p, c) {
        const color = this.color;
        for (const d of this.dirs) {
            const t = color[p + d];
            if (t !== c && t !== EDGE) return false;
        }
        let enemy = 0;
        let edge = 0;
        for (const d of this.diagonals) {
            const t = color[p + d];
            if (t === EDGE) edge = 1;
            else if (t === 3 - c) enemy++;
        }

        return enemy + edge < 2;
    }

    /**
     * Whether a stone here would leave its own chain with one liberty, and so be taken at once (counted in a copy). A
     * capture that makes room is fine.
     */
    selfAtari(p) {
        const copy = this.clone();
        copy.put(p, this.toMove);

        return copy.lastCaptured === 0 && copy.libs[copy.head[p]] > 0 && copy.inAtari(copy.head[p]) && copy.stones[copy.head[p]] > 1;
    }

    /**
     * The next move of a random game: answer the last move (take what it left in atari, save what it put in atari), else a
     * random legal point that is not one's own eye; a pass when nothing is left.
     */
    randomMove(random) {
        const color = this.color;
        const c = this.toMove;
        const last = this.last;
        if (last !== PASS && color[last] !== EMPTY) {
            // Take a chain the last move left in atari (its own, or one beside it).
            for (let i = -1; i < 4; i++) {
                const q = i < 0 ? last : last + this.dirs[i];
                if (color[q] === 3 - c && this.inAtari(this.head[q])) {
                    const at = this.atariPoint(this.head[q]);
                    if (this.legal(at) && random() < 0.9) return at;
                }
            }
            // Save a chain of one's own the last move put in atari, where running gives it room.
            for (const d of this.dirs) {
                const q = last + d;
                if (color[q] === c && this.inAtari(this.head[q])) {
                    const at = this.atariPoint(this.head[q]);
                    if (this.legal(at) && this.roomAt(at, c) >= 2 && random() < 0.8) return at;
                }
            }
        }
        const points = this.points;
        const n = points.length;
        const start = Math.floor(random() * n);
        for (let i = 0; i < n; i++) {
            const p = points[(start + i) % n];
            if (color[p] === EMPTY && this.legal(p) && !this.eyeOf(p, c)) return p;
        }

        return PASS;
    }

    /** The empty points next to a point, not counting the point itself: the room a stone there would have on its own. */
    roomAt(p, c) {
        let room = 0;
        for (const d of this.dirs) {
            const t = this.color[p + d];
            if (t === EMPTY) room++;
            else if (t === c && !this.inAtari(this.head[p + d])) room += 2;
        }

        return room;
    }
}

/* ---- the search ----------------------------------------------------------------------------------- */

class Node {
    constructor(move, color) {
        this.move = move;
        this.color = color;
        this.children = null;
        this.visits = 0;
        this.wins = 0;
        this.amafVisits = 0;
        this.amafWins = 0;
    }
}

/** How fast RAVE's estimate gives way to the node's own: about even after this many visits. */
const RAVE_EQUIVALENCE = 1200;

function value(child, logParent) {
    const n = child.visits;
    const a = child.amafVisits;
    const q = n ? child.wins / n : 0.5;
    const aq = a ? child.amafWins / a : 0.5;
    const beta = a / (n + a + (n * a) / RAVE_EQUIVALENCE + 1e-9);

    return (1 - beta) * q + beta * aq + (n ? 0.12 * Math.sqrt(logParent / n) : 0.3);
}

/** The children of a node: every legal point but one's own eyes, and the pass. */
function expand(node, board) {
    const children = [];
    const c = board.toMove;
    for (const p of board.points) if (board.legal(p) && !board.eyeOf(p, c)) children.push(new Node(p, c));
    children.push(new Node(PASS, c));
    node.children = children;
}

/**
 * Monte Carlo tree search with RAVE from a position: `playouts` random games at most, within `time` ms. Returns the
 * pass at once where the other side passed and passing wins; else the move with the most visits, its share of won games for the side to move (`winrate`), every move's visits, and how
 * often each point ended as Black's or White's (`ownership`, -1 White to 1 Black) in the random games.
 */
export function search(board, { playouts = 2000, time = Infinity, random = rng(), clock = () => performance.now() } = {}) {
    // The other side passed and a pass now wins the count: the game is won, so take it (the random games would also
    // win with a stone in one's own area, and play on for no reason).
    if (board.passes === 1 && board.count().margin > 0 === (board.toMove === BLACK)) return { move: 'pass', winrate: 1, playouts: 0, visits: {}, ownership: {} };
    const root = new Node(board.last, 3 - board.toMove);
    expand(root, board);
    const marks = new Uint8Array(board.cells);
    const ownership = new Float64Array(board.cells);
    const started = clock();
    const limit = 3 * board.size * board.size;
    let played = 0;

    while (played < playouts && (played % 64 !== 0 || clock() - started < time)) {
        played++;
        const b = board.clone();
        const path = [root];
        const moves = [];
        let node = root;
        // Down the tree.
        while (node.children && !b.over) {
            const logParent = Math.log(node.visits + 1);
            let best = null;
            let bestValue = -Infinity;
            for (const child of node.children) {
                const v = value(child, logParent);
                if (v > bestValue) [best, bestValue] = [child, v];
            }
            if (!best) break;
            node = best;
            b.play(node.move);
            moves.push(node.move, node.color);
            path.push(node);
            if (node.visits === 0) break;
        }
        if (!node.children && !b.over && node.visits > 0) expand(node, b);
        // A random game from there.
        const playoutStart = moves.length;
        while (!b.over && b.plies < limit + board.plies) {
            const c = b.toMove;
            const p = b.randomMove(random);
            b.play(p);
            moves.push(p, c);
        }
        const count = b.count();
        const winner = count.margin > 0 ? BLACK : WHITE;
        for (const p of board.points) {
            const c = b.color[p] || count.owners[p];
            if (c === BLACK) ownership[p] += 1;
            else if (c === WHITE) ownership[p] -= 1;
        }
        // Up again: each node's own result, and RAVE for its children (the moves played after it by their colour).
        marks.fill(0);
        for (let i = moves.length - 2; i >= playoutStart; i -= 2) if (moves[i] !== PASS) marks[moves[i]] = moves[i + 1];
        for (let depth = path.length - 1; depth >= 0; depth--) {
            const at = path[depth];
            if (at.children) {
                for (const child of at.children) {
                    if (child.move !== PASS && marks[child.move] === child.color) {
                        child.amafVisits++;
                        if (winner === child.color) child.amafWins++;
                    }
                }
            }
            at.visits++;
            if (winner === at.color) at.wins++;
            if (depth > 0) {
                const i = (depth - 1) * 2;
                if (moves[i] !== PASS) marks[moves[i]] = moves[i + 1];
            }
        }
    }

    let best = root.children[0];
    for (const child of root.children) if (child.visits > best.visits) best = child;
    const visits = {};
    for (const child of root.children) if (child.visits > 0) visits[board.idOf(child.move)] = child.visits;
    const own = {};
    for (const p of board.points) own[board.idOf(p)] = played ? ownership[p] / played : 0;

    return { move: board.idOf(best.move), winrate: best.visits ? best.wins / best.visits : 0.5, playouts: played, visits, ownership: own };
}
