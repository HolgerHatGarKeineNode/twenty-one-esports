/*
 * Go practice against bots (pages/board/⚡go-practice; DerCaddy, 2026-10-27: Go "nach dem Vorbild von Blockli", with
 * an interactive tutorial): eight short lessons on the board, then three bots, with small tips, on 9 x 9. The flow is
 * the shared practice (../boardPractice.js: the lessons, the bot's turn, the server's judge
 * App\Support\Board\GoPractice, take-back, tips); this file is what only Go knows: White's scripted answers and the
 * verdicts of its lessons, the count, and the words of its tips. So the board, the stone input and Go's bar on a phone
 * are those of a real game. The bots and the tips run on the browser's go engine (engine.js, bots.js, tips.js), the
 * bots' search in a worker (engineWorker.js).
 */
import { boardPractice, fill } from '../boardPractice.js';
import { registerAlpine } from '../registerAlpine.js';
import { BOTS, botMove } from './bots.js';
import { BLACK, GoBoard, WHITE, search } from './engine.js';
import { adviceFor, autoTip } from './tips.js';

/** The tip button's search where the page has no worker, and the Whale's there. */
const HERE = { time: 600, playouts: 8000 };

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

const GAME = {
    name: 'go-practice',
    slug: 'go-9',
    bots: BOTS,
    worker: () => new Worker(new URL('./engineWorker.js', import.meta.url), { type: 'module' }),
    answerHere,
    rival: 'white',
    standing: 'count',
    reset: { stone: null, confirmPass: false, showArea: false },
    undoReset: { showArea: false },
    // White's scripted answer in a lesson takes half a second.
    lessonPace: 500,
    // Stones taken, or put in atari.
    urgent: ['captured', 'atari'],
    lessonUndo: true,
    firstOf: turnOf,
};

export function goPractice(config) {
    return boardPractice(config, GAME, {
        boardExtras(game, status) {
            const learn = this.mode === 'learn';
            // A lesson about stones has no pass (two passes would end it): the board shows no Pass button there.
            const noPass = learn && !this.lesson?.pass;

            return {
                standing: null,
                // The count beside the board: in a bot game and where a lesson is about it.
                count: !learn || this.lesson?.count ? game.count : null,
                noPass,
                legal: status === 'active' ? (noPass ? game.legal.filter((option) => option.path.length > 0) : game.legal) : [],
            };
        },

        /** White's scripted answer in a lesson. */
        lessonMove(game) {
            return lessonReply(this.lesson.reply, game.position);
        },

        /** The pass, which is always legal. */
        spareMove() {
            return 'pass';
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

        /** After White's answer in a lesson that goes on: the ladder's runner got away. */
        afterReply() {
            const lesson = this.lesson;
            if (lesson.reply === 'runner') {
                const board = GoBoard.fromText(this.game.position);
                const escaped = board.points.some((p) => board.color[p] === WHITE && board.head[p] === p && board.liberties(p).length >= 3);
                if (escaped) this.lessonRetry(lesson.retry);
            }
        },

        /** A lesson's forbidden point: the board shows the stone there red, and the lesson says why once it was tried. */
        refused(closing) {
            const stone = this.board?.stone;

            return closing && stone === closing && !this.board.stoneMoves.has(stone);
        },

        /** By how many points the game was won, in the page's language. */
        resultValues() {
            return { points: this.game.count ? Math.abs(this.game.count.margin).toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: 1 }) : '' };
        },

        /** Stones in words: "1 stone", "3 stones". */
        stones(value) {
            return fill(this.cfg.labels.stones[value === 1 ? 0 : 1], { count: value });
        },

        findTip(before, move, by) {
            return autoTip({ before: GoBoard.fromText(before), after: GoBoard.fromText(this.game.position), move, by, me: this.game.color === 'b' ? WHITE : BLACK, shown: this.shown });
        },

        tipValues(found) {
            return { stones: found.count ? this.stones(found.count) : '', point: found.point ?? '' };
        },

        adviceValues(advice) {
            return { where: advice.area ? this.cfg.labels.advice.areas[advice.area] : '', stones: advice.count ? this.stones(advice.count) : '' };
        },
    });
}

registerAlpine((Alpine) => {
    Alpine.data('goPractice', goPractice);
});
