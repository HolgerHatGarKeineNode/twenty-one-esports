/*
 * Practice against bots on the league's own board, the part every game shares (Blockli: blockli/practice.js, Go:
 * go/practice.js): short lessons on the board, then three bots, with small tips. The game lives in the browser and is
 * stored nowhere: this component holds its positions, asks the server to judge every move (the page's `play` and
 * `open`, App\Support\Board\BoardPractice) and hands the answers to the board (boardGame.js) as that board's source
 * (boardSources). So the board and its input bar are those of a real game. The bots' search runs in the game's worker.
 *
 * A game brings its options (`game`) and the members that know its rules (`members`, methods and getters of the
 * component, `this` is the component):
 *
 *   game.name        the board's source name (boardSources) and the browser's key
 *   game.slug        the game the board draws
 *   game.bots        the bots by id, each with its pace `think` in ms
 *   game.worker()    the engine's worker; game.answerHere(request) answers where the page has none
 *   game.rival       the label of the lessons' opponent ('black' in Blockli, 'white' in Go)
 *   game.standing    the key of the server's standing in its answers ('standing', 'count')
 *   game.reset       what the board forgets with a new game, game.undoReset with a move taken back
 *   game.lessonPace  the least time of the opponent's answer in a lesson, in ms
 *   game.urgent      the tips about the bot's answer that replace one about the player's own move
 *   game.lessonUndo  whether a lesson's move can be taken back
 *   game.firstOf(position)  the side to move in a position's text
 *
 *   judgeLesson(before, move)   the verdict on the player's move in a lesson (`before`: the position's text)
 *   lessonMove(game)            the opponent's answer in a lesson; afterReply() once the server took it
 *   spareMove(game)             the move that goes to the server when the engine got its own wrong
 *   refused(closing)            whether the board shows the lesson's refused move right now
 *   boardExtras(game, status)   the game's own part of the board's state
 *   findTip(before, move, by), tipValues(found), adviceValues(advice)   the tips and their words
 *   request(type)               what the engine needs beside the position (optional), resultValues() (optional)
 *
 * What the browser keeps (localStorage, this device only): the lessons done, the bots beaten, the last bot and who
 * starts, and whether tips show.
 */
import { boardSources } from './boardGame.js';

/** How long a tip about the player's own move stays before an urgent one about the bot's answer replaces it (R17: texts at least 3 s). */
const TIP_HOLD_MS = 3000;

function load(key) {
    try {
        const saved = JSON.parse(localStorage.getItem(key) ?? 'null');

        return saved && typeof saved === 'object' ? saved : {};
    } catch {
        return {};
    }
}

function keep(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // A private window or a full storage: the progress stays for this visit only.
    }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function fill(text, values) {
    return String(text ?? '').replace(/:(\w+)/g, (placeholder, key) => (key in values ? values[key] : placeholder));
}

/** The side that makes ply `ply` (1 = the first move) of a game whose start has `first` to move. */
function moverOf(first, ply) {
    return ply % 2 === 1 ? first : first === 'w' ? 'b' : 'w';
}

export function boardPractice(config, game, members) {
    const saved = load(game.name);
    // Outside Alpine's reactive data: the worker and the requests waiting for it, the board's source.
    let worker = null;
    let nextRequest = 1;
    const requests = new Map();
    let source = null;
    let previewEffect = null;

    const core = {
        cfg: config,
        // 'learn' (the lessons) or 'bots'.
        mode: Array.isArray(saved.done) && config.lessons.every((lesson) => saved.done.includes(lesson.id)) ? 'bots' : 'learn',
        lessonIndex: 0,
        // The lesson: 'task' while the player tries, 'done', or 'retry' (what went wrong in `lessonNote`).
        lessonState: 'task',
        lessonNote: '',
        // A lesson's move the rules refuse, once the player tried it (the board shows it red).
        triedClosing: false,
        done: Array.isArray(saved.done) ? saved.done : [],
        beaten: Array.isArray(saved.beaten) ? saved.beaten : [],
        botId: game.bots[saved.bot] ? saved.bot : 'hodler',
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
            boardSources.set(game.name, source);
            try {
                worker = game.worker();
                // A worker that fails (its script not loaded, an error inside it) answers nothing: from then on the engine
                // runs here, and what waited for the worker is answered here too, so the bot still moves.
                worker.addEventListener('error', (event) => {
                    event.preventDefault?.();
                    worker?.terminate();
                    worker = null;
                    for (const [id, pending] of requests) {
                        requests.delete(id);
                        Promise.resolve().then(() => game.answerHere(pending.request)).then(pending.resolve, pending.reject);
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
            if (boardSources.get(game.name) === source) boardSources.delete(game.name);
        },

        /** The board's config (boardGame.js): the game held here, as the server describes a game. */
        boardConfig() {
            return {
                state: this.boardState(),
                layout: this.cfg.layout,
                color: this.game.color,
                labels: { ...this.cfg.labels.board, names: this.game.names },
                startPieces: this.game.startPieces,
                source: game.name,
            };
        },

        save() {
            keep(game.name, { done: this.done, beaten: this.beaten, bot: this.botId, first: this.humanFirst, tips: this.tipsOn });
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
                [game.standing]: view[game.standing],
                outcome: view.outcome,
                color,
                names,
                idle,
            };
            this.version++;
            if (!this.board) return;
            Object.assign(this.board, { viewIndex: null, startPieces: view.pieces, color, ...game.reset, error: '' });
            this.board.t.names = names;
            this.board.apply(this.boardState());
        },

        /** The game as the server describes a running board game (BoardGameService::snapshot()), without clocks. */
        boardState() {
            const held = this.game;
            const outcome = held.outcome;
            const status = held.idle ? 'idle' : outcome ? 'finished' : 'active';

            return {
                id: 0,
                game: game.slug,
                version: this.version,
                status,
                result: outcome?.result ?? null,
                reason: outcome?.reason ?? null,
                ply: held.moves.length,
                turn: held.turn,
                daily: false,
                clock: { w: 0, b: 0, running: null, serverNow: Date.now() },
                deadline: null,
                firstMoveDeadline: null,
                drawOffer: null,
                takeBackUntil: null,
                pieces: held.pieces,
                legal: status === 'active' ? held.legal : [],
                lastMove: held.moves.at(-1) ?? null,
                moves: held.moves.slice(),
                ...this.boardExtras(held, status),
            };
        },

        /** The server's answer to a move, recorded as the game's next move. */
        record(answer) {
            const held = this.game;
            held.moves.push({ ply: held.moves.length + 1, move: answer.move.move, notation: answer.move.notation, path: answer.move.path, spent: 0, at: null, pieces: answer.pieces });
            held.positions.push(answer.position);
            Object.assign(held, { position: answer.position, turn: answer.turn, pieces: answer.pieces, legal: answer.legal, [game.standing]: answer[game.standing], outcome: answer.outcome });
            this.version++;
        },

        get myTurnHere() {
            const held = this.game;

            return !!held && !held.idle && !held.outcome && held.turn === held.color && !this.thinking;
        },

        /** The player's move, from the board (its source's `move`): the server judges it, then the lesson or the bot answers. */
        async humanMove(move, ply) {
            const held = this.game;
            if (!this.myTurnHere) return { ok: false, error: 'not_your_turn', state: this.boardState() };
            if (ply !== held.moves.length + 1) return { ok: false, error: 'out_of_sync', state: this.boardState() };
            const token = this.token;
            const before = held.position;
            let answer = null;
            try {
                answer = await this.$wire.play(held.position, move, held.positions);
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
            if (!worker) return Promise.resolve().then(() => game.answerHere(request));
            const id = nextRequest++;

            return new Promise((resolve, reject) => {
                requests.set(id, { resolve, reject, request });
                worker.postMessage({ id, ...request });
            });
        },

        /** A bot's move for the position held, from the engine. */
        async askBot(bot) {
            return (await this.ask({ type: 'bot', bot, position: this.game.position, ...this.request?.('bot') })).move;
        },

        get botName() {
            return this.mode === 'learn' ? this.cfg.labels[game.rival] : this.cfg.bots[this.botId].name;
        },

        /** The bot's move (in a lesson the opponent's answer): never faster than the bot's own pace, judged by the server like the player's. */
        async botTurn() {
            const held = this.game;
            if (!held || held.idle || held.outcome || held.turn === held.color) return;
            const learn = this.mode === 'learn';
            const token = this.token;
            this.thinking = true;
            const started = performance.now();
            let move = null;
            try {
                move = await (learn ? this.lessonMove(held) : this.askBot(this.botId));
            } catch {
                move = null;
            }
            if (token !== this.token) return;
            await sleep(Math.max(0, (learn ? game.lessonPace : game.bots[this.botId].think) - (performance.now() - started)));
            if (token !== this.token) return;
            // A move the engine got wrong never goes to the server.
            if (!held.legal.some((legal) => legal.move === move)) move = this.spareMove(held);
            const before = held.position;
            let answer = null;
            try {
                answer = await this.$wire.play(held.position, move, held.positions);
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
            if (learn) return this.game.outcome ? this.lessonOver() : this.afterReply();
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
            this.setGame(lesson.position, lesson.view, { color: 'w', names: { w: this.cfg.labels.you, b: this.cfg.labels[game.rival] } });
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

        /** After the opponent's answer in a lesson that goes on: nothing, unless the game says otherwise. */
        afterReply() {},

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

        /** A lesson's move the rules refuse: the board shows it red, and the lesson says why once it was tried. */
        watchPreview() {
            if (previewEffect) window.Alpine.release(previewEffect);
            previewEffect = window.Alpine.effect(() => {
                const tried = this.refused(this.mode === 'learn' ? this.lesson?.closing : null);
                if (tried) this.triedClosing = true;
            });
        },

        /* ---- bots --------------------------------------------------------------------------------------- */

        /** Shows a bot's card with the start position; the game begins with "Play". */
        pickBot(id) {
            this.mode = 'bots';
            this.botId = game.bots[id] ? id : 'hodler';
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
            const held = this.game;

            return !!held?.outcome && held.outcome.result === (held.color === 'w' ? '1-0' : '0-1');
        },

        /** Only the player resigns here, so a resignation is never the player's win. */
        get resultLine() {
            const outcome = this.game?.outcome;
            const words = this.cfg.labels.result;
            if (!outcome) return '';
            if (outcome.result === '1/2-1/2') return words.draw;
            const values = { name: this.botName, ...this.resultValues?.() };
            if (this.won) return fill(words.won, values);

            return fill(outcome.reason === 'resignation' ? words.resigned : words.lost, values);
        },

        /** The next bot after a win against this one, if there is one. */
        get nextBot() {
            const order = Object.keys(this.cfg.bots);
            const next = order[order.indexOf(this.botId) + 1];

            return this.won && next ? next : null;
        },

        /** The board's resign (its source's `resign`): the game ends here and the bot wins it. */
        resign() {
            const held = this.game;
            if (!held || held.outcome || held.idle) return { ok: false, error: 'game_over', state: this.boardState() };
            this.token++;
            this.thinking = false;
            held.outcome = { result: held.color === 'w' ? '0-1' : '1-0', reason: 'resignation' };
            this.version++;
            if (this.mode === 'bots') this.finish();

            return { ok: true, state: this.boardState() };
        },

        /** The plies to keep when the player takes their last move back: everything before it (and so the bot's answer to it too). */
        get undoTo() {
            const held = this.game;
            if (!held || held.idle || (this.mode !== 'bots' && !game.lessonUndo)) return null;
            const first = game.firstOf(held.start);
            for (let ply = held.moves.length; ply > 0; ply--) if (moverOf(first, ply) === held.color) return ply - 1;

            return null;
        },

        get canUndo() {
            return this.undoTo !== null && (this.mode === 'learn' || this.stage !== 'pick');
        },

        /** Takes the player's last move back, with the bot's answer to it: the position before it, opened anew by the server. */
        async undo() {
            const keepPlies = this.undoTo;
            if (keepPlies === null) return { ok: false, error: 'default', state: this.boardState() };
            const held = this.game;
            const position = held.positions[keepPlies];
            const token = ++this.token;
            this.thinking = false;
            let view = null;
            try {
                view = await this.$wire.open(position);
            } catch {
                view = null;
            }
            if (token !== this.token || !view?.ok) return { ok: false, error: 'default', state: this.boardState() };
            held.moves.splice(keepPlies);
            held.positions.splice(keepPlies + 1);
            Object.assign(held, { position, turn: view.turn, pieces: view.pieces, legal: view.legal, [game.standing]: view[game.standing], outcome: null });
            if (this.mode === 'bots') this.stage = 'play';
            else [this.lessonState, this.lessonNote] = ['task', ''];
            [this.tip, this.asked, this.advice, this.failed] = [null, 0, null, ''];
            this.version++;
            if (this.board) Object.assign(this.board, { viewIndex: null, ...game.undoReset });
            this.board?.apply(this.boardState());

            return { ok: true, state: this.boardState() };
        },

        /* ---- tips --------------------------------------------------------------------------------------- */

        setTips(on) {
            this.tipsOn = on;
            this.save();
            if (!on && this.tip?.auto) this.tip = null;
        },

        /**
         * A tip by itself after a move (the game's findTip). One about the player's own move stays over the bot's answer,
         * unless that answer brings something urgent (game.urgent).
         */
        autoTipAfter(before, move, by) {
            if (!this.tipsOn || this.mode !== 'bots' || !before) return;
            const found = this.findTip(before, move, by);
            if (!found) return;
            if (by === 'bot' && this.tip?.by === 'me' && !game.urgent.includes(found.key)) return;
            this.shown = { ...this.shown, [found.key]: (this.shown[found.key] ?? 0) + 1 };
            const tip = { key: found.key, auto: true, by, at: Date.now(), text: fill(this.cfg.labels.tips[found.key], { name: this.botName, ...this.tipValues(found) }) };
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

        /** The tip button: first what kind of move fits, then roughly where (a kind without a second line says itself again); never the move itself. */
        async askTip() {
            if (!this.canAsk) return;
            const token = this.token;
            const position = this.game.position;
            if (this.advice?.position !== position) {
                this.tip = { key: 'thinking', auto: false, text: this.cfg.labels.advice.thinking };
                let answer = null;
                try {
                    answer = await this.ask({ type: 'advice', position, ...this.request?.('advice') });
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
            const key = this.asked === 1 || !(advice.kind + 'More' in words) ? advice.kind : advice.kind + 'More';
            this.tip = { key, auto: false, text: fill(words[key], { name: this.botName, ...this.adviceValues(advice) }) };
        },

        get turnLine() {
            const held = this.game;
            if (!held || held.idle || held.outcome) return '';
            if (this.thinking) return fill(this.cfg.labels.thinking, { name: this.botName });

            return held.turn === held.color ? this.cfg.labels.yourMove : fill(this.cfg.labels.toMove, { name: this.botName });
        },
    };

    // Getters stay getters: a spread would read them once, here.
    return Object.defineProperties(core, Object.getOwnPropertyDescriptors(members));
}
