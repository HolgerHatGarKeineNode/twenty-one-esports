/*
 * The small tips of Blockli practice (DerCaddy, 2026-10-23): a line now and then about what just happened on the board
 * (autoTip), and on request a nudge towards the best move without the move itself (adviceFor). Both answer with a key
 * and numbers only; the page words them (practice.js, the labels of pages/board/⚡practice). Plain functions on the
 * engine's states, for the page, the worker and the tests (tests/js/blockli/tips.test.mjs).
 */
import Blockli from './engine.js';
import { bestSteps, blockEffect, blockEffects } from './bots.js';

/** How many times a tip may come up in one game; a tip not listed comes up once. */
const REPEATS = { hit: 2, danger: 2, selfBlock: 3 };

function adjacent(a, b) {
    const d = Math.abs(a - b);

    return d === 9 || (d === 1 && Math.floor(a / 9) === Math.floor(b / 9));
}

/**
 * Where a block lies, as the board's edge names it: between two ranks (a horizontal block, `e3h` lies between ranks
 * 3 and 4) or between two files (a vertical one, `e3v` between the files e and f).
 */
export function blockArea(move) {
    const wall = Blockli.decodeWall(move);

    return wall.o ? { dir: 'v', a: Blockli.COLS[wall.c], b: Blockli.COLS[wall.c + 1] } : { dir: 'h', a: String(wall.r + 1), b: String(wall.r + 2) };
}

/** The other side's strongest block against `side`, as if it were its move: the one it would set, or null without one that costs anything. */
export function threatAgainst(s, side) {
    const other = 1 - side;
    if (s.walls[other] <= 0) return null;
    const turned = Blockli.clone(s);
    turned.turn = other;
    turned.winner = -1;
    const top = blockEffects(turned)[0];

    return top && top.theirs > 0 ? top : null;
}

/** Whether the side to move could jump over (or step beside) the other pawn on its shortest way. */
function canJump(s) {
    const side = s.turn;

    return bestSteps(s).some((move) => !adjacent(s.pawns[side], move));
}

/**
 * The tip after a move, or null. `me` is the player's side; `before` the state the move was made in, `s` the state
 * after it; `by` who moved ('me' or 'bot'); `shown` how often each tip came up in this game. After the player's own
 * move it is about that move (a block that cost the player more than the bot, a block worth one step only, a step
 * away from the goal); after the bot's move about the player's next one: a block that hit hard, the race about to be
 * lost, the bot or the player out of blocks, a narrow place on the player's way, a jump on offer.
 */
export function autoTip({ me, s, before, move, by, shown = {} }) {
    const bot = 1 - me;
    const left = (key) => (shown[key] ?? 0) < (REPEATS[key] ?? 1);
    if (s.winner >= 0) return null;
    const mine = Blockli.pathLen(s, me);
    const theirs = Blockli.pathLen(s, bot);

    if (by === 'me') {
        if (move >= 100) {
            const effect = blockEffect(before, move);
            if (effect.mine > effect.theirs && left('selfBlock')) return { key: 'selfBlock', mine: effect.mine, theirs: effect.theirs };
            if (effect.theirs <= 1 && before.walls[me] >= 6 && theirs > 3 && left('weakBlock')) return { key: 'weakBlock' };

            return null;
        }
        const jumped = !adjacent(before.pawns[me], move);
        if (!jumped && mine > Blockli.pathLen(before, me) && left('away')) return { key: 'away' };

        return null;
    }

    if (move >= 100) {
        const cost = mine - Blockli.pathLen(before, me);
        if (cost >= 3 && left('hit')) return { key: 'hit', steps: cost };
    }
    // The player moves first now: both only running, the player arrives first with at most as many steps.
    if (s.walls[me] > 0 && theirs <= 3 && mine > theirs && left('danger')) return { key: 'danger', steps: theirs };
    if (s.walls[bot] === 0 && left('botEmpty')) return { key: 'botEmpty' };
    if (s.walls[me] === 0 && left('youEmpty')) return { key: 'youEmpty' };
    if (left('threat')) {
        const threat = threatAgainst(s, me);
        if (threat && threat.theirs >= 4) return { key: 'threat', steps: threat.theirs };
    }
    if (left('jump') && canJump(s)) return { key: 'jump' };

    return null;
}

/**
 * A nudge towards the best move of an analysis (Blockli.analyze in the worker), never the move itself: run along the
 * shortest way; step aside from it; set a block now (what the best block costs the bot, and between which ranks or
 * files it lies); or guard the own way (what the bot's strongest block would cost, and where it would lie).
 */
export function adviceFor(s, analysis) {
    const best = analysis?.moves?.[0]?.m;
    if (best === undefined || best === Blockli.PASS) return null;
    const me = s.turn;
    const mine = Blockli.pathLen(s, me);
    const theirs = Blockli.pathLen(s, 1 - me);

    if (best < 100) return { kind: bestSteps(s).includes(best) ? 'run' : 'detour', mine, theirs };

    const effect = blockEffect(s, best);
    if (effect.theirs >= 2) return { kind: 'block', steps: effect.theirs, area: blockArea(best), mine, theirs };
    const threat = threatAgainst(s, me);

    return { kind: 'guard', steps: threat?.theirs ?? 0, area: threat ? blockArea(threat.move) : blockArea(best), mine, theirs };
}
