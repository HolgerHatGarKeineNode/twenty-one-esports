/* Blockfill for the posters and the reel: the app's own engine and renderer
 * (resources/js/stacker/engine.js, renderer.js, ticker.js), replaying the run in
 * lib/blockfill.run.js (gen-blockfill-run.mjs, accepted by the league's verifier).
 * Nothing here draws a cell by itself: every frame is drawWell()/drawPreview() on
 * the engine's state at that tick.
 *
 * ES module; exposes window.BF and repaints the hero once it has loaded
 * (classic scripts render first, a module runs after them). */
import { GOAL_LINES, createGame, nextPieces, step } from '../../../../resources/js/stacker/engine.js';
import { SHOWN_ROWS, drawPreview, drawWell } from '../../../../resources/js/stacker/renderer.js';
import { formatTicks } from '../../../../resources/js/stacker/ticker.js';

const RUN = window.BF_RUN;
if (!RUN) throw new Error('lib/blockfill.run.js not loaded');

/* inputs grouped per tick, as engine.run() feeds them */
const byTick = new Map();
for (const [tick, action, down] of RUN.inputs) {
  if (!byTick.has(tick)) byTick.set(tick, []);
  byTick.get(tick).push([action, down]);
}

let game = null;
/** The game after `tick` ticks of the run (cached; replays from 0 when going back). */
function at(tick) {
  const t = Math.max(0, Math.min(RUN.ticks, Math.round(tick)));
  if (!game || game.tick > t) game = createGame({ seed: RUN.seed, settings: RUN.settings });
  while (game.tick < t) step(game, byTick.get(game.tick) || []);
  return game;
}

/** The app's flash (page.js FLASH_MS 420 ms = 25.2 ticks) of the last clear before `tick`. */
const FLASH_TICKS = 25.2;
function flashAt(tick) {
  let last = null;
  for (const c of RUN.clears) if (c[0] < tick) last = c;
  if (!last) return { mined: 0, flash: 0 };
  // a clear at tick c happens inside step(c): it shows from tick c + 1 on
  const since = tick - (last[0] + 1);
  return since >= 0 && since < FLASH_TICKS ? { mined: last[1], flash: 1 - since / FLASH_TICKS } : { mined: 0, flash: 0 };
}

/** Size a canvas to CSS w x h at the device pixel ratio and return its 2D context in CSS pixels. */
function ctx2d(canvas, w, h) {
  const r = window.devicePixelRatio || 1;
  canvas.width = Math.round(w * r); canvas.height = Math.round(h * r);
  canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
  const g = canvas.getContext('2d');
  g.setTransform(r, 0, 0, r, 0, 0);
  return g;
}

/** Draws the well at `tick` into `canvas`, `cell` CSS px per cell; `flash` overrides the app's flash. */
function paintWell(canvas, tick, cell, o = {}) {
  const g = at(tick);
  const f = o.flash ?? flashAt(tick);
  drawWell(ctx2d(canvas, cell * 10, cell * SHOWN_ROWS), g, { cell, mined: f.mined, flash: f.flash });
  return g;
}
/** Draws piece `piece` (-1 none) centred in a canvas of w x h CSS px, the app's preview cell. */
function paintPreview(canvas, piece, w, h) {
  const cell = Math.max(6, Math.floor(Math.min(w / 4.6, h / 2.6)));
  drawPreview(ctx2d(canvas, w, h), piece, { width: w, height: h, cell });
}

window.BF = { RUN, at, flashAt, paintWell, paintPreview, drawWell, nextPieces, formatTicks, GOAL_LINES, SHOWN_ROWS };
window.dispatchEvent(new Event('bf-ready'));
if (window.Heroes && window.Heroes.paint) window.Heroes.paint();
