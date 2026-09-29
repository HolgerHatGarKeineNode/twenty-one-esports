/* Mühle and Dame boards for posters and reels, drawn as the site draws them
 * (resources/js/boardGame.js render(): #3F3F46 cells, #52525B lines, #71717A
 * points, light and dark men, an orange ring for a king, an orange halo on
 * the last move). The positions come only from lib/boardgames.data.js, which
 * gen-boardgames.php replays through the app's own rules. Exposes window.BG. */
(function () {
  'use strict';
  const D = window.BG_DATA;
  if (!D) throw new Error('boardgames: lib/boardgames.data.js not loaded');

  /* app/Support/Board/NineMensMorrisRules.php POINTS, LINES and at() */
  const MORRIS_POINTS = ['a1', 'a4', 'a7', 'b2', 'b4', 'b6', 'c3', 'c4', 'c5', 'd1', 'd2', 'd3', 'd5', 'd6', 'd7', 'e3', 'e4', 'e5', 'f2', 'f4', 'f6', 'g1', 'g4', 'g7'];
  const MORRIS_LINES = [
    ['a7', 'd7', 'g7'], ['b6', 'd6', 'f6'], ['c5', 'd5', 'e5'], ['a4', 'b4', 'c4'], ['e4', 'f4', 'g4'], ['c3', 'd3', 'e3'], ['b2', 'd2', 'f2'], ['a1', 'd1', 'g1'],
    ['a1', 'a4', 'a7'], ['b2', 'b4', 'b6'], ['c3', 'c4', 'c5'], ['d1', 'd2', 'd3'], ['d5', 'd6', 'd7'], ['e3', 'e4', 'e5'], ['f2', 'f4', 'f6'], ['g1', 'g4', 'g7'],
  ];
  /* CheckersRules.php: the 32 dark squares, rank 1 first, each rank from file a */
  const CHECKERS_SQUARES = [];
  for (let r = 1; r <= 8; r++) for (let f = 0; f < 8; f++) if ((f + r) % 2 === 1) CHECKERS_SQUARES.push('abcdefgh'[f] + r);

  const GEO = {
    morris: {
      size: 700,
      at: (p) => [50 + 100 * (p.charCodeAt(0) - 97), 650 - 100 * (+p[1] - 1)],
      points: MORRIS_POINTS,
      lines: MORRIS_LINES.map((l) => [l[0], l[2]]),
      cells: [],
    },
    checkers: {
      size: 800,
      at: (p) => [(p.charCodeAt(0) - 97) * 100 + 50, (8 - +p[1]) * 100 + 50],
      points: CHECKERS_SQUARES,
      lines: [],
      cells: CHECKERS_SQUARES.map((p) => [(p.charCodeAt(0) - 97) * 100, (8 - +p[1]) * 100]),
    },
  };
  /* boardGame.js pieceRadius(): a little over a third of the closest distance */
  const RADIUS = { morris: 36, checkers: 51 };

  /** Pieces of a serialized position: { point: 'w'|'b'|'W'|'B' }. */
  function pieces(game, pos) {
    const chars = pos.split(' ')[0], out = {};
    GEO[game].points.forEach((p, i) => { if (chars[i] !== '.') out[p] = chars[i]; });
    return out;
  }
  function info(game, pos) {
    const f = pos.split(' ');
    return game === 'morris' ? { turn: f[1], hand: { w: +f[2], b: +f[3] } } : { turn: f[1] };
  }
  const ply = (game, n) => {
    const p = D[game].plies[n];
    if (!p) throw new Error(`boardgames: ${game} has no ply ${n}`);
    return p;
  };

  /** Mills on the board that contain `point` (morris). */
  function millsAt(pcs, point) {
    return MORRIS_LINES.filter((l) => l.includes(point) && l.every((q) => pcs[q] && pcs[q].toLowerCase() === pcs[point].toLowerCase()));
  }

  /**
   * SVG of a board. o: pieces (override map), last [points] (orange halo),
   * mill [[a,b,c]] (orange line), taken [points] (ghost + cross), path [points]
   * (clicked rings + connecting line), float {from:[x,y] in board units, side, king} (a piece in flight).
   */
  function svg(game, pos, o = {}) {
    const g = GEO[game], r = RADIUS[game], S = g.size;
    const pcs = o.pieces || pieces(game, pos);
    const last = new Set(o.last || []);
    let s = `<svg class="bgsvg" viewBox="0 0 ${S} ${S}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="${S}" height="${S}" fill="#1A1A1E"/>`;
    for (const [x, y] of g.cells) s += `<rect x="${x}" y="${y}" width="100" height="100" fill="#3F3F46"/>`;
    for (const [a, b] of g.lines) { const [x1, y1] = g.at(a), [x2, y2] = g.at(b); s += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#52525B" stroke-width="${Math.max(2, r * 0.12)}" stroke-linecap="round"/>`; }
    for (const m of o.mill || []) { const [x1, y1] = g.at(m[0]), [x2, y2] = g.at(m[2]); s += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#F7931A" stroke-width="${r * 0.42}" stroke-linecap="round" opacity="${o.millAlpha ?? 1}"/>`; }
    if (o.path && o.path.length > 1) s += `<polyline points="${o.path.map((p) => g.at(p).join(',')).join(' ')}" fill="none" stroke="#F7931A" stroke-width="${r * 0.2}" stroke-dasharray="${r * 0.5} ${r * 0.35}" stroke-linecap="round" opacity=".85"/>`;
    for (const p of g.points) {
      const [x, y] = g.at(p), pc = pcs[p];
      if (last.has(p)) s += `<circle cx="${x}" cy="${y}" r="${r * 1.12}" fill="rgba(247,147,26,0.18)"/>`;
      if (game === 'morris') s += `<circle cx="${x}" cy="${y}" r="${Math.max(3, r * 0.14)}" fill="#71717A"/>`;
      if (pc) s += man(x, y, r, pc);
      if ((o.taken || []).includes(p) && !pc) s += `<g opacity="${o.takenAlpha ?? 1}"><circle cx="${x}" cy="${y}" r="${r * 0.92}" fill="none" stroke="#F87171" stroke-width="${r * 0.1}" stroke-dasharray="${r * 0.3} ${r * 0.2}"/><path d="M${x - r * 0.4} ${y - r * 0.4}L${x + r * 0.4} ${y + r * 0.4}M${x + r * 0.4} ${y - r * 0.4}L${x - r * 0.4} ${y + r * 0.4}" stroke="#F87171" stroke-width="${r * 0.14}" stroke-linecap="round"/></g>`;
      if ((o.path || []).includes(p)) s += `<circle cx="${x}" cy="${y}" r="${r * 1.02}" fill="none" stroke="#F7931A" stroke-width="${Math.max(3, r * 0.12)}"/>`;
    }
    if (o.float) s += man(o.float.x, o.float.y, r * (o.float.scale || 1), o.float.piece, true);
    return s + '</svg>';
  }
  function man(x, y, r, pc, lifted) {
    const side = pc.toLowerCase(), king = pc !== side;
    const fill = side === 'w' ? '#F4F4F5' : '#09090B', stroke = side === 'w' ? '#A1A1AA' : '#D4D4D8';
    let s = lifted ? `<circle cx="${x + r * 0.12}" cy="${y + r * 0.18}" r="${r * 0.92}" fill="rgba(0,0,0,.45)"/>` : '';
    s += `<circle cx="${x}" cy="${y}" r="${r * 0.92}" fill="${fill}" stroke="${stroke}" stroke-width="${Math.max(2, r * 0.08)}"/>`;
    if (king) s += `<circle cx="${x}" cy="${y}" r="${r * 0.45}" fill="none" stroke="#F7931A" stroke-width="${Math.max(2, r * 0.1)}"/>`;
    return s;
  }

  /** Points whose man the move `n` took off the board (the other side's pieces that are gone). */
  function taken(game, n) {
    const before = pieces(game, ply(game, n - 1).pos), after = pieces(game, ply(game, n).pos);
    const side = info(game, ply(game, n - 1).pos).turn;
    return Object.keys(before).filter((p) => before[p].toLowerCase() !== side && !after[p]);
  }
  /** Move label "12. a5xc7xe5xg3" / "9… c5xg4" for ply n (1-based). */
  const label = (n, move) => (n % 2 ? `${(n + 1) / 2}.` : `${n / 2}…`) + ' ' + move;

  window.BG = { DATA: D, GEO, RADIUS, pieces, info, ply, millsAt, svg, taken, label };
})();
