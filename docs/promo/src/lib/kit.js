/* TWENTY ONE esports promo kit, shared JS for posters and reels.
 * No build step, no dependencies: plain script, exposes window.Kit. */
(function () {
  'use strict';

  /* ---------- games: real, legal move lists ----------
   * `fen` is the position after the full list; check-positions.mjs replays
   * every `san` list through chess.js and fails the render on any mismatch. */
  const GAMES = {
    // Paul Morphy vs Duke Karl of Brunswick and Count Isouard, Paris Opera 1858.
    opera: {
      san: 'e4 e5 Nf3 d6 d4 Bg4 dxe5 Bxf3 Qxf3 dxe5 Bc4 Nf6 Qb3 Qe7 Nc3 c6 Bg5 b5 Nxb5 cxb5 Bxb5+ Nbd7 O-O-O Rd8 Rxd7 Rxd7 Rd1 Qe6 Bxd7+ Nxd7 Qb8+ Nxb8 Rd8#',
      fen: '1n1Rkb1r/p4ppp/4q3/4p1B1/4P3/8/PPP2PPP/2K5 b k - 1 17',
      name: { de: 'Morphy gegen Braunschweig und Isouard, Paris 1858', en: 'Morphy vs Brunswick and Isouard, Paris 1858' },
    },
    // Légal de Kermeur vs Saint Brie, Paris 1750: Légal's mate.
    legal: {
      san: 'e4 e5 Nf3 d6 Bc4 Bg4 Nc3 g6 Nxe5 Bxd1 Bxf7+ Ke7 Nd5#',
      fen: 'rn1q1bnr/ppp1kB1p/3p2p1/3NN3/4P3/8/PPPP1PPP/R1BbK2R b KQ - 2 7',
      name: { de: 'Légal gegen Saint Brie, Paris 1750', en: 'Légal vs Saint Brie, Paris 1750' },
    },
    // Queen's Gambit Declined, Orthodox Defence, main line to move 6.
    qgd: {
      san: 'd4 d5 c4 e6 Nc3 Nf6 Bg5 Be7 e3 O-O Nf3 Nbd7',
      fen: 'r1bq1rk1/pppnbppp/4pn2/3p2B1/2PP4/2N1PN2/PP3PPP/R2QKB1R w KQ - 3 7',
      name: { de: 'Abgelehntes Damengambit, orthodoxe Verteidigung', en: "Queen's Gambit Declined, Orthodox Defence" },
    },
    // The same Orthodox Defence one move on: 7.Rc1, the main line (daily reel, "one move").
    qgd7: {
      san: 'd4 d5 c4 e6 Nc3 Nf6 Bg5 Be7 e3 O-O Nf3 Nbd7 Rc1',
      fen: 'r1bq1rk1/pppnbppp/4pn2/3p2B1/2PP4/2N1PN2/PP3PPP/2RQKB1R b K - 4 7',
      name: { de: 'Abgelehntes Damengambit, 7.Tc1', en: "Queen's Gambit Declined, 7.Rc1" },
    },
    // Italian Game, Giuoco Piano, Moeller/main line to 6...Bb4+.
    italian: {
      san: 'e4 e5 Nf3 Nc6 Bc4 Bc5 c3 Nf6 d4 exd4 cxd4 Bb4+',
      fen: 'r1bqk2r/pppp1ppp/2n2n2/8/1bBPP3/5N2/PP3PPP/RNBQK2R w KQkq - 1 7',
      name: { de: 'Italienische Partie, Giuoco Piano', en: 'Italian Game, Giuoco Piano' },
    },
  };

  /* Positions along a game: [game, ply] -> fen + last move. check-positions.mjs
   * replays each one, so no position in the campaign is typed by hand. */
  const SNAPS = {
    'opera@24': { fen: '3rkb1r/p2nqppp/5n2/1B2p1B1/4P3/1Q6/PPP2PPP/2KR3R w k - 3 13', last: ['a8', 'd8'] },
    'opera@26': { fen: '4kb1r/p2rqppp/5n2/1B2p1B1/4P3/1Q6/PPP2PPP/2K4R w k - 0 14', last: ['d8', 'd7'] },
    'opera@28': { fen: '4kb1r/p2r1ppp/4qn2/1B2p1B1/4P3/1Q6/PPP2PPP/2KR4 w k - 2 15', last: ['e7', 'e6'] },
    'legal@10': { fen: 'rn1qkbnr/ppp2p1p/3p2p1/4N3/2B1P3/2N5/PPPP1PPP/R1BbK2R w KQkq - 0 6', last: ['g4', 'd1'] },
  };
  function snap(id) { const s = SNAPS[id]; if (!s) throw new Error('unknown snapshot ' + id); return s; }

  /* SAN in the poster's language: German figurine letters K D T L S. */
  const DE = { K: 'K', Q: 'D', R: 'T', B: 'L', N: 'S' };
  function sanLocal(san, lang) {
    if (lang !== 'de') return san;
    return san.replace(/^[KQRBN]/, (c) => DE[c]).replace(/=([QRBN])/, (_, c) => '=' + DE[c]);
  }
  /* "17.Td8#" style label for ply index i (0-based) of a game. */
  function moveLabel(game, i, lang) {
    const san = GAMES[game].san.split(' ');
    const n = Math.floor(i / 2) + 1;
    return (i % 2 === 0 ? n + '.' : n + '...') + sanLocal(san[i], lang);
  }

  /* ---------- FEN helpers (positions along a game come from the reels' timeline) ---------- */
  function fenRows(fen) {
    const rows = [];
    for (const row of fen.split(' ')[0].split('/')) {
      const r = [];
      for (const ch of row) { if (/\d/.test(ch)) { for (let k = 0; k < +ch; k++) r.push(''); } else { r.push(ch); } }
      rows.push(r);
    }
    return rows;
  }

  /* ---------- board, the site's own rendering (resources/js/chess.js boardCells) ---------- */
  const VS16 = '︎';
  function glyph(ch) {
    const idx = 'kqrbnp'.indexOf(ch.toLowerCase());
    const white = ch !== ch.toLowerCase();
    const solid = String.fromCodePoint(0x265a + idx) + VS16;
    const outline = white ? String.fromCodePoint(0x2654 + idx) + VS16 : '';
    return '<svg viewBox="0 0 45 45"><g text-anchor="middle" style="font-family:var(--glyph);font-variant-emoji:text;font-size:40px">'
      + `<text x="22.5" y="38" fill="${white ? '#FFFFFF' : '#0A0A0B'}">${solid}</text>`
      + (outline ? `<text x="22.5" y="38" fill="#0A0A0B">${outline}</text>` : '') + '</g></svg>';
  }
  /** Render fen into el. o: last [from,to], mate square, coords bool, flip bool. */
  function board(el, fen, o = {}) {
    el.classList.add('board');
    const rows = fenRows(fen);
    let html = '';
    for (let r = 0; r < 8; r++) {
      for (let f = 0; f < 8; f++) {
        const rr = o.flip ? 7 - r : r, ff = o.flip ? 7 - f : f;
        const name = 'abcdefgh'[ff] + (8 - rr);
        const light = (rr + ff) % 2 === 0;
        const last = (o.last || []).includes(name);
        const bg = last ? (light ? 'var(--sq-last-l)' : 'var(--sq-last-d)') : (light ? 'var(--sq-l)' : 'var(--sq-d)');
        const cc = light ? '#3A3A42' : '#E4E4E8';
        const ch = rows[rr][ff];
        let co = '';
        if (o.coords) {
          if (f === 0) co += `<span class="co r" style="color:${cc}">${8 - rr}</span>`;
          if (r === 7) co += `<span class="co f" style="color:${cc}">${'abcdefgh'[ff]}</span>`;
        }
        html += `<div class="sq${last ? ' last' : ''}${o.mate === name ? ' mate' : ''}" data-sq="${name}" style="background:${bg}">${co}${ch ? glyph(ch) : ''}</div>`;
      }
    }
    el.innerHTML = html;
    return el;
  }

  /* ---------- headline fitting ----------
   * Fits text into its box: largest step of the scale at which no word is
   * broken and the block does not overflow. Returns the chosen size. */
  const STEPS = [36, 40, 44, 48, 54, 60, 66, 72, 80, 88, 96, 104, 112, 120, 128, 140, 152, 164, 176, 192, 208];
  function fit(el, max, min) {
    const box = el.parentElement;
    const steps = STEPS.filter((s) => s <= max && s >= (min || 0)).reverse();
    for (const s of steps) {
      el.style.fontSize = s + 'px';
      const wordsFit = [...el.querySelectorAll('.w')].every((w) => w.getBoundingClientRect().width <= el.clientWidth + 0.5);
      if (wordsFit && el.scrollHeight <= el.clientHeight + 0.5 && el.scrollWidth <= el.clientWidth + 0.5) return s;
    }
    el.dataset.fitFailed = '1';
    return steps[steps.length - 1];
  }
  /* Wrap words so fit() can measure the longest one; keeps explicit \n line breaks. */
  function words(text) {
    // a dash never opens a line: bind it to the word before (typography, text unchanged)
    return String(text).replace(/ ([—–])/g, '\u00A0$1').split('\n').map((line) => line.split(/[ \t]+/).filter(Boolean)
      .map((w) => `<span class="w">${esc(w)}</span>`).join(' ')).join('<br>');
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  /* ---------- probe: overflow + collision, run before every screenshot ----------
   * [data-box] elements must: stay inside .poster, not overflow themselves,
   * and not intersect any other [data-box] (except declared data-over="id"). */
  function probe(root) {
    const R = root.getBoundingClientRect();
    const boxes = [...root.querySelectorAll('[data-box]')].filter((e) => e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden');
    const issues = [];
    const rect = (e) => e.getBoundingClientRect();
    for (const e of boxes) {
      const r = rect(e), id = e.dataset.box;
      if (r.left < R.left - 0.5 || r.top < R.top - 0.5 || r.right > R.right + 0.5 || r.bottom > R.bottom + 0.5) issues.push(`${id}: outside poster (${r.left.toFixed(0)},${r.top.toFixed(0)},${r.right.toFixed(0)},${r.bottom.toFixed(0)})`);
      if (e.scrollWidth > e.clientWidth + 1 || e.scrollHeight > e.clientHeight + 1) issues.push(`${id}: overflows itself (${e.scrollWidth}x${e.scrollHeight} > ${e.clientWidth}x${e.clientHeight})`);
      if (e.dataset.fitFailed) issues.push(`${id}: headline did not fit even at the minimum size`);
    }
    for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
      const a = boxes[i], b = boxes[j];
      if (a.contains(b) || b.contains(a)) continue;
      const allowed = (a.dataset.over || '').split(' ').includes(b.dataset.box) || (b.dataset.over || '').split(' ').includes(a.dataset.box);
      if (allowed) continue;
      const p = rect(a), q = rect(b);
      const ox = Math.min(p.right, q.right) - Math.max(p.left, q.left);
      const oy = Math.min(p.bottom, q.bottom) - Math.max(p.top, q.top);
      if (ox > 0.5 && oy > 0.5) issues.push(`${a.dataset.box} x ${b.dataset.box}: overlap ${ox.toFixed(0)}x${oy.toFixed(0)}`);
    }
    // every text element must have its font loaded (no fallback rendering)
    const fontsOk = document.fonts.check('800 40px Unbounded') && document.fonts.check('500 20px "JetBrains Mono"');
    if (!fontsOk) issues.push('fonts: Unbounded or JetBrains Mono not loaded');
    return { boxes: boxes.length, issues };
  }

  /* Chess floor in perspective as flat SVG (no 3D transform: large 3D layers drop
   * raster tiles in headless screenshots). Squares projected towards a horizon. */
  function floorSVG(w, h, o = {}) {
    const cx = w / 2, hor = o.horizon ?? 0, cam = o.cam ?? h * 0.9, cell = o.cell ?? 1, rows = o.rows ?? 14, cols = o.cols ?? 22;
    const P = (X, Z) => [cx + (X * cell * w * 0.5) / Z, hor + cam / Z];
    let d = '';
    for (let z = 0; z < rows; z++) {
      const z0 = 1 + z * 0.55, z1 = z0 + 0.55;
      for (let x = -cols / 2; x < cols / 2; x++) {
        if ((x + z) % 2 === 0) continue;
        const a = P(x, z1), b = P(x + 1, z1), c = P(x + 1, z0), e = P(x, z0);
        d += `M${a[0].toFixed(1)} ${a[1].toFixed(1)}L${b[0].toFixed(1)} ${b[1].toFixed(1)}L${c[0].toFixed(1)} ${c[1].toFixed(1)}L${e[0].toFixed(1)} ${e[1].toFixed(1)}Z`;
      }
    }
    return `<svg class="floor-svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" preserveAspectRatio="none" aria-hidden="true">
      <defs><linearGradient id="ff" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity="0"/><stop offset=".35" stop-color="#fff" stop-opacity="1"/><stop offset="1" stop-color="#fff" stop-opacity=".5"/></linearGradient>
      <mask id="fm"><rect width="${w}" height="${h}" fill="url(#ff)"/></mask></defs>
      <path d="${d}" fill="#15151A" mask="url(#fm)"/></svg>`;
  }

  function params() { return Object.fromEntries(new URLSearchParams(location.search)); }

  window.Kit = { GAMES, SNAPS, snap, floorSVG, sanLocal, moveLabel, fenRows, board, glyph, fit, words, esc, probe, params, STEPS };
})();
