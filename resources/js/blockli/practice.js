/*
 * Blockli practice against bots (pages/board/⚡practice; DerCaddy, 2026-10-23): six short lessons on the board, then
 * three bots, with small tips. The game lives in the browser and is stored nowhere: this component holds its
 * positions, asks the server to judge every move (the page's `play`, App\Support\Board\BlockliPractice) and hands the
 * answers to the league's own board (boardGame.js) as that board's source (boardSources). So the board, the block
 * input and Blockli's bar on a phone are those of a real game. The bots and the tips run on the engine of DerCaddy's
 * prototype (bots.js, tips.js), the bots' search in a worker (engineWorker.js).
 *
 * What the browser keeps (localStorage, this device only): the lessons done, the bots beaten, the last bot and who
 * starts, and whether tips show.
 */
import { boardSources } from '../boardGame.js';
import { registerAlpine } from '../registerAlpine.js';
import { BOTS, Blockli, blockEffect, botMove, engineMove, serverMove, stateOf } from './bots.js';
import { adviceFor, autoTip } from './tips.js';

/** The board's source name (boardSources) and the browser's key. */
const SOURCE = 'blockli-practice';
const STORE = 'blockli-practice';

/**
 * How long the tip button's analysis searches, in ms (two seconds reach about seven plies, where the lesson's race finds
 * its block; one second stopped at six and advised a guard), and the Whale's search where the page has no worker.
 */
const ADVICE_MS = 2000;
const HERE_MS = 500;

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

/** The engine's answer here on the page, for a browser without workers: the Whale with a short search. */
function answerHere(request) {
    const state = stateOf(request.position);
    const start = stateOf(request.start);
    const history = (request.history ?? []).map(engineMove).filter((move) => move >= 0);
    if (request.type === 'bot') return { move: serverMove(state, botMove(request.bot, state, { start, history, time: HERE_MS })) };

    return { advice: adviceFor(state, Blockli.analyze(state, { start, history, time: HERE_MS })) };
}

export function blockliPractice(config) {
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
        // The fence lesson's block that would lock Black in, once the player tried it (the board shows it red).
        triedClosing: false,
        done: Array.isArray(saved.done) ? saved.done : [],
        beaten: Array.isArray(saved.beaten) ? saved.beaten : [],
        botId: BOTS[saved.bot] ? saved.bot : 'hodler',
        humanFirst: saved.first !== false,
        tipsOn: saved.tips !== false,
        // A bot game: 'pick' (the bot's card, the start position), 'play' or 'over'.
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
        setGame(position, view, { color = 'w', names, idle = false }) {
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
                standing: view.standing,
                outcome: view.outcome,
                color,
                names,
                idle,
            };
            this.version++;
            if (!this.board) return;
            Object.assign(this.board, { viewIndex: null, startPieces: view.pieces, color, blockMode: false, preview: null, error: '' });
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
                game: 'blockli',
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
                standing: game.standing,
                legal: status === 'active' ? game.legal : [],
                lastMove: game.moves.at(-1) ?? null,
                moves: game.moves.slice(),
            };
        },

        /** The server's answer to a move, recorded as the game's next move. */
        record(answer) {
            const game = this.game;
            game.moves.push({ ply: game.moves.length + 1, move: answer.move.move, notation: answer.move.notation, path: answer.move.path, spent: 0, at: null, pieces: answer.pieces });
            game.positions.push(answer.position);
            Object.assign(game, { position: answer.position, turn: answer.turn, pieces: answer.pieces, legal: answer.legal, standing: answer.standing, outcome: answer.outcome });
            this.version++;
        },

        get humanSide() {
            return this.game?.color === 'b' ? 1 : 0;
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
            const before = stateOf(game.position);
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
                this.autoTipAfter(before, engineMove(move), 'me');
                this.botTurn();
            }, 0);

            return { ok: true, state: this.boardState() };
        },

        /** Asks the worker (or, without one, the engine here) for a bot's move or the tip button's analysis. */
        ask(request) {
            if (!worker) return Promise.resolve().then(() => answerHere(request));
            const id = nextRequest++;

            return new Promise((resolve, reject) => {
                requests.set(id, { resolve, reject });
                worker.postMessage({ id, ...request });
            });
        },

        get botName() {
            return this.mode === 'learn' ? this.cfg.labels.black : this.cfg.bots[this.botId].name;
        },

        /** The bot's move: thought up in the worker, never faster than the bot's own pace, judged by the server like the player's. */
        async botTurn() {
            const game = this.game;
            if (!game || game.idle || game.outcome || game.turn === game.color) return;
            const bot = this.mode === 'learn' ? 'hodler' : this.botId;
            const token = this.token;
            this.thinking = true;
            const started = performance.now();
            let move = null;
            try {
                move = (await this.ask({ type: 'bot', bot, position: game.position, start: game.start, history: game.moves.map((m) => m.move) })).move;
            } catch {
                move = null;
            }
            if (token !== this.token) return;
            await sleep(Math.max(0, BOTS[bot].think - (performance.now() - started)));
            if (token !== this.token) return;
            // A move the engine got wrong never goes to the server: then the first pawn move it allows.
            if (!game.legal.some((legal) => legal.move === move)) move = game.legal.find((legal) => legal.move.includes('-'))?.move ?? game.legal[0]?.move;
            const before = stateOf(game.position);
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
            if (this.mode === 'learn') {
                if (this.game.outcome) this.lessonOver();

                return;
            }
            if (this.game.outcome) return this.finish();
            this.autoTipAfter(before, engineMove(move), 'bot');
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
            this.setGame(lesson.position, lesson.view, { color: 'w', names: { w: this.cfg.labels.you, b: this.cfg.labels.black } });
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

        /** The verdict on the player's move in a lesson: done, try again, or (a lesson played out) the reply. */
        judgeLesson(before, move) {
            const lesson = this.lesson;
            if (lesson.goal === 'block') {
                const engine = engineMove(move);
                if (engine < 100) return this.lessonRetry(this.cfg.labels.lessonBlockOnly);

                return blockEffect(before, engine).theirs >= lesson.steps ? this.lessonDone() : this.lessonRetry(lesson.retry);
            }
            if (lesson.goal === 'jump') {
                const squares = move.split('-');
                if (squares.length !== 2) return this.lessonRetry(lesson.retry);
                const [from, to] = squares.map((square) => Blockli.parseMove(square));
                const far = Math.abs((from % 9) - (to % 9)) + Math.abs(Math.floor(from / 9) - Math.floor(to / 9)) > 1;

                return far ? this.lessonDone() : this.lessonRetry(lesson.retry);
            }
            if (this.game.outcome) return this.lessonOver();
            if (lesson.reply) this.botTurn();
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

        /** The fence lesson: the block it asks for first is refused by the rules; the board shows it red, the lesson says why. */
        watchPreview() {
            if (previewEffect) window.Alpine.release(previewEffect);
            previewEffect = window.Alpine.effect(() => {
                const preview = this.board?.preview;
                const closing = this.mode === 'learn' ? this.lesson?.closing : null;
                if (closing && preview && !preview.move && preview.crossing === closing[0] && preview.dir === closing[1]) this.triedClosing = true;
            });
        },

        /* ---- bots --------------------------------------------------------------------------------------- */

        /** Shows a bot's card with the start position; the game begins with "Play". */
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
            if (this.won) return fill(words.won, { name: this.botName });

            return fill(outcome.reason === 'resignation' ? words.resigned : words.lost, { name: this.botName });
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
            if (!game || game.idle || this.mode !== 'bots') return null;
            const first = game.start.split(' ')[4];
            for (let ply = game.moves.length; ply > 0; ply--) if (moverOf(first, ply) === game.color) return ply - 1;

            return null;
        },

        get canUndo() {
            return this.undoTo !== null && this.stage !== 'pick';
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
            Object.assign(game, { position, turn: view.turn, pieces: view.pieces, legal: view.legal, standing: view.standing, outcome: null });
            this.stage = 'play';
            [this.tip, this.asked, this.advice, this.failed] = [null, 0, null, ''];
            this.version++;
            if (this.board) this.board.viewIndex = null;
            this.board?.apply(this.boardState());

            return { ok: true, state: this.boardState() };
        },

        /* ---- tips --------------------------------------------------------------------------------------- */

        setTips(on) {
            this.tipsOn = on;
            this.save();
            if (!on && this.tip?.auto) this.tip = null;
        },

        /** Steps (or with `forms` = 'blocks', blocks) in words: "1 step", "3 steps". */
        count(value, forms = 'steps') {
            if (value === undefined || value === null) return '';

            return fill(this.cfg.labels[forms][value === 1 ? 0 : 1], { count: value });
        },

        /**
         * A tip by itself after a move (tips.js autoTip). One about the player's own move stays over the bot's answer,
         * unless that answer brings something urgent: a block that hit hard, the race about to be lost.
         */
        autoTipAfter(before, move, by) {
            if (!this.tipsOn || this.mode !== 'bots' || !before) return;
            const found = autoTip({ me: this.humanSide, s: stateOf(this.game.position), before, move, by, shown: this.shown });
            if (!found) return;
            if (by === 'bot' && this.tip?.by === 'me' && !['hit', 'danger'].includes(found.key)) return;
            this.shown = { ...this.shown, [found.key]: (this.shown[found.key] ?? 0) + 1 };
            const values = { name: this.botName, steps: this.count(found.steps), mine: this.count(found.mine), theirs: this.count(found.theirs) };
            this.tip = { key: found.key, auto: true, by, text: fill(this.cfg.labels.tips[found.key], values) };
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
                    answer = await this.ask({ type: 'advice', position, start: this.game.start, history: this.game.moves.map((m) => m.move), time: ADVICE_MS });
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
            const area = advice.area;
            const where = area ? fill(area.dir === 'h' ? words.ranks : words.files, { a: area.a, b: area.b }) : '';
            const values = { name: this.botName, steps: this.count(advice.steps), mine: this.count(advice.mine), theirs: this.count(advice.theirs), where };
            const key = this.asked === 1 ? advice.kind : advice.kind + 'More';
            this.tip = { key, auto: false, text: fill(words[key], values) };
        },

        /** The race in one line: how many steps each side needs and how many blocks it has left (the server's standing). */
        get raceLine() {
            const game = this.game;
            const standing = game?.standing;
            if (!standing || game.idle) return '';
            const me = game.color;
            const other = me === 'w' ? 'b' : 'w';

            return fill(this.cfg.labels.race, {
                mine: this.count(standing[me].steps),
                theirs: this.count(standing[other].steps),
                blocks: this.count(standing[me].blocks, 'blocks'),
                botBlocks: this.count(standing[other].blocks, 'blocks'),
                name: this.botName,
            });
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
    Alpine.data('blockliPractice', blockliPractice);
});
