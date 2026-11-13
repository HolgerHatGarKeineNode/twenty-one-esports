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
 *
 * Practice (Blockli against a bot, DerCaddy 2026-10-23): the same board for a
 * game its page keeps in the browser. `config.source` names an entry of
 * boardSources that answers the board's actions in the server's place; the
 * board then neither listens on a channel nor polls.
 *
 * Go (DerCaddy, 2026-10-27; layout `input: 'stones'`): a board of lines with
 * star points and the coordinates of a go board, a stone shown on the nearest
 * intersection first and set by a second tap, a pass button that asks before
 * a player's first pass and before the pass that ends the game, and the count
 * as it stands beside the board.
 */

import { setFocus } from './focusMode.js';
import { gameChat } from './gameChat.js';
import { registerAlpine } from './registerAlpine.js';
// Blockspace's styles ride with the board script, not with the lazily loaded board (blockspace/board.js): as a CSS
// dependency of that dynamic import, Vite's preload left the import pending on a page loaded fresh, and the block,
// the ladder and the yields stayed empty at every width (measured 2026-11-07). Every selector is `.bs-*`.
import './blockspace/board.css';

const SVG = 'http://www.w3.org/2000/svg';

/** How long the page trusts the websocket alone before it asks the server. */
const HEARTBEAT_MS = 4000;

/** How long the repetition warning stands over the board after a move (it stays beside the board). */
const WARNING_MS = 6000;

/**
 * The smallest board a desktop gives up for what stands under it (the game's actions, the lower player card), in px:
 * on a 9 x 9 board about 34 px a square. A window too short for it below the header gets what fits there (fitBoard).
 */
const DESKTOP_BOARD = 380;

/** How often the position of a state has stood there (Blockli's repetition warning), counted once per state. */
const REPEATS = new WeakMap();

/**
 * Boards whose page keeps the game in the browser instead of on the server (Blockli practice against a bot,
 * resources/js/blockli/practice.js): `config.source` names an entry here, an object with the actions the board asks
 * its Livewire component for (fetchState, move, resign, …), answered as the server would answer them. Such a board
 * neither listens on a channel nor polls: its page hands every change of the game to `apply()` itself.
 */
export const boardSources = new Map();

// Black men are dark discs with a light rim: on the dark board a dark rim would hide them.
const PIECE_FILL = { w: '#F4F4F5', b: '#09090B' };
const PIECE_STROKE = { w: '#A1A1AA', b: '#D4D4D8' };

/**
 * The go board (the stone input): wood with dark lines and star points, as a real board looks, so black and white
 * stones both stand out; the last stone ringed in the league's orange. The first side plays black (Go: Black moves
 * first); every stone on the board names its colour (`kind`).
 */
const GO = { wood: '#D8AD60', line: '#3B2C16', label: '#5A4422', ring: '#F7931A', illegal: 'rgba(239, 68, 68, 0.55)', cross: 'rgba(247, 147, 26, 0.6)' };
const STONE_FILL = { black: 'url(#go-stone-black)', white: 'url(#go-stone-white)' };
const STONE_EDGE = { black: '#050506', white: '#9A958B' };
const STONE_GRADIENTS =
    '<radialGradient id="go-stone-black" cx="36%" cy="32%" r="70%"><stop offset="0" stop-color="#55555C"/><stop offset="0.45" stop-color="#1C1C20"/><stop offset="1" stop-color="#08080A"/></radialGradient>' +
    '<radialGradient id="go-stone-white" cx="36%" cy="32%" r="75%"><stop offset="0" stop-color="#FFFFFF"/><stop offset="0.6" stop-color="#F1EFEA"/><stop offset="1" stop-color="#CFCBC2"/></radialGradient>';

/** The colour of a side's stones under the stone input: the first side plays black. */
export function stoneKind(side) {
    return side === 'w' ? 'black' : 'white';
}

/**
 * A board of stones comes short from the server (BoardGameService::snapshot(): a push of 19 x 19 has to fit into one
 * Reverb message): the stones as one character per point, the empty points that may not be played instead of every
 * legal move, each side's area as one character per point. This turns such a state into the form every other board
 * has (pieces by point, legal moves with their paths, the areas as lists), so the rest of the script reads one form.
 * A state that already has it (another board, the practice page's own answers) passes through.
 */
export function unpackStones(state, points) {
    if (!state || typeof state.pieces !== 'string') return state;
    const refused = new Set(state.illegal ?? []);
    const live = state.status === 'active';
    const pieces = {};
    const legal = [];
    points.forEach((point, index) => {
        const stone = state.pieces[index];
        if (stone === 'x' || stone === 'o') pieces[point.id] = stonePiece(stone === 'x' ? 'w' : 'b');
        else if (live && !refused.has(point.id)) legal.push({ move: point.id, path: [point.id] });
    });
    if (live && state.pass) legal.push({ move: state.pass, path: [] });
    const count = state.count && typeof state.count.owners === 'string' ? { ...state.count, owners: { w: [], b: [] } } : state.count;
    if (count !== state.count) points.forEach((point, index) => count.owners[state.count.owners[index]]?.push(point.id));

    return { ...state, pieces, legal, count };
}

/** A side's stone as a piece of the board. */
function stonePiece(side) {
    return { side, kind: stoneKind(side) };
}

/**
 * The stones after `index` plies of a record that names what each move took (`taken`) instead of the pieces after it:
 * built from the nearest position known, and kept (`known`, by ply) for the next step.
 */
function stonesAfter(moves, index, startPieces, known) {
    let from = index;
    while (from > 0 && !moves[from - 1].pieces && !known.has(from)) from--;
    let pieces = from === 0 ? startPieces : (moves[from - 1].pieces ?? known.get(from));
    for (let ply = from + 1; ply <= index; ply++) {
        const move = moves[ply - 1];
        pieces = { ...pieces };
        (move.taken ?? []).forEach((id) => delete pieces[id]);
        if (move.path[0] && move.side) pieces[move.path[0]] = stonePiece(move.side);
        known.set(ply, pieces);
    }

    return pieces;
}

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

/** Whether an element is rendered: checkVisibility(), or its boxes where a browser lacks it (Safari before 17.4). */
function rendered(el) {
    return !!el && (el.checkVisibility?.() ?? el.getClientRects().length > 0);
}

function svgElement(name, attributes) {
    const element = document.createElementNS(SVG, name);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));

    return element;
}

registerAlpine(() => {
    window.Alpine.data('gameChat', gameChat);
    // The stones of earlier positions of a record without pieces (stonesAfter()), by ply; emptied with every new state.
    window.Alpine.data('boardGame', (config, stonesKnown = new Map()) => ({
        state: unpackStones(config.state, config.layout.points),
        layout: config.layout,
        color: config.color,
        t: config.labels,
        radius: pieceRadius(config.layout.points),
        bar: barSize(config.layout.cells),
        receivedAt: performance.now(),
        now: performance.now(),
        clicks: [],
        // The block input (Blockli): set-a-block mode, the block shown before it is set, the last pointer, and how far from
        // the board's top and bottom edge the part of it in view begins (where the repetition warning stands).
        blockMode: false,
        preview: null,
        band: { top: 8, bottom: 8 },
        onScroll: null,
        warningUntil: 0,
        // A line for the other side's take-back (":name took the move back"), shown until noticeUntil.
        notice: '',
        noticeUntil: 0,
        // The stone input (Go): the intersection whose stone is shown before it is set, the pass that would end the game
        // waiting for its second click, and the area of each side marked on the board.
        stone: null,
        confirmPass: false,
        showArea: false,
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
        // The page's own answers in place of the server (boardSources), null for a game the server keeps.
        source: config.source ? (boardSources.get(config.source) ?? null) : null,

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

            if (window.Echo && !this.source) {
                const channel = this.color ? window.Echo.private('board.' + this.state.id) : window.Echo.channel('board.' + this.state.id + '.watch');
                channel.listen('.board.updated', (update) => this.receive(update));
                // Anything that happened between rendering the page and subscribing.
                channel.subscribed?.(() => this.resync());
            }
            // A page that plays in the browser gets the board, to hand it the other side's moves (boardSources).
            this.source?.attach?.(this);

            // The block input (Blockli): a mouse shows the block it points at before the click sets it.
            if (this.layout.input === 'blocks' && this.$refs.board) {
                const board = this.$refs.board;
                board.addEventListener('pointerdown', (event) => (this.pointerType = event.pointerType));
                // Where the finger really was: on touch screens the browser moves a click onto the nearest
                // clickable point (a crossing), which would decide the direction of the block for the player.
                board.addEventListener('pointerup', (event) => (this.lastTap = { clientX: event.clientX, clientY: event.clientY, at: performance.now() }));
                board.addEventListener('pointermove', (event) => this.hoverBlock(event));
                // A finger leaves the board after every tap: only a mouse leaving takes the block shown away. Not while the keys
                // steer: a mouse resting near the board must not take away the block the keys moved.
                board.addEventListener('pointerleave', (event) => event.pointerType === 'mouse' && this.pointerType !== 'keyboard' && this.hoverBlock(null));
                // The repetition warning over the board follows the part of it in view, once a frame at most.
                let queued = false;
                this.onScroll = () => {
                    if (queued) return;
                    queued = true;
                    requestAnimationFrame(() => {
                        queued = false;
                        this.measureBand();
                    });
                };
                window.addEventListener('scroll', this.onScroll, { passive: true });
                window.addEventListener('resize', this.onScroll);
                this.measureBand();
                this.warningUntil = performance.now() + WARNING_MS;
            }

            // Blockspace brings its own input and its jewels, loaded only for its boards.
            if (this.layout.input === 'pieces' && this.$refs.board) {
                import('./blockspace/board.js').catch(() => (this.error = this.t.errors.default)).then(({ mountPieceBoard } = {}) => {
                    if (!mountPieceBoard) return;
                    this.$refs.board.pieceBoard = mountPieceBoard(this);
                    this.fitBoard();
                    this.render();
                    this.fitBoard();
                    this.$refs.board.pieceBoard.openOnBlock();
                });
            }

            // The stone input (Go): a mouse shows the stone under it before the click sets it; a finger taps twice.
            if (this.layout.input === 'stones' && this.$refs.board) {
                const board = this.$refs.board;
                board.addEventListener('pointerdown', (event) => (this.pointerType = event.pointerType));
                board.addEventListener('pointermove', (event) => this.hoverStone(event));
                board.addEventListener('pointerleave', (event) => event.pointerType === 'mouse' && this.pointerType === 'mouse' && this.hoverStone(null));
                // A game that ends counted shows each side's area at once (the count says why it ended so).
                this.showArea = this.counted;
            }

            this.render();

            // Only another width fits anew (a turned phone, a resized window). A phone's browser shows and hides its
            // address bar while scrolling and fires `resize` with another height each time: a board that followed it
            // changed the page's height, which moved the bar again, and the board jumped between two sizes (players,
            // 2026-10-09). The keyboard of the chat input changes only the height too.
            let fittedWidth = innerWidth;
            this.onResize = () => {
                if (innerWidth === fittedWidth && matchMedia('(width < 64rem)').matches) return;
                fittedWidth = innerWidth;
                this.fitBoard();
            };
            addEventListener('resize', this.onResize);
            // Focus mode (focusMode.js): the tab bar slid away or came back, so the first screen changed height.
            this.onFocusMode = () => this.fitBoard();
            addEventListener('focus-mode', this.onFocusMode);
            this.$watch('state.status', () => this.$nextTick(() => this.fitBoard()));
            this.$nextTick(() => this.fitBoard());
        },

        destroy() {
            clearInterval(this.ticker);
            clearInterval(this.poller);
            removeEventListener('resize', this.onResize);
            removeEventListener('focus-mode', this.onFocusMode);
            if (this.onScroll) {
                window.removeEventListener('scroll', this.onScroll);
                window.removeEventListener('resize', this.onScroll);
            }
        },

        /**
         * The whole board and what belongs under it fit the first screen, on every width (P2; on a desktop since the
         * user's 1893x929, where Confirm sat at 1098 px). The edge to end above: on a phone the bars fixed to the bottom
         * (Blockli's bar, the chat sheet, the app's tab bar), on a desktop the window. Under the board count the parts
         * of its column that are not fixed (the lower player card; Blockli's bar heads the other column from lg). A phone narrows the board;
         * a desktop narrows the board's grid column, so the cards and the bar go with it and the right column widens. On a desktop the
         * board gives way only down to DESKTOP_BOARD; a window too short for that much below its header gets the board that fits there.
         */
        fitBoard() {
            const board = this.$refs.board;
            if (!board) return;
            if (this.layout.input === 'pieces') return board.pieceBoard?.fit();
            // The box around the board (Blockli's repetition warning stands in it) takes the size, so the warning keeps to the board.
            const box = board.closest('[data-board-box]') ?? board;
            const column = box.parentElement;
            const grid = column.parentElement;
            const page = this.$root.closest('[data-test=board-page]') ?? this.$root;
            const phone = matchMedia('(width < 64rem)').matches;
            const shown = (el) => rendered(el) && el.getBoundingClientRect().height > 0;
            const fixed = (el) => getComputedStyle(el).position === 'fixed';
            // The tab bar's room, none while focus mode has slid it away (focusMode.js; the body keeps its padding).
            const tabbar = document.documentElement.classList.contains('is-focus') ? 0 : parseFloat(getComputedStyle(document.body).paddingBottom) || 0;
            const sheet = document.querySelector('[data-test=chat-sheet-toggle]')?.closest('section');
            const dock = this.$refs.dock;
            let edge = innerHeight - tabbar;
            if (shown(sheet) && fixed(sheet)) edge = Math.min(edge, sheet.getBoundingClientRect().top);
            if (shown(dock) && fixed(dock)) edge = Math.min(edge, dock.getBoundingClientRect().top);
            // The action dock's tab on top of that bar (components/⚡match-dock, P7): nothing lies over the board.
            const dockTab = document.querySelector('[data-test=dock-bar-tab]');
            if (phone && shown(dockTab)) edge = Math.min(edge, dockTab.getBoundingClientRect().top);
            const drawn = board.getBoundingClientRect();
            // On a phone the game's actions under the board (abort, draw, resign) may follow below the first screen: the board keeps its size.
            const under = Math.max(drawn.bottom, ...[...column.children].filter((el) => shown(el) && !fixed(el) && !(phone && el.hasAttribute('data-fit-skip'))).map((el) => el.getBoundingClientRect().bottom)) - drawn.bottom;
            // The first screen: the board's place on the page, whatever was scrolled.
            const room = edge - (drawn.top + scrollY) - under - (phone ? 8 : 16);
            const width = Math.round(room * (drawn.width / drawn.height));
            if (phone) {
                // Too short a phone for the board's floor of 200 px (an iPhone SE in Safari, 553 px high: the board reached 40 px
                // under Blockli's bar until the first tap): the tab bar slides away at once, as focus mode does on that tap, and
                // the board fits again in the room it gives up (focus-mode calls this anew). Only on a focus page, once.
                if (width < 200 && tabbar > 0 && document.documentElement.dataset.focus === 'shown') setFocus(true);
                grid.style.gridTemplateColumns = '';
                box.style.maxWidth = Math.max(200, width) + 'px';
                page.style.paddingBottom = (shown(sheet) ? 72 : 0) + (shown(dock) ? dock.offsetHeight : 0) + 24 + 'px';
                // The warning over the board follows the board's new size.
                if (this.layout.input === 'blocks') this.$nextTick(() => this.measureBand());

                return;
            }
            box.style.maxWidth = '';
            page.style.paddingBottom = '';
            // The board gives way to the actions and the lower card only down to DESKTOP_BOARD: below that they follow under the
            // first screen. A short window (a laptop at 150 %, about 1250 x 555: DerCaddy, 2026-10-14, the board had fallen to its
            // floor of 300 px) gets the board that fits below the sticky header (the root's scroll-padding-top) with 16 px of air:
            // scrolled to, it is whole there, the title row above it and the actions below.
            const band = Math.round((innerHeight - (parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0) - 16) * (drawn.width / drawn.height));
            // From 87.5rem the players' chat is a third column (the page's grid classes).
            const third = this.$root.querySelector('[data-test=chat-panel]') && matchMedia('(width >= 87.5rem)').matches ? ' 380px' : '';
            grid.style.gridTemplateColumns = 'minmax(0, ' + Math.min(560, Math.max(300, width, Math.min(DESKTOP_BOARD, band))) + 'px)' + third + ' minmax(0, 1fr)';
        },

        /* ---- the board ------------------------------------------------------------------------------ */

        get myTurn() {
            return this.color !== null && this.state.status === 'active' && this.state.turn === this.color;
        },

        /** Blockli's bar (or Go's) for a player of a running game: on a phone it is fixed above the chat sheet. */
        get hasDock() {
            return (this.layout.input === 'blocks' || this.layout.input === 'stones') && this.color !== null && this.state.status === 'active';
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
            const move = this.state.moves[index - 1];
            // A record of stones names what each move took, not the pieces after it.
            if (!move.pieces && move.taken) return stonesAfter(this.state.moves, index, this.startPieces, stonesKnown);

            return move.pieces ?? this.state.pieces;
        },

        browse(index) {
            const newest = this.state.moves.length;
            const target = Math.max(0, Math.min(newest, index));
            this.viewIndex = target >= newest ? null : target;
            this.clicks = [];
            this.render();
        },

        browseKey(event) {
            // The board took the key already (the stone input moves its stone with the arrows).
            if (event.defaultPrevented || event.target.closest?.('input, textarea, select')) return;
            if (this.layout.input === 'blocks' && this.blockKey(event)) return;
            if (this.$refs.board?.pieceBoard?.key(event)) return;
            // The tutorial has no way back from an earlier position on its page.
            if (this.layout.input === 'pieces' && this.source) return;
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
            // Go: nearly every intersection is a move; the board marks none, it shows the stone where it would go.
            if (this.layout.input === 'stones' || !this.canMove) return [];
            const next = new Set(candidates(this.state.legal, this.clicks).map((option) => option.path[this.clicks.length]));
            next.delete(undefined);

            return [...next];
        },

        render() {
            const svg = this.$refs.board;
            if (!svg) return;
            if (this.layout.input === 'pieces') return svg.pieceBoard?.render();
            svg.replaceChildren();
            if (this.layout.input === 'stones') return this.renderStones(svg);
            const r = this.radius;
            const targets = new Set(this.targets);
            const shown = this.shownPly;
            const pieces = this.piecesAt(shown);
            const last = new Set((shown > 0 ? this.state.moves[shown - 1]?.path : null) ?? (this.viewIndex === null ? (this.state.lastMove?.path ?? []) : []));

            this.layout.cells.forEach((cell) => svg.append(svgElement('rect', { x: cell.x, y: cell.y, width: cell.size, height: cell.size, fill: '#3F3F46' })));
            this.layout.lines.forEach(([x1, y1, x2, y2]) => svg.append(svgElement('line', { x1, y1, x2, y2, stroke: '#52525B', 'stroke-width': Math.max(2, r * 0.12), 'stroke-linecap': 'round' })));
            this.drawCoordinates(svg);

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

        /**
         * The squares' names on the edge squares, as on a chessboard (Ben, 2026-10-11: "spart das ständige Abzählen,
         * wenn man alte Züge nachvollziehen will"): the file in the bottom right corner of the bottom row, the rank in the
         * top left corner of the left column, both as the player sees the board. Only for a full grid of cells whose
         * squares are named like a chessboard's (a1, e9); under the pieces, and never in the way of a click.
         */
        drawCoordinates(svg) {
            const geometry = geometryOf(this.layout);
            const named = this.layout.cells
                .map((cell) => ({ cell, id: geometry.squareOf.get(cell.x + cell.size / 2 + ',' + (cell.y + cell.size / 2)) }))
                .filter(({ id }) => /^[a-z]\d+$/.test(id ?? ''));
            if (named.length < 4) return;
            // Black's board is turned: its bottom row and left column are the drawing's top row and right column.
            const turned = this.color === 'b';
            const ys = named.map(({ cell }) => cell.y);
            const xs = named.map(({ cell }) => cell.x);
            const [bottom, left] = turned ? [Math.min(...ys), Math.max(...xs)] : [Math.max(...ys), Math.min(...xs)];
            // A full grid only (Blockli): on checkers' dark squares the edge would name every other file and rank.
            if (ys.filter((y) => y === bottom).length !== new Set(xs).size || xs.filter((x) => x === left).length !== new Set(ys).size) return;
            named.forEach(({ cell, id }) => {
                const size = cell.size;
                const pad = size * 0.08;
                const label = (text, corner, anchor, baseline) => {
                    // The corner as seen; on the turned board the text turns back, so it reads upright.
                    const [x, y] = turned ? [cell.x + size - corner[0], cell.y + size - corner[1]] : [cell.x + corner[0], cell.y + corner[1]];
                    const element = svgElement('text', { x, y, 'text-anchor': anchor, 'dominant-baseline': baseline, 'font-size': size * 0.28, 'font-weight': 700, fill: '#A1A1AA', 'pointer-events': 'none', 'aria-hidden': 'true', 'data-coordinate': text });
                    if (turned) element.setAttribute('transform', 'rotate(180 ' + x + ' ' + y + ')');
                    element.textContent = text;
                    svg.append(element);
                };
                if (cell.y === bottom) label(id[0], [size - pad, size - pad], 'end', 'text-after-edge');
                if (cell.x === left) label(id.slice(1), [pad, pad], 'start', 'text-before-edge');
            });
        },

        pointLabel(id, piece) {
            // A go board names its points in capitals (D4).
            const name = this.layout.input === 'stones' ? id.toUpperCase() : id;

            return piece ? name + ', ' + (piece.side === 'w' ? this.t.white : this.t.black) : name;
        },

        pick(event) {
            if (this.layout.input === 'blocks') return this.tapBlocks(event);
            if (this.layout.input === 'stones') return this.tapStones(event);
            const point = event.target.closest?.('[data-point]')?.dataset.point;
            if (point) this.click(point);
        },

        pickKey(event) {
            if (this.layout.input === 'stones') return this.stoneKey(event);
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
            this.stone = null;
            this.confirmPass = false;
            this.pending = true;
            this.render();
            try {
                return await this.call('move', move, this.state.ply + 1);
            } catch {
                // No answer at all (a lost connection, a server error): say so and ask where the game stands.
                this.error = this.t.errors.default;
                this.resync();

                return { ok: false };
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

        /** Set-a-block mode on the player's move (Blockli). */
        get settingBlock() {
            return this.blockMode && this.canMove;
        },

        /**
         * Rotate, Confirm and Move the pawn, once a block is shown, for a finger and the keys: in Blockli's bar, never over
         * the board (a player on an iPhone SE, 2026-10-14: a hint and buttons over a board of 200 px left no groove to tap).
         * A mouse sets the block with its click and turns it by pointing along the other groove: it keeps the hint.
         */
        get blockButtons() {
            return this.settingBlock && this.preview !== null && this.pointerType !== 'mouse';
        },

        /**
         * While a block is being set, the toggle says what its click does: "Move the pawn", as the button in the phone's bar
         * (DerCaddy, 2026-11-12: on a desktop a block shown but not set still read "Set a block"). From lg it stands in for that
         * button. The keys keep "Set a block": Enter on the toggle sets the block they show.
         */
        get toggleMoves() {
            return this.settingBlock && this.pointerType !== 'keyboard';
        },

        /** The bar's line: whose move it is, and in set-a-block mode what to do (the hint, red where no block fits); a take-back for a moment. */
        get barLine() {
            if (this.layout.input === 'stones') return this.stoneLine;

            return this.settingBlock ? this.blockHint : this.noticeShown ? this.notice : this.statusLine;
        },

        /**
         * How often the position on the board has stood there in this game, the current time included: for a game
         * whose layout names a repetition limit (Blockli), where the pieces and the side to move (the parity of the
         * ply) are the whole position.
         */
        get repeats() {
            if (!this.layout.repetitions) return 0;
            const state = this.state;
            if (REPEATS.has(state)) return REPEATS.get(state);
            const key = (pieces) => JSON.stringify(Object.keys(pieces ?? {}).sort().map((id) => [id, pieces[id].side, pieces[id].kind]));
            const moves = state.moves ?? [];
            const now = key(state.pieces);
            let count = 0;
            for (let ply = moves.length; ply >= 0; ply -= 2) if (key(ply === 0 ? this.startPieces : moves[ply - 1].pieces) === now) count++;
            REPEATS.set(state, count);

            return count;
        },

        /** The warning one time before the repetition draw, for players and spectators (DerCaddy, 2026-10-09). */
        get repetitionWarning() {
            if (!this.layout.repetitions || this.state.status !== 'active' || this.viewIndex !== null) return '';

            return this.repeats === this.layout.repetitions - 1 ? this.t.repetition.replace(':count', this.repeats) : '';
        },

        /**
         * Over the board like a push message for a few seconds after each move that brings it, at the top of the board in
         * view, and gone while a block is being set; beside the board it stays.
         */
        get warningShown() {
            return this.repetitionWarning !== '' && this.now < this.warningUntil && !this.settingBlock;
        },

        /**
         * The race standing of the live position (Blockli, DerCaddy 2026-10-09), from the server: steps to the goal and
         * blocks left a side, who leads by how many steps with a block worth `rate` steps.
         */
        get standingLine() {
            const standing = this.state.standing;
            if (!standing || this.viewIndex !== null || !this.t.standing) return '';
            const words = this.t.standing;
            // One pass over the placeholders, so a name with a colon or a dollar sign stays as it is.
            const fill = (text, values) => text.replace(/:(\w+)/g, (placeholder, key) => (key in values ? values[key] : placeholder));
            const number = (value) => value.toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: 2 });
            const count = (forms, value) => fill(forms[value === 1 ? 0 : 1], { count: number(value) });
            const side = (key) => fill(words.side, { name: this.t.names[key], steps: count(words.steps, standing[key].steps), blocks: count(words.blocks, standing[key].blocks) });
            const lead = standing.lead ? fill(words.lead, { name: this.t.names[standing.lead], steps: count(words.steps, Math.abs(standing.margin)) }) : words.level;

            return fill(words.line, { white: side('w'), black: side('b'), lead, rate: number(standing.rate) });
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

        /** `event` is the button's click: one from a key (detail 0) switches the hint to the keys, a mouse's to the mouse. */
        setBlockMode(on, event = null) {
            if (event && event.detail === 0) this.pointerType = 'keyboard';
            else if (event?.pointerType) this.pointerType = event.pointerType;
            // Focus on Rotate, Confirm or Move the pawn would go with them (Move the pawn, or Escape on Rotate): it moves to "Set a block".
            const onButtons = !!document.activeElement?.closest?.('[data-block-buttons]');
            this.blockMode = on && this.canMove && this.canSetBlocks;
            this.preview = null;
            this.error = '';
            if (!this.blockMode && onButtons) this.$nextTick(() => this.$root.querySelector('[data-test=mode-block]')?.focus());
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

        /**
         * The part of the board in view, below the sticky header and above the phone's tab bar and the dock (the root's
         * scroll padding names both) and above the bars fixed to a phone's bottom (Blockli's bar, the chat sheet, the
         * dock's tab: `data-page-bar`, `data-live-floor`): on a phone the board may reach under one of them, and the
         * repetition warning at its very edge would stand there unseen. It stands 8 px inside that part. True when it changed.
         */
        measureBand() {
            const box = this.$refs.board?.getBoundingClientRect();
            if (!box || !box.height) return false;
            const root = getComputedStyle(document.documentElement);
            const top = Math.max(box.top, parseFloat(root.scrollPaddingTop) || 0);
            let floor = window.innerHeight - (parseFloat(root.scrollPaddingBottom) || 0);
            document.querySelectorAll('[data-page-bar], [data-live-floor]').forEach((bar) => {
                if (!rendered(bar) || getComputedStyle(bar).position !== 'fixed') return;
                const edge = bar.getBoundingClientRect();
                // Only what lies over the board and below its top: a bar beside it, or a sheet open over all of it, does not count.
                if (edge.height > 0 && edge.left < box.right && edge.right > box.left && edge.top > box.top) floor = Math.min(floor, edge.top);
            });
            const bottom = Math.min(box.bottom, floor);
            // Never off the board: the warning keeps about 100 px of it below or above.
            const edge = (inset) => Math.round(Math.min(Math.max(0, inset), Math.max(0, box.height - 108))) + 8;
            const band = { top: edge(top - box.top), bottom: edge(box.bottom - bottom) };
            if (band.top === this.band.top && band.bottom === this.band.bottom) return false;
            this.band = band;

            return true;
        },

        /** The style of the warning over the board: at the top or the bottom of the part of the board in view. */
        popupAt(top) {
            return top ? { top: this.band.top + 'px', bottom: 'auto', height: 'auto' } : { top: 'auto', bottom: this.band.bottom + 'px', height: 'auto' };
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

        /* ---- the stone input (Go) ---------------------------------------------------------------------- */
        // A layout with `input: 'stones'` (Go, DerCaddy 2026-10-27): a tap shows the stone on the nearest intersection, a
        // second tap on it (or "Set stone") sets it, so a finger on 19 x 19 never sets a stone a line off; a mouse shows it
        // under the pointer and sets it with its click; with the board focused the arrow keys move it and Enter sets it.
        // The move without a path is the pass, a button that asks once more when the other side has just passed, with
        // the count the game would end in. The board still knows no rules: a stone is one of the legal moves the server
        // sent; anywhere else it shows red (a ko, or a point without a liberty).

        /** The legal stones by their intersection. */
        get stoneMoves() {
            const moves = new Map();
            if (this.canMove) this.state.legal.forEach((option) => option.path.length === 1 && moves.set(option.path[0], option.move));

            return moves;
        },

        /** The pass, while the player may make it. */
        get passMove() {
            return this.canMove ? (this.state.legal.find((option) => option.path.length === 0)?.move ?? null) : null;
        },

        /** The other side passed last: a pass now ends the game. */
        get opponentPassed() {
            return this.state.status === 'active' && this.color !== null && this.state.turn === this.color && this.state.ply > 0 && this.state.lastMove?.path?.length === 0;
        },

        /** A game that ended by its count (Go: both passed), not by a resignation, the clock or the move limit's draw. */
        get counted() {
            return this.state.status === 'finished' && !!this.state.count && this.state.reason === 'score';
        },

        /** The marks of each side's area: on request, and on a counted game's last position. */
        get areaShown() {
            return this.showArea && this.viewIndex === null && !!this.state.count;
        },

        get stoneHint() {
            if (!this.canMove) return '';
            const words = this.t.stones;
            if (this.pointerType === 'keyboard') {
                if (!this.stone) return words.keys;

                return words.at.replace(':point', this.stone.toUpperCase()) + ' ' + (this.stoneMoves.has(this.stone) ? words.enter : this.stoneRefusal);
            }
            if (!this.stone) return this.pointerType === 'mouse' ? words.click : words.tap;
            if (!this.stoneMoves.has(this.stone)) return this.stoneRefusal;

            return this.pointerType === 'mouse' ? words.click : words.confirm;
        },

        /** Why the stone shown may not go where it is: a stone lies there, or the point has no liberty or is a ko. */
        get stoneRefusal() {
            return this.state.pieces?.[this.stone] ? this.t.stones.taken : this.t.stones.illegal;
        },

        /** The bar's line under the stone input: the pass that ends the game, the stone shown, a take-back, a pass of the other side, or whose move it is. */
        get stoneLine() {
            if (this.confirmPass && this.canMove) return this.passEndLine;
            if (this.stone && this.canMove) return this.stoneHint;
            if (this.noticeShown) return this.notice;
            if (this.opponentPassed) return this.t.stones.passed.replace(':side', this.colour(this.state.turn === 'w' ? 'b' : 'w'));

            return this.statusLine;
        },

        /** The player passed before in this game (every second move back from the other side's last one is the player's). */
        get passedBefore() {
            const moves = this.state.moves;

            return moves.some((move, index) => (moves.length - index) % 2 === 0 && move.path.length === 0);
        },

        /**
         * What the pass that waits for its second click would mean: the pass that ends the game, or the player's first pass
         * of the game (the board is counted exactly as it stands, stones that could still be captured too); with the count
         * both passing now would end in.
         */
        get passEndLine() {
            const count = this.state.count;
            const line = this.opponentPassed ? this.t.stones.endGame : this.t.stones.firstPass;

            return count ? line + ' ' + this.countResult(count) : line;
        },

        /** "Black 41 · White 33 + 7.5 = 40.5 → Black +0.5": the count as it stands, from the server (stones and area, the komi for the second side). */
        get countLine() {
            const count = this.state.count;
            if (!count || this.viewIndex !== null || !this.t.count) return '';
            const words = this.t.count;
            const number = (value) => value.toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: 1 });
            const fill = (text, values) => text.replace(/:(\w+)/g, (placeholder, key) => (key in values ? values[key] : placeholder));
            const side = (key) => {
                const points = count[key].stones + count[key].area;
                const komi = count[key].score - points;

                return komi > 0 ? fill(words.komi, { colour: this.colour(key), points: number(points), komi: number(komi), score: number(count[key].score) }) : fill(words.side, { colour: this.colour(key), points: number(points) });
            };
            const lead = count.lead ? fill(words.lead, { colour: this.colour(count.lead), points: number(Math.abs(count.margin)) }) : words.level;

            // While the game runs it is the count as it stands; once it is over, the final one. A game drawn at the move
            // limit was not counted: the count is still only how it stood.
            const line = this.state.status === 'active' || this.state.reason === 'move_limit' ? words.line : words.final;

            return fill(line, { first: side('w'), second: side('b'), lead });
        },

        /** "Black wins by 0.5.": the result a count gives. */
        countResult(count) {
            if (!count.lead) return this.t.count.tie;
            const number = Math.abs(count.margin).toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: 1 });

            return this.t.count.result.replace(':colour', this.colour(count.lead)).replace(':points', number);
        },

        /** A side's colour as the page names it (Go: the first side is Black). */
        colour(side) {
            return side === 'w' ? this.t.white : this.t.black;
        },

        tapStones(event) {
            if (!this.canMove) return;
            this.error = '';
            this.confirmPass = false;
            const id = event.target.closest?.('[data-point]')?.dataset.point ?? this.nearestPoint(this.boardPoint(event))?.id ?? null;
            if (!id) return;
            // A mouse sets with its click; a finger (and a key's click) sees the stone first and sets it with the second tap.
            if (this.pointerType === 'mouse' || this.stone === id) {
                this.stone = id;

                return this.setStone();
            }
            this.stone = id;
            this.render();
        },

        hoverStone(event) {
            if (event && event.pointerType !== 'mouse') return;
            if (event) this.pointerType = 'mouse';
            if (!this.canMove) return;
            const at = event ? this.boardPoint(event) : null;
            const next = at ? (this.nearestPoint(at)?.id ?? null) : null;
            if (next === this.stone) return;
            // Another point: the refusal of the last one no longer stands.
            [this.stone, this.error] = [next, ''];
            // Only the shown stone moves: nothing else on the board changes under a mouse.
            if (this.$refs.board) this.drawGhost(this.$refs.board);
        },

        /** The intersection nearest to a board position, null off the board (more than half a line beyond its edge). */
        nearestPoint(at) {
            if (!at) return null;
            const unit = this.stoneUnit;
            let nearest = null;
            let distance = Infinity;
            this.layout.points.forEach((point) => {
                const d = Math.max(Math.abs(point.x - at.x), Math.abs(point.y - at.y));
                if (d < distance) [nearest, distance] = [point, d];
            });

            return distance <= unit * 0.75 ? nearest : null;
        },

        /** The distance between two lines of the board. */
        get stoneUnit() {
            const [a, b] = this.layout.points;

            return a && b ? Math.hypot(b.x - a.x, b.y - a.y) : 100;
        },

        setStone() {
            if (!this.stone || !this.canMove) return;
            const move = this.stoneMoves.get(this.stone);
            if (move) return this.send(move);
            // Refused on the board, without asking the server: the bar's line says why (stoneRefusal), once.
            this.render();
        },

        /**
         * Pass. The pass that would end the game (the other side passed last) asks first, with the count it ends in; so does
         * the player's first pass of a game: a newcomer who passes with stones left to capture loses them to the count
         * without a word otherwise (challenger, 2026-11-13).
         */
        pass() {
            const move = this.passMove;
            if (!move) return;
            if ((this.opponentPassed || !this.passedBefore) && !this.confirmPass) {
                this.confirmPass = true;
                this.stone = null;
                this.render();

                return;
            }
            this.send(move);
        },

        toggleArea() {
            this.showArea = !this.showArea;
            this.render();
        },

        /** With the board focused: the arrows move the stone shown (the middle first), Enter or Space sets it, Escape takes it away. */
        stoneKey(event) {
            if (!this.canMove) return;
            const step = BLOCK_KEY_STEPS[event.key];
            if (step) {
                [this.pointerType, this.error] = ['keyboard', ''];
                this.moveStone(step);
            } else if ((event.key === 'Enter' || event.key === ' ') && this.stone) {
                this.pointerType = 'keyboard';
                this.setStone();
            } else if (event.key === 'Escape' && this.stone) {
                [this.stone, this.error] = [null, ''];
                this.render();
            } else {
                return;
            }
            event.preventDefault();
        },

        moveStone([dx, dy]) {
            const points = this.layout.points;
            const from = points.find((point) => point.id === this.stone);
            if (!from) {
                const xs = points.map((point) => point.x);
                const ys = points.map((point) => point.y);
                this.stone = this.nearestPoint({ x: (Math.min(...xs) + Math.max(...xs)) / 2, y: (Math.min(...ys) + Math.max(...ys)) / 2 })?.id ?? null;
                this.render();

                return;
            }
            let next = null;
            let distance = Infinity;
            points.forEach((point) => {
                const along = (point.x - from.x) * dx + (point.y - from.y) * dy;
                const across = Math.abs((point.x - from.x) * dy) + Math.abs((point.y - from.y) * dx);
                if (along > 0 && across < 1 && along < distance) [next, distance] = [point, along];
            });
            if (next) this.stone = next.id;
            this.render();
        },

        /**
         * The go board: wood, the lines (the edge a little stronger), the star points, the coordinates as on a go board (files
         * A to T without I under the board, ranks up its left side, the same for both players), the stones with the last one
         * ringed, the areas on request, and the stone shown before it is set (red where no stone may go). Every intersection
         * is a `data-point` group as wide as a line's distance, so a tap anywhere near it hits it.
         */
        renderStones(svg) {
            const { width, height, points } = this.layout;
            const unit = this.stoneUnit;
            const shown = this.shownPly;
            const pieces = this.piecesAt(shown);
            const lastPath = (shown > 0 ? this.state.moves[shown - 1]?.path : null) ?? (this.viewIndex === null ? (this.state.lastMove?.path ?? []) : []);
            const last = lastPath[0] ?? null;
            const xs = points.map((point) => point.x);
            const ys = points.map((point) => point.y);
            const [left, right, top, bottom] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
            const defs = svgElement('defs', {});
            defs.innerHTML = STONE_GRADIENTS;
            svg.append(defs, svgElement('rect', { x: 0, y: 0, width, height, rx: unit * 0.1, fill: GO.wood }));

            this.layout.lines.forEach(([x1, y1, x2, y2]) => {
                const edge = x1 === x2 ? x1 === left || x1 === right : y1 === top || y1 === bottom;
                svg.append(svgElement('line', { x1, y1, x2, y2, stroke: GO.line, 'stroke-width': edge ? 1.7 : 1, 'vector-effect': 'non-scaling-stroke', 'stroke-linecap': 'square' }));
            });
            const byId = new Map(points.map((point) => [point.id, point]));
            (this.layout.stars ?? []).forEach((id) => byId.has(id) && svg.append(svgElement('circle', { cx: byId.get(id).x, cy: byId.get(id).y, r: unit * 0.09, fill: GO.line })));

            // The coordinates: under the bottom line and left of the left line, in the margin. On 13 x 13 and 19 x 19 a line
            // is narrower on the same screen, so their letters take more of it (two digits still fit beside an edge stone).
            const labelSize = unit * (points.length > 81 ? 0.4 : 0.28);
            points.forEach((point) => {
                const [file, rank] = [point.id.replace(/\d+$/, ''), point.id.replace(/^[a-z]/, '')];
                const label = (text, x, y) => {
                    const element = svgElement('text', { x, y, 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': labelSize, 'font-weight': 600, fill: GO.label, 'pointer-events': 'none', 'aria-hidden': 'true', 'data-coordinate': text });
                    element.textContent = text;
                    svg.append(element);
                };
                // In the middle between a stone on the edge line and the board's edge, so no stone ever covers them.
                if (point.y === bottom) label(file.toUpperCase(), point.x, bottom + (unit * 0.47 + (height - bottom)) / 2);
                if (point.x === left) label(rank, left - (unit * 0.47 + left) / 2, point.y);
            });

            const owners = this.areaShown ? this.state.count.owners : null;
            const ownerOf = new Map(owners ? [...owners.w.map((id) => [id, 'w']), ...owners.b.map((id) => [id, 'b'])] : []);
            points.forEach((point) => {
                const piece = pieces[point.id] ?? null;
                const group = svgElement('g', { 'data-point': point.id, 'data-piece': piece ? piece.side + ':' + piece.kind : '', 'aria-label': this.pointLabel(point.id, piece) });
                group.append(svgElement('rect', { x: point.x - unit / 2, y: point.y - unit / 2, width: unit, height: unit, fill: 'transparent' }));
                if (piece) {
                    group.append(svgElement('circle', { cx: point.x, cy: point.y, r: unit * 0.47, fill: STONE_FILL[piece.kind], stroke: STONE_EDGE[piece.kind], 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }));
                    if (point.id === last) group.append(svgElement('circle', { cx: point.x, cy: point.y, r: unit * 0.19, fill: 'none', stroke: GO.ring, 'stroke-width': unit * 0.07, 'data-last': '1' }));
                }
                const owner = ownerOf.get(point.id);
                if (owner) {
                    const size = unit * 0.3;
                    group.append(svgElement('rect', { x: point.x - size / 2, y: point.y - size / 2, width: size, height: size, fill: STONE_FILL[stoneKind(owner)], stroke: STONE_EDGE[stoneKind(owner)], 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke', 'data-area': owner }));
                }
                svg.append(group);
            });

            this.drawGhost(svg);
        },

        /**
         * The stone shown before it is set: in the player's colour where it may go, red where not. A finger's gets the
         * lines through it, so on 19 x 19 the row and the file are plain to see. Drawn on its own, so a mouse moving over
         * the board does not rebuild it (measured 2026-11-14 on 19 x 19: 5.7 ms a point for the whole board).
         */
        drawGhost(svg) {
            svg.querySelectorAll(':scope > [data-ghost]').forEach((element) => element.remove());
            const points = this.layout.points;
            const at = this.stone && this.canMove && !this.piecesAt(this.shownPly)[this.stone] ? points.find((point) => point.id === this.stone) : null;
            if (!at) return;
            const unit = this.stoneUnit;
            const legal = this.stoneMoves.has(at.id);
            if (this.pointerType !== 'mouse' && this.pointerType !== 'keyboard') {
                const xs = points.map((point) => point.x);
                const ys = points.map((point) => point.y);
                svg.append(svgElement('line', { x1: Math.min(...xs), y1: at.y, x2: Math.max(...xs), y2: at.y, stroke: GO.cross, 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke', 'pointer-events': 'none', 'data-ghost': '' }));
                svg.append(svgElement('line', { x1: at.x, y1: Math.min(...ys), x2: at.x, y2: Math.max(...ys), stroke: GO.cross, 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke', 'pointer-events': 'none', 'data-ghost': '' }));
            }
            const kind = stoneKind(this.color);
            svg.append(svgElement('circle', { cx: at.x, cy: at.y, r: unit * 0.47, fill: legal ? STONE_FILL[kind] : GO.illegal, stroke: legal ? STONE_EDGE[kind] : 'none', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke', opacity: legal ? 0.6 : 1, 'pointer-events': 'none', 'data-preview': legal ? 'legal' : 'illegal', 'data-ghost': '' }));
        },

        /* ---- take back (BoardGameService::takeBack()) ------------------------------------------------- */

        /**
         * Whole seconds left to take the own last move back, 0 when it cannot be: the server names the moment
         * (`takeBackUntil`, take_back_seconds after the move, earlier where the mover's own time ends) for the side that
         * just moved, so a player sees it right after their move, until the opponent answers or the time is up
         * (DerCaddy and the players, 2026-10-15: a misclick, 21 seconds, no accept step).
         */
        get takeBackLeft() {
            const until = this.state.takeBackUntil;
            if (!until || this.color === null || this.state.status !== 'active' || this.state.takeBackBy !== this.color) return 0;

            return Math.max(0, Math.ceil((until - this.serverNow()) / 1000));
        },

        get canTakeBack() {
            return this.takeBackLeft > 0 && !this.pending && this.viewIndex === null;
        },

        get noticeShown() {
            return this.notice !== '' && this.now < this.noticeUntil;
        },

        async takeBack() {
            if (!this.canTakeBack) return;
            [this.blockMode, this.preview] = [false, null];
            this.pending = true;
            try {
                await this.call('takeBack');
            } finally {
                this.pending = false;
                this.render();
            }
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
            const needed = !this.source && this.connection !== 'connected' && this.state.status === 'active';
            if (needed && !this.poller) {
                this.poller = setInterval(() => this.resync(), 3000);
            } else if (!needed && this.poller) {
                clearInterval(this.poller);
                this.poller = null;
            }
        },

        apply(state) {
            if (!state || state.version < this.state.version) return;
            state = unpackStones(state, this.layout.points);
            if (state.version !== this.state.version || state.moves) stonesKnown.clear();
            const changed = state.version !== this.state.version;
            const moved = state.ply !== this.state.ply;
            // One ply less in a running game: the side that had just moved took it back. Its opponent (and a spectator) is told.
            const takenBack = state.status === 'active' && state.ply === this.state.ply - 1 && state.turn !== this.color;
            this.state = { ...state, moves: state.moves ?? this.state.moves };
            this.receivedAt = performance.now();
            this.lastSyncAt = this.receivedAt;
            this.now = this.receivedAt;
            if (takenBack) [this.notice, this.noticeUntil] = [this.t.takeBack.notice.replace(':name', this.t.names[state.turn]), this.now + WARNING_MS];
            if (changed) this.clicks = [];
            if (moved) this.$refs.board?.pieceBoard?.changed();
            // The block input: a block shown is checked against the new legal moves; off the move none is shown.
            if (changed && this.layout.input === 'blocks') {
                if (moved) this.warningUntil = this.now + WARNING_MS;
                if (!this.canMove) [this.blockMode, this.preview] = [false, null];
                else if (this.preview) this.showBlock(this.preview.crossing, this.preview.dir, this.preview.turned);
            }
            // The stone input: a pass that waited for its second click waits no more; off the move no stone is shown; a game
            // that just ended by its count shows each side's area.
            if (changed && this.layout.input === 'stones') {
                this.confirmPass = false;
                if (!this.canMove) this.stone = null;
                if (this.counted) this.showArea = true;
            }
            if (this.state.status !== 'active') this.confirmResign = false;
            this.syncPolling();
            this.render();
        },

        /**
         * A state that came without the record (a push, the answer to an own action, a state the server kept short) with the
         * page's record joined to it: the next move added, the same move kept, a move taken back cut off. Null where it does
         * not follow on from what the page has: then the whole record is asked for.
         */
        follow(update) {
            if (update.moves) return update;
            const moves = this.state.moves;
            const same = (ply) => ply === 0 || moves[ply - 1]?.move === update.lastMove?.move;
            if (update.ply === moves.length + 1 && update.lastMove) {
                // A push of a board with its own input carries the pieces once (BoardGameService::snapshot()).
                return { ...update, moves: [...moves, { ...update.lastMove, pieces: update.lastMove.pieces ?? update.pieces }] };
            }
            if (update.ply === moves.length && same(update.ply)) return { ...update, moves };
            if (update.ply < moves.length && same(update.ply)) return { ...update, moves: moves.slice(0, update.ply) };

            return null;
        },

        receive(update) {
            if (update.version <= this.state.version) return;
            const state = this.follow(unpackStones(update, this.layout.points));
            if (state) this.apply(state);
            else this.resync();
        },

        async resync() {
            if (this.syncing) return;
            this.syncing = true;
            this.lastSyncAt = performance.now();
            try {
                const answer = await (this.source ?? this.$wire).fetchState(this.state.version);
                const state = answer ? this.follow(unpackStones(answer, this.layout.points)) : null;
                if (state) this.apply(state);
                // The server kept the record back (too many asked for in a minute): once more in a while.
                else if (answer) setTimeout(() => this.resync(), 3000);
            } finally {
                this.syncing = false;
            }
        },

        async call(method, ...args) {
            const response = await (this.source ?? this.$wire)[method](...args);
            if (!response) return null;
            const state = response.state ? this.follow(unpackStones(response.state, this.layout.points)) : null;
            if (state) this.apply(state);
            else if (response.state) this.resync();
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
