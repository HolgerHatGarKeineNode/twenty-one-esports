/*
 * The Blockli engine of DerCaddy's prototype (v0.7, 2026-10-19; Blockli Trainer and Simulator on bitucation.com):
 * the rules of a position and a search that picks a move, without any page of its own. The league's practice page
 * (pages/board/⚡practice, blockli/practice.js) plays its bots with it in a worker (blockli/engineWorker.js) and
 * words its tips from it (blockli/bots.js); the server still checks every move (App\Support\Board\BlockliRules).
 * Kept as the prototype has it, comments in German, so a later version of the prototype can replace this file
 * as it is; the only change is the module export at the end.
 *
 * Its own coordinates: squares 0-80 (a1 = 0, b1 = 1, a2 = 9, as BlockliRules), side 0 = White (₿), 1 = Black (⚡),
 * blocks as moves 100 + 64 * vertical + row * 8 + file (BlockliRules' block codes plus 100), moves named as the
 * league writes them (`e2`, `e3h`).
 */
var Blockli = (function () {
  'use strict';
  var GOAL = [8, 0];            // Zielreihe je Spieler (0 = ₿ unten -> oben, 1 = ⚡ oben -> unten)
  var COLS = 'abcdefghi';
  var PASS = 999;
  var DELTA = [9, 1, -9, -1];   // hoch, rechts, runter, links
  var now = (typeof performance !== 'undefined' && performance.now) ? function () { return performance.now(); } : function () { return Date.now(); };

  function newState(first) {
    return { pawns: [4, 76], walls: [10, 10], h: new Uint8Array(64), v: new Uint8Array(64), turn: first ? 1 : 0, winner: -1, ply: 0 };
  }
  function clone(s) {
    return { pawns: s.pawns.slice(), walls: s.walls.slice(), h: s.h.slice(), v: s.v.slice(), turn: s.turn, winner: s.winner, ply: s.ply };
  }
  function rowOf(cell) { return (cell / 9) | 0; }

  // Darf man von cell in Richtung dir gehen? (Rand + Blöcke)
  function canStep(s, cell, dir) {
    var r = (cell / 9) | 0, c = cell - r * 9;
    if (dir === 0) return r < 8 && !(c < 8 && s.h[r * 8 + c]) && !(c > 0 && s.h[r * 8 + c - 1]);
    if (dir === 2) return r > 0 && !(c < 8 && s.h[(r - 1) * 8 + c]) && !(c > 0 && s.h[(r - 1) * 8 + c - 1]);
    if (dir === 1) return c < 8 && !(r < 8 && s.v[r * 8 + c]) && !(r > 0 && s.v[(r - 1) * 8 + c]);
    return c > 0 && !(r < 8 && s.v[r * 8 + c - 1]) && !(r > 0 && s.v[(r - 1) * 8 + c - 1]);
  }

  // Kürzeste Weglänge zur Zielreihe (Figuren werden ignoriert), -1 = kein Weg
  var _d = new Int16Array(81), _q = new Int16Array(81);
  function pathLen(s, p) {
    var start = s.pawns[p], goal = GOAL[p];
    if (rowOf(start) === goal) return 0;
    _d.fill(-1);
    var head = 0, tail = 0;
    _q[tail++] = start; _d[start] = 0;
    while (head < tail) {
      var cur = _q[head++], nd = _d[cur] + 1;
      for (var dir = 0; dir < 4; dir++) {
        if (!canStep(s, cur, dir)) continue;
        var n = cur + DELTA[dir];
        if (_d[n] >= 0) continue;
        if (rowOf(n) === goal) return nd;
        _d[n] = nd; _q[tail++] = n;
      }
    }
    return -1;
  }

  function goalField(s, p) {
    var d = new Int16Array(81).fill(-1), q = new Int16Array(81), head = 0, tail = 0, g = GOAL[p] * 9;
    for (var c = 0; c < 9; c++) { d[g + c] = 0; q[tail++] = g + c; }
    while (head < tail) {
      var cur = q[head++];
      for (var dir = 0; dir < 4; dir++) {
        if (!canStep(s, cur, dir)) continue;
        var n = cur + DELTA[dir];
        if (d[n] < 0) { d[n] = d[cur] + 1; q[tail++] = n; }
      }
    }
    return d;
  }
  function shortestPath(s, p) {
    var d = goalField(s, p), cur = s.pawns[p], path = [cur];
    if (d[cur] < 0) return path;
    while (d[cur] > 0) {
      var moved = false;
      for (var dir = 0; dir < 4; dir++) {
        if (!canStep(s, cur, dir)) continue;
        var n = cur + DELTA[dir];
        if (d[n] === d[cur] - 1) { cur = n; moved = true; break; }
      }
      if (!moved) break;
      path.push(cur);
    }
    return path;
  }

  // Figurenzüge inkl. Sprung und Schrägsprung
  function pawnMoves(s, p) {
    var me = s.pawns[p], op = s.pawns[1 - p], res = [];
    for (var dir = 0; dir < 4; dir++) {
      if (!canStep(s, me, dir)) continue;
      var n = me + DELTA[dir];
      if (n !== op) { res.push(n); continue; }
      if (canStep(s, n, dir)) { res.push(n + DELTA[dir]); continue; }
      var a = (dir + 1) & 3, b = (dir + 3) & 3, m;
      if (canStep(s, n, a)) { m = n + DELTA[a]; if (res.indexOf(m) < 0) res.push(m); }
      if (canStep(s, n, b)) { m = n + DELTA[b]; if (res.indexOf(m) < 0) res.push(m); }
    }
    return res;
  }

  // Blöcke: o = 0 waagerecht, 1 senkrecht; (r,c) = Kreuzungspunkt rechts oberhalb von Feld (r,c)
  function wallCode(o, r, c) { return 100 + o * 64 + r * 8 + c; }
  function decodeWall(m) { var w = m - 100; return { o: w >= 64 ? 1 : 0, r: (w & 63) >> 3, c: w & 7 }; }
  function wallFits(s, o, r, c) {
    if (r < 0 || r > 7 || c < 0 || c > 7) return false;
    var i = r * 8 + c;
    if (s.h[i] || s.v[i]) return false;
    if (o === 0) { if ((c > 0 && s.h[i - 1]) || (c < 7 && s.h[i + 1])) return false; }
    else { if ((r > 0 && s.v[i - 8]) || (r < 7 && s.v[i + 8])) return false; }
    return true;
  }
  // null = erlaubt, sonst Begründung
  function wallProblem(s, p, o, r, c) {
    if (s.winner >= 0) return 'Die Partie ist vorbei.';
    if (s.walls[p] <= 0) return 'Keine Blöcke mehr übrig.';
    if (r < 0 || r > 7 || c < 0 || c > 7) return 'Außerhalb des Bretts.';
    var i = r * 8 + c, same = o ? s.v : s.h, other = o ? s.h : s.v;
    if (same[i]) return 'Hier liegt schon ein Block.';
    if (other[i]) return 'Blöcke dürfen sich nicht kreuzen.';
    if (o === 0 && ((c > 0 && s.h[i - 1]) || (c < 7 && s.h[i + 1]))) return 'Überlappt einen Block.';
    if (o === 1 && ((r > 0 && s.v[i - 8]) || (r < 7 && s.v[i + 8]))) return 'Überlappt einen Block.';
    same[i] = 1;
    var ok = pathLen(s, 0) >= 0 && pathLen(s, 1) >= 0;
    same[i] = 0;
    return ok ? null : 'Dieser Block würde einen Weg komplett sperren.';
  }

  function applyMove(s, m) {
    var p = s.turn, undo = -1;
    if (m !== PASS) {
      if (m < 100) { undo = s.pawns[p]; s.pawns[p] = m; if (rowOf(m) === GOAL[p]) s.winner = p; }
      else { var w = m - 100; (w >= 64 ? s.v : s.h)[w & 63] = 1; s.walls[p]--; }
    }
    s.turn = 1 - p; s.ply++;
    return undo;
  }
  function undoMove(s, m, undo) {
    s.turn = 1 - s.turn; s.ply--;
    if (m === PASS) return;
    var p = s.turn;
    if (m < 100) { s.pawns[p] = undo; s.winner = -1; }
    else { var w = m - 100; (w >= 64 ? s.v : s.h)[w & 63] = 0; s.walls[p]++; }
  }

  function legalMoves(s) {
    var p = s.turn, res = pawnMoves(s, p).slice();
    if (s.walls[p] > 0) {
      for (var o = 0; o < 2; o++) for (var i = 0; i < 64; i++)
        if (wallProblem(s, p, o, i >> 3, i & 7) === null) res.push(wallCode(o, i >> 3, i & 7));
    }
    if (!res.length) res.push(PASS);
    return res;
  }
  function isLegal(s, m) {
    if (s.winner >= 0) return false;
    if (m === PASS) { var lm = legalMoves(s); return lm.length === 1 && lm[0] === PASS; }
    if (m >= 0 && m < 81) return pawnMoves(s, s.turn).indexOf(m) >= 0;
    if (m >= 100 && m < 228) { var w = decodeWall(m); return wallProblem(s, s.turn, w.o, w.r, w.c) === null; }
    return false;
  }

  // Notation: Felder a1–i9, Blöcke wie e3h / e3v (Feld links unter der Blockmitte)
  function cellName(cell) { return COLS[cell % 9] + (rowOf(cell) + 1); }
  function moveName(m) {
    if (m === PASS) return '--';
    if (m < 100) return cellName(m);
    var w = decodeWall(m);
    return COLS[w.c] + (w.r + 1) + (w.o ? 'v' : 'h');
  }
  function parseMove(str) {
    var t = String(str || '').trim().toLowerCase();
    if (t === '--') return PASS;
    var mm = /^([a-i])([1-9])([hv])?$/.exec(t);
    if (!mm) return -1;
    var c = COLS.indexOf(mm[1]), r = +mm[2] - 1;
    if (!mm[3]) return r * 9 + c;
    if (r > 7 || c > 7) return -1;
    return wallCode(mm[3] === 'v' ? 1 : 0, r, c);
  }

  /* ---------- KI v0.3: Alpha-Beta mit Bewertung von Wettrennen und Blockdrohungen ----------
   * Bewertet wird in Schritten (×100) aus Sicht der Seite am Zug: Vorsprung im Wettrennen (mit
   * Anzugsvorteil), was der beste Block jetzt brächte (für beide Seiten), und der Vorrat an Blöcken.
   * Der Vorrat ist viel wert, solange er dem Gegner noch schaden kann (Blöcke entscheiden das Endspiel),
   * aber fast nichts mehr, wenn dessen Weg gegen jeden Block geschützt ist und er kurz vor dem Ziel steht.
   * Wer keine Blöcke mehr hat und im Rennen hinten liegt, hat verloren; das weiß die Bewertung auch.
   * Gesucht wird mit Alpha-Beta, Transpositionstabelle und Abwehrblöcken; Wiederholungen gelten als Remis.
   * Abgestimmt in Partien gegen v0.2 und gegen eine unabhängige Quoridor-KI (gorisanson, MCTS).
   */
  var WIN = 1000000, SURE = 50000, SCALE = 100;
  var LEVELS = {
    easy:   { depth: 1,  noise: 1.5, cand: 5,  rootCand: 8,  defend: false, time: 300 },
    medium: { depth: 2,  noise: 0.4, cand: 8,  rootCand: 12, defend: true,  time: 900 },
    hard:   { depth: 12, noise: 0,   cand: 10, rootCand: 16, defend: true,  time: 2000, traps: true }
  };
  // Stellschrauben der Bewertung. table[k]: Wert von k Blöcken in der Hand, in Schritten.
  var PARAMS_V04 = { table: [0, 2.5, 4.75, 6.75, 8.5, 10.08, 11.5, 12.83, 14.08, 15.25, 16.33], lambda: 0.6, imm: 1.0, u0: 0.25, protT: 5, hard: false, uHard: 1, hardSure: false, hardM: 0 };
  // v0.6: Blöcke gegen einen Weg, den kein Block mehr verlängern kann, zählen nur 10 % (bleibt etwas Abwehrwert)
  var PARAMS = { table: PARAMS_V04.table, lambda: 0.6, imm: 1.0, u0: 0.25, protT: 5, hard: true, uHard: 0.1, hardSure: true, hardM: 0 };

  var COL = new Int8Array(81), ROW = new Int8Array(81);
  for (var _c = 0; _c < 81; _c++) { COL[_c] = _c % 9; ROW[_c] = (_c / 9) | 0; }

  // Zobrist-Schlüssel (fester Startwert, damit Testläufe reproduzierbar sind)
  var _seed = 0x9e3779b9;
  function rnd32() { _seed ^= _seed << 13; _seed ^= _seed >>> 17; _seed ^= _seed << 5; return _seed | 0; }
  function table(n) { var a = new Int32Array(n); for (var i = 0; i < n; i++) a[i] = rnd32(); return a; }
  var ZP1 = table(162), ZP2 = table(162), ZW1 = table(128), ZW2 = table(128), ZL1 = table(22), ZL2 = table(22);
  var ZT1 = rnd32(), ZT2 = rnd32();

  // Internes Brett: Kanten statt Blöcke, damit die Wegsuche schnell bleibt.
  function makeBoard(s) {
    var b = { pw: [s.pawns[0], s.pawns[1]], wl: [s.walls[0], s.walls[1]], h: s.h.slice(), v: s.v.slice(),
      up: new Uint8Array(81), rt: new Uint8Array(81), turn: s.turn, won: -1, k1: 0, k2: 0 };
    for (var cell = 0; cell < 81; cell++) { b.up[cell] = canStep(s, cell, 0) ? 1 : 0; b.rt[cell] = canStep(s, cell, 1) ? 1 : 0; }
    var k1 = 0, k2 = 0, p, i;
    for (p = 0; p < 2; p++) { k1 ^= ZP1[p * 81 + b.pw[p]]; k2 ^= ZP2[p * 81 + b.pw[p]]; k1 ^= ZL1[p * 11 + b.wl[p]]; k2 ^= ZL2[p * 11 + b.wl[p]]; }
    for (i = 0; i < 64; i++) { if (b.h[i]) { k1 ^= ZW1[i]; k2 ^= ZW2[i]; } if (b.v[i]) { k1 ^= ZW1[64 + i]; k2 ^= ZW2[64 + i]; } }
    if (b.turn) { k1 ^= ZT1; k2 ^= ZT2; }
    b.k1 = k1; b.k2 = k2;
    return b;
  }

  function wallOpen(b, w) {           // passt der Block w (0..127) ohne Überlappen/Kreuzen?
    var i = w & 63, c = i & 7, r = i >> 3;
    if (b.h[i] || b.v[i]) return false;
    if (w < 64) return !((c > 0 && b.h[i - 1]) || (c < 7 && b.h[i + 1]));
    return !((r > 0 && b.v[i - 8]) || (r < 7 && b.v[i + 8]));
  }
  function setWall(b, w, on) {        // on = 1 setzen, 0 entfernen (nur Kanten und Blockfelder)
    var i = w & 63, cell = (i >> 3) * 9 + (i & 7), open = on ? 0 : 1;
    if (w < 64) { b.h[i] = on; b.up[cell] = open; b.up[cell + 1] = open; }
    else { b.v[i] = on; b.rt[cell] = open; b.rt[cell + 9] = open; }
  }

  // Wegsuche: kürzeste Länge zur Zielreihe (Figuren zählen nicht), -1 = kein Weg
  var MARK = new Int32Array(81), STAMP = 0, Q = new Int16Array(81), DIST = new Int16Array(81);
  function bLen(b, p) {
    var start = b.pw[p], lo = GOAL[p] * 9, hi = lo + 9;
    if (start >= lo && start < hi) return 0;
    var up = b.up, rt = b.rt, head = 0, tail = 0, cur, nd, n;
    if (++STAMP > 0x3fffffff) { MARK.fill(0); STAMP = 1; }      // v0.4.1: vor dem Überlauf zurücksetzen (sonst nach ~65 Mio. Stellungen kaputt)
    MARK[start] = STAMP; DIST[start] = 0; Q[tail++] = start;
    while (head < tail) {
      cur = Q[head++]; nd = DIST[cur] + 1;
      if (up[cur]) { n = cur + 9; if (MARK[n] !== STAMP) { if (n >= lo && n < hi) return nd; MARK[n] = STAMP; DIST[n] = nd; Q[tail++] = n; } }
      if (cur >= 9 && up[cur - 9]) { n = cur - 9; if (MARK[n] !== STAMP) { if (n >= lo && n < hi) return nd; MARK[n] = STAMP; DIST[n] = nd; Q[tail++] = n; } }
      if (rt[cur]) { n = cur + 1; if (MARK[n] !== STAMP) { MARK[n] = STAMP; DIST[n] = nd; Q[tail++] = n; } }
      if (COL[cur] > 0 && rt[cur - 1]) { n = cur - 1; if (MARK[n] !== STAMP) { MARK[n] = STAMP; DIST[n] = nd; Q[tail++] = n; } }
    }
    return -1;
  }
  // Entfernung jedes Feldes zur Zielreihe von p (für das Ablaufen eines kürzesten Weges)
  function bField(b, p, d) {
    var up = b.up, rt = b.rt, head = 0, tail = 0, lo = GOAL[p] * 9, cur, n;
    d.fill(-1);
    for (n = lo; n < lo + 9; n++) { d[n] = 0; Q[tail++] = n; }
    while (head < tail) {
      cur = Q[head++];
      if (up[cur]) { n = cur + 9; if (d[n] < 0) { d[n] = d[cur] + 1; Q[tail++] = n; } }
      if (cur >= 9 && up[cur - 9]) { n = cur - 9; if (d[n] < 0) { d[n] = d[cur] + 1; Q[tail++] = n; } }
      if (rt[cur]) { n = cur + 1; if (d[n] < 0) { d[n] = d[cur] + 1; Q[tail++] = n; } }
      if (COL[cur] > 0 && rt[cur - 1]) { n = cur - 1; if (d[n] < 0) { d[n] = d[cur] + 1; Q[tail++] = n; } }
    }
  }
  // Ein kürzester Weg von p als Kantenliste: up-Kanten als Zelle, rt-Kanten als 81 + Zelle
  var FLD = new Int16Array(81);
  function pathEdges(b, p, out) {
    bField(b, p, FLD);
    var cur = b.pw[p], k = 0, up = b.up, rt = b.rt;
    if (FLD[cur] < 0) return 0;
    while (FLD[cur] > 0) {
      var want = FLD[cur] - 1;
      if (GOAL[p] === 8 && up[cur] && FLD[cur + 9] === want) { out[k++] = cur; cur += 9; continue; }
      if (GOAL[p] === 0 && cur >= 9 && up[cur - 9] && FLD[cur - 9] === want) { out[k++] = cur - 9; cur -= 9; continue; }
      if (rt[cur] && FLD[cur + 1] === want) { out[k++] = 81 + cur; cur += 1; continue; }
      if (COL[cur] > 0 && rt[cur - 1] && FLD[cur - 1] === want) { out[k++] = 81 + cur - 1; cur -= 1; continue; }
      if (up[cur] && FLD[cur + 9] === want) { out[k++] = cur; cur += 9; continue; }
      if (cur >= 9 && up[cur - 9] && FLD[cur - 9] === want) { out[k++] = cur - 9; cur -= 9; continue; }
      break;
    }
    return k;
  }
  // Die zwei Blöcke, die eine Kante sperren können
  function blockersOf(edge, out) {
    var k = 0, cell, r, c;
    if (edge < 81) { cell = edge; r = ROW[cell]; c = COL[cell]; if (c < 8) out[k++] = r * 8 + c; if (c > 0) out[k++] = r * 8 + c - 1; }
    else { cell = edge - 81; r = ROW[cell]; c = COL[cell]; if (r < 8) out[k++] = 64 + r * 8 + c; if (r > 0) out[k++] = 64 + (r - 1) * 8 + c; }
    return k;
  }
  function edgesOf(w, out) {          // die zwei Kanten, die Block w sperrt
    var i = w & 63, cell = (i >> 3) * 9 + (i & 7);
    if (w < 64) { out[0] = cell; out[1] = cell + 1; } else { out[0] = 81 + cell; out[1] = 81 + cell + 9; }
  }

  // Alle Blöcke von a gegen t, die t verlängern: [Block, Gewinn] mit Gewinn = Umweg t − Umweg a.
  // Nur Blöcke auf einem kürzesten Weg von t können ihn verlängern; ob a selbst getroffen wird,
  // sagt ein Blick auf einen kürzesten Weg von a (liegt der Block nicht darauf, bleibt a gleich lang).
  var PE_T = new Int16Array(200), PE_A = new Int16Array(200), BL2 = new Int16Array(2), ED2 = new Int16Array(2);
  var ONA = new Int32Array(162), ONA_STAMP = 0, SEENW = new Int32Array(128), SEEN_STAMP = 0;
  // v0.4.2: Entfernung jedes Feldes zur Zielreihe von p (DGT) und Zahl der kürzesten Wege von dort ins Ziel (CGT)
  var DGT = new Int16Array(81), CGT = new Float64Array(81), CST = new Float64Array(81), FMARK = new Int32Array(81), FSTAMP = 0, QF = new Int16Array(81);
  function bFieldCount(b, p) {
    var up = b.up, rt = b.rt, head = 0, tail = 0, lo = GOAL[p] * 9, cur, n, nd;
    DGT.fill(-1);
    for (n = lo; n < lo + 9; n++) { DGT[n] = 0; CGT[n] = 1; Q[tail++] = n; }
    while (head < tail) {
      cur = Q[head++]; nd = DGT[cur] + 1;
      if (up[cur]) { n = cur + 9; if (DGT[n] < 0) { DGT[n] = nd; CGT[n] = CGT[cur]; Q[tail++] = n; } else if (DGT[n] === nd) CGT[n] += CGT[cur]; }
      if (cur >= 9 && up[cur - 9]) { n = cur - 9; if (DGT[n] < 0) { DGT[n] = nd; CGT[n] = CGT[cur]; Q[tail++] = n; } else if (DGT[n] === nd) CGT[n] += CGT[cur]; }
      if (rt[cur]) { n = cur + 1; if (DGT[n] < 0) { DGT[n] = nd; CGT[n] = CGT[cur]; Q[tail++] = n; } else if (DGT[n] === nd) CGT[n] += CGT[cur]; }
      if (COL[cur] > 0 && rt[cur - 1]) { n = cur - 1; if (DGT[n] < 0) { DGT[n] = nd; CGT[n] = CGT[cur]; Q[tail++] = n; } else if (DGT[n] === nd) CGT[n] += CGT[cur]; }
    }
  }
  // Zahl der kürzesten Wege vom Startfeld zu jedem Feld, das auf einem kürzesten Weg liegt (CST, markiert mit FSTAMP)
  function bForwardCount(b, start) {
    if (++FSTAMP > 0x3fffffff) { FMARK.fill(0); FSTAMP = 1; }
    var up = b.up, rt = b.rt, head = 0, tail = 0, cur, n, want;
    FMARK[start] = FSTAMP; CST[start] = 1; QF[tail++] = start;
    while (head < tail) {
      cur = QF[head++]; want = DGT[cur] - 1;
      if (want < 0) continue;
      if (up[cur]) { n = cur + 9; if (DGT[n] === want) { if (FMARK[n] !== FSTAMP) { FMARK[n] = FSTAMP; CST[n] = CST[cur]; QF[tail++] = n; } else CST[n] += CST[cur]; } }
      if (cur >= 9 && up[cur - 9]) { n = cur - 9; if (DGT[n] === want) { if (FMARK[n] !== FSTAMP) { FMARK[n] = FSTAMP; CST[n] = CST[cur]; QF[tail++] = n; } else CST[n] += CST[cur]; } }
      if (rt[cur]) { n = cur + 1; if (DGT[n] === want) { if (FMARK[n] !== FSTAMP) { FMARK[n] = FSTAMP; CST[n] = CST[cur]; QF[tail++] = n; } else CST[n] += CST[cur]; } }
      if (COL[cur] > 0 && rt[cur - 1]) { n = cur - 1; if (DGT[n] === want) { if (FMARK[n] !== FSTAMP) { FMARK[n] = FSTAMP; CST[n] = CST[cur]; QF[tail++] = n; } else CST[n] += CST[cur]; } }
    }
  }
  // Wie viele kürzeste Wege laufen über die Kante zwischen x und y (y = x + 9 oder x + 1)?
  function edgeFlow(x, y) {
    if (FMARK[x] === FSTAMP && DGT[y] >= 0 && DGT[y] === DGT[x] - 1) return CST[x] * CGT[y];
    if (FMARK[y] === FSTAMP && DGT[x] >= 0 && DGT[x] === DGT[y] - 1) return CST[y] * CGT[x];
    return 0;
  }
  // Kann Block w alle kürzesten Wege sperren? Nur dann wird der Weg länger. Ein Weg über beide Kanten würde doppelt
  // gezählt; das macht die Summe nur größer, im Zweifel wird also gerechnet, nie ein wirksamer Block übersehen.
  function mayBlockAll(w, total) {
    var i = w & 63, cell = (i >> 3) * 9 + (i & 7), f;
    if (w < 64) f = edgeFlow(cell, cell + 9) + edgeFlow(cell + 1, cell + 10);
    else f = edgeFlow(cell, cell + 1) + edgeFlow(cell + 9, cell + 10);
    return f >= total * 0.999999;
  }
  // Ein kürzester Weg von p wie pathEdges, aber auf DGT (nach bFieldCount)
  function pathEdgesT(b, p, out) {
    bFieldCount(b, p);
    var cur = b.pw[p], k = 0, up = b.up, rt = b.rt;
    if (DGT[cur] < 0) return 0;
    while (DGT[cur] > 0) {
      var want = DGT[cur] - 1;
      if (GOAL[p] === 8 && up[cur] && DGT[cur + 9] === want) { out[k++] = cur; cur += 9; continue; }
      if (GOAL[p] === 0 && cur >= 9 && up[cur - 9] && DGT[cur - 9] === want) { out[k++] = cur - 9; cur -= 9; continue; }
      if (rt[cur] && DGT[cur + 1] === want) { out[k++] = 81 + cur; cur += 1; continue; }
      if (COL[cur] > 0 && rt[cur - 1] && DGT[cur - 1] === want) { out[k++] = 81 + cur - 1; cur -= 1; continue; }
      if (up[cur] && DGT[cur + 9] === want) { out[k++] = cur; cur += 9; continue; }
      if (cur >= 9 && up[cur - 9] && DGT[cur - 9] === want) { out[k++] = cur - 9; cur -= 9; continue; }
      break;
    }
    return k;
  }
  // v0.6: LAST_HARD = 1, wenn an keine Kante des gefundenen kürzesten Weges von t noch ein erlaubter Block passt
  // (alle Plätze belegt, überlappt, gekreuzt oder der Block würde jemanden ganz einsperren). Das bleibt so: Neue Blöcke
  // machen weitere Blöcke nur unmöglicher. Dieser Weg ist also nie mehr zu verlängern.
  var LAST_HARD = 0;
  function wallScanRaw(b, a, t, dA, dT, out) {
    var nT = pathEdgesT(b, t, PE_T), nA = pathEdges(b, a, PE_A), k, j, m, n = 0, hard = nT > 0 ? 1 : 0;
    bForwardCount(b, b.pw[t]);
    var total = CGT[b.pw[t]];
    if (++ONA_STAMP > 0x3fffffff) { ONA.fill(0); ONA_STAMP = 1; }
    for (k = 0; k < nA; k++) ONA[PE_A[k]] = ONA_STAMP;
    if (++SEEN_STAMP > 0x3fffffff) { SEENW.fill(0); SEEN_STAMP = 1; }
    for (k = 0; k < nT; k++) {
      m = blockersOf(PE_T[k], BL2);
      for (j = 0; j < m; j++) {
        var w = BL2[j];
        if (SEENW[w] === SEEN_STAMP) continue;
        SEENW[w] = SEEN_STAMP;
        if (!wallOpen(b, w)) continue;
        if (!mayBlockAll(w, total)) { hard = 0; continue; }      // passt und lässt t einen Weg: Kante sperrbar
        setWall(b, w, 1);
        var lt = bLen(b, t), la = dA;
        if (lt >= 0) { edgesOf(w, ED2); if (ONA[ED2[0]] === ONA_STAMP || ONA[ED2[1]] === ONA_STAMP) la = bLen(b, a); }
        setWall(b, w, 0);
        if (lt < 0 || la < 0) continue;
        hard = 0;
        var gain = (lt - dT) - (la - dA);
        if (gain > 0) { out[n++] = w; out[n++] = gain; }      // v0.4.2: nur Blöcke mit Gewinn (andere nutzt niemand)
      }
    }
    LAST_HARD = hard;
    return n >> 1;
  }
  // v0.4.2: Ergebnisse der Blocksuche je Brettbild (Blöcke und beide Figuren, ohne Vorrat und Zugrecht) und Seite.
  // Die Suche fragt dieselben Brettbilder sehr oft (Zugumstellungen, jede neue Tiefe wieder): je nach Stellung 45 bis 77 %.
  var SC_BITS = 16, SC_SIZE = 1 << SC_BITS, SC_MASK = SC_SIZE - 1, SC_W = 20;
  var SCK1 = new Int32Array(SC_SIZE), SCK2 = new Int32Array(SC_SIZE), SCN = new Int8Array(SC_SIZE).fill(-1), SCD = new Int16Array(SC_SIZE * SC_W * 2), SCH = new Int8Array(SC_SIZE);
  var SIDE1 = rnd32(), SIDE2 = rnd32();
  function wallScan(b, a, t, dA, dT, out) {
    var g1 = b.k1 ^ ZL1[b.wl[0]] ^ ZL1[11 + b.wl[1]] ^ (b.turn ? ZT1 : 0) ^ (a ? SIDE1 : 0);
    var g2 = b.k2 ^ ZL2[b.wl[0]] ^ ZL2[11 + b.wl[1]] ^ (b.turn ? ZT2 : 0) ^ (a ? SIDE2 : 0);
    var slot = g1 & SC_MASK, k, n;
    if (SCN[slot] >= 0 && SCK1[slot] === g1 && SCK2[slot] === g2) {
      n = SCN[slot]; LAST_HARD = SCH[slot];
      for (k = 0; k < 2 * n; k++) out[k] = SCD[slot * SC_W * 2 + k];
      return n;
    }
    n = wallScanRaw(b, a, t, dA, dT, out);
    if (n <= SC_W) { SCK1[slot] = g1; SCK2[slot] = g2; SCN[slot] = n; SCH[slot] = LAST_HARD; for (k = 0; k < 2 * n; k++) SCD[slot * SC_W * 2 + k] = out[k]; }
    return n;
  }
  var SCAN = new Int16Array(400);
  function bestGain(b, a, t, dA, dT) {
    if (b.wl[a] <= 0) return 0;
    var n = wallScan(b, a, t, dA, dT, SCAN), best = 0;
    for (var k = 0; k < n; k++) if (SCAN[2 * k + 1] > best) best = SCAN[2 * k + 1];
    return best;
  }

  // Fallen: Blöcke, die selbst wenig oder nichts bringen, nach denen aber ein zweiter Block viel bringt,
  // etwa eine Sackgasse, die ein weiterer Block schließt. Die normale Zugauswahl kennt nur Blöcke mit
  // Sofortwirkung und sähe solche Pläne nie. Ergebnis: [Block, Gewinn beider Blöcke, Gewinn des ersten,
  // bester zweiter Block, dessen Gewinn], stärkste zuerst. ctx.work zählt die Wegsuchen (Aufwand).
  var TRAP_MIN = 3, TRAP_EDGE = 2, TRAP_K = 3;
  var SCAN2 = new Int16Array(400), SCAN3 = new Int16Array(400);
  function trapScan(b, p, ctx) {
    var q = 1 - p, res = [];
    if (b.wl[p] < 2) return res;
    var dp = bLen(b, p), dq = bLen(b, q);
    if (dp <= 0 || dq <= 0) return res;
    var n1 = wallScan(b, p, q, dp, dq, SCAN2), G1 = 0, k, work = 4 + n1;
    for (k = 0; k < n1; k++) if (SCAN2[2 * k + 1] > G1) G1 = SCAN2[2 * k + 1];
    for (var w = 0; w < 128; w++) {
      if (!wallOpen(b, w)) continue;
      setWall(b, w, 1);
      var lq = bLen(b, q), lp = lq >= 0 ? bLen(b, p) : -1;
      work += 2;
      if (lq >= 0 && lp >= 0) {
        var g1 = (lq - dq) - (lp - dp);
        if (g1 <= 1) {
          var n2 = wallScanRaw(b, p, q, lp, lq, SCAN3), G2 = 0, w2 = -1;   // Brett mit vorläufigem Block: ohne Zwischenspeicher
          work += 4 + n2;
          for (k = 0; k < n2; k++) if (SCAN3[2 * k + 1] > G2) { G2 = SCAN3[2 * k + 1]; w2 = SCAN3[2 * k]; }
          if (G2 >= TRAP_MIN && g1 + G2 >= G1 + TRAP_EDGE) res.push([w, g1 + G2, g1, w2, G2]);
        }
      }
      setWall(b, w, 0);
    }
    if (ctx) ctx.work += work;
    res.sort(function (x, y) { return y[1] - x[1] || y[2] - x[2]; });
    return res;
  }
  function trapMoves(b, p, ctx) {
    var key = b.k1 + ':' + b.k2, hit = ctx.trapCache.get(key);
    if (hit) return hit;
    var list = trapScan(b, p, ctx), out = [];
    for (var k = 0; k < list.length && out.length < TRAP_K; k++) out.push(100 + list[k][0]);
    ctx.trapCache.set(key, out);
    return out;
  }
  // v0.7: Schutzblöcke. Droht der Gegner mit einem Block, der uns mindestens GUARD_MIN Schritte kostet, prüfen wir alle
  // eigenen Blöcke, die unseren Weg nicht verlängern: Welche nehmen der stärksten Drohung mindestens GUARD_GAIN Schritte?
  // Etwa weil der Block des Gegners danach nicht mehr passt oder nicht mehr erlaubt ist, da er uns ganz einsperren würde.
  // Ergebnis: [Block, wie viel weniger die Drohung bringt, wie viel länger der Weg des Gegners wird], stärkste zuerst.
  var GUARD_MIN = 3, GUARD_GAIN = 2, GUARD_K = 2;
  var SCAN4 = new Int16Array(400), SCAN5 = new Int16Array(400);
  function guardScan(b, p, ctx) {
    var q = 1 - p, res = [], k, w, work = 2;
    if (b.wl[p] < 1 || b.wl[q] < 1) return res;
    var dp = bLen(b, p), dq = bLen(b, q);
    if (dp <= 0 || dq <= 0) return res;
    var n = wallScanRaw(b, q, p, dq, dp, SCAN4), G = 0;
    work += 4 + n;
    for (k = 0; k < n; k++) if (SCAN4[2 * k + 1] > G) G = SCAN4[2 * k + 1];
    if (G >= GUARD_MIN) {
      for (w = 0; w < 128; w++) {
        if (!wallOpen(b, w)) continue;
        setWall(b, w, 1);
        var lp = bLen(b, p), lq = lp >= 0 ? bLen(b, q) : -1;
        work += 2;
        if (lp === dp && lq >= 0) {
          var n2 = wallScanRaw(b, q, p, lq, lp, SCAN5), G2 = 0;
          work += 4 + n2;
          for (k = 0; k < n2; k++) if (SCAN5[2 * k + 1] > G2) G2 = SCAN5[2 * k + 1];
          if (G - G2 >= GUARD_GAIN) res.push([w, G - G2, lq - dq]);
        }
        setWall(b, w, 0);
      }
    }
    if (ctx) ctx.work += work;
    res.sort(function (x, y) { return y[1] - x[1] || y[2] - x[2]; });
    return res;
  }
  function guardMoves(b, p, ctx) {
    var key = b.k1 + ':' + b.k2, hit = ctx.guardCache.get(key);
    if (hit) return hit;
    var list = guardScan(b, p, ctx), out = [];
    for (var k = 0; k < list.length && out.length < GUARD_K; k++) out.push(100 + list[k][0]);
    ctx.guardCache.set(key, out);
    return out;
  }
  // Für Trainer und Simulator: bester Schutzblock für die Seite am Zug oder null
  function bestGuard(s0) {
    var b = makeBoard(s0), list = guardScan(b, b.turn, null);
    return list.length ? { m: 100 + list[0][0], cut: list[0][1], extra: list[0][2] } : null;
  }

  // Bewertung aus Sicht von p (am Zug), in Hundertstel Schritten
  function evaluate(b, p, ctx) {
    var q = 1 - p, dp = bLen(b, p), dq = bLen(b, q);
    if (dp <= 1) return WIN - 1000;                         // p zieht ins Ziel
    var M = dq - dp + 0.5, Wp = b.wl[p], Wq = b.wl[q];
    if (Wq === 0 && M > 0) return SURE + M * SCALE;          // q kann p nicht mehr aufhalten
    if (Wp === 0 && M < 0) return -SURE + M * SCALE;         // p kann q nicht mehr aufhalten
    if (Wp === 0 && Wq === 0) return (M > 0 ? SURE : -SURE) + M * SCALE;
    var P = ctx.P;
    var Gp = Wp > 0 ? bestGain(b, p, q, dp, dq) : 0, hq = Wp > 0 ? LAST_HARD : 0;   // hq: Weg von q nicht mehr zu verlängern
    var Gq = Wq > 0 ? bestGain(b, q, p, dq, dp) : 0, hp = Wq > 0 ? LAST_HARD : 0;   // hp: Weg von p nicht mehr zu verlängern
    // v0.6: Wer einen Weg hat, den kein Block mehr verlängern kann, und im Rennen vorn liegt, gewinnt.
    if (P.hard && P.hardSure) {
      if (hp && M > P.hardM) return SURE + M * SCALE;
      if (hq && M < -P.hardM) return -SURE + M * SCALE;
    }
    // Ist der Weg des Gegners gegen jeden Block geschützt und er nah am Ziel, sind Blöcke in der Hand
    // kaum noch etwas wert: Sie können nichts mehr ausrichten. v0.6: Ist er gar nicht mehr zu verlängern, gilt das immer.
    var up = (Gp === 0 && dq <= P.protT) ? P.u0 : 1, uq = (Gq === 0 && dp <= P.protT) ? P.u0 : 1;
    if (P.hard) { if (hq) up = P.uHard; if (hp) uq = P.uHard; }
    var sc = M + P.imm * Math.max(0, Gp - 1) - P.lambda * Math.max(0, Gq - 1) + P.table[Wp] * up - P.table[Wq] * uq;
    return Math.round(sc * SCALE);
  }

  // Züge auf dem internen Brett
  function bStep(b, cell, dir) {
    if (dir === 0) return b.up[cell] === 1;
    if (dir === 2) return cell >= 9 && b.up[cell - 9] === 1;
    if (dir === 1) return b.rt[cell] === 1;
    return COL[cell] > 0 && b.rt[cell - 1] === 1;
  }
  function bPawnMoves(b, p, out) {
    var me = b.pw[p], op = b.pw[1 - p], k = 0, dir, n, a, c, m, x;
    for (dir = 0; dir < 4; dir++) {
      if (!bStep(b, me, dir)) continue;
      n = me + DELTA[dir];
      if (n !== op) { out[k++] = n; continue; }
      if (bStep(b, n, dir)) { out[k++] = n + DELTA[dir]; continue; }
      a = (dir + 1) & 3; c = (dir + 3) & 3;
      if (bStep(b, n, a)) { m = n + DELTA[a]; for (x = 0; x < k && out[x] !== m; x++); if (x === k) out[k++] = m; }
      if (bStep(b, n, c)) { m = n + DELTA[c]; for (x = 0; x < k && out[x] !== m; x++); if (x === k) out[k++] = m; }
    }
    return k;
  }
  // Zug ausführen; Rückgabe = alte Position (Figur) bzw. -1
  function bApply(b, m) {
    var p = b.turn, old = -1;
    if (m < 100) {
      old = b.pw[p];
      b.k1 ^= ZP1[p * 81 + old] ^ ZP1[p * 81 + m]; b.k2 ^= ZP2[p * 81 + old] ^ ZP2[p * 81 + m];
      b.pw[p] = m;
      if (ROW[m] === GOAL[p]) b.won = p;
    } else if (m !== PASS) {
      var w = m - 100;
      setWall(b, w, 1);
      b.k1 ^= ZW1[w] ^ ZL1[p * 11 + b.wl[p]] ^ ZL1[p * 11 + b.wl[p] - 1];
      b.k2 ^= ZW2[w] ^ ZL2[p * 11 + b.wl[p]] ^ ZL2[p * 11 + b.wl[p] - 1];
      b.wl[p]--;
    }
    b.turn = 1 - p; b.k1 ^= ZT1; b.k2 ^= ZT2;
    return old;
  }
  function bUndo(b, m, old) {
    b.turn = 1 - b.turn; b.k1 ^= ZT1; b.k2 ^= ZT2;
    var p = b.turn;
    if (m < 100) {
      b.k1 ^= ZP1[p * 81 + m] ^ ZP1[p * 81 + old]; b.k2 ^= ZP2[p * 81 + m] ^ ZP2[p * 81 + old];
      b.pw[p] = old; b.won = -1;
    } else if (m !== PASS) {
      var w = m - 100;
      b.wl[p]++;
      b.k1 ^= ZW1[w] ^ ZL1[p * 11 + b.wl[p]] ^ ZL1[p * 11 + b.wl[p] - 1];
      b.k2 ^= ZW2[w] ^ ZL2[p * 11 + b.wl[p]] ^ ZL2[p * 11 + b.wl[p] - 1];
      setWall(b, w, 0);
    }
  }
  function wallLegal(b, w) {          // passt und lässt beiden einen Weg
    if (!wallOpen(b, w)) return false;
    setWall(b, w, 1);
    var ok = bLen(b, 0) >= 0 && bLen(b, 1) >= 0;
    setWall(b, w, 0);
    return ok;
  }

  // Züge in sinnvoller Reihenfolge: bester Figurenzug, Blöcke nach Gewinn, übrige Figurenzüge,
  // Abwehrblöcke (machen den gefährlichsten Block des Gegners unmöglich).
  var PM = new Int16Array(8), PMD = new Int16Array(8);
  function genMoves(b, cand, defend, ttMove, ply, ctx) {
    var p = b.turn, q = 1 - p, res = [], k, j;
    var np = bPawnMoves(b, p, PM), dp = bLen(b, p), dq = bLen(b, q);
    for (k = 0; k < np; k++) { var old = b.pw[p]; b.pw[p] = PM[k]; PMD[k] = bLen(b, p); b.pw[p] = old; }
    for (k = 1; k < np; k++) for (j = k; j > 0 && PMD[j] < PMD[j - 1]; j--) {   // nach Restweg sortieren
      var t1 = PM[j]; PM[j] = PM[j - 1]; PM[j - 1] = t1; var t2 = PMD[j]; PMD[j] = PMD[j - 1]; PMD[j - 1] = t2;
    }
    if (np) res.push(PM[0]);
    if (b.wl[p] > 0 && cand > 0) {
      var n = wallScan(b, p, q, dp, dq, SCAN), list = [];
      for (k = 0; k < n; k++) if (SCAN[2 * k + 1] > 0) list.push([SCAN[2 * k], SCAN[2 * k + 1]]);
      list.sort(function (x, y) { return y[1] - x[1]; });
      for (k = 0; k < list.length && k < cand; k++) res.push(100 + list[k][0]);
    }
    for (k = 1; k < np; k++) res.push(PM[k]);
    // Vorbereitende Blöcke nur in den ersten beiden Halbzügen der Rechnung (für beide Seiten), sonst zu teuer
    if (ctx && ctx.traps && ply <= ctx.trapPly && b.wl[p] >= 2) {
      var tl = trapMoves(b, p, ctx);
      for (k = 0; k < tl.length; k++) if (res.indexOf(tl[k]) < 0) res.push(tl[k]);
    }
    if (defend && b.wl[p] > 0 && b.wl[q] > 0) {
      var nq = wallScan(b, q, p, dq, dp, SCAN), bw = -1, bg = 2;
      for (k = 0; k < nq; k++) if (SCAN[2 * k + 1] > bg) { bg = SCAN[2 * k + 1]; bw = SCAN[2 * k]; }
      if (bw >= 0) {
        var i = bw & 63, r = i >> 3, c = i & 7, opts = [bw < 64 ? 64 + i : i];
        if (bw < 64) { if (c > 0) opts.push(i - 1); if (c < 7) opts.push(i + 1); }
        else { if (r > 0) opts.push(64 + i - 8); if (r < 7) opts.push(64 + i + 8); }
        for (k = 0; k < opts.length; k++) { var mv = 100 + opts[k]; if (res.indexOf(mv) < 0 && wallLegal(b, opts[k])) res.push(mv); }
      }
    }
    // v0.7: Schutzblöcke, wie die Fallen nur in den ersten beiden Halbzügen der Rechnung
    if (ctx && ctx.guards && ply <= ctx.guardPly && b.wl[p] > 0 && b.wl[q] > 0) {
      var gl = guardMoves(b, p, ctx);
      for (k = 0; k < gl.length; k++) if (res.indexOf(gl[k]) < 0) res.push(gl[k]);
    }
    if (ttMove >= 0) { var at = res.indexOf(ttMove); if (at > 0) { res.splice(at, 1); res.unshift(ttMove); } }
    if (!res.length) res.push(PASS);
    return res;
  }

  // Transpositionstabelle
  var TT_BITS = 19, TT_SIZE = 1 << TT_BITS, TT_MASK = TT_SIZE - 1;
  var TTK = new Int32Array(TT_SIZE), TTD = new Int8Array(TT_SIZE), TTF = new Int8Array(TT_SIZE), TTV = new Int32Array(TT_SIZE), TTM = new Int16Array(TT_SIZE), TTA = new Int32Array(TT_SIZE);
  var TT_AGE = 0;

  // Stellungswiederholung: Eine Stellung, die in der Partie oder auf dem Rechenweg schon da war,
  // zählt als Remis (die Plattform wertet die dritte Wiederholung als Remis). So zieht die KI im
  // Vorteil nicht hin und her, und im Nachteil nimmt sie ein Remis gern.
  var PATH1 = new Int32Array(128), PATH2 = new Int32Array(128);
  function repeated(b, ply, ctx) {
    for (var k = ply - 2; k >= 0; k -= 2) if (PATH1[k] === b.k1 && PATH2[k] === b.k2) return true;
    return ctx.seen !== null && ctx.seen.has(b.k1 + ':' + b.k2);
  }

  function search(b, depth, alpha, beta, ply, ctx) {
    if (b.won >= 0) return -(WIN - ply);                     // der Gegner hat gerade das Ziel erreicht
    if ((++ctx.nodes & 255) === 0 && now() > ctx.deadline) ctx.abort = true;
    if (ctx.abort) return 0;
    if (repeated(b, ply, ctx)) return 0;
    PATH1[ply] = b.k1; PATH2[ply] = b.k2;
    if (depth <= 0) return evaluate(b, b.turn, ctx);
    var slot = b.k1 & TT_MASK, ttMove = -1, a0 = alpha;
    if (TTA[slot] === TT_AGE && TTK[slot] === b.k2) {
      ttMove = TTM[slot];
      if (TTD[slot] >= depth) {
        var tv = TTV[slot], tf = TTF[slot];
        if (tf === 0) return tv;
        if (tf === 1 && tv >= beta) return tv;
        if (tf === 2 && tv <= alpha) return tv;
      }
    }
    var moves = genMoves(b, ctx.cand, ctx.defend, ttMove, ply, ctx), best = -Infinity, bestMove = moves[0];
    for (var k = 0; k < moves.length; k++) {
      var m = moves[k], old = bApply(b, m);
      var val = -search(b, depth - 1, -beta, -alpha, ply + 1, ctx);
      bUndo(b, m, old);
      if (ctx.abort) return 0;
      if (val > best) { best = val; bestMove = m; }
      if (val > alpha) alpha = val;
      if (alpha >= beta) break;
    }
    TTA[slot] = TT_AGE; TTK[slot] = b.k2; TTD[slot] = depth; TTV[slot] = best; TTM[slot] = bestMove;
    TTF[slot] = best >= beta ? 1 : (best <= a0 ? 2 : 0);
    return best;
  }

  // opts (optional): { history: [Zugcodes seit Partiebeginn], first: 0|1, start: Stellung, ab der history gilt } für die Wiederholungsregel
  function chooseMove(s0, level, opts) {
    var cfg = LEVELS[level] || LEVELS.medium, b = makeBoard(s0), p = b.turn, k;
    opts = opts || {};
    var P = opts.params || PARAMS, seen = null;
    if (opts.history && opts.history.length) {
      var g = opts.start ? clone(opts.start) : newState(opts.first ? 1 : 0);
      seen = new Set();
      for (k = 0; k < opts.history.length; k++) { var kb = makeBoard(g); seen.add(kb.k1 + ':' + kb.k2); applyMove(g, opts.history[k]); }
    }
    var np = bPawnMoves(b, p, PM);
    for (k = 0; k < np; k++) if (ROW[PM[k]] === GOAL[p]) return PM[k];   // Ziel in einem Zug
    var ctx = { nodes: 0, deadline: now() + cfg.time, abort: false, cand: cfg.cand, defend: cfg.defend, P: P, seen: seen,
      traps: opts.traps === undefined ? !!cfg.traps : !!opts.traps, trapPly: cfg.trapPly === undefined ? 1 : cfg.trapPly, trapCache: new Map(), work: 0,
      guards: opts.guards === undefined ? !!cfg.traps : !!opts.guards, guardPly: 1, guardCache: new Map() };
    PATH1[0] = b.k1; PATH2[0] = b.k2;
    TT_AGE = (TT_AGE + 1) | 0;
    var root = genMoves(b, cfg.rootCand, cfg.defend, -1, 0, ctx);
    if (root.length === 1) return root[0];
    for (k = root.length - 1; k > 0; k--) { var j = Math.floor(Math.random() * (k + 1)), tmp = root[k]; root[k] = root[j]; root[j] = tmp; }
    var best = root[0], noise = cfg.noise * SCALE;
    for (var d = 1; d <= cfg.depth; d++) {
      var alpha = -Infinity, iterBest = null, iterVal = -Infinity, vals = [];
      for (k = 0; k < root.length; k++) {
        var m = root[k], old = bApply(b, m);
        var val = -search(b, d - 1, -Infinity, -alpha, 1, ctx);
        bUndo(b, m, old);
        if (ctx.abort) break;
        if (noise) val += (Math.random() * 2 - 1) * noise;
        vals.push(val);
        if (val > iterVal) { iterVal = val; iterBest = m; }
        if (val > alpha) alpha = val;
      }
      if (ctx.abort) { if (d === 1 && iterBest !== null) best = iterBest; break; }
      best = iterBest; ctx.depthDone = d; ctx.value = iterVal;
      root = [best].concat(root.filter(function (x) { return x !== best; }));
      if (iterVal >= WIN - 2000 || iterVal <= -(WIN - 2000)) break;
    }
    chooseMove.last = { depth: ctx.depthDone || 0, value: ctx.value, nodes: ctx.nodes, work: ctx.work };
    return best;
  }

  // Trainer und Simulator: Werte der Kandidatenzüge aus Sicht der Seite am Zug, in Hundertstel Schritten.
  // Züge bis margin hinter dem besten bekommen einen genauen Wert (exact), schlechtere nur eine obere Schranke;
  // das spart gegenüber einem vollen Suchfenster rund eine Halbzugtiefe.
  // opts: { history, first, start (Stellung, ab der history gilt), time (ms), maxDepth, margin, only: [Züge],
  //         extra: [Züge], keepTT, onDepth(result) nach jeder fertigen Tiefe }
  function analyze(s0, opts) {
    opts = opts || {};
    var b = makeBoard(s0), p = b.turn, k, seen = null;
    if (opts.history && opts.history.length) {
      var g = opts.start ? clone(opts.start) : newState(opts.first ? 1 : 0);
      seen = new Set();
      for (k = 0; k < opts.history.length; k++) { var kb = makeBoard(g); seen.add(kb.k1 + ':' + kb.k2); applyMove(g, opts.history[k]); }
    }
    var ctx = { nodes: 0, deadline: Infinity, abort: false, cand: 10, defend: true, P: opts.params || PARAMS, seen: seen,
      traps: opts.traps !== false, trapPly: opts.trapPly === undefined ? 1 : opts.trapPly, trapCache: new Map(), work: 0,
      guards: opts.guards !== false, guardPly: opts.guardPly === undefined ? 1 : opts.guardPly, guardCache: new Map() };
    PATH1[0] = b.k1; PATH2[0] = b.k2;
    if (!opts.keepTT) TT_AGE = (TT_AGE + 1) | 0;
    var root;
    if (opts.only) root = opts.only.filter(function (m) { return isLegal(s0, m); });
    else {
      root = genMoves(b, 16, true, -1, 0, ctx);
      var np = bPawnMoves(b, p, PM);
      for (k = 0; k < np; k++) if (root.indexOf(PM[k]) < 0) root.push(PM[k]);
      (opts.extra || []).forEach(function (m) { if (root.indexOf(m) < 0 && isLegal(s0, m)) root.push(m); });
    }
    if (!root.length) return { moves: [], depth: 0, nodes: 0, ms: 0 };
    var margin = opts.margin === undefined ? 300 : opts.margin;
    var result = null, maxD = opts.maxDepth || 60, t0 = now();
    for (var d = 1; d <= maxD; d++) {
      ctx.deadline = d === 1 ? Infinity : t0 + (opts.time || 900);
      var vals = [], best = -Infinity;
      for (k = 0; k < root.length; k++) {
        var m = root[k], old = bApply(b, m);
        var lo = (best === -Infinity || margin < 0) ? -Infinity : best - margin;
        var val = -search(b, d - 1, -Infinity, -lo, 1, ctx);
        bUndo(b, m, old);
        if (ctx.abort) break;
        vals.push({ m: m, v: val, exact: !(val <= lo) });
        if (val > best) best = val;
      }
      if (ctx.abort) break;
      vals.sort(function (x, y) { return y.v - x.v; });
      for (k = 0; k < vals.length && k < 6; k++) vals[k].pv = pvLine(b, vals[k].m, Math.min(12, d + 1));
      result = { moves: vals, depth: d, nodes: ctx.nodes, ms: Math.round(now() - t0) };
      if (opts.onDepth) opts.onDepth(result);
      root = vals.map(function (x) { return x.m; });
      if (vals[0].v >= WIN - 2000 || vals.every(function (x) { return x.v <= -(WIN - 2000); })) break;
    }
    if (result) { result.final = true; result.ms = Math.round(now() - t0); result.nodes = ctx.nodes; result.work = ctx.work; }
    return result;
  }

  // Erwartete Fortsetzung: ab dem Zug first den in der Transpositionstabelle gemerkten besten Zügen folgen
  var PMX = new Int16Array(8);
  function ttLegal(b, m) {
    var p = b.turn;
    if (m >= 0 && m < 81) { var n = bPawnMoves(b, p, PMX); for (var i = 0; i < n; i++) if (PMX[i] === m) return true; return false; }
    if (m >= 100 && m < 228) return b.wl[p] > 0 && wallLegal(b, m - 100);
    return false;
  }
  function pvLine(b, first, maxLen) {
    var line = [first], olds = [bApply(b, first)], seenK = {};
    seenK[b.k1 + ':' + b.k2] = 1;
    while (line.length < maxLen && b.won < 0) {
      var slot = b.k1 & TT_MASK;
      if (TTA[slot] !== TT_AGE || TTK[slot] !== b.k2) break;
      var m = TTM[slot];
      if (!ttLegal(b, m)) break;
      line.push(m); olds.push(bApply(b, m));
      var kk = b.k1 + ':' + b.k2;
      if (seenK[kk]) break;
      seenK[kk] = 1;
    }
    for (var i = line.length - 1; i >= 0; i--) bUndo(b, line[i], olds[i]);
    return line;
  }
  // Stärkste Falle für p in der Stellung s0 (egal, wer am Zug ist), oder null
  function bestTrap(s0, p) {
    var b = makeBoard(s0), q = 1 - p, list = trapScan(b, p, null);
    if (!list.length) return null;
    var t = list[0], dq = bLen(b, q), dp = bLen(b, p), n1 = wallScan(b, p, q, dp, dq, SCAN2), single = 0, k;
    for (k = 0; k < n1; k++) if (SCAN2[2 * k + 1] > single) single = SCAN2[2 * k + 1];
    setWall(b, t[0], 1); setWall(b, t[3], 1);
    var dq2 = bLen(b, q), dp2 = bLen(b, p);
    setWall(b, t[3], 0); setWall(b, t[0], 0);
    return { first: 100 + t[0], second: 100 + t[3], total: t[1], firstGain: t[2], secondGain: t[4], single: single, dq: dq, dq2: dq2, dp: dp, dp2: dp2 };
  }

  // Für Tests: Bestandteile der Bewertung aus Sicht der Seite am Zug
  function explain(s0) {
    var b = makeBoard(s0), p = b.turn, q = 1 - p, dp = bLen(b, p), dq = bLen(b, q);
    var ctx = { P: PARAMS, seen: null };
    wallScan(b, p, q, dp, dq, SCAN); var hq = LAST_HARD; wallScan(b, q, p, dq, dp, SCAN); var hp = LAST_HARD;
    return { dp: dp, dq: dq, Wp: b.wl[p], Wq: b.wl[q], Gp: bestGain(b, p, q, dp, dq), Gq: bestGain(b, q, p, dq, dp), hq: hq, hp: hp, eval: evaluate(b, p, ctx) / SCALE };
  }

  return {
    explain: explain, analyze: analyze, bestGuard: bestGuard, PARAMS_V04: PARAMS_V04, bestTrap: bestTrap, WIN: WIN, SURE: SURE, SCALE: SCALE,
    GOAL: GOAL, PASS: PASS, COLS: COLS,
    newState: newState, clone: clone, canStep: canStep, pathLen: pathLen, shortestPath: shortestPath,
    pawnMoves: pawnMoves, wallCode: wallCode, decodeWall: decodeWall, wallProblem: wallProblem,
    applyMove: applyMove, undoMove: undoMove, legalMoves: legalMoves, isLegal: isLegal,
    cellName: cellName, moveName: moveName, parseMove: parseMove, chooseMove: chooseMove, LEVELS: LEVELS, PARAMS: PARAMS
  };
})();

export default Blockli;
