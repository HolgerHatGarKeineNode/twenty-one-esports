/* Hero objects, one per motif: the real thing from the product, at hero scale.
 * Each builder returns HTML for a `.hero` container (container-type: size);
 * heroes.css lays it out for portrait and landscape zones. Boards are real
 * positions (Kit.GAMES), UI text is the app's own (ui-strings.js). */
(function () {
  'use strict';
  const K = window.Kit, U = window.UI;
  const A = '../../assets/brand';
  const av = (n) => `<img class="av" src="${A}/avatars/${n}.svg" alt="">`;
  const crest = (t) => `<img class="crest-img" src="${A}/clans/${t}.svg" alt="">`;

  /* board markup from a fen, with the cube frame and a HUD label */
  function boardHTML(fen, o = {}) {
    const el = document.createElement('div');
    K.board(el, fen, o);
    return `<div class="bd cube${o.lit ? ' lit' : ''}" ${o.attr || ''}>${el.outerHTML}</div>`;
  }
  const hudTag = (text, cls = '') => `<div class="hud-tag ${cls}">${K.esc(text)}</div>`;
  const sideToMove = (fen) => (fen.split(' ')[1] === 'w' ? 'white' : 'black');
  const moveNo = (fen) => fen.split(' ')[5];

  const H = {};

  H.login = (lang) => `
    <div class="h-login">
      <div class="key-item">
        <div class="hud"><i></i><i></i><i></i><i></i></div>
        <svg class="pix-key" viewBox="0 0 16 9" shape-rendering="crispEdges" aria-hidden="true">
          <path fill="#F7931A" d="M2 1h4v1h1v2h8v2h-1v2h-2V6h-1v1h-2V6H7v2H6v1H2V8H1V2h1z"/>
          <path fill="#F9B25F" d="M2 1h4v1H2zM1 2h1v2H1zM7 4h8v1H7z"/>
          <path fill="#B9640A" d="M2 8h4v1H2zM6 7h1v1H6zM13 6h1v2h-1zM10 6h1v1h-1z"/>
          <path fill="#0A0A0B" d="M3 3h2v3H3z"/>
        </svg>
      </div>
      <div class="ui-card cube login-card">
        <div class="lc-title">${U('logInToPlay', lang)}</div>
        <div class="btn primary">${U('nostrExt', lang)}</div>
        <div class="lc-note">${U('nostrFor', lang)}</div>
        <div class="btn ghost">${U('google', lang)}</div>
        <div class="lc-foot">${U('noPassword', lang)}</div>
      </div>
    </div>`;

  H.blitz = (lang) => {
    const g = K.GAMES.legal;
    const san = g.san.split(' ');
    let moves = '';
    for (let i = 0; i < san.length; i += 2) {
      const last = i + 2 >= san.length;
      moves += `<span class="mn">${i / 2 + 1}.</span><span class="${last && !san[i + 1] ? 'hot' : ''}">${K.sanLocal(san[i], lang)}</span><span class="${last && san[i + 1] ? 'hot' : ''}">${san[i + 1] ? K.sanLocal(san[i + 1], lang) : ''}</span>`;
    }
    return `
    <div class="h-blitz">
      <div class="bwrap" data-bleed="left">${boardHTML(g.fen, { last: ['c3', 'd5'], mate: 'e7', lit: true })}</div>
      <div class="side">
        <div class="clock"><span class="who">${av('satsjaeger')}<b>satsjaeger</b></span><span class="t">3:58</span></div>
        <div class="moves">${moves}</div>
        <div class="clock dim"><span class="who">${av('hodlqueen')}<b>hodlqueen</b></span><span class="t">2:41</span></div>
        <div class="mode">${U('blitzLive', lang)}</div>
      </div>
    </div>`;
  };

  H.daily = (lang) => {
    const g = K.GAMES.qgd;
    const san = g.san.split(' ');
    const days = lang === 'de' ? ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    let rows = '';
    for (let d = 0; d < 6; d++) {
      rows += `<div class="day"><span class="dn">${days[d]}</span><span class="mv">${d + 1}.${K.sanLocal(san[2 * d], lang)}</span><span class="mv">${K.sanLocal(san[2 * d + 1], lang)}</span></div>`;
    }
    rows += `<div class="day now"><span class="dn">${days[6]}</span><span class="mv">7.</span><span class="mv you">${U('yourMove', lang)}</span></div>`;
    return `
    <div class="h-daily">
      <div class="bwrap">${boardHTML(g.fen, { last: ['b8', 'd7'] })}</div>
      <div class="days">${rows}</div>
      <div class="dm ui-card">
        ${av('satsjaeger')}
        <div><div class="dm-from">Nostr DM</div><div class="dm-text">${U('dailyVs', lang)}</div></div>
      </div>
    </div>`;
  };

  H.clans = (lang) => {
    const others = [['LSR', 'Laser Eyes'], ['LNB', 'Lightning Boost'], ['STK', 'Stack Sats Crew'], ['HDL', 'HODL Rockets'], ['B21', 'Block 21'], ['NCE', 'Nonce Hunters']];
    const wall = others.map(([t, n]) => `<div class="tile"><div class="tcrest">${crest(t)}</div><span>${t}</span></div>`).join('');
    return `
    <div class="h-clans">
      <div class="clan-card ui-card">
        <div class="crest cube">${crest('OPS')}</div>
        <div class="cc-body">
          <div class="cc-name">Orange Pill Squad</div>
          <div class="cc-tag"><span class="chip">OPS</span><span class="k">${U('tag', lang)}</span></div>
          <div class="cc-members">${['satsjaeger', 'kai_blitz', 'zap_zoe'].map(av).join('')}</div>
          <div class="btn primary">${U('createJoinLink', lang)}</div>
        </div>
      </div>
      <div class="wall">${wall}</div>
    </div>`;
  };

  H.opensource = (lang) => {
    /* resources/js/sanNotation.js lines 19-24, verbatim; highlighted by a tiny tokenizer. */
    const src = [
      'export function displaySan(san, locale = pageLocale()) {',
      "    const letters = LETTERS[(locale || '').slice(0, 2)];",
      '    if (!san || !letters) return san;',
      '',
      '    return san.replace(/^[KQRBN]|=[QRBN]/, (piece) => piece.replace(/[KQRBN]/, (ch) => letters[ch]));',
      '}',
    ];
    const hl = (line) => K.esc(line)
      .replace(/\b(export|function|const|if|return)\b/g, '<span class="c-k">$1</span>')
      .replace(/(&#39;&#39;|'')/g, '<span class="c-s">$1</span>')
      .replace(/\b(displaySan|pageLocale|slice|replace)\b/g, '<span class="c-f">$1</span>');
    const body = src.map((l, i) => `<div class="ln"><i>${19 + i}</i><code>${hl(l) || ' '}</code></div>`).join('');
    return `
    <div class="h-os">
      <div class="editor ui-card cube">
        <div class="ed-bar">resources/js/sanNotation.js</div>
        <div class="ed-body">${body}</div>
      </div>
      <div class="footer-strip">
        <span>${U('rules', lang)}</span><span>${U('howVerified', lang)}</span><span class="on">${U('openSource', lang)}</span><span>${U('legal', lang)}</span>
      </div>
      <div class="repo">github.com/HolgerHatGarKeineNode/twenty-one-esports</div>
    </div>`;
  };

  H.tournaments = (lang) => {
    const P = [['satsjaeger', 'satsjaeger'], ['kai_blitz', 'kai_blitz'], ['hodlqueen', 'hodlqueen'], ['zap_zoe', 'zap_zoe'], ['taproot_tim', 'taproot_tim'], ['lnurl_lena', 'lnurl_lena'], ['orange_olga', 'orange_olga'], ['hodl_hanna', 'hodl_hanna']];
    const slot = (p, cls = '') => `<div class="slot ${cls}">${p ? av(p[0]) + `<b>${p[1]}</b>` : '<span class="q">?</span>'}</div>`;
    const r1 = [0, 2, 4, 6].map((i) => `<div class="match">${slot(P[i], i === 0 || i === 4 ? 'won' : 'out')}${slot(P[i + 1], i === 2 || i === 6 ? 'won' : 'out')}</div>`).join('');
    const sf = `<div class="match">${slot(P[0], 'won')}${slot(P[3], 'out')}</div><div class="match">${slot(P[4], 'live')}${slot(P[7], 'live')}</div>`;
    const fin = `<div class="match">${slot(P[0], 'won')}${slot(null, 'open')}</div>`;
    return `
    <div class="h-tour">
      <div class="bracket cube">
        <div class="col"><div class="rh">${U('round1', lang)}</div>${r1}</div>
        <div class="col"><div class="rh">${U('semifinal', lang)}</div>${sf}</div>
        <div class="col"><div class="rh">${U('final', lang)}</div>${fin}</div>
      </div>
      <div class="tag-open">${U('openForSignup', lang)}</div>
    </div>`;
  };

  H.watch = (lang) => {
    const tl = [
      [K.snap('opera@24').fen, K.snap('opera@24').last],
      [K.GAMES.italian.fen, ['c5', 'b4']],
      [K.GAMES.qgd.fen, ['b8', 'd7']],
      [K.snap('legal@10').fen, K.snap('legal@10').last],
    ];
    const cells = tl.map(([f, last]) => {
      const side = U(sideToMove(f), lang);
      return `<div class="cell"><div class="live-row"><i class="dot"></i>${K.esc(U('liveMove', lang, { move: moveNo(f), side }))}</div>${boardHTML(f, { last })}</div>`;
    }).join('');
    return `<div class="h-watch"><div class="wall-head">${U('liveGames', lang)}</div><div class="wall">${cells}</div></div>`;
  };

  H.invite = (lang) => {
    const start = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
    return `
    <div class="h-invite">
      <div class="linkbox ui-card">
        <div class="lb-note">${U('inviteReady', lang)}</div>
        <div class="lb-row"><span class="lb-url">esports.einundzwanzig.space/i/…</span><span class="btn primary sm">${U('copyInvite', lang)}</span></div>
      </div>
      <div class="icard cube">
        <div class="ic-left">
          <div class="ic-who">${av('satsjaeger')}<b>satsjaeger</b></div>
          <div class="ic-head">${U('beatMe', lang)}</div>
          <div class="ic-sub">${U('tapSeat', lang)}</div>
        </div>
        <div class="ic-board">${boardHTML(start, {})}</div>
      </div>
    </div>`;
  };

  /* ===== board games: Mühle and Dame (positions from lib/boardgames.data.js) ===== */
  function boardGameHero(game, n, lang, o) {
    const B = window.BG;
    const p = B.ply(game, n);
    const plies = B.DATA[game].plies;
    let moves = '';
    for (let i = o.from; i <= n; i += 2) {
      const w = plies[i], b = plies[i + 1];
      moves += `<span class="mn">${(i + 1) / 2}.</span><span class="${i === n ? 'hot' : ''}">${K.esc(w.move)}</span><span class="${i + 1 === n ? 'hot' : ''}">${b && i + 1 <= n ? K.esc(b.move) : ''}</span>`;
    }
    const board = `<div class="bd cube lit">${B.svg(game, p.pos, o.draw(p))}</div>`;
    return `
    <div class="h-blitz h-bgame h-bg-${game}">
      <div class="bwrap">${board}</div>
      <div class="side">
        <div class="clock${o.run === 'w' ? '' : ' dim'}"><span class="who">${av(o.white)}<b>${o.white}</b></span><span class="t">${o.clocks[0]}</span></div>
        <div class="moves">${moves}</div>
        <div class="clock${o.run === 'b' ? '' : ' dim'}"><span class="who">${av(o.black)}<b>${o.black}</b></span><span class="t">${o.clocks[1]}</span></div>
        <div class="mode">${U(o.name, lang)}</div>
      </div>
    </div>`;
  }

  /* Mühle, ply 18: Black places its last man on c5, closes c3-c4-c5 and takes g4. */
  H.morris = (lang) => boardGameHero('morris', 18, lang, {
    from: 9, name: 'gameMorris', white: 'kai_blitz', black: 'zap_zoe', clocks: ['4:31', '4:12'], run: 'w',
    draw: (p) => ({ last: p.path, mill: window.BG.millsAt(window.BG.pieces('morris', p.pos), 'c5'), taken: window.BG.taken('morris', 18) }),
  });

  /* Dame, ply 23: one move, a chain of three captures a5xc7xe5xg3. */
  H.checkers = (lang) => boardGameHero('checkers', 23, lang, {
    from: 21, name: 'gameCheckers', white: 'hodlqueen', black: 'taproot_tim', clocks: ['3:47', '3:05'], run: 'b',
    draw: (p) => ({ path: p.path, last: p.path, taken: window.BG.taken('checkers', 23) }),
  });

  /* ===== mempool, on the stream, tournaments live (motifs 11-13) ===== */

  /* Game marks as the strip draws them: the knight of components/block-strip.blade.php,
     the stroke icons of components/icon.blade.php. */
  const KNIGHT = '<svg class="gl" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M17 18C17.5 12 17 6.5 12.5 4L11.5 2L10 4.2C8 5.2 6 7.8 4.6 10.2C4.3 10.9 4.7 11.7 5.4 11.9L6.4 12.3C7.1 12.5 7.9 12.2 8.3 11.6L9.6 10.6C10.3 10.3 10.8 10.4 11.2 10.8C9.4 12.8 8 15 7.6 18ZM9.9 6.2a.9 .9 0 1 0 .01 0ZM5 19.5h14V22H5z"></path></svg>';
  const ICON = {
    rl: '<circle cx="12" cy="12" r="9"></circle><path d="M12 3v4l-3.5 2.5M12 7l3.5 2.5M8.5 9.5 7 14l5 3 5-3-1.5-4.5M3.5 10.5 7 14M20.5 10.5 17 14M12 17v4"></path>',
    fc: '<circle cx="12" cy="12" r="9"></circle><path d="m12 7 4 3-1.5 4.5h-5L8 10z"></path><path d="M12 3v4M16 10l4.5-1.5M14.5 14.5l2.5 4M9.5 14.5 7 18.5M8 10 3.5 8.5"></path>',
    morris: '<rect x="3" y="3" width="18" height="18"></rect><rect x="7.5" y="7.5" width="9" height="9"></rect><path d="M12 3v4.5M12 16.5V21M3 12h4.5M16.5 12H21"></path>',
    checkers: '<ellipse cx="12" cy="9" rx="8" ry="3.5"></ellipse><path d="M4 9v5c0 1.9 3.6 3.5 8 3.5s8-1.6 8-3.5V9"></path><ellipse cx="12" cy="9" rx="4" ry="1.6"></ellipse>',
    // Age of Empires II: the castle keep with its gate (components/icon.blade.php 'castle')
    aoe2: '<path d="M4 21V8h3v3h2.5V8h5v3H17V8h3v13zM10 21v-4a2 2 0 0 1 4 0v4"></path>',
  };
  const glyphOf = (g) => (g === 'chess' ? KNIGHT : `<svg class="gl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICON[g]}</svg>`);
  /* Game names of the legend (GameNames::game, lang/de.json). */
  const GAME = { chess: { en: 'Chess', de: 'Schach' }, rl: { en: 'Rocket League', de: 'Rocket League' }, fc: { en: 'EA Sports FC', de: 'EA Sports FC' }, morris: { en: 'Nine Men\'s Morris', de: 'Mühle' }, checkers: { en: 'Checkers', de: 'Dame' } };
  /* Carbon's short diffForHumans, as the cubes print `when` (MatchBlocks::oneVsOne). */
  const AGO = { '2m': { en: '2m ago', de: 'vor 2 Min.' }, '9m': { en: '9m ago', de: 'vor 9 Min.' }, '1h': { en: '1h ago', de: 'vor 1 Std.' }, '3h': { en: '3h ago', de: 'vor 3 Std.' }, '5h': { en: '5h ago', de: 'vor 5 Std.' } };

  /* The strip's sample: kit players and clans only. Finished oldest to newest (the newest
     sits next to the divider), then running and next. Only the rated Rocket League 1v1
     mined: rated chess and rated board games are not open (pages/⚡mining, "not open"),
     and casual never mines (config/season.php `casual`). Block 1 is the first block a
     season mines ("The first fair rated win mines block 1.", pages/⚡mining). */
  const STRIP = [
    { g: 'chess', num: '#36', casual: true, st: 'fin', mode: 'Blitz 5+3', score: '1–0', who: 'satsjaeger', when: '5h', sides: [['satsjaeger', 1], ['hodlqueen', 0]] },
    { g: 'checkers', num: '#7', casual: true, st: 'fin', mode: 'Blitz 5+3', score: '0–1', who: 'taproot_tim', when: '3h', sides: [['orange_olga', 0], ['taproot_tim', 1]] },
    { g: 'fc', num: '#39', casual: true, st: 'fin', mode: 'FC26 1v1', score: '2 : 1', who: 'STK', when: '1h', sides: [['STK', 1, 'clan'], ['B21', 0, 'clan']] },
    { g: 'morris', num: '#12', casual: true, st: 'fin', mode: 'Blitz 5+3', score: '1–0', who: 'kai_blitz', when: '9m', sides: [['kai_blitz', 1], ['zap_zoe', 0]] },
    { g: 'rl', num: '#41', casual: false, st: 'fin', mode: 'RL 1v1', score: '3 : 1', who: 'OPS', when: '2m', sides: [['OPS', 1, 'clan'], ['LSR', 0, 'clan']], block: 1 },
    { g: 'chess', num: '#37', casual: true, st: 'live', mode: 'Blitz 5+3', move: 23, turn: 'lnurl_lena', lvl: 29, sides: [['lnurl_lena', 0], ['hodl_hanna', 0]] },
    { g: 'morris', num: '#13', casual: true, st: 'live', mode: 'Blitz 5+3', move: 14, turn: 'dca_doris', lvl: 45, sides: [['dca_doris', 0], ['sat_sepp', 0]] },
    { g: 'rl', num: '#42', casual: false, st: 'next', mode: 'RL 1v1', score: 'BO5', who: 'ladder', when: '20:00', sides: [['HDL', 0, 'clan'], ['NCE', 0, 'clan']] },
  ];
  const face = (n, kind) => (kind === 'clan' ? `<img class="fc" src="${A}/clans/${n}.svg" alt="">` : `<img class="fc" src="${A}/avatars/${n}.svg" alt="">`);
  /* One column of the strip: number + casual tag, the cube, the two sides, the chain stamp. */
  function stripCol(c, lang, o = {}) {
    const who = c.st === 'live' ? U('nameTurn', lang, { name: c.turn }) : c.st === 'next' ? U('ladderWord', lang) : c.who;
    const when = c.st === 'live' ? U('liveWord', lang) : c.st === 'next' ? U('atTime', lang, { time: c.when }) : AGO[c.when][lang];
    const score = c.st === 'live' ? U('moveN', lang, { n: c.move }) : c.score;
    const cls = ['mcube', 'cube', 'g-' + c.g, 'is-' + c.st, c.casual ? 'is-casual' : '', c.block && o.mined !== false ? 'is-mined' : ''].filter(Boolean).join(' ');
    const sides = c.sides.map(([n, won, kind], i) => `<span class="sd${won && c.st === 'fin' ? ' won' : ''}">${i ? '<i>vs</i>' : ''}${face(n, kind)}<span class="nm">${K.esc(n)}</span></span>`).join('');
    const stamp = c.block && o.mined !== false ? `<span class="stamp"><i class="mini"></i>${K.esc(U('blockHeight', lang, { height: c.block }))}</span>` : '';
    return `<div class="mcol${c.block ? ' newest' : ''}" data-st="${c.st}">
      <span class="mh"><span class="num">${c.num}</span>${c.casual ? `<span class="tag">${U('casualTag', lang)}</span>` : ''}</span>
      <div class="${cls}" style="--lvl:${c.lvl || 0}%">${c.st === 'live' ? '<i class="fill"></i>' : ''}
        <span class="r1">${glyphOf(c.g)}<span>${K.esc(c.mode)}</span></span>
        <span class="sc${c.st !== 'fin' ? ' word' : ''}">${K.esc(score)}</span>
        <span class="wh">${K.esc(who)}</span>
        <span class="wn">${c.st === 'live' ? '<i class="dot"></i>' : ''}${K.esc(when)}</span>
      </div>
      <span class="cap">${sides}</span>
      <span class="chain">${stamp}</span>
    </div>`;
  }
  const STRIP_FIN = STRIP.filter((c) => c.st === 'fin'), STRIP_RUN = STRIP.filter((c) => c.st !== 'fin');
  function stripHTML(lang, nFin, nRun, o = {}) {
    const fin = STRIP_FIN.slice(-nFin).map((c) => stripCol(c, lang, o)).join('');
    const run = STRIP_RUN.slice(0, nRun).map((c) => stripCol(c, lang, o)).join('');
    return `<div class="mrow"><div class="mgrp">${fin}</div><span class="mdiv"></span><div class="mgrp">${run}</div></div>`;
  }
  const legendHTML = (lang, games) => games.map((g) => `<span class="lk g-${g}"><i class="chip"></i>${glyphOf(g)}<span>${K.esc(GAME[g][lang])}</span></span>`).join('');

  H.mempool = (lang) => `
    <div class="h-mempool">
      <div class="mp-head"><b>${U('mempool', lang)}</b><span>${K.esc(U('mpLeadLive', lang))}</span></div>
      <div class="mp-strip">
        <div class="v v5">${stripHTML(lang, 4, 2)}</div>
        <div class="v v4">${stripHTML(lang, 3, 1)}</div>
        <div class="v v3">${stripHTML(lang, 2, 1)}</div>
      </div>
      <div class="mp-block ui-card">
        <div class="bk-cube cube"><i class="mini"></i></div>
        <div class="bk-t"><span class="lab">${K.esc(U('seasonChain', lang))}</span><b>${K.esc(U('blockHeight', lang, { height: 1 }))}</b><span class="sub">${K.esc(U('minedAsBlock', lang, { height: 1 }))}</span></div>
        <div class="bk-who">${face('OPS', 'clan')}<b>OPS</b></div>
      </div>
      <div class="mp-legend">${legendHTML(lang, ['chess', 'morris', 'checkers', 'rl', 'fc'])}<span class="lk ch"><i class="mini"></i><span>${K.esc(U('ratedWinsMine', lang))}</span></span></div>
    </div>`;

  /* ---- the stream frame: the channel as it airs, English only (the stream's own texts,
     resources/views/stream/rotation/*: "LATEST WIN · GG", "beat :name", "+N casual Elo",
     "Climbers of the week"). The casual Elo is the app's EloRating with season.casual
     (start 1000, provisional K 40): one win against a new player is +20, three are +57,
     two +39. ---- */
  function tvHTML(inner, lang, o = {}) {
    return `<div class="tv${o.cls ? ' ' + o.cls : ''}"><div class="tv-bar"><span class="tv-bug"><i class="mark">21</i>TWENTY ONE ESPORTS</span><span class="tv-live"><i class="dot"></i>LIVE</span></div><div class="tv-body">${inner}</div></div>`;
  }
  const crown = '<svg class="crown" viewBox="0 0 12 8" shape-rendering="crispEdges" aria-hidden="true"><path fill="var(--btc)" d="M0 1h1v1h1v1h1V1h1v1h1V0h2v2h1V1h1v2h1V2h1V1h1v7H0z"/><path fill="var(--btc-hi)" d="M1 6h10v1H1z"/></svg>';
  const bigFace = (n, cls = '') => `<div class="bf ${cls}">${crown}<img src="${A}/avatars/${n}.svg" alt=""></div>`;

  H.onstream = (lang) => {
    const win = `<div class="win">${bigFace('satsjaeger')}<div class="wt"><span class="lab">LATEST WIN · GG</span><b class="nm">satsjaeger</b><span class="bt">beat hodlqueen</span><span class="elo">+20 casual Elo</span></div></div>`;
    const thumb = (n, label, value, cls = '') => `<div class="th ${cls}"><span class="thl">${label}</span><div class="thb"><img src="${A}/avatars/${n}.svg" alt=""><b>${value}</b></div></div>`;
    return `
    <div class="h-onstream">
      <div class="os-tv">${tvHTML(win, lang)}</div>
      <div class="os-thumbs">
        ${thumb('kai_blitz', 'Climbers of the week', '+57 casual Elo')}
        ${thumb('zap_zoe', 'Season chain', 'Block 1', 'blk')}
        ${thumb('hodlqueen', 'Final', 'hodlqueen', 'fin')}
      </div>
    </div>`;
  };

  /* ---- tournaments live: semifinals decided, the final decided, the champion; then the
     next tournament open for sign-up. Names are kit players; the bracket is a mock of the
     live bracket slides (plan stream-slides-stolz-und-turniere, P5). ---- */
  H.livecup = (lang) => {
    const slot = (n, cls = '') => `<div class="ls ${cls}"><img src="${A}/avatars/${n}.svg" alt=""><b>${n}</b></div>`;
    const sf = `<div class="lcol"><span class="rh">SEMIFINAL</span><div class="lm">${slot('kai_blitz', 'won')}${slot('taproot_tim', 'out')}</div><div class="lm">${slot('hodlqueen', 'won')}${slot('zap_zoe', 'out')}</div></div>`;
    const fin = `<div class="lcol"><span class="rh">FINAL</span><div class="lm">${slot('kai_blitz', 'won')}${slot('hodlqueen', 'out')}</div></div>`;
    const champ = `<div class="lcol champ"><span class="rh">CHAMPION</span>${bigFace('kai_blitz', 'sm')}<b class="cn">kai_blitz</b></div>`;
    const seats = ['satsjaeger', 'lnurl_lena', 'orange_olga', 'sat_sepp', 'dca_doris', null, ''].map((n) => (n ? `<i class="seat"><img src="${A}/avatars/${n}.svg" alt=""></i>` : n === null ? `<i class="seat you"><span>${K.esc(U('yourSpot', lang))}</span></i>` : '<i class="seat open"></i>')).join('');
    return `
    <div class="h-livecup">
      <div class="lc-tv">${tvHTML(`<div class="lbr">${sf}${fin}${champ}</div>`, lang)}</div>
      <div class="lc-next ui-card">
        <div class="nx-h"><b>${K.esc(U('nextTournament', lang))}</b><span class="open"><i class="dot"></i>${K.esc(U('signupOpen', lang))}</span></div>
        <div class="seats">${seats}</div>
        <div class="btn primary">${K.esc(U('signUp', lang))}</div>
      </div>
    </div>`;
  };

  /* ---- Age of Empires II (motif 14): the lobby tournament (P10, branch 210edd80), drawn as
     its lobby card renders it (components/⚡tournament-lobbies, App\Support\Tournaments\Lobbies
     ::facts(), LobbyResults): one lobby of six, decided. The two allies still standing are #1
     together ("Shared place 1: …"), the others ranked by the order they were defeated (#3 …
     #6, as the app ranks a shared place 1); the proof is the end-screen screenshot, then the
     director's decision. The settings are config/esports.php lobby_rules.age-of-empires-2.lobby;
     the map size follows the players (2 Tiny … 7-8 Large). The cover is cropped to its logo
     band. Players are kit names with their pixel avatars. ---- */
  const AOE = {
    lobby: 1,
    winners: ['satsjaeger', 'hodlqueen'],
    // the others with their place, the last one out first
    out: [['kai_blitz', 3], ['zap_zoe', 4], ['taproot_tim', 5], ['orange_olga', 6]],
    sizes: [['sizeTiny', '2'], ['sizeSmall', '3'], ['sizeMedium', '4'], ['sizeNormal', '5–6'], ['sizeLarge', '7–8']],
    // config values the card prints as they are (lobby_rules.age-of-empires-2.lobby)
    map: 'Arabia', population: 200, hours: 2, delayMinutes: 2, restartMinutes: 5,
  };
  AOE.players = AOE.winners.length + AOE.out.length;
  AOE.size = 3; // index in sizes: 6 players play on Normal
  const COVER = '../../../../public/images/games/age-of-empires-2-800.jpg';
  const TICK = '<svg class="tk" viewBox="0 0 8 8" shape-rendering="crispEdges" aria-hidden="true"><path fill="currentColor" d="M6 1h1v2H6v1H5v1H4v1H3v1H2V6H1V4h1v1h1V4h1V3h1V2h1z"/></svg>';
  // two pixel chain links: the alliance between the two winners
  const LINK = '<svg class="lk" viewBox="0 0 14 6" shape-rendering="crispEdges" aria-hidden="true"><g fill="none" stroke="currentColor"><rect x="1.5" y="1.5" width="6" height="3"/><rect x="6.5" y="1.5" width="6" height="3"/></g></svg>';
  // "Lobby 1 · 6 players", as the card's header
  const aoeHead = (lang, n = AOE.players) => `${K.esc(U('lobbyNumber', lang, { number: AOE.lobby }))} · ${K.esc(U('countPlayers', lang, { count: n }))}`;
  const place = (n) => `<span class="pl${n === 1 ? ' p1' : ''}">#${n}</span>`;
  function aoeResult(lang) {
    const win = (n) => `<div class="ar-w">${av(n)}<span class="nm">${place(1)}<b>${K.esc(n)}</b></span></div>`;
    const out = AOE.out.map(([n, p]) => `<span class="ar-o">${place(p)}${av(n)}<b>${K.esc(n)}</b></span>`).join('');
    const proof = ['endScreen', 'reportedWaiting'].map((k) => `<span class="st done">${TICK}<span>${K.esc(U(k, lang))}</span></span>`).join('');
    return `<div class="ae-result ui-card">
      <div class="ar-h"><b>${aoeHead(lang)}</b><span class="chip">${K.esc(U('decided', lang))}</span></div>
      <div class="ar-win">${win(AOE.winners[0])}<div class="ar-link">${LINK}</div>${win(AOE.winners[1])}
        <span class="sh">${K.esc(U('sharedPlace1', lang, { names: AOE.winners.join(', ') }))}</span></div>
      <div class="ar-out">${out}</div>
      <div class="ar-proof">${proof}</div>
    </div>`;
  }
  function aoeSettings(lang) {
    const row = (k, v, cls = '') => `<div class="as-r${cls}"><span class="k">${K.esc(U(k, lang))}</span><b>${K.esc(v)}</b></div>`;
    const sizes = AOE.sizes.map(([k, n], i) => `<span class="sz${i === AOE.size ? ' on' : ''}"><b>${K.esc(U(k, lang))}</b><i>${n}</i></span>`).join('');
    const minutes = (m) => U('countMinutes', lang, { count: m });
    return `<div class="ae-settings ui-card">
      <div class="as-h"><b>${K.esc(U('settingsWord', lang))}</b><span>${aoeHead(lang)}</span></div>
      ${row('mapWord', AOE.map)}
      ${row('mapSize', U(AOE.sizes[AOE.size][0], lang), ' as-size')}
      <div class="as-scale">${sizes}</div>
      ${row('lockTeams', U('off', lang), ' as-dip')}
      ${row('alliedVictory', U('on', lang), ' as-dip')}
      ${row('victory', U('timeLimitAt', lang, { time: U('countHours', lang, { count: AOE.hours }) }))}
      ${row('civs', U('civsFree', lang), ' as-minor')}
      ${row('population', String(AOE.population), ' as-minor')}
      ${row('spectatorDelay', minutes(AOE.delayMinutes), ' as-minor')}
      ${row('restart', U('restartOnce', lang, { time: minutes(AOE.restartMinutes) }), ' as-minor as-extra')}
    </div>`;
  }
  const aoeTags = (lang) => `<div class="ae-tags"><span class="mk">${glyphOf('aoe2')}<b>AoE2</b></span><span>${K.esc(U('freeForAll', lang))}</span><span>${K.esc(U('alliedVictory', lang))}</span><span>${K.esc(U('timeLimitAt', lang, { time: U('countHours', lang, { count: AOE.hours }) }))}</span></div>`;
  H.aoe2 = (lang) => `
    <div class="h-aoe2">
      <div class="ae-cover cube"><div class="ae-art" style="background-image:url('${COVER}')"></div>
        ${aoeTags(lang)}</div>
      ${aoeResult(lang)}
      ${aoeSettings(lang)}
    </div>`;

  function build(motif, lang) {
    if (!H[motif]) throw new Error('no hero for motif ' + motif);
    return H[motif](lang);
  }
  window.Heroes = { build, boardHTML, hudTag, av, crest, H, STRIP, stripCol, stripHTML, legendHTML, tvHTML, bigFace, glyphOf, GAME, AOE, COVER, TICK, aoeResult, aoeSettings, aoeTags, aoeHead };
})();
