/*
 * Live chess on the client: the board kit (CHESS-BOARD.md), clocks, the game
 * page and the lobby. chess.js (BSD-2) is used for UX only: legal-target dots,
 * promotion detection and SAN input. The server decides every move; the board
 * shows what the server sends back.
 */
import { Chess } from 'chess.js';
import { dailyGame } from './dailyGame.js';
import { gameChat } from './gameChat.js';
import { boardKey } from './hotkeys.js';
import { ensureSigner } from './nostrSign.js';
import { signTemplate } from './signing.js';
import { moveSound, playSound, sounds } from './sounds.js';

const PIECE_NAMES = { k: 'king', q: 'queen', r: 'rook', b: 'bishop', n: 'knight', p: 'pawn' };
const VS16 = '︎';

/*
 * Board colours of ChessSettings ("Colors"): light and dark squares and the
 * coordinate ink on each. The player's choice arrives as <meta name="board-theme">
 * (partials/head.blade.php); the last move stays orange on every board.
 */
const BOARD_THEMES = {
    house: { l: '#CFCFD4', d: '#62626C', cl: '#3A3A42', cd: '#E4E4E8' },
    wood: { l: '#E4CCA2', d: '#8E5F3B', cl: '#3A3A42', cd: '#FFFFFF' },
    slate: { l: '#DCE3EA', d: '#5E7891', cl: '#3A3A42', cd: '#FFFFFF' },
    orange: { l: '#F4D9B0', d: '#B9640A', cl: '#3A3A42', cd: '#FFFFFF' },
};
const themeMeta = document.querySelector('meta[name="board-theme"]');
const defaultTheme = BOARD_THEMES[themeMeta?.content] ? themeMeta.content : 'house';
const coordinatesOn = themeMeta?.dataset.coordinates !== '0';

/** Low-time sound (P5c): once per game, when the player's own clock reaches this. */
const LOW_TIME_MS = 10000;

/**
 * The end-of-game sound for this viewer: win, loss or draw; spectators hear
 * the neutral one, an aborted game none.
 */
export function endSound(state, color) {
    if (state.status !== 'finished') return null;
    if (state.result === '1/2-1/2' || !color) return 'draw';

    return (state.result === '1-0') === (color === 'w') ? 'win' : 'loss';
}

// Listen for the first gesture on board pages too (guests watching have no alerts module).
sounds();

/**
 * The 64 squares for the board markup, straight from the kit's chessBoard().
 * `o`: flip, last (two squares), check (square), select (square), dots (squares), noCoords.
 */
export function boardCells(fen, o = {}) {
    const cells = [];
    const theme = BOARD_THEMES[o.theme] ?? BOARD_THEMES[defaultTheme];
    const square = (r, f, ch) => {
        const name = 'abcdefgh'[f] + (8 - r);
        const light = (r + f) % 2 === 0;
        const isLast = (o.last || []).includes(name);
        const idx = ch ? 'kqrbnp'.indexOf(ch.toLowerCase()) : -1;
        const white = !!ch && ch !== ch.toLowerCase();
        let ring = 'none';
        if (name === o.check) ring = 'inset 0 0 0 3px #F87171, inset 0 0 16px 4px rgba(248,113,113,.8)';
        if (name === o.select) ring = 'inset 0 0 0 4px #F7931A';

        return {
            name,
            bg: isLast ? (light ? '#F4C47F' : '#B8741F') : light ? theme.l : theme.d,
            ring,
            piece: !!ch,
            color: ch ? (white ? 'w' : 'b') : null,
            solid: ch ? String.fromCodePoint(0x265a + idx) + VS16 : '',
            outline: white ? String.fromCodePoint(0x2654 + idx) + VS16 : '',
            fill: white ? '#FFFFFF' : '#0A0A0B',
            label: name + (ch ? ': ' + (white ? 'white' : 'black') + ' ' + PIECE_NAMES[ch.toLowerCase()] : ''),
            type: ch ? ch.toLowerCase() : null,
            coordC: light ? theme.cl : theme.cd,
            dot: (o.dots || []).includes(name),
            // Clickable: a piece of the side that may move now, or a legal target.
            hot: (!!ch && (white ? 'w' : 'b') === o.mover) || (o.dots || []).includes(name),
            rank: '',
            file: '',
        };
    };

    fen.split(' ')[0]
        .split('/')
        .forEach((row, r) => {
            let f = 0;
            for (const ch of row) {
                if (/\d/.test(ch)) {
                    for (let k = 0; k < +ch; k++) cells.push(square(r, f++, ''));
                } else {
                    cells.push(square(r, f++, ch));
                }
            }
        });

    const out = o.flip ? cells.reverse() : cells;
    if (!o.noCoords && (coordinatesOn || o.coords)) {
        out.forEach((c, i) => {
            if (i % 8 === 0) c.rank = c.name[1];
            if (i >= 56) c.file = c.name[0];
        });
    }

    return out;
}

/** m:ss, rounded up so a clock shows 0:00 only when it has really run out. */
export function formatClock(ms) {
    const total = Math.max(0, Math.ceil(ms / 1000));

    return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
}

/** Material balance, White minus Black (kit's material()). */
function material(fen) {
    const values = { q: 9, r: 5, b: 3, n: 3, p: 1, k: 0 };
    let balance = 0;
    for (const ch of fen.split(' ')[0]) {
        const lower = ch.toLowerCase();
        if (values[lower] !== undefined) balance += ch === lower ? -values[lower] : values[lower];
    }

    return balance;
}

function kingInCheck(fen) {
    const chess = new Chess(fen);
    if (!chess.inCheck()) return '';
    const turn = chess.turn();
    for (const row of chess.board()) {
        for (const sq of row) {
            if (sq && sq.type === 'k' && sq.color === turn) return sq.square;
        }
    }

    return '';
}

/**
 * Watches the websocket (if there is one) and reports state changes, so a
 * page can show "Connected" / "Reconnecting" and resync after a gap.
 */
/** How long a live game page trusts the websocket alone before it asks the server. */
const HEARTBEAT_MS = 4000;

function watchConnection(onChange) {
    const pusher = window.Echo?.connector?.pusher;
    if (!pusher) {
        onChange('none');

        return;
    }

    onChange(pusher.connection.state === 'connected' ? 'connected' : 'connecting');
    pusher.connection.bind('state_change', ({ current }) => onChange(current));
}

// Static boards (lobby thumbnails) build their cells inline.
window.chessBoardCells = boardCells;

/**
 * Sign a server-prepared template with the player's signer, if one is
 * there without asking to connect (extension, or a remote signer already
 * connected on this page). Returns the signed event, or null.
 */
async function signQuietly(template, pubkey) {
    if (typeof window.nostr?.signEvent !== 'function' || !template) return null;
    try {
        // signTemplate signs a plain copy and logs a refusal with its reason (console.warn).
        return await signTemplate(template, { pubkey });
    } catch {
        return null;
    }
}

/**
 * NIP-64 final record (NIP "Game Record"): when a game ends, the players'
 * app signs the finished PGN and the first valid one counts. Nothing to click
 * (ChessGame: "Nothing to click afterwards"); without a signer at hand the
 * finished game's page offers it instead.
 */
async function publishRecord(wire, pubkey, { connect = false } = {}) {
    // $wire resolves its component lazily; do it now, before any await (see dailyGame.js commit()).
    void wire.$id;
    // Only an explicit click may open the signer connect dialog.
    if (connect && !(await ensureSigner())) return;
    const template = await wire.recordTemplate();
    const event = await signQuietly(template, pubkey);
    if (event) await wire.submitRecord(JSON.stringify(event));
}

window.chessPublishRecord = publishRecord;

function chatNotice(text) {
    window.dispatchEvent(new CustomEvent('chess-chat-notice', { detail: { at: Date.now(), text } }));
}

/* ---------- Game page ---------------------------------------------------------------------------------------- */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('gameChat', gameChat);
    window.Alpine.data('dailyGame', (config) => dailyGame(config, boardCells, kingInCheck));

    window.Alpine.data('chessGame', (config) => ({
        state: config.state,
        color: config.color,
        t: config.labels,
        receivedAt: performance.now(),
        now: performance.now(),
        flipped: config.color === 'b',
        selected: '',
        dots: [],
        promotion: null,
        confirm: null,
        pending: false,
        error: '',
        sanInput: '',
        connection: 'connecting',
        wasConnected: false,
        disconnectedAt: null,
        latency: null,
        clockCheckSent: 0,
        ticker: null,
        poller: null,
        opponentGoneAt: null,
        disconnectDismissed: false,
        opponentGoneNoticed: false,
        recording: false,
        lowTimePlayed: false,
        soundedPly: 0,
        lastSyncAt: 0,
        syncing: false,

        init() {
            this.ticker = setInterval(() => this.tick(), 200);
            this.lastSyncAt = performance.now();

            // Back in the tab (or window): the push may have been missed meanwhile.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.resync();
            });
            window.addEventListener('focus', () => this.resync());

            // Move sounds (P5c): once per ply, own moves included (shown at once in send()).
            // A ply shown, taken back and confirmed again is not heard twice.
            this.soundedPly = this.state.ply;
            this.$watch('state.ply', (ply) => {
                if (ply <= this.soundedPly) return;
                this.soundedPly = ply;
                playSound(moveSound(this.state.moves[this.state.moves.length - 1]?.san));
            });

            watchConnection((current) => {
                const before = this.connection;
                this.connection = current;
                if (current === 'connected') {
                    if (this.wasConnected && before !== 'connected') this.resync();
                    this.wasConnected = true;
                    this.disconnectedAt = null;
                } else if (this.wasConnected && this.disconnectedAt === null) {
                    this.disconnectedAt = performance.now();
                }
                this.syncPolling();
            });

            if (window.Echo) {
                const channel = this.color
                    ? window.Echo.private('game.' + this.state.id)
                    : window.Echo.channel('game.' + this.state.id + '.watch');
                channel.listen('.game.updated', (update) => this.receive(update));
                // Anything that happened between rendering the page and subscribing.
                channel.subscribed?.(() => this.resync());
            }

            // The two players' presence: "opponent disconnected" and claim-win (ChessOverlays).
            if (window.Echo && this.color) {
                window.Echo.join('game.' + this.state.id + '.players')
                    .here((members) => {
                        // Not there when this page joined: gone, but no "disconnected" line for it.
                        if (!members.some((m) => m.color !== this.color)) this.opponentLeft(false);
                    })
                    .joining((member) => member.color !== this.color && this.opponentReturned())
                    .leaving((member) => member.color !== this.color && this.opponentLeft());
            }
        },

        opponentLeft(notice = true) {
            if (this.opponentGoneAt !== null || this.state.status !== 'active') return;
            this.opponentGoneAt = performance.now();
            this.opponentGoneNoticed = notice;
            this.disconnectDismissed = false;
            if (notice) chatNotice(this.t.disconnected.gone);
            // The server checks with Reverb itself and starts its own timer.
            this.$wire.reportGone();
        },

        opponentReturned() {
            if (this.opponentGoneAt === null) return;
            const seconds = Math.round((performance.now() - this.opponentGoneAt) / 1000);
            this.opponentGoneAt = null;
            // Only a real disconnect gets its "back" line: an opponent who opens the
            // game after this page did never left.
            if (this.opponentGoneNoticed && this.state.status === 'active') chatNotice(this.t.disconnected.back.replace(':s', seconds));
        },

        get disconnect() {
            if (this.opponentGoneAt === null || this.state.status !== 'active' || this.state.ply < 2 || this.reconnecting || !this.color) return null;
            const elapsed = Math.max(0, (this.now - this.opponentGoneAt) / 1000);
            const left = Math.max(0, Math.ceil(this.t.claimSeconds - elapsed));
            if (this.disconnectDismissed && left > 0) return null;
            const opponent = this.color === 'w' ? 'b' : 'w';
            const running = this.state.clock.running === opponent;

            return {
                left,
                clock: formatClock(left * 1000),
                text: (running ? this.t.disconnected.ago : this.t.disconnected.agoIdle)
                    .replace(':s', Math.floor(elapsed))
                    .replace(':clock', formatClock(this.remaining(opponent))),
            };
        },

        dismissDisconnect() {
            this.disconnectDismissed = true;
        },

        destroy() {
            clearInterval(this.ticker);
            clearInterval(this.poller);
        },

        /* Without a live websocket the page asks the server every few seconds. */
        syncPolling() {
            const needed = this.connection !== 'connected' && this.state.status === 'active';
            if (needed && !this.poller) {
                this.poller = setInterval(() => this.resync(), 3000);
            } else if (!needed && this.poller) {
                clearInterval(this.poller);
                this.poller = null;
            }
        },

        tick() {
            this.now = performance.now();
            if (this.state.status !== 'active') return;
            // Heartbeat even while "connected": a push that never arrives (the
            // server could not reach Reverb, the socket died silently) must not
            // leave a player on an old position. fetchState renders no HTML.
            if (this.connection === 'connected' && this.now - this.lastSyncAt > HEARTBEAT_MS) {
                this.resync();
            }
            const running = this.state.clock.running;
            if (!this.lowTimePlayed && this.color && running === this.color && this.remaining(this.color) <= LOW_TIME_MS) {
                this.lowTimePlayed = true;
                playSound('lowTime');
            }
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

        remaining(color) {
            const base = this.state.clock[color];
            if (this.state.status === 'active' && this.state.clock.running === color) {
                return Math.max(0, base - (this.now - this.receivedAt));
            }

            return base;
        },

        /* ---- state from the server -------------------------------------------------------------------- */

        apply(state) {
            if (!state || (state.version < this.state.version && state.id === this.state.id)) return;
            const moves = state.moves ?? this.state.moves;
            // A heartbeat that brings nothing new keeps a half-made move (selected piece).
            const changed = state.version !== this.state.version || state.id !== this.state.id;
            const ended = this.state.status === 'active' && state.status === 'finished';
            this.state = { ...state, moves };
            if (ended) {
                const sound = endSound(state, this.color);
                // After the last move's click, not on top of it.
                if (sound) setTimeout(() => playSound(sound), 250);
            }
            if (ended && this.color && !state.recorded && !this.recording) {
                this.recording = true;
                publishRecord(this.$wire, this.t.pubkey).finally(() => (this.recording = false));
            }
            this.receivedAt = performance.now();
            this.lastSyncAt = this.receivedAt;
            this.now = this.receivedAt;
            if (changed) {
                this.selected = '';
                this.dots = [];
            }
            this.syncPolling();
            if (this.state.status !== 'active') this.confirm = null;
            if (state.rematchUrl && this.color) window.location.assign(state.rematchUrl);
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
            const started = performance.now();
            const response = await this.$wire[method](...args);
            this.latency = Math.round(performance.now() - started);
            if (!response) return null;
            if (response.state) this.apply(response.state);
            this.error = response.ok === false ? this.t.errors[response.error] ?? this.t.errors.default : '';

            return response;
        },

        /* ---- moves ------------------------------------------------------------------------------------ */

        get myTurn() {
            return this.color !== null && this.state.status === 'active' && this.state.turn === this.color;
        },

        get canMove() {
            // Moves made while the websocket is down would still reach the server
            // over HTTP, but the design promises "not sent": no guessing mid-outage.
            return this.myTurn && !this.pending && this.connection !== 'unavailable' && this.connection !== 'failed';
        },

        get lastSquares() {
            const last = this.state.moves[this.state.moves.length - 1];

            return last ? [last.uci.slice(0, 2), last.uci.slice(2, 4)] : [];
        },

        get boardLabel() {
            const last = this.state.moves[this.state.moves.length - 1];

            return this.t.board.replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black).replace(':move', last ? last.san : '–');
        },

        get cells() {
            return boardCells(this.state.fen, {
                flip: this.flipped,
                last: this.lastSquares,
                check: kingInCheck(this.state.fen),
                select: this.selected,
                dots: this.dots,
                mover: this.canMove ? this.color : null,
            });
        },

        clickSquare(square) {
            if (!this.canMove) return;
            const chess = new Chess(this.state.fen);
            const piece = chess.get(square);

            if (this.selected && this.dots.includes(square)) {
                this.tryMove(this.selected, square);

                return;
            }

            if (piece && piece.color === this.color) {
                this.selected = square;
                this.dots = chess.moves({ square, verbose: true }).map((m) => m.to);
            } else {
                this.selected = '';
                this.dots = [];
            }
        },

        tryMove(from, to, promotion = null) {
            const chess = new Chess(this.state.fen);
            const candidates = chess.moves({ square: from, verbose: true }).filter((m) => m.to === to);
            if (candidates.length === 0) return;

            if (candidates.some((m) => m.promotion) && !promotion) {
                this.promotion = { from, to };

                return;
            }

            this.send(from + to + (promotion ?? ''));
        },

        pickPromotion(piece) {
            if (!this.promotion) return;
            const { from, to } = this.promotion;
            this.promotion = null;
            this.send(from + to + piece);
        },

        hotkey(event) {
            const key = boardKey(event);
            if (!key) return;
            if (this.promotion) {
                if (key === 'Escape') this.promotion = null;
                else if (['q', 'r', 'b', 'n'].includes(key)) this.pickPromotion(key);

                return;
            }
            if (key === 'f') this.flipped = !this.flipped;
            else if (key === 'Escape') {
                this.selected = '';
                this.dots = [];
            }
        },

        get promotionPieces() {
            const white = this.color === 'w';

            return ['q', 'r', 'n', 'b'].map((p) => {
                const idx = 'kqrbnp'.indexOf(p);

                return {
                    key: p,
                    name: this.t.pieces[p],
                    solid: String.fromCodePoint(0x265a + idx) + VS16,
                    outline: white ? String.fromCodePoint(0x2654 + idx) + VS16 : '',
                    fill: white ? '#FFFFFF' : '#0A0A0B',
                };
            });
        },

        /* The picker sits on the target file, counted from the viewer's left. */
        get promotionLeft() {
            if (!this.promotion) return '0%';
            const file = 'abcdefgh'.indexOf(this.promotion.to[0]);

            return ((this.flipped ? 7 - file : file) * 12.5) + '%';
        },

        submitSan() {
            const san = this.sanInput.trim();
            if (!san || !this.canMove) return;
            const chess = new Chess(this.state.fen);
            let move = null;
            try {
                move = chess.move(san);
            } catch {
                move = null;
            }
            if (!move) {
                this.error = this.t.errors.illegal_move;

                return;
            }
            this.sanInput = '';
            this.send(move.from + move.to + (move.promotion ?? ''));
        },

        async send(uci) {
            // Shown at once; the server's answer replaces it either way.
            const chess = new Chess(this.state.fen);
            let local = null;
            try {
                local = chess.move({ from: uci.slice(0, 2), to: uci.slice(2, 4), promotion: uci[4] });
            } catch {
                return;
            }
            const before = this.state;
            this.state = {
                ...before,
                fen: chess.fen(),
                turn: chess.turn(),
                ply: before.ply + 1,
                moves: [...before.moves, { uci, san: local.san, spent: null }],
                clock: { ...before.clock, running: null, [this.color]: this.remaining(this.color) },
            };
            this.selected = '';
            this.dots = [];
            this.pending = true;
            try {
                const response = await this.call('move', uci, before.ply + 1);
                if (!response) this.apply({ ...before, version: before.version });
            } finally {
                this.pending = false;
            }
        },

        /* ---- actions ---------------------------------------------------------------------------------- */

        confirmResign() {
            this.confirm = null;
            this.call('resign');
        },

        confirmAbort() {
            this.confirm = null;
            this.call('abort');
        },

        /* ---- labels ----------------------------------------------------------------------------------- */

        clock(color) {
            const ms = this.remaining(color);
            const running = this.state.status === 'active' && this.state.clock.running === color;
            let key = 'idle';
            if (this.state.status !== 'active') key = ms <= 0 && this.state.reason === 'timeout' ? 'flagged' : 'stopped';
            else if (running) key = ms < 30000 ? 'low' : 'running';
            const styles = {
                idle: { bg: '#121215', ring: 'inset 0 0 0 1px #2A2A30', fg: '#ADADB0' },
                running: { bg: '#F7931A', ring: 'none', fg: '#17120A' },
                low: { bg: '#F87171', ring: 'none', fg: '#17120A' },
                flagged: { bg: '#121215', ring: 'inset 0 0 0 1px #5A2A2E', fg: '#F87171' },
                stopped: { bg: '#121215', ring: 'inset 0 0 0 1px #2A2A30', fg: '#ADADB0' },
            }[key];
            const t = formatClock(ms);

            return { ...styles, t, label: this.t.clock[key], low: key === 'low', aria: this.t.clockAria.replace(':time', t).replace(':state', this.t.clock[key]) };
        },

        get bottomColor() {
            return this.flipped ? 'b' : 'w';
        },

        get topColor() {
            return this.flipped ? 'w' : 'b';
        },

        materialFor(color) {
            const balance = material(this.state.fen) * (color === 'w' ? 1 : -1);

            return balance > 0 ? '+' + balance : '';
        },

        get moveRows() {
            const rows = [];
            const moves = this.state.moves;
            const current = moves.length - 1;
            const spent = (m) => (m && m.spent !== null && m.spent !== undefined ? Math.round(m.spent / 1000) + ' s' : '');
            for (let i = 0; i < moves.length; i += 2) {
                rows.push({
                    n: i / 2 + 1,
                    w: moves[i].san,
                    b: moves[i + 1]?.san ?? '',
                    wt: spent(moves[i]),
                    bt: spent(moves[i + 1]),
                    wCur: current === i,
                    bCur: current === i + 1,
                });
            }

            return rows;
        },

        get statusLine() {
            if (this.state.status === 'aborted') return this.t.status.aborted;
            if (this.state.status === 'finished') return this.t.status.over;
            const move = Math.floor(this.state.ply / 2) + 1;

            return this.t.status.live.replace(':move', move).replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black);
        },

        get statusShort() {
            if (this.state.status !== 'active') return this.statusLine;

            return this.t.status.short.replace(':move', Math.floor(this.state.ply / 2) + 1);
        },

        get connectionLabel() {
            if (this.connection === 'connected') return this.latency !== null ? this.t.connection.connectedMs.replace(':ms', this.latency) : this.t.connection.connected;
            if (this.connection === 'none') return this.t.connection.polling;
            if (this.disconnectedAt !== null) return this.t.connection.disconnected.replace(':s', Math.round((this.now - this.disconnectedAt) / 1000));

            return this.t.connection.connecting;
        },

        get reconnecting() {
            return this.wasConnected && this.connection !== 'connected' && this.state.status === 'active';
        },

        reconnectNow() {
            window.Echo?.connector?.pusher?.connect();
            this.resync();
        },

        get firstMoveLeft() {
            if (this.state.status !== 'active' || !this.state.firstMoveDeadline) return null;

            return Math.max(0, Math.ceil((this.state.firstMoveDeadline - this.serverNow()) / 1000));
        },

        get outcome() {
            const s = this.state;
            if (s.status === 'aborted') return { key: 'aborted', title: this.t.outcome.aborted, tone: 'draw' };
            if (s.status !== 'finished') return null;
            const winner = s.result === '1-0' ? 'w' : s.result === '0-1' ? 'b' : null;
            let key = winner === null ? 'draw' : this.color === null ? 'decided' : winner === this.color ? 'win' : 'loss';
            const title = key === 'decided' ? this.t.outcome.wins.replace(':name', this.t.names[winner]) : this.t.outcome[key];

            return { key, title, tone: key === 'decided' ? 'draw' : key, result: s.result.replaceAll('1/2', '½').replace('-', '–'), reason: this.t.reasons[s.reason] ?? '' };
        },
    }));

    /* ---------- Replay (finished games) ------------------------------------------------------------------ */

    window.Alpine.data('chessReplay', (config) => ({
        fens: config.fens,
        moves: config.moves,
        index: config.fens.length - 1,
        flipped: config.flipped,

        get boardLabel() {
            return this.index === 0 ? config.labels.start : config.labels.after.replace(':move', this.moves[this.index - 1].san);
        },

        get cells() {
            const move = this.moves[this.index - 1];

            return boardCells(this.fens[this.index], {
                flip: this.flipped,
                last: move ? [move.uci.slice(0, 2), move.uci.slice(2, 4)] : [],
                check: kingInCheck(this.fens[this.index]),
            });
        },

        go(index) {
            this.index = Math.max(0, Math.min(this.fens.length - 1, index));
        },

        hotkey(event) {
            const key = boardKey(event);
            if (key === 'ArrowLeft') this.go(this.index - 1);
            else if (key === 'ArrowRight') this.go(this.index + 1);
            else if (key === 'f') this.flipped = !this.flipped;
        },

        copyMoves() {
            navigator.clipboard?.writeText(config.pgn);
        },

        download() {
            const url = URL.createObjectURL(new Blob([config.pgn], { type: 'application/x-chess-pgn' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = config.filename;
            link.click();
            URL.revokeObjectURL(url);
        },
    }));

    /* ---------- Lobby ---------------------------------------------------------------------------------------- */

    window.Alpine.data('chessLobby', (config) => ({
        online: [],
        unsubscribe: null,
        connection: 'connecting',
        now: Date.now(),
        ticker: null,
        poller: null,

        init() {
            this.ticker = setInterval(() => (this.now = Date.now()), 1000);

            watchConnection((current) => {
                this.connection = current;
                // No websocket: invites and pairings still arrive by asking the server.
                if (current !== 'connected' && config.userId && !this.poller) {
                    this.poller = setInterval(() => this.$wire.pollQueue(), 5000);
                } else if (current === 'connected' && this.poller) {
                    clearInterval(this.poller);
                    this.poller = null;
                }
            });

            if (!window.Echo || !config.userId) return;

            // The page-wide `online` membership (resources/js/echo.js), joined on every logged-in page.
            this.unsubscribe = window.esportsPresence?.subscribe((members) => (this.online = members));

            window.Echo.private('App.Models.User.' + config.userId)
                .listen('.chess.game-started', ({ url }) => this.gameStarted(url))
                .listen('.chess.invite', () => this.$wire.$refresh());
        },

        /**
         * A game was made for this player (P5c): the lobby plays the sound and
         * moves to the board, also with the tab in the background.
         */
        gameStarted(url) {
            window.esportsAlerts?.leavingTo(url);
            playSound('matchFound');
            setTimeout(() => window.location.assign(url), 700);
        },

        /* The one-time question about desktop notifications, asked when the player joins the queue. */
        askNotify: false,

        joinQueue() {
            this.askNotify = window.esportsAlerts?.shouldAsk() ?? false;
            this.$wire.findOpponent();
        },

        async answerNotify(allow) {
            this.askNotify = false;
            await window.esportsAlerts?.answer(allow);
        },

        destroy() {
            clearInterval(this.ticker);
            clearInterval(this.poller);
            this.unsubscribe?.();
        },

        /* "Looking to play" (P5e): the switch shows the wanted state at once and the server catches up. */
        looking: Boolean(config.looking),
        savedLooking: Boolean(config.looking),
        savingLooking: false,
        lookingFailed: false,

        toggleLooking() {
            this.looking = !this.looking;
            this.lookingFailed = false;
            this.saveLooking();
        },

        /*
         * One request at a time, always with the state the switch shows now;
         * a click during a request is sent when it returns. A failed save
         * puts the switch back to the last stored state and says so.
         */
        async saveLooking() {
            if (this.savingLooking || this.looking === this.savedLooking) return;

            this.savingLooking = true;
            try {
                this.savedLooking = Boolean(await this.$wire.setLookingToPlay(this.looking));
            } catch {
                this.looking = this.savedLooking;
                this.lookingFailed = true;
                return;
            } finally {
                this.savingLooking = false;
            }

            this.saveLooking();
        },

        /* The row of the player this one's open invite goes to, until it expires. */
        invited(member) {
            return member.id === this.$wire.invitedUserId && this.now < this.$wire.invitedUntilMs;
        },

        get others() {
            return this.online
                .filter((m) => m.id !== config.userId)
                .sort((a, b) => (b.looking ? 1 : 0) - (a.looking ? 1 : 0) || a.name.localeCompare(b.name));
        },

        since(ms) {
            return formatClock(Math.max(0, this.now - ms));
        },
    }));
});
