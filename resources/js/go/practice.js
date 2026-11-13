/*
 * Go practice against bots (pages/board/⚡go-practice; DerCaddy, 2026-10-27: Go "nach dem Vorbild von Blockli", with
 * an interactive tutorial): eight short lessons on the board, then three bots, with small tips, on 9 x 9. The game
 * lives in the browser and is stored nowhere: this component holds its positions, asks the server to judge every move
 * (the page's `play`, App\Support\Board\GoPractice) and hands the answers to the league's own board (boardGame.js) as
 * that board's source (boardSources), so the board, the stone input and Go's bar on a phone are those of a real game.
 * The bots and the tips run on the browser's go engine (engine.js, bots.js, tips.js), the bots' search in a worker
 * (engineWorker.js). Built as Blockli's practice (resources/js/blockli/practice.js) is.
 *
 * What the browser keeps (localStorage, this device only): the lessons done, the bots beaten, the last bot and who
 * starts, and whether tips show.
 */
import { boardSources } from '../boardGame.js';
import { registerAlpine } from '../registerAlpine.js';
import { BOTS, botMove } from './bots.js';
import { BLACK, GoBoard, WHITE, search } from './engine.js';
import { adviceFor, autoTip } from './tips.js';

/** The board's source name (boardSources) and the browser's key. */
const SOURCE = 'go-practice';
const STORE = 'go-practice';

/** The tip button's search where the page has no worker, and the Whale's there. */
const HERE = { time: 600, playouts: 8000 };

/** How long a tip about the player's own move stays before an urgent one about the bot's answer replaces it (R17: texts at least 3 s). */
const TIP_HOLD_MS = 3000;

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

function fill(text, values) {
    return String(text ?? '').replace(/:(\w+)/g, (placeholder, key) => (key in values ? values[key] : placeholder));
}

/** The side that makes ply `ply` (1 = the first move) of a game whose start has `first` to move. */
function moverOf(first, ply) {
    return ply % 2 === 1 ? first : first === 'w' ? 'b' : 'w';
}

/** The side to move in a position's text (`x` Black, the server's `w`). */
function turnOf(text) {
    return text.split(' ')[1] === 'o' ? 'b' : 'w';
}

/**
 * White's answer in a lesson: `pass` passes; `runner` runs with a white chain in atari (onto its last liberty), and
 * waits (passes) while none is.
 */
function lessonReply(reply, text) {
    if (reply !== 'runner') return 'pass';
    const board = GoBoard.fromText(text);
    let run = null;
    let size = 0;
    for (const p of board.points) {
        if (board.color[p] === board.toMove && board.head[p] === p && board.inAtari(p) && board.stones[p] > size) {
            const at = board.atariPoint(p);
            if (board.legal(at)) [run, size] = [at, board.stones[p]];
        }
    }

    return run ? board.idOf(run) : 'pass';
}

/** The engine's answer here on the page, for a browser without workers: the bot's move, or the tip button's search. */
function answerHere(request) {
    if (request.type === 'bot') return { move: botMove(request.bot, request.position) };

    return { advice: adviceFor(request.position, search(GoBoard.fromText(request.position), HERE)) };
}

export function goPractice(config) {
    const saved = load();
    // Outside Alpine's reactive data: the worker and the requests waiting for it, the board's source.
    let worker = null;
    let nextRequest = 1;
    const requests = new Map();
    let source = null;
    let previewEffect = null;

    return {
        cfg: config,
        // 'learn' (the lessons) or 'bots'.
        mode: Array.isArray(saved.done) && config.lessons.every((lesson) => saved.done.includes(lesson.id)) ? 'bots' : 'learn',
        lessonIndex: 0,
        // The lesson: 'task' while the player tries, 'done', or 'retry' (what went wrong in `lessonNote`).
        lessonState: 'task',
        lessonNote: '',
        // A lesson's forbidden point, once the player tried it (the board shows it red).
        triedClosing: false,
        done: Array.isArray(saved.done) ? saved.done : [],
        beaten: Array.isArray(saved.beaten) ? saved.beaten : [],
        botId: BOTS[saved.bot] ? saved.bot : 'hodler',
        humanFirst: saved.first !== false,
        tipsOn: saved.tips !== false,
        // A bot game: 'pick' (the bot's card, the empty board), 'play' or 'over'.
        stage: 'pick',
        thinking: false,
        tip: null,
        asked: 0,
        advice: null,
        shown: {},
        failed: '',
        game: null,
        version: 1,
        token: 0,
        board: null,

        init() {
            source = {
                fetchState: () => this.boardState(),
                move: (move, ply) => this.humanMove(move, ply),
                resign: () => this.resign(),
                takeBack: () => this.undo(),
                checkClock: () => null,
                attach: (board) => {
                    this.board = board;
                    this.watchPreview();
                },
            };
            boardSources.set(SOURCE, source);
            try {
                worker = new Worker(new URL('./engineWorker.js', import.meta.url), { type: 'module' });
                // A worker that fails (its script not loaded, an error inside it) answers nothing: from then on the engine
                // runs here, and what waited for the worker is answered here too, so the bot still moves.
                worker.addEventListener('error', (event) => {
                    event.preventDefault?.();
                    worker?.terminate();
                    worker = null;
                    for (const [id, pending] of requests) {
                        requests.delete(id);
                        Promise.resolve().then(() => answerHere(pending.request)).then(pending.resolve, pending.reject);
                    }
                });
                worker.addEventListener('message', ({ data }) => {
                    const request = requests.get(data.id);
                    if (!request) return;
                    requests.delete(data.id);
                    if (data.error) request.reject(new Error(data.error));
                    else request.resolve(data);
                });
            } catch {
                worker = null;
            }
            if (this.mode === 'learn') {
                const next = this.cfg.lessons.findIndex((lesson) => !this.done.includes(lesson.id));
                this.openLesson(Math.max(0, next), false);
            } else {
                this.pickBot(this.botId);
            }
        },

        destroy() {
            this.token++;
            worker?.terminate();
            if (previewEffect) window.Alpine.release(previewEffect);
            if (boardSources.get(SOURCE) === source) boardSources.delete(SOURCE);
        },

        /** The board's config (boardGame.js): the game held here, as the server describes a game. */
        boardConfig() {
            return {
                state: this.boardState(),
                layout: this.cfg.layout,
                color: this.game.color,
                labels: { ...this.cfg.labels.board, names: this.game.names },
                startPieces: this.game.startPieces,
                source: SOURCE,
            };
        },

        save() {
            keep({ done: this.done, beaten: this.beaten, bot: this.botId, first: this.humanFirst, tips: this.tipsOn });
        },

        /* ---- the game in the browser ---------------------------------------------------------------- */

        /** Puts a position on the board as a new game: a lesson's, or the start of a bot game (`idle` before "Play"). */
        setGame(position, view, { color = 'w', names, idle = false, showCount = true }) {
            this.token++;
            this.thinking = false;
            this.tip = null;
            this.asked = 0;
            this.advice = null;
            this.shown = {};
            this.failed = '';
            this.game = {
                start: position,
                startPieces: view.pieces,
                positions: [position],
                moves: [],
                position,
                turn: view.turn,
                pieces: view.pieces,
                legal: view.legal,
                count: view.count,
                outcome: view.outcome,
                color,
                names,
                idle,
                showCount,
            };
            this.version++;
            if (!this.board) return;
            Object.assign(this.board, { viewIndex: null, startPieces: view.pieces, color, stone: null, confirmPass: false, showArea: false, error: '' });
            this.board.t.names = names;
            this.board.apply(this.boardState());
        },

        /** The game as the server describes a running board game (BoardGameService::snapshot()), without clocks. */
        boardState() {
            const game = this.game;
            const outcome = game.outcome;
            const status = game.idle ? 'idle' : outcome ? 'finished' : 'active';

            return {
                id: 0,
                game: 'go-9',
                version: this.version,
                status,
                result: outcome?.result ?? null,
                reason: outcome?.reason ?? null,
                ply: game.moves.length,
                turn: game.turn,
                daily: false,
                clock: { w: 0, b: 0, running: null, serverNow: Date.now() },
                deadline: null,
                firstMoveDeadline: null,
                drawOffer: null,
                takeBackUntil: null,
                pieces: game.pieces,
                standing: null,
                // The count beside the board: in a bot game and where a lesson is about it.
                count: game.showCount ? game.count : null,
                // A lesson about stones has no pass (two passes would end it): the board shows no Pass button there.
                noPass: this.mode === 'learn' && !this.lesson?.pass,
                legal: status === 'active' ? (this.mode === 'learn' && !this.lesson?.pass ? game.legal.filter((option) => option.path.length > 0) : game.legal) : [],
                lastMove: game.moves.at(-1) ?? null,
                moves: game.moves.slice(),
            };
        },

        /** The server's answer to a move, recorded as the game's next move. */
        record(answer) {
            const game = this.game;
            game.moves.push({ ply: game.moves.length + 1, move: answer.move.move, notation: answer.move.notation, path: answer.move.path, spent: 0, at: null, pieces: answer.pieces });
            game.positions.push(answer.position);
            Object.assign(game, { position: answer.position, turn: answer.turn, pieces: answer.pieces, legal: answer.legal, count: answer.count, outcome: answer.outcome });
            this.version++;
        },

        get humanColor() {
            return this.game?.color === 'b' ? WHITE : BLACK;
        },

        get myTurnHere() {
            const game = this.game;

            return !!game && !game.idle && !game.outcome && game.turn === game.color && !this.thinking;
        },

        /** The player's move, from the board (its source's `move`): the server judges it, then the lesson or the bot answers. */
        async humanMove(move, ply) {
            const game = this.game;
            if (!this.myTurnHere) return { ok: false, error: 'not_your_turn', state: this.boardState() };
            if (ply !== game.moves.length + 1) return { ok: false, error: 'out_of_sync', state: this.boardState() };
            const token = this.token;
            const before = game.position;
            let answer = null;
            try {
                answer = await this.$wire.play(game.position, move, game.positions);
            } catch {
                answer = null;
            }
            if (token !== this.token) return { ok: false, error: 'out_of_sync', state: this.boardState() };
            if (!answer?.ok) return { ok: false, error: answer?.error ?? 'default', state: this.boardState() };
            this.record(answer);
            [this.tip, this.asked, this.advice] = [null, 0, null];
            // Once the board has taken the new state (a task after the board's own await): the lesson's verdict, a tip, the bot.
            setTimeout(() => {
                if (token !== this.token) return;
                if (this.mode === 'learn') return this.judgeLesson(before, move);
                if (this.game.outcome) return this.finish();
                this.autoTipAfter(before, move, 'me');
                this.botTurn();
            }, 0);

            return { ok: true, state: this.boardState() };
        },

        /** Asks the worker (or, without one, the engine here) for a bot's move or the tip button's analysis. */
        ask(request) {
            if (!worker) return Promise.resolve().then(() => answerHere(request));
            const id = nextRequest++;

            return new Promise((resolve, reject) => {
                requests.set(id, { resolve, reject, request });
                worker.postMessage({ id, ...request });
            });
        },

        get botName() {
            return this.mode === 'learn' ? this.cfg.labels.white : this.cfg.bots[this.botId].name;
        },

        /** The bot's move (in a lesson White's scripted answer): thought up in the worker, never faster than the bot's pace, judged by the server. */
        async botTurn() {
            const game = this.game;
            if (!game || game.idle || game.outcome || game.turn === game.color) return;
            const token = this.token;
            this.thinking = true;
            const started = performance.now();
            let move = null;
            if (this.mode === 'learn') {
                move = lessonReply(this.lesson.reply, game.position);
            } else {
                try {
                    move = (await this.ask({ type: 'bot', bot: this.botId, position: game.position })).move;
                } catch {
                    move = null;
                }
            }
            if (token !== this.token) return;
            await sleep(Math.max(0, (this.mode === 'learn' ? 500 : BOTS[this.botId].think) - (performance.now() - started)));
            if (token !== this.token) return;
            // A move the engine got wrong never goes to the server: then the pass, which is always legal.
            if (!game.legal.some((legal) => legal.move === move)) move = 'pass';
            const before = game.position;
            let answer = null;
            try {
                answer = await this.$wire.play(game.position, move, game.positions);
            } catch {
                answer = null;
            }
            if (token !== this.token) return;
            this.thinking = false;
            if (!answer?.ok) {
                this.failed = this.cfg.labels.botFailed;

                return;
            }
            this.record(answer);
            this.board?.apply(this.boardState());
            if (this.mode === 'learn') return this.afterReply();
            if (this.game.outcome) return this.finish();
            this.autoTipAfter(before, move, 'bot');
        },

        /* ---- lessons ------------------------------------------------------------------------------------ */

        get lesson() {
            return this.cfg.lessons[this.lessonIndex];
        },

        openLesson(index, scroll = true) {
            this.mode = 'learn';
            this.lessonIndex = Math.max(0, Math.min(this.cfg.lessons.length - 1, index));
            this.lessonState = 'task';
            this.lessonNote = '';
            this.triedClosing = false;
            const lesson = this.lesson;
            this.setGame(lesson.position, lesson.view, { color: 'w', names: { w: this.cfg.labels.you, b: this.cfg.labels.white }, showCount: !!lesson.count });
            if (scroll) this.showBoard();
        },

        /** The next lesson that is not done yet (after the current one), else the bots. */
        nextLesson() {
            if (this.lessonIndex < this.cfg.lessons.length - 1) return this.openLesson(this.lessonIndex + 1);
            this.pickBot(this.botId);
        },

        /**
         * Brings the board back into view after a button below it started something new (on a phone the panel lies under
         * the board): only when the board's column begins above the screen or in its lower half.
         */
        showBoard() {
            this.$nextTick(() => {
                const column = this.$root.querySelector('[data-test=board-column]');
                const top = column?.getBoundingClientRect().top ?? 0;
                if (column && (top < 0 || top > innerHeight / 2)) column.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            });
        },

        /** The verdict on the player's move in a lesson: done, try again, or White's answer and on. */
        judgeLesson(beforeText, move) {
            const lesson = this.lesson;
            const before = GoBoard.fromText(beforeText);
            const after = GoBoard.fromText(this.game.position);
            const captured = before.count().stones[WHITE] - after.count().stones[WHITE];
            if (lesson.goal === 'capture' && captured >= (lesson.min ?? 1)) return this.lessonDone();
            if (lesson.goal === 'escape') {
                const p = after.pointOf(lesson.point);

                return after.color[p] === BLACK && after.liberties(p).length >= (lesson.min ?? 2) ? this.lessonDone() : this.lessonRetry(lesson.retry);
            }
            if (lesson.goal === 'point') return (lesson.points ?? []).includes(move) ? this.lessonDone() : this.lessonRetry(lesson.retry);
            if (this.game.outcome) return this.lessonOver();
            if (!lesson.reply) return this.lessonRetry(lesson.retry);
            this.botTurn();
        },

        /** After White's answer in a lesson: the game over, or the ladder's runner got away. */
        afterReply() {
            const lesson = this.lesson;
            if (this.game.outcome) return this.lessonOver();
            if (lesson.reply === 'runner') {
                const board = GoBoard.fromText(this.game.position);
                const escaped = board.points.some((p) => board.color[p] === WHITE && board.head[p] === p && board.liberties(p).length >= 3);
                if (escaped) this.lessonRetry(lesson.retry);
            }
        },

        lessonOver() {
            return this.won ? this.lessonDone() : this.lessonRetry(this.lesson.retry);
        },

        lessonDone() {
            this.lessonState = 'done';
            this.lessonNote = this.lesson.done;
            if (!this.done.includes(this.lesson.id)) this.done = [...this.done, this.lesson.id];
            this.save();
        },

        lessonRetry(note) {
            this.lessonState = 'retry';
            this.lessonNote = note;
        },

        /** A lesson's forbidden point: the board shows the stone there red, and the lesson says why once it was tried. */
        watchPreview() {
            if (previewEffect) window.Alpine.release(previewEffect);
            previewEffect = window.Alpine.effect(() => {
                const stone = this.board?.stone;
                const closing = this.mode === 'learn' ? this.lesson?.closing : null;
                if (closing && stone === closing && !this.board.stoneMoves.has(stone)) this.triedClosing = true;
            });
        },

        /* ---- bots --------------------------------------------------------------------------------------- */

        /** Shows a bot's card with the empty board; the game begins with "Play". */
        pickBot(id) {
            this.mode = 'bots';
            this.botId = BOTS[id] ? id : 'hodler';
            this.stage = 'pick';
            this.save();
            const color = this.humanFirst ? 'w' : 'b';
            this.setGame(this.cfg.start.position, this.cfg.start.view, { color, names: this.namesFor(color), idle: true });
        },

        namesFor(color) {
            const bot = this.cfg.bots[this.botId].name;

            return color === 'w' ? { w: this.cfg.labels.you, b: bot } : { w: bot, b: this.cfg.labels.you };
        },

        setFirst(first) {
            this.humanFirst = first;
            this.save();
            if (this.stage === 'pick') this.pickBot(this.botId);
        },

        play() {
            const color = this.humanFirst ? 'w' : 'b';
            this.stage = 'play';
            this.setGame(this.cfg.start.position, this.cfg.start.view, { color, names: this.namesFor(color) });
            this.save();
            this.showBoard();
            if (color === 'b') this.botTurn();
        },

        finish() {
            this.stage = 'over';
            this.thinking = false;
            if (this.won && !this.beaten.includes(this.botId)) {
                this.beaten = [...this.beaten, this.botId];
                this.save();
            }
        },

        get won() {
            const game = this.game;

            return !!game?.outcome && game.outcome.result === (game.color === 'w' ? '1-0' : '0-1');
        },

        get resultLine() {
            const outcome = this.game?.outcome;
            const words = this.cfg.labels.result;
            if (!outcome) return '';
            if (outcome.result === '1/2-1/2') return words.draw;
            const by = this.game.count ? Math.abs(this.game.count.margin).toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: 1 }) : '';
            if (outcome.reason === 'resignation') return fill(words.resigned, { name: this.botName });
            if (this.won) return fill(words.won, { name: this.botName, points: by });

            return fill(words.lost, { name: this.botName, points: by });
        },

        /** The next bot after a win against this one, if there is one. */
        get nextBot() {
            const order = Object.keys(this.cfg.bots);
            const next = order[order.indexOf(this.botId) + 1];

            return this.won && next ? next : null;
        },

        /** The board's resign (its source's `resign`): the game ends here and the bot wins it. */
        resign() {
            const game = this.game;
            if (!game || game.outcome || game.idle) return { ok: false, error: 'game_over', state: this.boardState() };
            this.token++;
            this.thinking = false;
            game.outcome = { result: game.color === 'w' ? '0-1' : '1-0', reason: 'resignation' };
            this.version++;
            if (this.mode === 'bots') this.finish();

            return { ok: true, state: this.boardState() };
        },

        /** The plies to keep when the player takes their last move back: everything before it (and so the bot's answer to it too). */
        get undoTo() {
            const game = this.game;
            if (!game || game.idle) return null;
            const first = turnOf(game.start);
            for (let ply = game.moves.length; ply > 0; ply--) if (moverOf(first, ply) === game.color) return ply - 1;

            return null;
        },

        get canUndo() {
            return this.undoTo !== null && (this.mode === 'learn' || this.stage !== 'pick');
        },

        /** Takes the player's last move back, with the bot's answer to it: the position before it, opened anew by the server. */
        async undo() {
            const keepPlies = this.undoTo;
            if (keepPlies === null) return { ok: false, error: 'default', state: this.boardState() };
            const game = this.game;
            const position = game.positions[keepPlies];
            const token = ++this.token;
            this.thinking = false;
            let view = null;
            try {
                view = await this.$wire.open(position);
            } catch {
                view = null;
            }
            if (token !== this.token || !view?.ok) return { ok: false, error: 'default', state: this.boardState() };
            game.moves.splice(keepPlies);
            game.positions.splice(keepPlies + 1);
            Object.assign(game, { position, turn: view.turn, pieces: view.pieces, legal: view.legal, count: view.count, outcome: null });
            if (this.mode === 'bots') this.stage = 'play';
            else [this.lessonState, this.lessonNote] = ['task', ''];
            [this.tip, this.asked, this.advice, this.failed] = [null, 0, null, ''];
            this.version++;
            if (this.board) Object.assign(this.board, { viewIndex: null, showArea: false });
            this.board?.apply(this.boardState());

            return { ok: true, state: this.boardState() };
        },

        /* ---- tips --------------------------------------------------------------------------------------- */

        setTips(on) {
            this.tipsOn = on;
            this.save();
            if (!on && this.tip?.auto) this.tip = null;
        },

        /** Stones in words: "1 stone", "3 stones". */
        stones(value) {
            return fill(this.cfg.labels.stones[value === 1 ? 0 : 1], { count: value });
        },

        /**
         * A tip by itself after a move (tips.js autoTip). One about the player's own move stays over the bot's answer,
         * unless that answer brings something urgent: stones taken, or put in atari.
         */
        autoTipAfter(beforeText, move, by) {
            if (!this.tipsOn || this.mode !== 'bots' || !beforeText) return;
            const found = autoTip({ before: GoBoard.fromText(beforeText), after: GoBoard.fromText(this.game.position), move, by, me: this.humanColor, shown: this.shown });
            if (!found) return;
            if (by === 'bot' && this.tip?.by === 'me' && !['captured', 'atari'].includes(found.key)) return;
            this.shown = { ...this.shown, [found.key]: (this.shown[found.key] ?? 0) + 1 };
            const values = { name: this.botName, stones: found.count ? this.stones(found.count) : '', point: found.point ?? '' };
            const tip = { key: found.key, auto: true, by, at: Date.now(), text: fill(this.cfg.labels.tips[found.key], values) };
            // The player's own tip stays its 3 s; the urgent one follows then, unless the player has moved meanwhile.
            const wait = by === 'bot' && this.tip?.by === 'me' ? TIP_HOLD_MS - (Date.now() - this.tip.at) : 0;
            if (wait <= 0) {
                this.tip = tip;

                return;
            }
            const [token, shownAt] = [this.token, this.tip.at];
            setTimeout(() => {
                if (token === this.token && this.tip?.at === shownAt) this.tip = tip;
            }, wait);
        },

        get canAsk() {
            return this.mode === 'bots' && this.stage === 'play' && this.myTurnHere && this.asked < 2 && this.tip?.key !== 'thinking';
        },

        /** The tip button: first what kind of move fits, then roughly where; never the move itself. */
        async askTip() {
            if (!this.canAsk) return;
            const token = this.token;
            const position = this.game.position;
            if (this.advice?.position !== position) {
                this.tip = { key: 'thinking', auto: false, text: this.cfg.labels.advice.thinking };
                let answer = null;
                try {
                    answer = await this.ask({ type: 'advice', position });
                } catch {
                    answer = null;
                }
                if (token !== this.token || this.game.position !== position) return;
                if (!answer?.advice) {
                    this.tip = null;

                    return;
                }
                this.advice = { position, ...answer.advice };
            }
            this.asked++;
            const advice = this.advice;
            const words = this.cfg.labels.advice;
            const where = advice.area ? words.areas[advice.area] : '';
            const values = { name: this.botName, where, stones: advice.count ? this.stones(advice.count) : '' };
            const key = this.asked === 1 || advice.kind === 'pass' ? advice.kind : advice.kind + 'More';
            this.tip = { key, auto: false, text: fill(words[key], values) };
        },

        get turnLine() {
            const game = this.game;
            if (!game || game.idle || game.outcome) return '';
            if (this.thinking) return fill(this.cfg.labels.thinking, { name: this.botName });

            return game.turn === game.color ? this.cfg.labels.yourMove : fill(this.cfg.labels.toMove, { name: this.botName });
        },
    };
}

registerAlpine((Alpine) => {
    Alpine.data('goPractice', goPractice);
});
