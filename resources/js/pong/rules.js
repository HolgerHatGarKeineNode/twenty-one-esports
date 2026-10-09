/**
 * Proof of Pong's rules and a game as a row of rallies (plan "Proof of Pong", P1), the server's
 * App\Support\Pong\PongRules and PongGame: a game to `points_to_win`, `win_by` ahead, and the meme events (Halving,
 * Brrr, Pizza Day, Difficulty Adjustment, and since P7 Steuern sind Raub, Kapitalverkehrskontrolle, Few understand,
 * Proof of Work, Arbeitsamt): since P8 every block of `event_block_rallies` rallies holds all nine once, on distinct
 * rallies drawn from the game's seed, a block never opening with the event that closed the one before; the same for
 * both sides.
 */
import { seeded } from './rng.js';
import { ARBEITSAMT, BRRR, CONTROLS, DIFFICULTY, FEW, HALVING, PIZZA, POW, TAX, createRally, stepRally } from './physics.js';
import { botSpeed, createBot } from './bot.js';

export const EVENTS = [HALVING, BRRR, PIZZA, DIFFICULTY, TAX, CONTROLS, FEW, POW, ARBEITSAMT];
export const DEFAULT_RULES = { points_to_win: 21, win_by: 2, event_block_rallies: 21 };

const EVENT_SALT = 0x504f4e47;

/** The seed of rally `rally` (from 1), as PongRules::rallySeed(). */
export const rallySeed = (seed, rally) => ((seed ^ ((rally * 0x9e3779b9) % 4294967296)) >>> 0);

/**
 * The first `count` blocks of a game, as PongRules::blocks(): per block a Map position (0 = its first rally) => event,
 * drawn block after block from one generator.
 */
export function blocks(rules, seed, count) {
    const size = rules.event_block_rallies;
    const rng = seeded(((seed ^ EVENT_SALT) >>> 0));
    const perBlock = Math.min(EVENTS.length, size);
    const all = Array.from({ length: size }, (_, i) => i);
    let previous = null;
    const out = [];

    for (let b = 0; b < count; b++) {
        const order = rng.shuffle(EVENTS);
        if (order[0] === previous) {
            // The block would open with the event that closed the last one: swap it with a later one.
            const swap = 1 + rng.below(perBlock > 1 ? perBlock - 1 : EVENTS.length - 1);
            [order[0], order[swap]] = [order[swap], order[0]];
        }
        const positions = rng.shuffle(all).slice(0, perBlock).sort((a, b2) => a - b2);
        out.push(new Map(positions.map((position, i) => [position, order[i]])));
        previous = order[perBlock - 1];
    }

    return out;
}

/** The event of rally `rally` (from 1), or null. Blocks already drawn are kept per seed and block size. */
const drawn = new Map();
export function eventOf(rules, seed, rally) {
    if (rally < 1) return null;
    const size = rules.event_block_rallies;
    const block = Math.floor((rally - 1) / size);
    const key = `${seed}|${size}`;
    let known = drawn.get(key);
    if (!known || known.length <= block) {
        known = blocks(rules, seed, block + 1);
        if (drawn.size > 64) drawn.clear();
        drawn.set(key, known);
    }

    return known[block].get((rally - 1) % size) ?? null;
}

/** The winning side, or null. */
export function winner(rules, score) {
    for (const side of [0, 1]) {
        if (score[side] >= rules.points_to_win && score[side] - score[1 - side] >= rules.win_by) return side;
    }

    return null;
}

/** A game: score, rally number, winner; serve() starts the current rally, goal() counts, next() moves on. */
export function createGame(seed, rules = DEFAULT_RULES) {
    const game = {
        seed, rules, score: [0, 0], rally: 1, winner: null,
        event: () => eventOf(rules, seed, game.rally),
        serve: (speeds) => createRally(rallySeed(seed, game.rally), game.event(), speeds),
        goal(scorer, points) {
            if (game.winner !== null) return true;
            game.score[scorer] += points;
            game.winner = winner(rules, game.score);

            return game.winner !== null;
        },
        next() {
            game.rally++;
        },
    };

    return game;
}

/** A whole game between two bot levels, as PongGame::bots(). */
export function playBots(seed, levels, rules = DEFAULT_RULES, maxRallies = 500) {
    const game = createGame(seed, rules);
    let ticks = 0;
    const events = [];
    const goals = [];

    while (game.winner === null && game.rally <= maxRallies) {
        const rally = game.serve([botSpeed(levels[0]), botSpeed(levels[1])]);
        const s = rallySeed(seed, game.rally);
        const bots = [createBot(levels[0], 0, s), createBot(levels[1], 1, s)];
        if (rally.event !== null) events.push([game.rally, rally.event]);

        let counted = 0;
        while (!rally.over && game.winner === null) {
            stepRally(rally, [bots[0].target(rally), bots[1].target(rally)]);
            for (; counted < rally.goals.length; counted++) {
                const [tick, scorer, ball] = rally.goals[counted];
                goals.push([game.rally, tick, scorer, ball]);
                if (game.goal(scorer, rally.points)) break;
            }
        }

        ticks += rally.tick;
        if (game.winner === null) game.next();
    }

    return { score: game.score, winner: game.winner, rallies: game.rally, ticks, events, goals };
}
