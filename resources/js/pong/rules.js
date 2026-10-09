/**
 * Proof of Pong's rules and a game as a row of rallies (plan "Proof of Pong", P1), the server's
 * App\Support\Pong\PongRules and PongGame: a game to `points_to_win`, `win_by` ahead, and every
 * `event_every_rallies`-th rally a meme event (Halving, Brrr, Pizza Day, Difficulty Adjustment, and since P7 Steuern
 * sind Raub, Kapitalverkehrskontrolle, Few understand, Proof of Work, Arbeitsamt) in an order drawn
 * from the game's seed, the same for both sides.
 */
import { seeded } from './rng.js';
import { ARBEITSAMT, BRRR, CONTROLS, DIFFICULTY, FEW, HALVING, PIZZA, POW, TAX, createRally, stepRally } from './physics.js';
import { botSpeed, createBot } from './bot.js';

export const EVENTS = [HALVING, BRRR, PIZZA, DIFFICULTY, TAX, CONTROLS, FEW, POW, ARBEITSAMT];
export const DEFAULT_RULES = { points_to_win: 21, win_by: 2, event_every_rallies: 21 };

const EVENT_SALT = 0x504f4e47;

/** The seed of rally `rally` (from 1), as PongRules::rallySeed(). */
export const rallySeed = (seed, rally) => ((seed ^ ((rally * 0x9e3779b9) % 4294967296)) >>> 0);

/** The event of rally `rally`, or null. */
export function eventOf(rules, seed, rally) {
    if (rally < 1 || rally % rules.event_every_rallies !== 0) return null;

    const index = Math.floor(rally / rules.event_every_rallies) - 1;
    const rng = seeded(((seed ^ EVENT_SALT) >>> 0));
    let order = [];
    for (let round = 0; round <= Math.floor(index / EVENTS.length); round++) order = rng.shuffle(EVENTS);

    return order[index % EVENTS.length];
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
