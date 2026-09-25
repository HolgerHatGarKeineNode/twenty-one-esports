/*
 * A daily chess game (ChessCorrespondence, MobileChessCorrespondence,
 * ChessOverlays "Daily move, double-check"): one move per day, no running
 * clock, a deadline per move.
 *
 * A move is chosen on the board or typed, the server checks it and returns
 * the NIP-64 note for it (the whole game so far plus this move), the player
 * confirms ("Make my move", once more if double-check is on), the signer
 * signs, and the server plays the move only together with that signature.
 */
import { Chess } from 'chess.js';
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';
import { boardKey } from './hotkeys.js';
import { moveSound, playSound } from './sounds.js';

const PIECES = { k: 'king', q: 'queen', r: 'rook', b: 'bishop', n: 'knight', p: 'pawn' };
const DAY = 86_400_000;

export function dailyGame(config, boardCells, kingInCheck) {
    return {
        state: config.state,
        color: config.color,
        t: config.labels,
        startedAt: config.startedAt,
        doubleCheck: config.doubleCheck,
        notify: config.notify,
        remind: config.remind,
        flipped: config.color === 'b',
        selected: '',
        dots: [],
        promotion: null,
        pending: null,
        confirmOpen: false,
        busy: false,
        resyncAfter: false,
        error: '',
        sanInput: '',
        now: Date.now(),
        ticker: null,

        init() {
            this.ticker = setInterval(() => (this.now = Date.now()), 20_000);

            // Move sounds (P5c): the opponent's move arriving, and the player's own once played.
            let soundedPly = this.state.ply;
            this.$watch('state.ply', (ply) => {
                if (ply <= soundedPly) return;
                soundedPly = ply;
                playSound(moveSound(this.state.moves?.[this.state.moves.length - 1]?.san));
            });

            if (window.Echo) {
                const channel = this.color ? window.Echo.private('game.' + this.state.id) : window.Echo.channel('game.' + this.state.id + '.watch');
                // Never ask the server while an own call is in flight: a second call to the
                // component while playMove waits lost playMove's answer (ChatAndDailyTest).
                channel.listen('.game.updated', (update) => {
                    if (update.version <= this.state.version) return;
                    if (this.busy) {
                        this.resyncAfter = true;

                        return;
                    }
                    this.resync();
                });
            }
        },

        destroy() {
            clearInterval(this.ticker);
        },

        async resync() {
            this.apply(await this.$wire.fetchState());
        },

        apply(state) {
            if (!state) return;
            // The finished view (result, replay, record) is the server-rendered page.
            if (state.status !== 'active') {
                window.location.reload();

                return;
            }
            this.state = state;
            this.selected = '';
            this.dots = [];
        },

        /* ---- board ---- */

        get myTurn() {
            return this.color !== null && this.state.status === 'active' && this.state.turn === this.color;
        },

        get lastSquares() {
            const last = this.state.moves[this.state.moves.length - 1];

            return last ? [last.uci.slice(0, 2), last.uci.slice(2, 4)] : [];
        },

        get cells() {
            if (this.pending) {
                return boardCells(this.pending.fen, { flip: this.flipped, last: [this.pending.uci.slice(0, 2), this.pending.uci.slice(2, 4)], check: kingInCheck(this.pending.fen), noCoords: !config.coordinates });
            }

            return boardCells(this.state.fen, { flip: this.flipped, last: this.lastSquares, check: kingInCheck(this.state.fen), select: this.selected, dots: this.dots, mover: this.myTurn && !this.busy ? this.color : null, noCoords: !config.coordinates });
        },

        get boardLabel() {
            const last = this.state.moves[this.state.moves.length - 1];

            return this.t.board.replace(':side', this.state.turn === 'w' ? this.t.white : this.t.black).replace(':move', last ? last.san : '–');
        },

        clickSquare(square) {
            if (!this.myTurn || this.pending || this.busy) return;
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
                if (config.alwaysQueen) {
                    promotion = 'q';
                } else {
                    this.promotion = { from, to };

                    return;
                }
            }

            this.prepare(from + to + (promotion ?? ''));
        },

        pickPromotion(piece) {
            if (!this.promotion) return;
            const { from, to } = this.promotion;
            this.promotion = null;
            this.prepare(from + to + piece);
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
            else if (key === 'Escape' && !this.busy) {
                if (this.confirmOpen) this.confirmOpen = false;
                else {
                    this.selected = '';
                    this.dots = [];
                }
            }
        },

        submitSan() {
            const san = this.sanInput.trim();
            if (!san || !this.myTurn || this.pending) return;
            let move = null;
            try {
                move = new Chess(this.state.fen).move(san);
            } catch {
                move = null;
            }
            if (!move) {
                this.error = this.t.errors.illegal_move;

                return;
            }
            this.sanInput = '';
            this.prepare(move.from + move.to + (move.promotion ?? ''));
        },

        /* ---- the move ---- */

        async prepare(uci) {
            this.busy = true;
            this.error = '';
            try {
                const answer = await this.$wire.prepareMove(uci, this.state.ply + 1);
                if (!answer?.ok) {
                    this.error = this.t.errors[answer?.error] ?? this.t.errors.default;
                    if (answer?.error === 'out_of_sync' || answer?.error === 'game_over') this.resync();

                    return;
                }
                const chess = new Chess(this.state.fen);
                const move = chess.move({ from: uci.slice(0, 2), to: uci.slice(2, 4), promotion: uci[4] });
                this.pending = { uci, san: answer.move.san, template: answer.move.template, fen: chess.fen(), describe: this.describe(move) };
                this.selected = '';
                this.dots = [];
            } finally {
                this.busy = false;
                this.flushResync();
            }
        },

        describe(move) {
            let text = this.t.describe.move.replace(':piece', this.t.pieces[move.piece] ?? PIECES[move.piece]).replace(':from', move.from).replace(':to', move.to);
            if (move.captured) text += ', ' + this.t.describe.takes.replace(':piece', this.t.pieces[move.captured] ?? PIECES[move.captured]);
            if (move.san.includes('#')) text += ', ' + this.t.describe.mate;
            else if (move.san.includes('+')) text += ', ' + this.t.describe.check;

            return text;
        },

        get pendingLabel() {
            if (!this.pending) return '';
            const n = Math.floor(this.state.ply / 2) + 1;

            return n + (this.state.ply % 2 === 0 ? '. ' : '… ') + this.pending.san;
        },

        clear() {
            this.pending = null;
            this.confirmOpen = false;
            this.error = '';
        },

        makeMove() {
            if (!this.pending || this.busy) return;
            if (this.doubleCheck && !this.confirmOpen) {
                this.confirmOpen = true;

                return;
            }
            this.commit();
        },

        async commit() {
            // Resolved before anything else. Livewire's $wire is a lazy proxy that looks
            // up its component on first use from the element this was called from; the
            // overlay (and that button) is gone after the awaits below, and a lookup
            // from a detached element silently returns a no-op: the move was never sent
            // (livewire.esm.js, magic "wire"; caught by tests/Browser/ChatAndDailyTest.php).
            const wire = this.$wire;
            void wire.$id;
            this.confirmOpen = false;
            if (!this.pending) return;
            this.busy = true;
            this.error = '';
            try {
                if (!(await ensureSigner())) {
                    this.error = this.t.signer.noSigner;

                    return;
                }
                // signTemplate hands the signer a plain copy: pending.template is
                // Alpine's reactive proxy, which NIP-07 extensions (nos2x, Alby)
                // cannot structured-clone for their postMessage.
                let event;
                try {
                    event = await signTemplate(this.pending.template, { pubkey: this.t.pubkey });
                } catch (error) {
                    this.error = signerMessage(this.t.signer, error);

                    return;
                }
                const answer = await wire.playMove(this.pending.uci, this.state.ply + 1, JSON.stringify(event));
                if (!answer?.ok) {
                    this.error = this.t.errors[answer?.error] ?? this.t.errors.default;
                    this.pending = null;
                    this.apply(answer?.state);

                    return;
                }
                this.pending = null;
                this.apply(answer.state);
            } finally {
                this.busy = false;
                this.flushResync();
            }
        },

        flushResync() {
            if (this.resyncAfter) {
                this.resyncAfter = false;
                this.resync();
            }
        },

        /* ---- draw and resign (daily games too) ---- */

        async call(method) {
            this.error = '';
            const answer = await this.$wire[method]();
            if (answer && answer.ok === false) this.error = this.t.errors[answer.error] ?? this.t.errors.default;
            if (answer?.state) this.apply(answer.state);
        },

        /* ---- time ---- */

        get leftMs() {
            return this.state.deadline ? Math.max(0, this.state.deadline - this.now) : 0;
        },

        hoursMinutes(ms) {
            const minutes = Math.max(0, Math.floor(ms / 60_000));

            return this.t.left.replace(':h', Math.floor(minutes / 60)).replace(':m', String(minutes % 60).padStart(2, '0'));
        },

        clockText(ms) {
            const minutes = Math.max(0, Math.floor(ms / 60_000));

            return Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0');
        },

        get low() {
            return this.leftMs < config.remindHours * 3_600_000;
        },

        get statusPill() {
            return this.myTurn ? this.t.yourMoveLeft.replace(':left', this.hoursMinutes(this.leftMs)) : this.t.theirMove;
        },

        day(at) {
            return at ? Math.floor((at - this.startedAt) / DAY) + 1 : null;
        },

        get today() {
            return Math.floor((this.now - this.startedAt) / DAY) + 1;
        },

        ago(at) {
            const minutes = Math.max(0, Math.floor((this.now - at) / 60_000));

            return minutes < 60 ? this.t.agoMinutes.replace(':m', minutes) : this.t.agoHours.replace(':h', Math.floor(minutes / 60)).replace(':m', String(minutes % 60).padStart(2, '0'));
        },

        get lastMove() {
            const moves = this.state.moves;
            const last = moves[moves.length - 1];
            if (!last) return null;
            const ply = moves.length;

            return { ...last, label: Math.ceil(ply / 2) + (ply % 2 === 1 ? '. ' : '… ') + last.san, mine: (ply % 2 === 1) === (this.color === 'w') };
        },

        get moveRows() {
            const rows = [];
            const moves = this.state.moves;
            for (let i = 0; i < moves.length; i += 2) {
                rows.push({
                    n: i / 2 + 1,
                    w: moves[i].san,
                    b: moves[i + 1]?.san ?? '',
                    wt: moves[i].at ? this.t.day.replace(':n', this.day(moves[i].at)) : '',
                    bt: moves[i + 1]?.at ? this.t.day.replace(':n', this.day(moves[i + 1].at)) : '',
                    wCur: moves.length - 1 === i,
                    bCur: moves.length - 1 === i + 1,
                });
            }

            return rows;
        },

        /* ---- notifications for this game ---- */

        async setNotify(choice) {
            this.notify = choice;
            await this.$wire.setNotify(choice);
        },

        async toggleRemind() {
            this.remind = !this.remind;
            await this.$wire.setRemind(this.remind);
        },
    };
}
