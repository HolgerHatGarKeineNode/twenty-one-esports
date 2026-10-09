/*
 * The live board of a board game next to chess (plan "Mühle und Dame", P2):
 * nine men's morris, checkers and the test fixture all use it. It knows no
 * rules. It draws what the server describes (the layout: lines, cells and
 * clickable points; the state: pieces and legal moves) and builds a move from
 * clicks along the paths of the legal moves the server sent. It proposes that
 * move and shows whatever the server answers: the server alone decides.
 *
 * Realtime as on the chess page (resources/js/chess.js, not imported so chess
 * stays untouched): every change reaches both players on the private
 * `board.{id}` channel and spectators on the public `board.{id}.watch`
 * channel; a heartbeat asks the server anyway, and without a websocket the
 * page polls.
 *
 * Correspondence (P8): the same board with a day per move. The side to
 * move's "clock" is the time left until its deadline, shown in hours; the
 * deadline itself is written out below the board.
 *
 * History (P8, as chess P55): every move carries the pieces after it, so the
 * board steps back through the game without rules of its own. `viewIndex`
 * null follows the game; a number pins the board to the position after that
 * many plies (0 = the start), where no move can be made. Arrow keys, the
 * buttons under the board and a click on a move browse; stepping onto the
 * newest position follows the game again.
 *
 * The players chat as on a chess game's page (plan "Blockli-Optimierung",
 * P1: gameChat, NIP-17). On a phone the board fits the first screen (P2,
 * fitBoard): as wide as the height left between its top and what sits under
 * it: Blockli's bar, the chat sheet and the app's tab bar; on a desktop the
 * window, with the lower player card under the board.
 */

import { gameChat } from './gameChat.js';
import { registerAlpine } from './registerAlpine.js';

const SVG = 'http://www.w3.org/2000/svg';

/** How long the page trusts the websocket alone before it asks the server. */
const HEARTBEAT_MS = 4000;

// Black men are dark discs with a light rim: on the dark board a dark rim would hide them.
const PIECE_FILL = { w: '#F4F4F5', b: '#09090B' };
const PIECE_STROKE = { w: '#A1A1AA', b: '#D4D4D8' };

export function formatClock(ms) {
    const total = Math.max(0, Math.ceil(ms / 1000));

    return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
}

/** A correspondence clock: "23 h 05" (hours and minutes, as the dock) while an hour or more is left, then minutes and seconds. */
export function formatDeadline(ms) {
    const minutes = Math.max(0, Math.floor(ms / 60000));
    if (minutes < 60) return formatClock(ms);

    return Math.floor(minutes / 60) + ' h ' + String(minutes % 60).padStart(2, '0');
}

/**
 * The legal moves whose path begins with these clicks.
 */
export function candidates(legal, clicks) {
    return legal.filter((option) => clicks.every((point, index) => option.path[index] === point));
}

/**
 * The radius a piece gets: a little over a third of the closest distance
 * between two points, so neighbours never touch.
 */
function pieceRadius(points) {
    let closest = Infinity;
    points.forEach((a, i) => points.slice(i + 1).forEach((b) => (closest = Math.min(closest, Math.hypot(a.x - b.x, a.y - b.y)))));

    return Number.isFinite(closest) ? closest * 0.36 : 20;
}

/**
 * Pieces drawn as bars instead of discs (Blockli): a block on the board spans two squares and the
 * groove between them; a handle is a short bar, a block in a tray a shorter one. `length` is
 * `board` for a full block, else a multiple of the piece radius.
 */
const BARS = {
    'block-h': { horizontal: true, length: 'board' },
    'block-v': { horizontal: false, length: 'board' },
    'handle-h': { horizontal: true, length: 2.2 },
    'handle-v': { horizontal: false, length: 2.2 },
    spare: { horizontal: false, length: 1.5 },
};

/** The second part of a block's path under the block input: its direction. */
const BLOCK_DIRECTIONS = ['h', 'v'];

/** The arrow keys of the block input: one crossing in this direction, in board units as seen by White. */
const BLOCK_KEY_STEPS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

const GEOMETRY = new WeakMap();

/**
 * For the block input, once per layout: the area of the cells and grooves, the point at each cell's
 * middle (its square) and the other points on that area (the crossings).
 */
function geometryOf(layout) {
    if (GEOMETRY.has(layout)) return GEOMETRY.get(layout);
    const cells = layout.cells;
    const box = { left: Math.min(...cells.map((c) => c.x)), right: Math.max(...cells.map((c) => c.x + c.size)), top: Math.min(...cells.map((c) => c.y)), bottom: Math.max(...cells.map((c) => c.y + c.size)) };
    const inside = (at) => at.x >= box.left && at.x <= box.right && at.y >= box.top && at.y <= box.bottom;
    const middles = new Set(cells.map((c) => c.x + c.size / 2 + ',' + (c.y + c.size / 2)));
    const geometry = {
        inside,
        squareOf: new Map(layout.points.filter((p) => middles.has(p.x + ',' + p.y)).map((p) => [p.x + ',' + p.y, p.id])),
        crossings: layout.points.filter((p) => inside(p) && !middles.has(p.x + ',' + p.y)),
    };
    GEOMETRY.set(layout, geometry);

    return geometry;
}

/**
 * How long and thick a block is: two cells and the groove between them long, a little thinner than
 * the groove. Read from the layout's cells, so the board still knows no game; null on a board whose
 * cells have no grooves between them (checkers), where no piece is a bar.
 */
export function barSize(cells) {
    if (cells.length < 2) return null;
    const size = cells[0].size;
    const pitch = Math.min(...cells.map((cell) => Math.abs(cell.x - cells[0].x)).filter((dx) => dx > 0));

    return Number.isFinite(pitch) && pitch > size ? { length: pitch + size, thickness: (pitch - size) * 0.8 } : null;
}

function watchConnection(onChange) {
    const pusher = window.Echo?.connector?.pusher;
    if (!pusher) {
        onChange('none');

        return;
    }

    onChange(pusher.connection.state === 'connected' ? 'connected' : 'connecting');
    pusher.connection.bind('state_change', ({ current }) => onChange(current));
}

function svgElement(name, attributes) {
    const element = document.createElementNS(SVG, name);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));

    return element;
}

registerAlpine(() => {
    window.Alpine.data('gameChat', gameChat);
    window.Alpine.data('boardGame', (config) => ({
        state: config.state,
        layout: config.layout,
        color: config.color,
        t: config.labels,
        radius: pieceRadius(config.layout.points),
        bar: barSize(config.layout.cells),
        receivedAt: performance.now(),
        now: performance.now(),
        clicks: [],
        // The block input (Blockli): set-a-block mode, the block shown before it is set, the last pointer.
        blockMode: false,
        preview: null,
        pointerType: 'touch',
        lastTap: null,
        pending: false,
        error: '',
        confirmResign: false,
        connection: 'connecting',
        wasConnected: false,
        ticker: null,
        poller: null,
        lastSyncAt: 0,
        syncing: false,
        clockCheckSent: 0,
        startPieces: config.startPieces ?? {},
        viewIndex: null,

        init() {
            this.ticker = setInterval(() => this.tick(), 200);
            this.lastSyncAt = performance.now();

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.resync();
            });

            watchConnection((current) => {
                const before = this.connection;
                this.connection = current;
                // Back after a drop: pushes may have been missed meanwhile.
                if (current === 'connected' && before !== 'connected' && this.wasConnected) this.resync();
                if (current === 'connected') this.wasConnected = true;
                this.syncPolling();
            });

            if (window.Echo) {
                const channel = this.color ? window.Echo.private('board.' + this.state.id) : window.Echo.channel('board.' + this.state.id + '.watch');
                channel.listen('.board.updated', (update) => this.receive(update));
                // Anything that happened between rendering the page and subscribing.
                channel.subscribed?.(() => this.resync());
            }

            // The block input (Blockli): a mouse shows the block it points at before the click sets it.
            if (this.layout.input === 'blocks' && this.$refs.board) {
                const board = this.$refs.board;
                board.addEventListener('pointerdown', (event) => (this.pointerType = event.pointerType));
                // Where the finger really was: on touch screens the browser moves a click onto the nearest
                // clickable point (a crossing), which would decide the direction of the block for the player.
                board.addEventListener('pointerup', (event) => (this.lastTap = { clientX: event.clientX, clientY: event.clientY, at: performance.now() }));
                board.addEventListener('pointermove', (event) => this.hoverBlock(event));
                // A finger leaves the board after every tap: only a mouse leaving takes the block shown away.
                board.addEventListener('pointerleave', (event) => event.pointerType === 'mouse' && this.hoverBlock(null));
            }

            this.render();

            this.onResize = () => this.fitBoard();
            addEventListener('resize', this.onResize);
            this.$watch('state.status', () => this.$nextTick(() => this.fitBoard()));
            this.$nextTick(() => this.fitBoard());
        },

        destroy() {
            clearInterval(this.ticker);
            clearInterval(this.poller);
            removeEventListener('resize', this.onResize);
        },

        /**
         * The whole board and what belongs under it fit the first screen, on every width (P2; on a desktop since the
         * user's 1893x929, where Confirm sat at 1098 px). The edge to end above: on a phone the bars fixed to the bottom
         * (Blockli's bar, the chat sheet, the app's tab bar), on a desktop the window. Under the board count the parts
         * of its column that are not fixed (the lower player card; Blockli's bar heads the other column from lg). A phone narrows the board;
         * a desktop narrows the board's grid column, so the cards and the bar go with it and the right column widens.
         */
        fitBoard() {
            const board = this.$refs.board;
            if (!board) return;
            const column = board.parentElement;
            const grid = column.parentElement;
            const page = this.$root.closest('[data-test=board-page]') ?? this.$root;
            const phone = matchMedia('(width < 64rem)').matches;
            const shown = (el) => !!el && el.checkVisibility() && el.getBoundingClientRect().height > 0;
            const fixed = (el) => getComputedStyle(el).position === 'fixed';
            const tabbar = parseFloat(getComputedStyle(document.body).paddingBottom) || 0;
            const sheet = document.querySelector('[data-test=chat-sheet-toggle]')?.closest('section');
            const dock = this.$refs.dock;
            let edge = innerHeight - tabbar;
            if (shown(sheet) && fixed(sheet)) edge = Math.min(edge, sheet.getBoundingClientRect().top);
            if (shown(dock) && fixed(dock)) edge = Math.min(edge, dock.getBoundingClientRect().top);
            const drawn = board.getBoundingClientRect();
            const under = Math.max(drawn.bottom, ...[...column.children].filter((el) => shown(el) && !fixed(el)).map((el) => el.getBoundingClientRect().bottom)) - drawn.bottom;
            // The first screen: the board's place on the page, whatever was scrolled.
            const room = edge - (drawn.top + scrollY) - under - (phone ? 8 : 16);
            const width = Math.round(room * (drawn.width / drawn.height));
            if (phone) {
                grid.style.gridTemplateColumns = '';
                board.style.maxWidth = Math.max(200, width) + 'px';
                page.style.paddingBottom = (shown(sheet) ? 72 : 0) + (shown(dock) ? dock.offsetHeight : 0) + 24 + 'px';

                return;
            }
            board.style.maxWidth = '';
            page.style.paddingBottom = '';
            grid.style.gridTemplateColumns = 'minmax(0, ' + Math.min(560, Math.max(300, width)) + 'px) minmax(0, 1fr)';
        },

        /* ---- the board ------------------------------------------------------------------------------ */

        get myTurn() {
            return this.color !== null && this.state.status === 'active' && this.state.turn === this.color;
        },

        /** Blockli's bar for a player of a running game: on a phone it is fixed above the chat sheet. */
        get hasDock() {
            return this.layout.input === 'blocks' && this.color !== null && this.state.status === 'active';
        },

        get canMove() {
            return this.myTurn && !this.pending && this.viewIndex === null;
        },

        /* ---- history ---------------------------------------------------------------------------------- */

        /** The ply the board shows: the newest while following the game. */
        get shownPly() {
            return this.viewIndex ?? this.state.moves.length;
        },

        /** The pieces after `index` plies; the newest are the live state's. */
        piecesAt(index) {
            if (index >= this.state.moves.length) return this.state.pieces;
            if (index <= 0) return this.startPieces;

            return this.state.moves[index - 1].pieces ?? this.state.pieces;
        },

        browse(index) {
            const newest = this.state.moves.length;
            const target = Math.max(0, Math.min(newest, index));
            this.viewIndex = target >= newest ? null : target;
            this.clicks = [];
            this.render();
        },

        browseKey(event) {
            if (event.target.closest?.('input, textarea, select')) return;
            if (this.layout.input === 'blocks' && this.blockKey(event)) return;
            const steps = { ArrowLeft: this.shownPly - 1, ArrowRight: this.shownPly + 1, Home: 0, End: this.state.moves.length };
            if (!(event.key in steps)) return;
            event.preventDefault();
            this.browse(steps[event.key]);
        },

        /** Moves made since the board was pinned to an earlier position. */
        get newMoves() {
            return this.viewIndex === null ? 0 : this.state.moves.length - this.viewIndex;
        },

        /** The points a click may go to next: the start of a move, or the next step of one begun. */
        get targets() {
            if (this.layout.input === 'blocks') return this.blockTargets;
            if (!this.canMove) return [];
            const next = new Set(candidates(this.state.legal, this.clicks).map((option) => option.path[this.clicks.length]));
            next.delete(undefined);

            return [...next];
        },

        render() {
            const svg = this.$refs.board;
            if (!svg) return;
            svg.replaceChildren();
            const r = this.radius;
            const targets = new Set(this.targets);
            const shown = this.shownPly;
            const pieces = this.piecesAt(shown);
            const last = new Set((shown > 0 ? this.state.moves[shown - 1]?.path : null) ?? (this.viewIndex === null ? (this.state.lastMove?.path ?? []) : []));

            this.layout.cells.forEach((cell) => svg.append(svgElement('rect', { x: cell.x, y: cell.y, width: cell.size, height: cell.size, fill: '#3F3F46' })));
            this.layout.lines.forEach(([x1, y1, x2, y2]) => svg.append(svgElement('line', { x1, y1, x2, y2, stroke: '#52525B', 'stroke-width': Math.max(2, r * 0.12), 'stroke-linecap': 'round' })));

            this.layout.points.forEach((point) => {
                const piece = pieces[point.id] ?? null;
                const clicked = this.clicks.includes(point.id);
                const group = svgElement('g', {
                    'data-point': point.id,
                    'data-piece': piece ? piece.side + ':' + piece.kind : '',
                    'data-target': targets.has(point.id) ? '1' : '0',
                    role: 'button',
                    tabindex: targets.has(point.id) || clicked ? 0 : -1,
                    'aria-label': this.pointLabel(point.id, piece),
                    'aria-pressed': clicked ? 'true' : 'false',
                    class: 'outline-none',
                });
                group.style.cursor = targets.has(point.id) || clicked ? 'pointer' : 'default';
                // The whole disc around the point takes the click, not only the piece.
                group.append(svgElement('circle', { cx: point.x, cy: point.y, r, fill: 'transparent' }));
                if (last.has(point.id)) group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 1.12, fill: 'rgba(247, 147, 26, 0.18)' }));
                group.append(svgElement('circle', { cx: point.x, cy: point.y, r: Math.max(3, r * 0.14), fill: '#71717A' }));
                const bar = piece && this.bar ? BARS[piece.kind] : null;
                if (bar) {
                    const full = bar.length === 'board';
                    const long = full ? this.bar.length : r * bar.length;
                    const thick = full ? this.bar.thickness : this.bar.thickness * 0.8;
                    const [width, height] = bar.horizontal ? [long, thick] : [thick, long];
                    group.append(svgElement('rect', { x: point.x - width / 2, y: point.y - height / 2, width, height, rx: thick / 2, fill: PIECE_FILL[piece.side], stroke: PIECE_STROKE[piece.side], 'stroke-width': Math.max(1.5, thick * 0.12) }));
                } else if (piece) {
                    group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 0.92, fill: PIECE_FILL[piece.side], stroke: PIECE_STROKE[piece.side], 'stroke-width': Math.max(2, r * 0.08) }));
                    if (piece.kind === 'king') group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 0.45, fill: 'none', stroke: '#F7931A', 'stroke-width': Math.max(2, r * 0.1) }));
                }
                if (clicked) group.append(svgElement('circle', { cx: point.x, cy: point.y, r: r * 1.02, fill: 'none', stroke: '#F7931A', 'stroke-width': Math.max(3, r * 0.12) }));
                if (targets.has(point.id) && !clicked) group.append(svgElement('circle', { cx: point.x, cy: point.y, r: Math.max(5, r * 0.26), fill: 'rgba(247, 147, 26, 0.75)' }));
                svg.append(group);
            });

            // The block input: the block shown at its crossing, orange if it may be set, red if not.
            if (this.layout.input === 'blocks') {
                svg.style.cursor = this.blockMode && this.canMove ? 'crosshair' : '';
                const at = this.blockMode && this.preview && this.bar ? this.layout.points.find((point) => point.id === this.preview.crossing) : null;
                if (at) {
                    const [width, height] = this.preview.dir === 'h' ? [this.bar.length, this.bar.thickness] : [this.bar.thickness, this.bar.length];
                    svg.append(svgElement('rect', { x: at.x - width / 2, y: at.y - height / 2, width, height, rx: this.bar.thickness / 2, fill: this.preview.move ? 'rgba(247, 147, 26, 0.9)' : 'rgba(239, 68, 68, 0.8)', 'pointer-events': 'none', 'data-preview': this.preview.move ? 'legal' : 'illegal' }));
                }
            }
        },

        pointLabel(id, piece) {
            return piece ? id + ', ' + (piece.side === 'w' ? this.t.white : this.t.black) : id;
        },

        pick(event) {
            if (this.layout.input === 'blocks') return this.tapBlocks(event);
            const point = event.target.closest?.('[data-point]')?.dataset.point;
            if (point) this.click(point);
        },

        pickKey(event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const point = event.target.closest?.('[data-point]')?.dataset.point;
            if (!point) return;
            event.preventDefault();
            if (this.layout.input === 'blocks') {
                const move = this.pawnMoves.get(point);
                if (move) this.send(move);

                return;
            }
            this.click(point);
        },

        click(point) {
            if (!this.canMove) return;
            this.error = '';

            // A second click on the last point takes that click back.
            if (this.clicks.length > 0 && this.clicks[this.clicks.length - 1] === point) {
                this.clicks = this.clicks.slice(0, -1);
                this.render();

                return;
            }

            const next = [...this.clicks, point];
            const options = candidates(this.state.legal, next);

            if (options.length === 0) {
                // Not part of the move begun: start over from this point, if a move starts there.
                this.clicks = candidates(this.state.legal, [point]).length > 0 ? [point] : [];
                const complete = this.clicks.length === 1 ? candidates(this.state.legal, this.clicks).find((option) => option.path.length === 1) : null;
                if (complete) return this.send(complete.move);
                this.render();

                return;
            }

            const complete = options.find((option) => option.path.length === next.length);
            if (complete) return this.send(complete.move);

            this.clicks = next;
            this.render();
        },

        async send(move) {
            this.clicks = [];
            this.blockMode = false;
            this.preview = null;
            this.pending = true;
            this.render();
            try {
                await this.call('move', move, this.state.ply + 1);
            } finally {
                this.pending = false;
                this.render();
            }
        },

        /* ---- the block input (Blockli) ---------------------------------------------------------------- */
        // A layout with `input: 'blocks'` plays as the Blockli prototype: one tap on a target square moves
        // the pawn; a tap in a groove, or anywhere after "Set a block", shows a block at the nearest
        // crossing, lying along the groove nearest to the tap; a second tap on that crossing or "Set" sets
        // it, "Rotate" turns it. With a mouse the block follows the pointer and a click sets it. The board
        // still knows no rules: a block is one of the legal moves the server sent (path [crossing, h|v]),
        // anything else shows red and cannot be set.

        /** The legal pawn moves by their target square. */
        get pawnMoves() {
            const moves = new Map();
            if (this.canMove) this.state.legal.forEach((option) => !BLOCK_DIRECTIONS.includes(option.path[1]) && moves.set(option.path[option.path.length - 1], option.move));

            return moves;
        },

        /** The legal blocks by crossing and direction (`e3/f4 h`). */
        get blockMoves() {
            const moves = new Map();
            if (this.canMove) this.state.legal.forEach((option) => BLOCK_DIRECTIONS.includes(option.path[1]) && moves.set(option.path[0] + ' ' + option.path[1], option.move));

            return moves;
        },

        get blockTargets() {
            return this.blockMode ? [] : [...this.pawnMoves.keys()];
        },

        get canSetBlocks() {
            return this.blockMoves.size > 0;
        },

        /** The blocks the player has left: the spares of their side in the tray. */
        get blocksLeft() {
            return Object.values(this.state.pieces).filter((piece) => piece.kind === 'spare' && piece.side === this.color).length;
        },

        get blockHint() {
            if (!this.blockMode || !this.canMove) return '';
            if (this.pointerType === 'keyboard') {
                if (!this.preview) return this.t.blocks.keys;
                const direction = this.preview.dir === 'h' ? this.t.blocks.horizontal : this.t.blocks.vertical;
                const where = this.t.blocks.at.replace(':crossing', this.preview.crossing).replace(':direction', direction);

                return where + ' ' + (this.preview.move ? this.t.blocks.enter : this.t.blocks.illegal);
            }
            const mouse = this.pointerType === 'mouse';
            if (!this.preview) return mouse ? this.t.blocks.point : this.t.blocks.tap;
            if (!this.preview.move) return this.t.blocks.illegal;

            return mouse ? this.t.blocks.click : this.t.blocks.confirm;
        },

        /** `event` is the button's click: one from a key (detail 0) switches the hint to the keys. */
        setBlockMode(on, event = null) {
            if (event && event.detail === 0) this.pointerType = 'keyboard';
            this.blockMode = on && this.canMove && this.canSetBlocks;
            this.preview = null;
            this.error = '';
            this.render();
        },

        rotateBlock() {
            if (!this.preview) return;
            this.showBlock(this.preview.crossing, this.preview.dir === 'h' ? 'v' : 'h', true);
            this.render();
        },

        setBlock() {
            if (!this.preview || !this.canMove) return;
            if (this.preview.move) return this.send(this.preview.move);
            this.error = this.t.blocks.illegal;
            this.render();
        },

        tapBlocks(event) {
            if (!this.canMove) return;
            this.error = '';
            const tap = this.lastTap && performance.now() - this.lastTap.at < 1000 ? this.lastTap : event;
            this.lastTap = null;
            const at = this.boardPoint(tap);
            if (!at) return;

            if (!this.blockMode) {
                const square = this.squareAt(at);
                const move = square === null ? null : this.pawnMoves.get(square);
                if (move) return this.send(move);
                // A tap in a groove asks for a block right there.
                if (square === null && this.onBoard(at) && this.canSetBlocks) {
                    this.blockMode = true;
                    this.showBlockAt(at);
                }
                this.render();

                return;
            }

            if (!this.onBoard(at)) return;
            // A second tap on the crossing shown sets its block, turned or not; a mouse click sets what it shows.
            const same = this.preview?.crossing === this.nearestCrossing(at).id;
            if (same && this.pointerType !== 'mouse') return this.setBlock();
            if (!same) this.showBlockAt(at);
            if (this.pointerType === 'mouse') return this.setBlock();
            this.render();
        },

        hoverBlock(event) {
            if (event && event.pointerType !== 'mouse') return;
            // A mouse that only moved over the board (after clicking "Set a block") gets the mouse hint too.
            if (event) this.pointerType = 'mouse';
            if (!this.blockMode || !this.canMove) return;
            const shown = this.preview ? this.preview.crossing + this.preview.dir : '';
            const at = event ? this.boardPoint(event) : null;
            if (at && this.onBoard(at)) this.showBlockAt(at);
            else this.preview = null;
            if ((this.preview ? this.preview.crossing + this.preview.dir : '') !== shown) this.render();
        },

        /**
         * Keys while setting a block: the arrows move it from crossing to crossing (as the board is seen, also
         * on Black's turned board), R turns it, Enter sets it, Escape leaves the mode. Enter on the Rotate or
         * Move button keeps doing what that button says.
         */
        blockKey(event) {
            if (!this.blockMode || !this.canMove) return false;
            const step = BLOCK_KEY_STEPS[event.key];
            if (event.key === 'Escape') {
                this.setBlockMode(false);
            } else if (event.key === 'r' || event.key === 'R') {
                this.pointerType = 'keyboard';
                if (this.preview) this.rotateBlock();
                else this.moveBlock([0, 0]);
            } else if (step) {
                this.pointerType = 'keyboard';
                this.moveBlock(this.color === 'b' ? [-step[0], -step[1]] : step);
            } else if (event.key === 'Enter' && this.preview && !event.target.closest?.('[data-test="rotate-block"], [data-test="mode-move"]')) {
                this.pointerType = 'keyboard';
                this.setBlock();
            } else {
                return false;
            }
            event.preventDefault();

            return true;
        },

        /** Moves the block shown to the next crossing in a direction ([dx, dy] in board units); the first key shows it in the middle. */
        moveBlock([dx, dy]) {
            if (!this.preview) {
                this.showBlock(this.centreCrossing().id, 'h');
                this.render();

                return;
            }
            const from = this.layout.points.find((point) => point.id === this.preview.crossing);
            let next = null;
            let distance = Infinity;
            geometryOf(this.layout).crossings.forEach((point) => {
                const along = (point.x - from.x) * dx + (point.y - from.y) * dy;
                const across = Math.abs((point.x - from.x) * dy) + Math.abs((point.y - from.y) * dx);
                if (along > 0 && across < 1 && along < distance) [next, distance] = [point, along];
            });
            if (next) this.showBlock(next.id, this.preview.dir, this.preview.turned);
            this.render();
        },

        /** The crossing nearest to the middle of the cells. */
        centreCrossing() {
            const cells = this.layout.cells;
            const size = cells[0].size;
            const xs = cells.map((cell) => cell.x);
            const ys = cells.map((cell) => cell.y);

            return this.nearestCrossing({ x: (Math.min(...xs) + Math.max(...xs) + size) / 2, y: (Math.min(...ys) + Math.max(...ys) + size) / 2 });
        },

        /** Shows a block; `turned` marks one the player rotated, which keeps its direction on that crossing. */
        showBlock(crossing, dir, turned = false) {
            this.preview = { crossing, dir, turned, move: this.blockMoves.get(crossing + ' ' + dir) ?? null };
        },

        /** Shows the block at the crossing nearest to a board position, along the groove the position lies in. */
        showBlockAt(at) {
            const crossing = this.nearestCrossing(at);
            if (this.preview?.turned && this.preview.crossing === crossing.id) return;
            this.showBlock(crossing.id, Math.abs(at.x - crossing.x) >= Math.abs(at.y - crossing.y) ? 'h' : 'v');
        },

        /** Where a pointer (an event, or a tap noted from one) lies in board units; Black's board is turned. */
        boardPoint(event) {
            const svg = this.$refs.board;
            if (!svg) return null;
            const box = svg.getBoundingClientRect();
            const { width, height } = this.layout;
            const scale = Math.min(box.width / width, box.height / height);
            let x = (event.clientX - box.left - (box.width - width * scale) / 2) / scale;
            let y = (event.clientY - box.top - (box.height - height * scale) / 2) / scale;
            const style = getComputedStyle(svg);
            if (style.rotate === '180deg' || style.transform.startsWith('matrix(-1, 0, 0, -1')) [x, y] = [width - x, height - y];

            return { x, y };
        },

        /** The square of the cell under a board position, null in a groove or off the cells. */
        squareAt(at) {
            const cell = this.layout.cells.find((c) => at.x >= c.x && at.x <= c.x + c.size && at.y >= c.y && at.y <= c.y + c.size);

            return cell ? (geometryOf(this.layout).squareOf.get(cell.x + cell.size / 2 + ',' + (cell.y + cell.size / 2)) ?? null) : null;
        },

        /** Whether a board position lies on the cells and the grooves between them (not in a tray). */
        onBoard(at) {
            return geometryOf(this.layout).inside(at);
        },

        /** The crossing nearest to a board position. */
        nearestCrossing(at) {
            let nearest = null;
            let distance = Infinity;
            geometryOf(this.layout).crossings.forEach((point) => {
                const d = Math.hypot(point.x - at.x, point.y - at.y);
                if (d < distance) [nearest, distance] = [point, d];
            });

            return nearest;
        },

        /* ---- clocks and status ---------------------------------------------------------------------- */

        tick() {
            this.now = performance.now();
            if (this.state.status !== 'active') return;
            if (this.connection === 'connected' && this.now - this.lastSyncAt > HEARTBEAT_MS) this.resync();
            const running = this.state.clock.running;
            const due = running ? this.remaining(running) <= 0 : this.state.firstMoveDeadline && this.serverNow() >= this.state.firstMoveDeadline;
            // The display reached zero: ask the server, which alone decides.
            if (due && this.now - this.clockCheckSent > 2000) {
                this.clockCheckSent = this.now;
                this.call('checkClock');
            }
        },

        serverNow() {
            return this.state.clock.serverNow + (this.now - this.receivedAt);
        },

        remaining(side) {
            const base = this.state.clock[side];
            if (this.state.status === 'active' && this.state.clock.running === side) {
                return Math.max(0, base - (this.now - this.receivedAt));
            }

            return base;
        },

        clock(side) {
            return this.state.daily ? formatDeadline(this.remaining(side)) : formatClock(this.remaining(side));
        },

        /** Correspondence: when the side to move's move is due, in the page's language. */
        get deadlineLine() {
            if (!this.state.daily || this.state.status !== 'active' || !this.state.deadline) return '';
            const due = new Date(this.state.deadline).toLocaleString(document.documentElement.lang || undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

            return this.t.deadline.replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black).replace(':time', due);
        },

        get topSide() {
            return this.color === 'b' ? 'w' : 'b';
        },

        get bottomSide() {
            return this.color === 'b' ? 'b' : 'w';
        },

        sideName(side) {
            return this.t.names[side] + ' · ' + (side === 'w' ? this.t.white : this.t.black);
        },

        get statusLine() {
            if (this.state.status === 'aborted') return this.t.status.aborted;
            if (this.state.status !== 'active') return this.t.status.over;

            return (this.state.daily ? this.t.status.daily : this.t.status.live).replace(':move', this.state.ply + 1).replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black);
        },

        /** Seconds left for the first move, or null when no first-move deadline runs. */
        get firstMoveLeft() {
            if (this.state.status !== 'active' || !this.state.firstMoveDeadline) return null;

            return Math.max(0, Math.ceil((this.state.firstMoveDeadline - this.serverNow()) / 1000));
        },

        get firstMoveLine() {
            if (this.state.status !== 'active' || !this.state.firstMoveDeadline) return '';
            const seconds = Math.max(0, Math.ceil((this.state.firstMoveDeadline - this.serverNow()) / 1000));

            return this.t.firstMove.replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black).replace(':s', seconds);
        },

        get outcome() {
            if (this.state.status === 'active') return '';
            if (this.state.status === 'aborted') return this.t.outcome.aborted;
            const reason = this.t.reasons[this.state.reason] ?? this.state.reason ?? '';
            if (this.state.result === '1/2-1/2') return this.t.outcome.draw + ' · ' + reason;
            const winner = this.state.result === '1-0' ? 'w' : 'b';

            return this.t.outcome.wins.replace(':name', this.t.names[winner]) + ' · ' + reason;
        },

        get connectionLabel() {
            if (this.connection === 'connected') return this.t.connection.connected;
            if (this.connection === 'none') return this.t.connection.polling;

            return this.t.connection.connecting;
        },

        /* ---- state from the server ------------------------------------------------------------------ */

        syncPolling() {
            const needed = this.connection !== 'connected' && this.state.status === 'active';
            if (needed && !this.poller) {
                this.poller = setInterval(() => this.resync(), 3000);
            } else if (!needed && this.poller) {
                clearInterval(this.poller);
                this.poller = null;
            }
        },

        apply(state) {
            if (!state || state.version < this.state.version) return;
            const changed = state.version !== this.state.version;
            this.state = { ...state, moves: state.moves ?? this.state.moves };
            this.receivedAt = performance.now();
            this.lastSyncAt = this.receivedAt;
            this.now = this.receivedAt;
            if (changed) this.clicks = [];
            // The block input: a block shown is checked against the new legal moves; off the move none is shown.
            if (changed && this.layout.input === 'blocks') {
                if (!this.canMove) [this.blockMode, this.preview] = [false, null];
                else if (this.preview) this.showBlock(this.preview.crossing, this.preview.dir, this.preview.turned);
            }
            if (this.state.status !== 'active') this.confirmResign = false;
            this.syncPolling();
            this.render();
        },

        receive(update) {
            if (update.version <= this.state.version) return;
            if (update.ply === this.state.ply + 1 && update.lastMove) {
                this.apply({ ...update, moves: [...this.state.moves, update.lastMove] });
            } else if (update.ply === this.state.ply) {
                this.apply({ ...update, moves: this.state.moves });
            } else {
                this.resync();
            }
        },

        async resync() {
            if (this.syncing) return;
            this.syncing = true;
            this.lastSyncAt = performance.now();
            try {
                const state = await this.$wire.fetchState();
                if (state) this.apply(state);
            } finally {
                this.syncing = false;
            }
        },

        async call(method, ...args) {
            const response = await this.$wire[method](...args);
            if (!response) return null;
            if (response.state) this.apply(response.state);
            this.error = response.ok === false ? (this.t.errors[response.error] ?? this.t.errors.default) : '';

            return response;
        },

        resign() {
            if (!this.confirmResign) {
                this.confirmResign = true;

                return;
            }
            this.confirmResign = false;
            this.call('resign');
        },
    }));
});
