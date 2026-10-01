/**
 * Scripted players that produced the Blockfill reference input logs in
 * tests/Fixtures/stacker. Not part of the test run (node --test skips this file name).
 *
 * Dry run, prints each run's result:   node tests/js/stacker/tools/build-fixtures.mjs
 * Rewrite the fixtures (on purpose only, see golden.test.mjs):
 *                                       node tests/js/stacker/tools/build-fixtures.mjs . write
 */
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = process.argv[2] && process.argv[2] !== '.' ? process.argv[2] : fileURLToPath(new URL('../../../..', import.meta.url));
const E = await import(`${root}/resources/js/stacker/engine.js`);
const P = await import(`${root}/resources/js/stacker/pieces.js`);
const { ACTION, WIDTH, HEIGHT, SPAWN_X, SPAWN_Y } = { ...E, ...P };

function evaluateBoard(board) {
    const heights = [];
    let holes = 0;
    for (let x = 0; x < WIDTH; x++) {
        let h = 0;
        let seen = false;
        for (let y = 0; y < HEIGHT; y++) {
            if (board[y * WIDTH + x]) {
                if (!seen) { h = HEIGHT - y; seen = true; }
            } else if (seen) {
                holes++;
            }
        }
        heights.push(h);
    }
    let agg = 0, bump = 0, max = 0;
    for (let x = 0; x < WIDTH; x++) {
        agg += heights[x];
        max = Math.max(max, heights[x]);
        if (x > 0) bump += Math.abs(heights[x] - heights[x - 1]);
    }
    return { agg, holes, bump, max };
}

function place(board, piece, rot, x) {
    const g = { board };
    const fitsAt = (y) => E.fits(g, piece, rot, x, y);
    // reachable: rotate at spawn, then slide along the spawn row
    const y0 = SPAWN_Y[piece];
    if (!E.fits(g, piece, rot, SPAWN_X[piece], y0)) return null;
    const dir = x < SPAWN_X[piece] ? -1 : 1;
    for (let cx = SPAWN_X[piece]; cx !== x; cx += dir) {
        if (!E.fits(g, piece, rot, cx + dir, y0)) return null;
    }
    let y = y0;
    while (fitsAt(y + 1)) y++;
    const b = board.slice();
    for (const [dx, dy] of P.SHAPES[piece][rot]) b[(y + dy) * WIDTH + x + dx] = piece + 1;
    let lines = 0;
    const out = new Uint8Array(b.length);
    let w = HEIGHT - 1;
    for (let r = HEIGHT - 1; r >= 0; r--) {
        let full = true;
        for (let c = 0; c < WIDTH; c++) if (!b[r * WIDTH + c]) full = false;
        if (full) { lines++; continue; }
        out.set(b.subarray(r * WIDTH, r * WIDTH + WIDTH), w * WIDTH);
        w--;
    }
    return { board: out, lines };
}

function bestPlacement(board, piece) {
    let best = null;
    for (let rot = 0; rot < (piece === 1 ? 1 : 4); rot++) {
        for (let x = -3; x < WIDTH; x++) {
            const r = place(board, piece, rot, x);
            if (!r) continue;
            const s = evaluateBoard(r.board);
            const score = -510 * s.agg + 760 * r.lines - 3570 * s.holes - 184 * s.bump;
            if (!best || score > best.score) best = { rot, x, score };
        }
    }
    return best;
}

/** Closed-loop player: rotate, slide (taps, or DAS for long slides), hard drop; uses hold when it pays. */
function smartRun(seed, settings, maxTicks) {
    const game = E.createGame({ seed, settings });
    const log = [];
    let plan = null;
    let planFor = -1;
    let tapDown = null;
    let holding = null;
    let lastX = null;
    let stuck = 0;
    let holdWait = 0;
    while (!E.isOver(game) && game.tick < maxTicks) {
        const t = game.tick;
        const inputs = [];
        const key = game.pieces * 2 + (game.holdUsed ? 1 : 0);
        if (tapDown !== null) {
            inputs.push([tapDown, 0]);
            tapDown = null;
        } else {
            if (planFor !== key) {
                const c = game.current;
                const here = bestPlacement(game.board, c.piece);
                const alt = game.holdUsed ? null : bestPlacement(game.board, game.hold < 0 ? game.queue[0] : game.hold);
                plan = here;
                plan.hold = false;
                if (alt && (!here || alt.score > here.score + 2000)) {
                    plan = { ...alt, hold: true };
                }
                planFor = key;
                stuck = 0;
                lastX = null;
            }
            const c = game.current;
            if (plan.hold) {
                inputs.push([ACTION.HOLD, 1]);
                tapDown = ACTION.HOLD;
                plan.hold = false;
            } else if (c.rot !== plan.rot && stuck < 3) {
                const diff = (plan.rot - c.rot) & 3;
                const a = diff === 1 ? ACTION.CW : diff === 3 ? ACTION.CCW : ACTION.FLIP;
                inputs.push([a, 1]);
                tapDown = a;
                stuck++;
            } else if (c.x !== plan.x && stuck < 6) {
                const a = c.x > plan.x ? ACTION.LEFT : ACTION.RIGHT;
                const far = Math.abs(c.x - plan.x) >= 3;
                if (far) {
                    if (holding !== a) {
                        if (holding !== null) inputs.push([holding, 0]);
                        inputs.push([a, 1]);
                        holding = a;
                    }
                } else {
                    if (holding !== null) { inputs.push([holding, 0]); holding = null; }
                    inputs.push([a, 1]);
                    tapDown = a;
                }
                if (lastX === c.x) stuck += holding === null ? 1 : 0;
                if (holding !== null && lastX === c.x) holdWait++; else holdWait = 0;
                if (holdWait > 30) stuck = 6;
                lastX = c.x;
            } else {
                if (holding !== null) { inputs.push([holding, 0]); holding = null; }
                inputs.push([ACTION.HARD, 1]);
                tapDown = ACTION.HARD;
            }
        }
        for (const [a, d] of inputs) log.push([t, a, d]);
        E.step(game, inputs);
    }
    return { log, result: E.result(game) };
}

/** Wall stacker without hard drop: soft drop held, DAS to alternating walls, a turn now and then. */
function wallRun(seed, settings, maxTicks) {
    const game = E.createGame({ seed, settings });
    const log = [];
    let seen = -1;
    let phase = 0;
    while (!E.isOver(game) && game.tick < maxTicks) {
        const t = game.tick;
        const inputs = [];
        if (seen !== game.pieces) {
            seen = game.pieces;
            phase = 0;
        }
        const side = game.pieces % 3 === 2 ? null : game.pieces % 2 === 0 ? ACTION.LEFT : ACTION.RIGHT;
        if (phase === 0) {
            for (const k of [ACTION.LEFT, ACTION.RIGHT, ACTION.SOFT]) if (game.held[k]) inputs.push([k, 0]);
            phase = 1;
        } else if (phase === 1) {
            if (game.pieces % 4 === 1) inputs.push([ACTION.CW, 1]);
            phase = 2;
        } else if (phase === 2) {
            if (game.held[ACTION.CW]) inputs.push([ACTION.CW, 0]);
            if (side !== null) inputs.push([side, 1]);
            phase = 3;
        } else if (phase === 3 && t % 5 === 0) {
            inputs.push([ACTION.SOFT, 1]);
            phase = 4;
        }
        for (const [a, d] of inputs) log.push([t, a, d]);
        E.step(game, inputs);
    }
    return { log, result: E.result(game) };
}

/** Hard drops only, at an uneven rhythm. */
function hardRun(seed, settings, maxTicks) {
    const log = [];
    let t = 3;
    for (let i = 0; i < 60; i++) {
        log.push([t, ACTION.HARD, 1], [t + 1, ACTION.HARD, 0]);
        t += 4 + (i % 5) * 3;
    }
    return { log, result: E.run(seed, settings, log, { maxTicks }) };
}

const out = `${root}/tests/Fixtures/stacker`;
const cases = [
    ['forty-lines', '5eed0b10c0ff11ce4a7f3b2d9e8c1a60', { das: 8, arr: 1, sdf: 20 }, smartRun],
    ['top-out', 'b10cf111deadbeef0123456789abcdef', { das: 10, arr: 2, sdf: 10 }, wallRun],
    ['hard-drops', '0000000000000000000000000000002a', { das: 10, arr: 2, sdf: 20 }, hardRun],
];
for (const [name, seed, settings, play] of cases) {
    const { log, result } = play(seed, settings, E.MAX_TICKS);
    const replayed = E.run(seed, settings, log);
    if (JSON.stringify(replayed) !== JSON.stringify(result)) throw new Error(`${name}: replay differs`);
    console.log(name, log.length, 'inputs', JSON.stringify(result));
    const body = {
        name,
        engine: E.ENGINE_VERSION,
        seed,
        settings,
        expected: { ticks: result.ticks, lines: result.lines, pieces: result.pieces, finished: result.finished, toppedOut: result.toppedOut, stateHash: result.stateHash },
        inputs: log,
    };
    const json = JSON.stringify(body, null, 4).replace(/\[\n\s+(\d+),\n\s+(\d+),\n\s+(\d+)\n\s+\]/g, '[$1, $2, $3]');
    if (process.argv[3] === 'write') writeFileSync(`${out}/${name}.json`, json + '\n');
}
