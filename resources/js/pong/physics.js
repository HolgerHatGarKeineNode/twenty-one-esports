/**
 * Proof of Pong's field, ball and rally (plan "Proof of Pong", P1): the browser's copy of the server's
 * App\Support\Pong\PongPhysics and PongRally, number for number. A fixed step of 1/60 s in whole units; every product
 * stays below 2^31 and every division is floorDiv(), so a double here and a 64-bit integer there agree exactly.
 * tests/js/pongPhysics.test.mjs plays the server's golden rallies (tests/fixtures/pong-golden.json) against it.
 *
 * A ball is [x, y, vx, vy, r] (a sixth element while it waits in the Arbeitsamt's queue). x runs from side 0's goal line (0) to side 1's (WIDTH), y from the top wall (0) to the
 * bottom wall (HEIGHT). The log of a rally has a line per serve, hit, goal and a rally that hit the tick cap:
 * ['serve', tick, ball, x, y, vx, vy], ['hit', tick, side, ball, y, vy], ['goal', tick, scorer, ball], ['void', tick].
 */
import { seeded } from './rng.js';

export const TICKS_PER_SECOND = 60;
export const WIDTH = 160000;
export const HEIGHT = 90000;
export const PADDLE_X = 4000;
export const PADDLE_HALF = 9000;
export const PADDLE_DEPTH = 1600;
export const BALL_RADIUS = 1200;
export const BASE_SPEED = 1000;
export const SPEEDUP = 60;
export const MAX_SPEED = 2600;
export const ANGLE_NUM = 4;
export const ANGLE_DEN = 5;
export const PLAYER_SPEED = 2400;
export const RALLY_TICK_CAP = 7200;
export const CENTRE = 80000;
export const TAX_HALF_X = 2000;
export const TAX_HALF_Y = 10000;
export const TAX_SPEED = 450;
export const WALL_HALF_X = 1200;
export const GAP_HALF = 15000;
export const GAP_SPEED = 300;
export const QUEUE_TICKS = 60;
export const POW_GROW = 1500;
export const POW_MAX_HALF = 18000;

export const HALVING = 'halving';
export const BRRR = 'brrr';
export const PIZZA = 'pizza';
export const DIFFICULTY = 'difficulty';
export const TAX = 'tax';
export const CONTROLS = 'controls';
export const FEW = 'few';
export const POW = 'pow';
export const ARBEITSAMT = 'arbeitsamt';

/** Floor division of two integers, as PongPhysics::floorDiv(). */
export const floorDiv = (a, b) => Math.floor(a / b);

/** The ball one tick on: the velocity added, mirrored off the top or bottom wall it crossed. */
export function move(ball) {
    let [x, y, vx, vy] = ball;
    const r = ball[4];
    x += vx;
    y += vy;

    if (y - r < 0) {
        y = 2 * r - y;
        vy = -vy;
    } else if (y + r > HEIGHT) {
        y = 2 * (HEIGHT - r) - y;
        vy = -vy;
    }

    return [x, y, vx, vy, r];
}

/** Half the width of an event's obstacle on the centre line, as PongPhysics::bandHalf(). */
export const bandHalf = (event) => (event === TAX ? TAX_HALF_X : WALL_HALF_X);

/** The centre y of the tax block or the wall's gap at `tick`, as PongPhysics::patrol(). */
export function patrol(event, seed, tick) {
    const [half, speed] = event === TAX ? [TAX_HALF_Y, TAX_SPEED] : [GAP_HALF, GAP_SPEED];
    const span = HEIGHT - 2 * half;
    const u = (tick * speed + (seed % (2 * span))) % (2 * span);

    return half + (u <= span ? u : 2 * span - u);
}

/** Whether the obstacle stops a ball at height `y` at `tick`, as PongPhysics::blocked(). */
export function blocked(event, seed, tick, y, r) {
    const centre = patrol(event, seed, tick);

    return event === TAX ? Math.abs(y - centre) <= TAX_HALF_Y + r : Math.abs(y - centre) > GAP_HALF - r;
}

/** A paddle's half length after its side's `hits` hits, as PongPhysics::halfOf(). */
export function halfOf(event, hits) {
    if (event === DIFFICULTY) return floorDiv(PADDLE_HALF * 2, 3);
    if (event === POW) return Math.min(PADDLE_HALF + hits * POW_GROW, POW_MAX_HALF);

    return PADDLE_HALF;
}

/**
 * The ball one tick on in a rally of `event` (seed `seed`), arriving at tick `tick`, as PongPhysics::step(): move()
 * plus the tax block, the border wall and the Arbeitsamt's queue (a sixth element counts the ticks left to wait).
 */
export function step(ball, tick, event, seed) {
    if (event === ARBEITSAMT) {
        if (ball.length > 5) {
            const left = ball[5] - 1;

            return left > 0 ? [ball[0], ball[1], ball[2], ball[3], ball[4], left] : [ball[0], ball[1], ball[2], ball[3], ball[4]];
        }

        const moved = move(ball);
        if ((ball[0] < CENTRE && moved[0] >= CENTRE) || (ball[0] > CENTRE && moved[0] <= CENTRE)) return [...moved, QUEUE_TICKS];

        return moved;
    }

    if (event !== TAX && event !== CONTROLS) return move(ball);

    const moved = move(ball);
    const [x, y, vx, vy, r] = moved;
    const face = vx > 0 ? CENTRE - bandHalf(event) : CENTRE + bandHalf(event);
    const enters = vx > 0 ? ball[0] + r < face && x + r >= face : ball[0] - r > face && x - r <= face;
    if (!enters || !blocked(event, seed, tick, y, r)) return moved;

    return [vx > 0 ? 2 * (face - r) - x : 2 * (face + r) - x, y, -vx, vy, r];
}

export const faceX = (side) => (side === 0 ? PADDLE_X : WIDTH - PADDLE_X);

/** Whether the ball runs towards `side` and has not yet passed its paddle's face. */
export function approaches(ball, side) {
    const [x, , vx, , r] = ball;

    return side === 0 ? vx < 0 && x - r > PADDLE_X : vx > 0 && x + r < WIDTH - PADDLE_X;
}

/** Whether the ball's front edge is at or past `side`'s paddle face. */
export const crossed = (ball, side) => (side === 0 ? ball[0] - ball[4] <= PADDLE_X : ball[0] + ball[4] >= WIDTH - PADDLE_X);

/**
 * [ticks, y] until the ball reaches `side`'s face untouched, or null when it does not run towards it or an event's
 * obstacle sends it back first; `tick` is the tick the ball stands at.
 */
export function predict(ball, side, event = null, seed = 0, tick = 0) {
    if (!approaches(ball, side)) return null;

    let b = ball;
    for (let ticks = 1; ticks <= RALLY_TICK_CAP; ticks++) {
        b = step(b, tick + ticks, event, seed);
        if (crossed(b, side)) return [ticks, b[1]];
        if (!approaches(b, side)) return null;
    }

    return null;
}

export const speedAfter = (hits, base, speedup, max) => Math.min(base + hits * speedup, max);

/** Whether a ball that crossed a face in this tick meets the paddle there, as PongPhysics::meets(). */
export const meets = (ball, paddle, half) => Math.abs(ball[1] - paddle) <= half + ball[4];

/** A ball that met side `side`'s paddle, sent back, as PongPhysics::bounce(). */
export function bounce(ball, side, paddle, half, speed) {
    const [x, y, , , r] = ball;
    const reach = half + r;
    const face = faceX(side);
    const vy = floorDiv((y - paddle) * speed * ANGLE_NUM, reach * ANGLE_DEN);

    return [side === 0 ? 2 * (face + r) - x : 2 * (face - r) - x, y, side === 0 ? speed : -speed, vy, r];
}

/**
 * A served rally, as `new PongRally(seed, event, speeds)`.
 *
 * @param {number} seed the rally's seed (rules.js rallySeed())
 * @param {string|null} event
 * @param {number[]} speeds each side's paddle speed
 */
export function createRally(seed, event, speeds) {
    const brrr = event === BRRR;
    const rally = {
        seed, event, speeds,
        tick: 0, balls: [], alive: [], hits: [], sideHits: [0, 0], paddles: [HEIGHT >> 1, HEIGHT >> 1], events: [], goals: [], over: false,
        base: brrr ? floorDiv(BASE_SPEED * 3, 2) : BASE_SPEED,
        speedup: brrr ? floorDiv(SPEEDUP * 3, 2) : SPEEDUP,
        max: brrr ? floorDiv(MAX_SPEED * 3, 2) : MAX_SPEED,
        half: halfOf(event, 0),
        points: event === HALVING ? 2 : 1,
    };
    const radius = event === HALVING ? floorDiv(BALL_RADIUS, 2) : BALL_RADIUS;
    const rng = seeded(seed);
    const towards = rng.below(2);

    (event === PIZZA ? [towards, 1 - towards] : [towards]).forEach((side, index) => {
        const y = floorDiv(HEIGHT, 2) + (rng.below(5) - 2) * 6000;
        const vy = floorDiv((rng.below(9) - 4) * rally.base, 8);
        const vx = side === 0 ? -rally.base : rally.base;
        rally.balls.push([floorDiv(WIDTH, 2), y, vx, vy, radius]);
        rally.alive.push(true);
        rally.hits.push(0);
        rally.events.push(['serve', 0, index, floorDiv(WIDTH, 2), y, vx, vy]);
    });

    return rally;
}

/** Side `side`'s paddle half length now, as PongRally::halfOf(). */
export const rallyHalf = (rally, side) => halfOf(rally.event, rally.sideHits[side]);

function advance(rally, index, ball) {
    const side = ball[2] < 0 ? 0 : 1;
    const before = approaches(ball, side);
    const moved = step(ball, rally.tick, rally.event, rally.seed);
    const [x, y] = moved;
    const half = rallyHalf(rally, side);

    if (before && crossed(moved, side) && meets(moved, rally.paddles[side], half)) {
        rally.hits[index]++;
        rally.sideHits[side]++;
        const hit = bounce(moved, side, rally.paddles[side], half, speedAfter(rally.hits[index], rally.base, rally.speedup, rally.max));
        rally.events.push(['hit', rally.tick, side, index, y, hit[3]]);

        return hit;
    }

    if (x <= 0 || x >= WIDTH) {
        const scorer = x <= 0 ? 1 : 0;
        rally.alive[index] = false;
        rally.goals.push([rally.tick, scorer, index]);
        rally.events.push(['goal', rally.tick, scorer, index]);
    }

    return moved;
}

/** One tick: paddles towards their targets (at most their speed), then the balls. */
export function stepRally(rally, targets) {
    if (rally.over) return;

    rally.tick++;

    for (const side of [0, 1]) {
        const speed = rally.speeds[side];
        const half = rallyHalf(rally, side);
        const delta = Math.max(-speed, Math.min(speed, targets[side] - rally.paddles[side]));
        rally.paddles[side] = Math.max(half, Math.min(HEIGHT - half, rally.paddles[side] + delta));
    }

    rally.balls.forEach((ball, index) => {
        if (rally.alive[index]) rally.balls[index] = advance(rally, index, ball);
    });

    if (!rally.alive.includes(true)) {
        rally.over = true;
    } else if (rally.tick >= RALLY_TICK_CAP) {
        rally.over = true;
        rally.events.push(['void', rally.tick]);
    }
}

/** The rally as PongRally::toArray() writes it. */
export const rallyToArray = (rally) => ({ tick: rally.tick, balls: rally.balls, alive: rally.alive, paddles: rally.paddles, events: rally.events, over: rally.over });
