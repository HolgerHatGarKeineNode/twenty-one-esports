/*
 * Blockli practice against bots (pages/board/⚡practice; DerCaddy, 2026-10-23): six short lessons on the board, then
 * three bots, with small tips. The flow is the shared practice (../boardPractice.js: the lessons, the bot's turn, the
 * server's judge App\Support\Board\BlockliPractice, take-back, tips); this file is what only Blockli knows: its lessons'
 * verdicts, the race line, and the words of its tips. So the board, the block input and Blockli's bar on a phone are
 * those of a real game. The bots and the tips run on the engine of DerCaddy's prototype (bots.js, tips.js), the bots'
 * search in a worker (engineWorker.js).
 */
import { boardPractice, fill } from '../boardPractice.js';
import { registerAlpine } from '../registerAlpine.js';
import { BOTS, Blockli, blockEffect, botMove, engineMove, serverMove, stateOf } from './bots.js';
import { adviceFor, autoTip } from './tips.js';

/**
 * How long the tip button's analysis searches, in ms (two seconds reach about seven plies, where the lesson's race finds
 * its block; one second stopped at six and advised a guard), and the Whale's search where the page has no worker.
 */
const ADVICE_MS = 2000;
const HERE_MS = 500;

/** The engine's answer here on the page, for a browser without workers: the Whale with a short search. */
function answerHere(request) {
    const state = stateOf(request.position);
    const start = stateOf(request.start);
    const history = (request.history ?? []).map(engineMove).filter((move) => move >= 0);
    if (request.type === 'bot') return { move: serverMove(state, botMove(request.bot, state, { start, history, time: HERE_MS })) };

    return { advice: adviceFor(state, Blockli.analyze(state, { start, history, time: HERE_MS })) };
}

const GAME = {
    name: 'blockli-practice',
    slug: 'blockli',
    bots: BOTS,
    worker: () => new Worker(new URL('./engineWorker.js', import.meta.url), { type: 'module' }),
    answerHere,
    standing: 'standing',
    reset: { blockMode: false, preview: null },
    // A lesson's opponent is the Hodler, at his pace.
    lessonPace: BOTS.hodler.think,
    // A block that hit hard, the race about to be lost.
    urgent: ['hit', 'danger'],
    lessonUndo: false,
    firstOf: (position) => position.split(' ')[4],
};

export function blockliPractice(config) {
    return boardPractice(config, GAME, {
        boardExtras(game) {
            return { standing: game.standing };
        },

        /** What the engine needs beside the position: where the game began and its moves, and the tip button's search time. */
        request(type) {
            return { start: this.game.start, history: this.game.moves.map((m) => m.move), ...(type === 'advice' ? { time: ADVICE_MS } : {}) };
        },

        lessonMove() {
            return this.askBot('hodler');
        },

        /** The first pawn move the rules allow. */
        spareMove(game) {
            return game.legal.find((legal) => legal.move.includes('-'))?.move ?? game.legal[0]?.move;
        },

        /** The verdict on the player's move in a lesson: done, try again, or (a lesson played out) the reply. */
        judgeLesson(beforeText, move) {
            const lesson = this.lesson;
            if (lesson.goal === 'block') {
                const engine = engineMove(move);
                if (engine < 100) return this.lessonRetry(this.cfg.labels.lessonBlockOnly);

                return blockEffect(stateOf(beforeText), engine).theirs >= lesson.steps ? this.lessonDone() : this.lessonRetry(lesson.retry);
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

        /** The fence lesson: the block it asks for first is refused by the rules; the board shows it red, the lesson says why. */
        refused(closing) {
            const preview = this.board?.preview;

            return closing && preview && !preview.move && preview.crossing === closing[0] && preview.dir === closing[1];
        },

        /** Steps (or with `forms` = 'blocks', blocks) in words: "1 step", "3 steps". */
        count(value, forms = 'steps') {
            if (value === undefined || value === null) return '';

            return fill(this.cfg.labels[forms][value === 1 ? 0 : 1], { count: value });
        },

        findTip(before, move, by) {
            return autoTip({ me: this.game.color === 'b' ? 1 : 0, s: stateOf(this.game.position), before: stateOf(before), move: engineMove(move), by, shown: this.shown });
        },

        tipValues(found) {
            return { steps: this.count(found.steps), mine: this.count(found.mine), theirs: this.count(found.theirs) };
        },

        adviceValues(advice) {
            const words = this.cfg.labels.advice;
            const area = advice.area;
            const where = area ? fill(area.dir === 'h' ? words.ranks : words.files, { a: area.a, b: area.b }) : '';

            return { steps: this.count(advice.steps), mine: this.count(advice.mine), theirs: this.count(advice.theirs), where };
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
    });
}

registerAlpine((Alpine) => {
    Alpine.data('blockliPractice', blockliPractice);
});
