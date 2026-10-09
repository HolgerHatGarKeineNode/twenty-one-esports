/**
 * Proof of Pong's bot (plan "Proof of Pong", P1), the server's App\Support\Pong\PongBot draw for draw: four levels
 * that differ in reaction (ticks before it moves for a ball coming at it), paddle speed and aim error (drawn once per
 * approaching ball; beyond the paddle's reach it misses). It aims at the ball's exact path plus that error and
 * returns to the middle while no ball comes.
 */
import { seeded } from './rng.js';
import { HEIGHT, PADDLE_HALF, POW, approaches, floorDiv, halfOf, predict } from './physics.js';

export const LEVELS = {
    1: { name: 'Nocoiner Uncle', reaction: 20, speed: 520, error: 20000 },
    2: { name: 'Shitcoiner', reaction: 14, speed: 780, error: 15500 },
    3: { name: 'Goldbug', reaction: 9, speed: 1050, error: 12500 },
    4: { name: 'Madame Brrr Lagarde', reaction: 5, speed: 1400, error: 11000 },
};

const SALT = 0xb07b07;

export function botSpeed(level) {
    if (!LEVELS[level]) throw new RangeError(`No bot level [${level}].`);

    return LEVELS[level].speed;
}

/**
 * A bot for one rally and side; target(rally) says where it wants its paddle before the rally steps.
 */
export function createBot(level, side, rallySeed) {
    if (!LEVELS[level]) throw new RangeError(`No bot level [${level}].`);

    const config = LEVELS[level];
    const rng = seeded(((rallySeed ^ (SALT + side)) >>> 0));
    const paths = new Map();
    let key = null;
    let aim = 0;
    let ready = 0;

    return {
        level,
        side,
        target(rally) {
            let next = null;

            rally.balls.forEach((ball, index) => {
                if (!rally.alive[index] || !approaches(ball, side)) return;

                const k = index * 1000 + rally.hits[index];
                if (!paths.has(k)) {
                    const path = predict(ball, side, rally.event ?? null, rally.seed ?? 0, rally.tick);
                    paths.set(k, path === null ? null : [rally.tick + path[0], path[1]]);
                }

                const path = paths.get(k);
                if (path !== null && (next === null || path[0] < next[1])) next = [k, path[0], path[1]];
            });

            if (next === null) {
                key = null;

                return HEIGHT >> 1;
            }

            if (next[0] !== key) {
                key = next[0];
                // Proof of Work: the aim's error grows with the bot's paddle, as PongBot.
                const error = rally.event === POW ? floorDiv(config.error * halfOf(POW, rally.sideHits[side]), PADDLE_HALF) : config.error;
                aim = next[2] + rng.below(2 * error + 1) - error;
                ready = rally.tick + config.reaction;
            }

            return rally.tick < ready ? rally.paddles[side] : aim;
        },
    };
}
