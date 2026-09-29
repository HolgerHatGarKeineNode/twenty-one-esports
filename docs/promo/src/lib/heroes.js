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

  function build(motif, lang) {
    if (!H[motif]) throw new Error('no hero for motif ' + motif);
    return H[motif](lang);
  }
  window.Heroes = { build, boardHTML, hudTag, av, crest, H };
})();
