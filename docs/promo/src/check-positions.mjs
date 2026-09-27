// Replays every game in src/lib/kit.js through chess.js (the app's own dependency)
// and fails when a move is illegal or the stored FEN does not match.
// Usage: node docs/promo/src/check-positions.mjs   (render scripts call it first)
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath, pathToFileURL } from 'node:url';

const SRC = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(SRC, '../../..');
const { Chess } = await import(pathToFileURL(path.join(REPO, 'node_modules/chess.js/dist/esm/chess.js')).href);

export function loadKit() {
  const ctx = { window: {}, location: { search: '' }, document: {}, URLSearchParams };
  vm.runInNewContext(fs.readFileSync(path.join(SRC, 'lib/kit.js'), 'utf8'), ctx);
  return ctx.window.Kit;
}

export function checkPositions() {
  const { GAMES } = loadKit();
  const bad = [];
  for (const [id, g] of Object.entries(GAMES)) {
    const c = new Chess();
    try {
      for (const m of g.san.split(' ')) c.move(m);
    } catch (e) { bad.push(`${id}: illegal move (${e.message})`); continue; }
    if (c.fen() !== g.fen) bad.push(`${id}: fen mismatch, stored ${g.fen}, replayed ${c.fen()}`);
  }
  for (const [key, snap] of Object.entries(loadKit().SNAPS || {})) {
    const [id, ply] = key.split('@');
    const t = timeline(id)[+ply];
    if (!t) { bad.push(`${key}: ply out of range`); continue; }
    if (t.fen !== snap.fen) bad.push(`${key}: fen mismatch, stored ${snap.fen}, replayed ${t.fen}`);
    if (t.last.join('-') !== snap.last.join('-')) bad.push(`${key}: last move ${snap.last.join('-')} should be ${t.last.join('-')}`);
  }
  return bad;
}

/** FEN after `ply` half-moves plus the last move squares, for reels. */
export function timeline(id) {
  const { GAMES } = loadKit();
  const c = new Chess();
  const out = [{ fen: c.fen(), last: [] }];
  for (const m of GAMES[id].san.split(' ')) { const mv = c.move(m); out.push({ fen: c.fen(), last: [mv.from, mv.to], check: c.inCheck() ? kingSquare(c) : null, mate: c.isCheckmate() }); }
  return out;
}
function kingSquare(c) {
  for (const row of c.board()) for (const sq of row) if (sq && sq.type === 'k' && sq.color === c.turn()) return sq.square;
  return null;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const bad = checkPositions();
  if (bad.length) { console.error('POSITIONS FAIL\n' + bad.join('\n')); process.exit(1); }
  console.log('positions ok:', Object.keys(loadKit().GAMES).join(', '));
}
