// Plays one Blockfill run on the app's own engine (resources/js/stacker/engine.js, bf1)
// from a fixed seed and writes lib/blockfill.run.js: the seed, the settings, the input
// log, and the result. Nothing is drawn or typed by hand: the posters and the reel
// replay this input log through the same engine, tick by tick.
//
// The player is a small placement bot (one piece of lookahead; it stacks flat over nine
// columns, keeps the right one open and mines four rows at a time, as fast players do),
// pressing keys at a human pace: a turn, then one
// column per 2 ticks, then a hard drop, at least MIN_TICKS ticks per piece (about 3.3
// pieces per second). The run is then handed to the league's verifier
// (resources/js/stacker/verify.mjs) with the limits of config/esports.php; if the
// verifier does not answer ok, nothing is written.
//
// Usage: node docs/promo/src/gen-blockfill-run.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  ACTION, ENGINE_VERSION, GOAL_LINES, HEIGHT, WIDTH, DEFAULT_SETTINGS,
  createGame, fits, isOver, result, step,
} from '../../../resources/js/stacker/engine.js';
import { SHAPES } from '../../../resources/js/stacker/pieces.js';
import { encodeReplay, REPLAY_VERSION } from '../../../resources/js/stacker/replay.js';
import { verify } from '../../../resources/js/stacker/verify.mjs';

const SRC = path.dirname(fileURLToPath(import.meta.url));
const SEED = process.env.BF_SEED || 'b10cf111000000000000000021e5b007';
const MIN_TICKS = 18;
// config/esports.php blockfill.limits
const LIMITS = { ticks: 36000, inputs: 20000, bytes: 65536, inputsPerTick: 1.0, inputSlack: 64 };

/* Board after dropping `piece` in `rot` at column x: lines cleared and the stack's shape. */
function evaluate(board, piece, rot, x) {
  let y = 0;
  const fitsAt = (yy) => SHAPES[piece][rot].every(([dx, dy]) => {
    const cx = x + dx, cy = yy + dy;
    return cx >= 0 && cx < WIDTH && cy >= 0 && cy < HEIGHT && board[cy * WIDTH + cx] === 0;
  });
  if (!fitsAt(2)) return null;
  y = 2;
  while (fitsAt(y + 1)) y++;
  const b = board.slice();
  for (const [dx, dy] of SHAPES[piece][rot]) b[(y + dy) * WIDTH + x + dx] = 9;
  let lines = 0;
  const rows = [];
  for (let r = 0; r < HEIGHT; r++) {
    let full = true;
    for (let c = 0; c < WIDTH; c++) if (!b[r * WIDTH + c]) { full = false; break; }
    if (full) lines++; else rows.push(b.slice(r * WIDTH, r * WIDTH + WIDTH));
  }
  while (rows.length < HEIGHT) rows.unshift(new Uint8Array(WIDTH));
  const heights = [];
  let holes = 0;
  for (let c = 0; c < WIDTH; c++) {
    let h = 0;
    for (let r = 0; r < HEIGHT; r++) if (rows[r][c]) { h = HEIGHT - r; break; }
    heights.push(h);
    for (let r = HEIGHT - h + 1; r < HEIGHT; r++) if (!rows[r][c]) holes++;
  }
  // How fast players stack for 40 rows: flat over nine columns, the right one kept open
  // for a straight piece that mines four blocks at once. Small clears are avoided.
  let bump = 0;
  for (let c = 0; c < WIDTH - 2; c++) bump += Math.abs(heights[c] - heights[c + 1]);
  const max = Math.max(...heights.slice(0, WIDTH - 1));
  const usedWell = SHAPES[piece][rot].some(([dx]) => x + dx === WIDTH - 1);
  let s = -4 * holes - 0.35 * bump - 0.02 * heights.reduce((a, h) => a + h, 0);
  if (lines >= 4) s += 12;
  else if (lines > 0) s -= max > 12 ? 0 : 3 * lines;
  if (usedWell && lines < 4) s -= max > 13 ? 2 : 12;
  if (max > 12) s -= 3 * (max - 12);
  const next = new Uint8Array(WIDTH * HEIGHT);
  rows.forEach((r, i) => next.set(r, i * WIDTH));
  return { s, board: next };
}

/* Best landing spot for the current piece, looking one piece ahead (the next queue). */
function plan(game) {
  const c = game.current, ahead = game.queue[0];
  const spots = (board, piece) => {
    const out = [];
    for (let rot = 0; rot < 4; rot++) for (let x = -3; x < WIDTH; x++) { const e = evaluate(board, piece, rot, x); if (e) out.push({ ...e, rot, x }); }
    return out;
  };
  let best = null;
  for (const a of spots(game.board, c.piece)) {
    const follow = spots(a.board, ahead).reduce((m, b) => Math.max(m, b.s), -1e9);
    const s = a.s + follow;
    if (!best || s > best.s) best = { s, rot: a.rot, x: a.x };
  }
  return best;
}

const game = createGame({ seed: SEED, settings: DEFAULT_SETTINGS });
const log = [];
const schedule = new Map();
const at = (tick, action, down) => { if (!schedule.has(tick)) schedule.set(tick, []); schedule.get(tick).push([action, down]); };
let plannedFor = -1;
const clears = [];
while (!isOver(game) && game.tick < LIMITS.ticks) {
  if (game.current && game.pieces !== plannedFor) {
    plannedFor = game.pieces;
    const p = plan(game);
    if (!p) throw new Error('bot found no placement at tick ' + game.tick);
    let t = game.tick + 3;
    const turn = { 0: null, 1: ACTION.CW, 2: ACTION.FLIP, 3: ACTION.CCW }[p.rot];
    if (turn !== null) { at(t, turn, 1); at(t + 1, turn, 0); t += 2; }
    // the box column after the turn (no kick in open space): spawn x; shift to p.x
    const dx = p.x - game.current.x;
    const key = dx < 0 ? ACTION.LEFT : ACTION.RIGHT;
    for (let k = 0; k < Math.abs(dx); k++) { at(t, key, 1); at(t + 1, key, 0); t += 2; }
    t = Math.max(t + 1, game.tick + MIN_TICKS);
    at(t, ACTION.HARD, 1); at(t + 1, ACTION.HARD, 0);
  }
  const inputs = schedule.get(game.tick) || [];
  for (const [a, d] of inputs) log.push([game.tick, a, d]);
  const before = game.lines;
  step(game, inputs);
  if (game.lines > before) clears.push([game.tick - 1, game.lines - before]);
}
// a release scheduled after the last tick was never played: the verifier calls that trailing
const res = result(game);
const inputs = log.filter(([tick]) => tick < res.ticks);
if (!res.finished || res.lines < GOAL_LINES) { console.error('run did not finish', res); process.exit(1); }

const header = { v: REPLAY_VERSION, engine: ENGINE_VERSION, seed: SEED, settings: { ...DEFAULT_SETTINGS } };
const replay = encodeReplay(header, inputs);
const verdict = await verify({ replay, seed: SEED, engine: ENGINE_VERSION, claimed: { ticks: res.ticks, hash: res.stateHash }, limits: LIMITS });
if (!verdict.ok) { console.error('verifier refused the run:', verdict); process.exit(1); }

const data = {
  seed: SEED, engine: ENGINE_VERSION, settings: header.settings, inputs,
  ticks: res.ticks, lines: res.lines, pieces: res.pieces, hash: res.stateHash, replay,
  clears, verdict: { ok: verdict.ok, ticks: verdict.ticks, lines: verdict.lines, pieces: verdict.pieces, hash: verdict.hash },
};
fs.writeFileSync(path.join(SRC, 'lib/blockfill.run.js'),
  '/* Generated by gen-blockfill-run.mjs: one Blockfill run on engine bf1, accepted by\n'
  + ' * resources/js/stacker/verify.mjs. Do not edit. */\n'
  + `window.BF_RUN = ${JSON.stringify(data)};\n`);
const ms = Math.floor((res.ticks * 1000) / 60);
console.log(`blockfill run ok: ${res.ticks} ticks (${Math.floor(ms / 60000)}:${String(Math.floor(ms / 1000) % 60).padStart(2, '0')}.${String(ms % 1000).padStart(3, '0')}), ${res.lines} lines, ${res.pieces} pieces, ${(res.pieces * 60 / res.ticks).toFixed(2)} pps, ${inputs.length} inputs, hash ${res.stateHash}, verifier ok`);
