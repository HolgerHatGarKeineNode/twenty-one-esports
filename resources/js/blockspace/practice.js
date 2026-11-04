/*
 * The Blockspace tutorial and bots (pages/board/⚡blockspace-practice): nine lessons, then three bots. The game lives in
 * this component and is stored nowhere, it plays on the league's own board (boardGame.js) as that board's source. What
 * the browser keeps (localStorage, this device only): the lessons done and the bots beaten.
 */
import { boardSources } from '../boardGame.js';
import { registerAlpine } from '../registerAlpine.js';
import { applyMove, moveText, newGame, notationOf, piecesOf, positionOf, rng, stateOf, think, winner } from './engine.js';
import { lessonVerdict } from './lessons.js';

const SOURCE = 'blockspace-practice';
const STORE = 'blockspace-practice';
const BOT_PAUSE_MS = 450;

function load() {
    try {
        const saved = JSON.parse(localStorage.getItem(STORE) ?? 'null');

        return saved && typeof saved === 'object' ? saved : {};
    } catch {
        return {};
    }
}

function keep(value) {
    try {
        localStorage.setItem(STORE, JSON.stringify(value));
    } catch {
        // A private window or a full storage: the progress stays for this visit only.
    }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function blockspacePractice(config) {
    const saved = load();
    let worker = null;
    let nextRequest = 1;
    const requests = new Map();
    let source = null;

    return {
        cfg: config,
        mode: 'learn',
        lessonIndex: 0,
        lessonState: 'task',
        lessonHint: '',
        // On a phone: the lesson's words over the block, open when a lesson starts, folded while playing.
        sheetOpen: true,
        done: Array.isArray(saved.done) ? saved.done : [],
        beaten: Array.isArray(saved.beaten) ? saved.beaten : [],
        botId: config.bots[saved.bot] ? saved.bot : 'hodler',
        fuse: saved.fuse === true,
        humanFirst: saved.first !== false,
        stage: 'pick',
        thinking: false,
        game: null,
        version: 1,
        token: 0,
        board: null,

        init() {
            source = {
                fetchState: () => this.boardState(),
                move: (move, ply) => this.humanMove(move, ply),
                resign: () => this.resign(),
                takeBack: () => null,
                checkClock: () => null,
                attach: (board) => (this.board = board),
            };
            boardSources.set(SOURCE, source);
            try {
                worker = new Worker(new URL('./botWorker.js', import.meta.url), { type: 'module' });
                worker.addEventListener('error', (event) => {
                    event.preventDefault?.();
                    worker?.terminate();
                    worker = null;
                    for (const [id, pending] of requests) {
                        requests.delete(id);
                        pending(this.thinkHere(id, pending.position, pending.level));
                    }
                });
                worker.addEventListener('message', ({ data }) => {
                    const pending = requests.get(data.id);
                    if (!pending) return;
                    requests.delete(data.id);
                    pending(data.move ?? this.thinkHere(data.id, pending.position, pending.level));
                });
            } catch {
                worker = null;
            }
            const next = this.cfg.lessons.findIndex((lesson) => !this.done.includes(lesson.id));
            this.openLesson(Math.max(0, next));
        },

        destroy() {
            this.token++;
            worker?.terminate();
            if (boardSources.get(SOURCE) === source) boardSources.delete(SOURCE);
        },

        boardConfig() {
            return { state: this.boardState(), layout: this.cfg.layout, color: this.game.color, labels: { ...this.cfg.labels.board, names: this.game.names }, startPieces: this.game.startPieces, source: SOURCE };
        },

        save() {
            keep({ done: this.done, beaten: this.beaten, bot: this.botId, first: this.humanFirst, fuse: this.fuse });
        },

        get lesson() {
            return this.cfg.lessons[this.lessonIndex];
        },

        get botName() {
            return this.cfg.bots[this.botId].name;
        },

        /* ---- the game in the browser ---------------------------------------------------------------- */

        setGame(g, { color = 'w', names, idle = false }) {
            this.token++;
            this.thinking = false;
            this.game = { g, color, names, idle, moves: [], startPieces: piecesOf(g), resigned: false };
            this.version++;
            if (!this.board) return;
            Object.assign(this.board, { viewIndex: null, startPieces: this.game.startPieces, color, error: '' });
            this.board.t.names = names;
            this.board.apply(this.boardState());
        },

        boardState() {
            const { g, idle, moves: played, resigned, color } = this.game;
            const over = g.over || resigned;
            const score = winner(g);
            const result = resigned ? (color === 'w' ? '0-1' : '1-0') : score === 0 ? '1-0' : score === 1 ? '0-1' : '1/2-1/2';

            return {
                id: 0, game: 'blockspace', version: this.version,
                status: idle ? 'idle' : over ? 'finished' : 'active',
                result: over ? result : null,
                reason: over ? (resigned ? 'resignation' : g.pool.length ? 'block_full' : 'mempool_empty') : null,
                ply: played.length, turn: g.side === 0 ? 'w' : 'b', daily: false,
                clock: { w: 0, b: 0, running: null, serverNow: Date.now() },
                deadline: null, firstMoveDeadline: null, drawOffer: null, takeBackUntil: null, takeBackBy: null,
                pieces: piecesOf(g), standing: null, legal: [], lastMove: played.at(-1) ?? null, moves: played.slice(),
            };
        },

        /** Plays a move on the game kept here and hands the board the new state. */
        record(move) {
            const game = this.game;
            const notation = notationOf(game.g, move);
            if (!applyMove(game.g, move)) return false;
            const names = move === 'pass' ? [] : move.split(':')[1].split(',');
            game.moves.push({ ply: game.moves.length + 1, move, notation, path: names, spent: 0, at: null, pieces: piecesOf(game.g) });
            this.version++;

            return true;
        },

        push() {
            this.board?.apply(this.boardState());
        },

        get humanSide() {
            return this.game?.color === 'b' ? 1 : 0;
        },

        async humanMove(move, ply) {
            const game = this.game;
            if (game.idle || game.g.over || game.g.side !== this.humanSide || this.thinking) return { ok: false, error: 'not_your_turn', state: this.boardState() };
            if (ply !== game.moves.length + 1) return { ok: false, error: 'out_of_sync', state: this.boardState() };
            const token = this.token;
            const before = stateOf(positionOf(game.g));
            if (!this.record(move)) return { ok: false, error: 'illegal_move', state: this.boardState() };
            if (this.mode === 'learn') this.judge(before, move);
            const state = this.boardState();
            if (!game.idle && !game.g.over) this.$nextTick(() => this.botTurn(token));
            else if (game.g.over) this.$nextTick(() => this.finish());

            return { ok: true, error: null, state };
        },

        /** The lesson's verdict on a move of the player. */
        judge(before, move) {
            const game = this.game;
            const lesson = this.lesson;
            if (lesson.goal === 'win') return;
            const verdict = lessonVerdict(lesson, before, game.g, move, game.moves.length);
            if (verdict === 'going') return;
            this.lessonState = verdict;
            game.idle = true;
            this.version++;
            if (verdict === 'done') this.markDone();
        },

        markDone() {
            if (!this.done.includes(this.lesson.id)) this.done = [...this.done, this.lesson.id];
            this.save();
        },

        ask(position, level) {
            return new Promise((resolve) => {
                if (!worker) return resolve(this.thinkHere(0, position, level));
                const id = nextRequest++;
                resolve.position = position;
                resolve.level = level;
                requests.set(id, resolve);
                worker.postMessage({ id, position, level });
            });
        },

        thinkHere(id, position, level) {
            const move = think(stateOf(position), level === 'stark' ? 'mittel' : level, rng(Date.now() + id));

            return move ? moveText(move.i, move.cells) : 'pass';
        },

        /** The other side plays until it is the player's turn; it may move twice in a row. */
        async botTurn(token) {
            const game = this.game;
            const level = this.mode === 'learn' ? 'leicht' : this.cfg.bots[this.botId].level;
            while (token === this.token && !game.idle && !game.g.over && game.g.side !== this.humanSide) {
                this.thinking = true;
                const [move] = await Promise.all([this.ask(positionOf(game.g), level), sleep(BOT_PAUSE_MS)]);
                if (token !== this.token) return;
                this.thinking = false;
                if (!this.record(move)) this.record('pass');
                this.push();
            }
            this.thinking = false;
            if (token === this.token && game.g.over) this.finish();
        },

        finish() {
            const won = winner(this.game.g) === this.humanSide;
            if (this.mode === 'learn') {
                this.lessonState = won ? 'done' : 'retry';
                if (won) this.markDone();

                return;
            }
            this.stage = 'over';
            this.sheetOpen = true;
            if (won && !this.beaten.includes(this.botId)) {
                this.beaten = [...this.beaten, this.botId];
                this.save();
            }
        },

        resign() {
            if (this.mode !== 'bots' || this.stage !== 'play') return { ok: false, error: 'game_over', state: this.boardState() };
            this.token++;
            this.thinking = false;
            this.game.resigned = true;
            this.version++;
            this.stage = 'over';
            this.sheetOpen = true;

            return { ok: true, error: null, state: this.boardState() };
        },

        /* ---- lessons -------------------------------------------------------------------------------- */

        openLesson(index) {
            this.mode = 'learn';
            this.lessonIndex = index;
            this.lessonState = 'task';
            this.lessonHint = '';
            this.sheetOpen = true;
            this.setGame(stateOf(this.lesson.position), { names: { w: this.cfg.labels.you, b: this.cfg.labels.black } });
            this.settleBoard();
        },

        /** After the page changed around the block: it takes its size and the phone its place anew. */
        settleBoard() {
            if (!this.board) return;
            this.$nextTick(() => {
                this.board.fitBoard();
                this.board.$refs.board?.pieceBoard?.openOnBlock();
            });
        },

        /** The line above the block on a phone. */
        get phoneLine() {
            if (this.mode === 'bots') return this.stage === 'over' ? this.resultLine : this.turnLine || this.botName;
            if (this.lessonState !== 'task') return this.lessonNote;

            return this.lessonHint || this.lesson.task;
        },

        get phoneTone() {
            if (this.mode === 'bots') return this.won ? 'text-win' : 'text-ink';

            return this.lessonState === 'done' ? 'text-win' : this.lessonState === 'retry' ? 'text-loss' : 'text-btc-hi';
        },

        nextLesson() {
            if (this.lessonIndex < this.cfg.lessons.length - 1) this.openLesson(this.lessonIndex + 1);
            else this.pickBot(this.botId);
        },

        get lessonNote() {
            if (this.lessonState === 'done') return this.lesson.done;
            if (this.lessonState !== 'retry') return '';

            return this.game?.g.over && winner(this.game.g) === -1 ? this.cfg.labels.result.draw : this.lesson.retry;
        },

        /** A stone the rules refused: the board says why, the lesson what to look for. */
        refused() {
            if (this.mode === 'learn' && this.lessonState === 'task') this.lessonHint = this.lesson.retry;
        },

        /* ---- bots ----------------------------------------------------------------------------------- */

        pickBot(id) {
            this.mode = 'bots';
            this.stage = 'pick';
            this.botId = id;
            this.save();
            this.setGame(newGame(1, false), { names: { w: this.cfg.labels.you, b: this.botName }, idle: true });
        },

        play() {
            const color = this.humanFirst ? 'w' : 'b';
            const names = color === 'w' ? { w: this.cfg.labels.you, b: this.botName } : { w: this.botName, b: this.cfg.labels.you };
            this.stage = 'play';
            this.save();
            this.setGame(newGame(Math.floor(Math.random() * 2 ** 31), this.fuse), { color, names });
            this.sheetOpen = false;
            this.settleBoard();
            const token = this.token;
            this.$nextTick(() => this.botTurn(token));
        },

        get resultLine() {
            if (!this.game || this.stage !== 'over') return '';
            const words = this.cfg.labels.result;
            if (this.game.resigned) return words.resigned.replace(':name', this.botName);
            const score = winner(this.game.g);

            return (score === -1 ? words.draw : score === this.humanSide ? words.won : words.lost).replace(':name', this.botName);
        },

        get won() {
            return this.stage === 'over' && !this.game.resigned && winner(this.game.g) === this.humanSide;
        },

        get turnLine() {
            if (!this.game || this.game.idle) return '';
            if (this.thinking) return this.cfg.labels.thinking.replace(':name', this.game.color === 'w' ? this.game.names.b : this.game.names.w);

            return this.game.g.side === this.humanSide ? this.cfg.labels.yourMove : '';
        },
    };
}

registerAlpine((Alpine) => {
    Alpine.data('blockspacePractice', blockspacePractice);
});
