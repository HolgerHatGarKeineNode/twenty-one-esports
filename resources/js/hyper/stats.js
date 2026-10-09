/**
 * The end-of-match statistics of a Hyperbitcoinization match (plan "Hyperbitcoinization", P3, "Nachspiel-Statistik"):
 * after the end screen a sequence of pages over the table: line charts per player over the rounds (drawn with d3,
 * turning points marked), the leaderboard tiles, in a team match the teams with their clans and MVPs, then one
 * scene per pride or meme moment.
 *
 * Pacing (P4, user direction 2026-10-09, statsPlan.js): no page turns by itself. Each page animates calmly (lines
 * draw for 2.6 s, numbers count up for 1.6 s, a scene arrives in 1.8 s); the Next button appears when it is done,
 * and Next, → , Enter or Space before that finish the animation at once. "Skip all" closes the whole; tabs and
 * Previous browse freely.
 *
 * The numbers come from `config.urls.stats` (App\Support\Hyper\HyperStats, computed from the replay). The page and
 * the replay both open it; a player, a spectator and a guest see the same. Scenes are 2D and CSS (no WebGL), so a
 * headless browser shows them as a player does. Reduced motion: no drawing, no confetti, the pages still turn.
 * Sound: effects only (the effects switch), and none for a moment of a bot, as in a bot's turn.
 *
 * The sequence opens by itself once per match and browser (localStorage `hb-stats-seen`) when the page opens on a
 * finished match, and always when the match ends while the page is open. The end screen's "Match statistics"
 * button opens it again.
 *
 * Team matches (P4): the clans are the heroes. The team page shows each side with its clan logo (or tag), its name
 * (a clan linked to a meetup as that meetup), its players' avatars and sums, and its MVP; the team moments
 * (`team_win`, `clan_bank`, `clan_zone`, `clan_knockout`) show the clan's logo and its players' avatars.
 */
import { FACTIONS, TDEF, ZONES, CARDS } from './data.js';
import { fmt, t } from './i18n.js';
import { sfx } from './audio.js';
import { COUNT_MS, LINE_MS, SCENE_MS, Sequence } from './statsPlan.js';

const REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;
const SEEN_KEY = 'hb-stats-seen';
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const NAMES = Object.fromEntries(TDEF.map(([id, name]) => [id, name]));

/** Each moment: pride or meme, its sprite, its title and its line (`n` names a seat, `tn` a territory). */
const MOMENTS = {
    finale: { kind: 'pride', art: 'medal-finale', title: (m) => t(FACTIONS[m.faction]?.win[0] ?? 'HYPERBITCOINIZATION'), line: (m, n) => t(':name wins in round :round', { name: n(m.seat), round: m.round }) },
    first_bank: { kind: 'pride', art: 'medal-first-bank', title: () => t('First central bank toppled'), line: (m, n, tn) => (m.loser !== null && m.loser !== undefined ? t(':name takes :territory from :loser', { name: n(m.seat), territory: tn(m.territory), loser: n(m.loser) }) : t(':name takes :territory', { name: n(m.seat), territory: tn(m.territory) })) },
    zone: { kind: 'pride', art: 'medal-zone', title: () => t('A whole currency space'), line: (m, n) => t(':name holds the whole :zone', { name: n(m.seat), zone: t(ZONES[m.zone]?.name ?? m.zone) }) },
    comeback: { kind: 'pride', art: 'medal-comeback', title: () => t('Comeback from the last region'), line: (m, n) => t(':name was down to :zone in round :round and still won', { name: n(m.seat), zone: t(ZONES[m.zone]?.name ?? m.zone), round: m.round }) },
    perfect_dice: { kind: 'pride', art: 'medal-dice', title: () => t('Perfect dice'), line: (m, n, tn) => t('Three sixes for :name on :territory', { name: n(m.seat), territory: tn(m.territory) }) },
    knockout: { kind: 'pride', art: 'medal-knockout', title: () => t('Knockout'), line: (m, n) => t(':name knocks :loser out of the game', { name: n(m.seat), loser: n(m.victim) }) },
    lost_keys: { kind: 'meme', art: 'meme-keys', title: () => t(CARDS.keys.name), line: (m, n) => t(':name, the richest, loses :sats M sats', { name: n(m.victim), sats: fmt(m.sats ?? 0) }), joke: () => t(CARDS.keys.joke) },
    salvador_lost: { kind: 'meme', art: 'meme-salvador', title: () => t('Coin flip lost'), line: (m, n) => t(':name bets on El Salvador: −25 %', { name: n(m.seat) }), joke: () => t(CARDS.salvador.joke) },
    pizza: { kind: 'meme', art: 'meme-pizza', title: () => t(CARDS.pizza.name), line: (m, n) => t(':name pays for pizza in sats', { name: n(m.seat) }), joke: () => t(CARDS.pizza.joke) },
    attack51: { kind: 'meme', art: 'meme-51', title: () => t(CARDS.attack51.name), line: (m, n, tn) => t(':name seizes :territory', { name: n(m.seat), territory: tn(m.territory) }), joke: () => t(CARDS.attack51.joke) },
    bitcoin_dead: { kind: 'meme', art: 'fall-no', title: () => t(FACTIONS.nocoiner.win[0]), line: () => t(FACTIONS.nocoiner.win[1]) },
    // Team matches (P4): the clan's moments. `c` names a team by its clan (or meetup).
    team_win: { kind: 'pride', team: true, art: 'medal-finale', title: (m, n, tn, c) => t(':clan wins', { clan: c(m.team) }), line: (m, n) => t('MVP: :name with :count conquests', { name: n(m.mvp?.seat), count: m.mvp?.conquests ?? 0 }) },
    clan_bank: { kind: 'pride', team: true, art: 'medal-first-bank', title: (m, n, tn, c) => t('First central bank for :clan', { clan: c(m.team) }), line: (m, n, tn) => t(':name takes :territory', { name: n(m.seat), territory: tn(m.territory) }) },
    clan_zone: { kind: 'pride', team: true, art: 'medal-zone', title: (m, n, tn, c) => t(':clan holds a whole currency space', { clan: c(m.team) }), line: (m) => t('The whole :zone, together', { zone: t(ZONES[m.zone]?.name ?? m.zone) }) },
    clan_knockout: { kind: 'pride', team: true, art: 'medal-knockout', title: (m, n, tn, c) => t(':clan knocks out a rival', { clan: c(m.team) }), line: (m, n, tn, c) => t(':name throws :loser of :rival out of the game', { name: n(m.seat), loser: n(m.victim), rival: c(m.victim_team) }) },
};

const METRICS = [
    ['territories', () => t('Territories')],
    ['units', () => t('Units')],
    ['banks', () => t('Central banks')],
    ['fiat', () => t('Fiat')],
    ['loot', () => t('Sats collected')],
];

export function startStats(config, net, game) {
    const $ = (s) => document.querySelector(s);
    const A = config.assets ?? '/hyper/';
    const root = $('#stats');
    const page = $('#st-page');
    const skip = $('#st-skip');
    const openBtn = $('#stats-btn');
    let data = null;
    let pages = [];
    let index = 0;
    let metric = 'territories';
    let seq = new Sequence(0);
    let readyTimer = 0;
    let finishPage = () => {};
    let loading = null;
    const next = $('#st-next');

    const n = (i) => (i === null || i === undefined ? t('neutral') : game.seatName(i));
    const color = (i) => (i === null || i === undefined ? '#5b6680' : game.seatColor(i));
    const tn = (id) => t(NAMES[id] ?? id);
    const human = (i) => i !== null && i !== undefined && !game.isBot(i);
    // A team match (P4): the sides from the snapshot (HyperTeams), the seats' avatars.
    const sides = config.snapshot?.teams ?? null;
    const clan = (team) => sides?.[team]?.name ?? t('Team :number', { number: (team ?? 0) + 1 });
    const avatarOf = (seat) => (config.snapshot?.seats ?? []).find((s) => s.seat === seat)?.avatar ?? null;
    const logoHtml = (team, size = 64) => {
        const side = sides?.[team];
        if (side?.logo) return `<img class="tm-logo" src="${esc(side.logo)}" alt="" width="${size}" height="${size}" data-clan-logo>`;

        return `<span class="tm-logo tm-tag" style="width:${size}px;height:${size}px">${esc(side?.tag ?? '🤖')}</span>`;
    };
    const faces = (seats) => `<span class="tm-faces">${seats.map((seat) => { const a = avatarOf(seat); return a ? `<img src="${esc(a)}" alt="" title="${esc(n(seat))}" style="--c:${esc(color(seat))}">` : `<span class="tm-bot" title="${esc(n(seat))}" style="--c:${esc(color(seat))}">🤖</span>`; }).join('')}</span>`;

    function load() {
        loading ??= net.get(config.urls.stats).then((r) => {
            if (r.ok && r.data && r.data.series) {
                data = r.data;
                pages = ['charts', 'leaders', ...(data.teams ? ['teams'] : []), ...data.moments.map((m) => 'moment:' + m.key)];
                seq = new Sequence(pages.length);
                if (openBtn) openBtn.hidden = false;
            }

            return data;
        });

        return loading;
    }

    /** The end screen is up: load the numbers, and play the sequence when it is due. */
    async function ended(quiet) {
        if (!config.urls?.stats) return;
        const d = await load();
        if (!d) return;
        let seen = [];
        try { seen = JSON.parse(localStorage.getItem(SEEN_KEY) || '[]'); } catch { seen = []; }
        if (quiet && seen.includes(config.snapshot.id)) return;
        try { localStorage.setItem(SEEN_KEY, JSON.stringify([...seen.filter((x) => x !== config.snapshot.id), config.snapshot.id].slice(-50))); } catch { /* private mode */ }
        open();
    }

    function open() {
        if (!data) return;
        root.hidden = false;
        document.body.dataset.stats = 'open';
        seq = new Sequence(pages.length);
        skip.textContent = t('Skip all');
        metric = 'territories';
        buildTabs();
        show(0);
    }

    function close() {
        seq.skip();
        clearTimeout(readyTimer);
        if (root.hidden) return;
        root.hidden = true;
        document.body.dataset.stats = 'closed';
        page.innerHTML = '';
        $('#rematch-btn, #again-btn')?.focus?.();
    }

    /* ---------------- pacing: every page waits for the player (statsPlan.js) ---------------- */
    /** The page's animation is over (or was finished by a click): the Next button shows. */
    function ready() {
        clearTimeout(readyTimer);
        seq.finished();
        root.dataset.ready = '1';
        next.hidden = false;
        next.querySelector('span').textContent = index === pages.length - 1 ? t('Done') : t('Next step');
    }
    /** Next, →, Enter, Space: finish the animation first, then the next page, after the last one close. */
    function advance() {
        const step = seq.next();
        if (step.action === 'finish') { finishPage(); ready(); } else if (step.action === 'show') show(step.index); else if (step.action === 'end') close();
    }
    /** A page turned by tab or Previous. */
    function turn(i) {
        if (i >= 0 && i < pages.length) show(i);
    }

    /* ---------------- pages ---------------- */
    function buildTabs() {
        const tabs = [['charts', t('Charts')], ['leaders', t('Leaders')], ...(data.teams ? [['teams', t('Teams')]] : [])];
        if (data.moments.length) tabs.push(['moment:' + data.moments[0].key, t('Moments (:count)', { count: data.moments.length })]);
        $('#st-tabs').innerHTML = tabs.map(([p, label]) => `<button type="button" role="tab" data-page="${esc(p)}" data-test="hyper-stats-tab">${esc(label)}</button>`).join('');
        $('#st-tabs').querySelectorAll('button').forEach((b) => { b.onclick = () => turn(pages.indexOf(b.dataset.page)); });
    }
    function show(i) {
        if (i < 0 || i >= pages.length) return;
        index = seq.begin(i);
        clearTimeout(readyTimer);
        finishPage = () => {};
        root.dataset.ready = '0';
        next.hidden = true;
        const p = pages[i];
        root.dataset.page = p;
        $('#st-count').textContent = `${i + 1} / ${pages.length}`;
        $('#st-bar').style.width = `${((i + 1) / pages.length) * 100}%`;
        $('#st-prev').disabled = i === 0;
        $('#st-tabs').querySelectorAll('button').forEach((b) => {
            const on = b.dataset.page === p || (b.dataset.page.startsWith('moment:') && p.startsWith('moment:'));
            b.classList.toggle('on', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        let ms;
        if (p === 'charts') ms = renderCharts();
        else if (p === 'leaders') ms = renderLeaders();
        else if (p === 'teams') ms = renderTeams();
        else ms = renderMoment(data.moments.find((m) => 'moment:' + m.key === p));
        if (REDUCED || !ms) ready(); else readyTimer = setTimeout(ready, ms);
    }

    /** Counts every `.st-num` of the page up from 0 to its `data-to` in COUNT_MS; returns the finisher. */
    function countUp() {
        const nums = [...page.querySelectorAll('.st-num')];
        const begin = performance.now();
        let raf = 0;
        const paint = (k) => nums.forEach((el) => { const to = parseFloat(el.dataset.to) || 0; el.textContent = fmt(k >= 1 ? to : Math.round(to * k * 10) / 10); });
        const frame = () => { const k = Math.min(1, (performance.now() - begin) / COUNT_MS); paint(k); if (k < 1 && !root.hidden) raf = requestAnimationFrame(frame); };
        if (REDUCED) { paint(1); return () => {}; }
        paint(0);
        raf = requestAnimationFrame(frame);

        return () => { cancelAnimationFrame(raf); paint(1); };
    }
    /** A text with its number as a counting span: `:count battles` with count 5. */
    const counted = (text, value) => esc(text).replace('\u0001', `<span class="st-num" data-to="${esc(value)}">${esc(fmt(value))}</span>`);
    const N = '\u0001';

    function renderCharts() {
        const chips = METRICS.map(([key, label]) => `<button type="button" class="chip${key === metric ? ' on' : ''}" data-metric="${key}" aria-pressed="${key === metric}">${esc(label())}</button>`).join('');
        const seats = data.factions.map((_, i) => `<span class="st-who"><i style="background:${esc(color(i))}"></i>${esc(n(i))}${data.winner === i ? ' <b>★</b>' : ''}</span>`).join('');
        const turns = pickTurns();
        const list = turns.map((p, k) => `<li class="chip st-turn" style="--c:${esc(color(p.seat))}"><b>${k + 1}</b>${esc(turnText(p))}</li>`).join('');
        page.innerHTML = `<div class="st-charts" data-test="hyper-stats-charts">
            <div class="st-metrics" role="group" aria-label="${esc(t('Chart'))}">${chips}</div>
            <div class="st-chart" id="st-chart" data-test="hyper-stats-chart"></div>
            <div class="st-legend">${seats}</div>
            ${turns.length ? `<ol class="st-turns" aria-label="${esc(t('Turning points'))}">${list}</ol>` : ''}
        </div>`;
        page.querySelectorAll('[data-metric]').forEach((b) => { b.onclick = () => { metric = b.dataset.metric; drawCharts(); }; });
        drawCharts();

        return LINE_MS + data.factions.length * 180;
    }

    /** At most six turning points: knockouts first, then completed spaces, then fallen banks; shown in time order. */
    function pickTurns() {
        const weight = { player_eliminated: 3, zone_completed: 2, bank_fallen: 1 };

        return [...data.turning_points].map((p, i) => ({ ...p, i }))
            .sort((a, b) => weight[b.type] - weight[a.type] || a.i - b.i)
            .slice(0, 6)
            .sort((a, b) => a.round - b.round || a.i - b.i);
    }
    function turnText(p) {
        if (p.type === 'bank_fallen') return t('Round :round: :loser loses :territory', { round: p.round, loser: n(p.loser), territory: tn(p.territory) });
        if (p.type === 'zone_completed') return t('Round :round: :name takes the whole :zone', { round: p.round, name: n(p.seat), zone: t(ZONES[p.zone]?.name ?? p.zone) });

        return t('Round :round: :name knocks out :loser', { round: p.round, name: n(p.seat), loser: n(p.loser) });
    }

    function drawCharts() {
        const box = $('#st-chart');
        if (!box || !window.d3) return;
        const d3 = window.d3;
        page.querySelectorAll('[data-metric]').forEach((b) => { const on = b.dataset.metric === metric; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); });
        const W = Math.max(240, box.clientWidth); const H = Math.max(160, box.clientHeight);
        const m = { t: 16, r: 14, b: 26, l: 38 };
        const series = data.series[metric] ?? [];
        const rounds = Math.max(1, data.rounds);
        const max = Math.max(1, d3.max(series.flat()) ?? 1);
        const x = d3.scaleLinear().domain([0, rounds]).range([m.l, W - m.r]);
        const y = d3.scaleLinear().domain([0, max * 1.08]).nice().range([H - m.b, m.t]);
        box.innerHTML = '';
        const svg = d3.select(box).append('svg').attr('viewBox', `0 0 ${W} ${H}`).attr('width', W).attr('height', H).attr('role', 'img')
            .attr('aria-label', t(':metric per player over :rounds rounds', { metric: METRICS.find(([k]) => k === metric)[1](), rounds }));
        svg.append('g').attr('class', 'st-axis').attr('transform', `translate(0,${H - m.b})`).call(d3.axisBottom(x).ticks(Math.min(rounds, W < 500 ? 5 : 10)).tickFormat(d3.format('d')));
        svg.append('g').attr('class', 'st-axis').attr('transform', `translate(${m.l},0)`).call(d3.axisLeft(y).ticks(5).tickSize(-(W - m.l - m.r)).tickFormat((v) => fmt(v)));
        const turns = pickTurns();
        const marks = svg.append('g').attr('class', 'st-marks');
        turns.forEach((p, k) => {
            const g = marks.append('g').attr('transform', `translate(${x(p.round)},0)`);
            g.append('line').attr('y1', m.t).attr('y2', H - m.b).attr('stroke', color(p.seat)).attr('stroke-dasharray', '3 4').attr('opacity', 0.7);
            g.append('circle').attr('cy', m.t + 2).attr('r', 8).attr('fill', '#0a1226').attr('stroke', color(p.seat)).attr('stroke-width', 2);
            g.append('text').attr('y', m.t + 6).attr('text-anchor', 'middle').attr('class', 'st-mark-n').text(k + 1);
            g.append('title').text(turnText(p));
        });
        const line = d3.line().x((_, i) => x(i)).y((v) => y(v)).curve(d3.curveMonotoneX);
        series.forEach((values, seat) => {
            const path = svg.append('path').attr('class', 'st-line').attr('d', line(values)).attr('stroke', color(seat)).attr('data-seat', seat);
            if (data.winner === seat) path.classed('win', true);
            if (!REDUCED) {
                const len = path.node().getTotalLength();
                path.attr('stroke-dasharray', `${len} ${len}`).attr('stroke-dashoffset', len)
                    .transition().duration(LINE_MS).delay(seat * 180).ease(d3.easeCubicOut).attr('stroke-dashoffset', 0)
                    .on('end', function () { d3.select(this).attr('stroke-dasharray', null); });
            }
        });
        // Next before the lines are drawn: every line at once.
        finishPage = () => svg.selectAll('path.st-line').interrupt().attr('stroke-dashoffset', 0).attr('stroke-dasharray', null);
    }

    function renderLeaders() {
        const L = data.leaders;
        const tile = (key, icon, title, holder, value, extra = '') => `<article class="st-tile" data-leader="${key}" style="--c:${esc(holder === null ? '#5b6680' : color(holder))}">
            <img src="${A}art/${icon}?v=1" alt="">
            <div><small>${esc(title)}</small><b>${esc(holder === null ? t('Nobody') : n(holder))}</b><span class="chip">${value}</span>${extra}</div>
        </article>`;
        const chip = (text) => `<span class="chip">${esc(text)}</span>`;
        page.innerHTML = `<div class="st-tiles" data-test="hyper-stats-leaders">
            ${tile('battles', 'ph-attack.webp', t('Most battles'), L.battles.seat, counted(t(':count battles', { count: N }), L.battles.value))}
            ${tile('conquest_run', 'medal-knockout.webp', t('Biggest conquest run'), L.conquest_run.seat, counted(t(':count territories in one turn', { count: N }), L.conquest_run.value), L.conquest_run.seat === null ? '' : chip(t('Round :round', { round: L.conquest_run.round })))}
            ${tile('defence', 'ph-fortify.webp', t('Longest defence'), L.defence.seat, L.defence.seat === null ? '–' : counted(t(':territory held against :count attacks', { territory: tn(L.defence.territory), count: N }), L.defence.value))}
            ${tile('loot', 'ico-sats.webp', t('Most loot'), L.loot.seat, counted(t(':sats M sats', { sats: N }), L.loot.value))}
            ${tile('unlucky', 'medal-dice.webp', t('Unluckiest roller'), L.unlucky.seat, esc(t(':pips pips against the odds', { pips: (L.unlucky.value > 0 ? '+' : '') + fmt(L.unlucky.value) })), L.unlucky.seat === null ? '' : chip(t(':count dice', { count: L.unlucky.dice })))}
        </div>`;
        const tiles = !REDUCED && window.gsap ? window.gsap.from('.st-tile', { y: 24, opacity: 0, duration: 0.6, stagger: 0.18, ease: 'back.out(1.6)' }) : null;
        const counts = countUp();
        finishPage = () => { tiles?.progress(1); counts(); };

        return COUNT_MS + 400;
    }

    /** A team match (P4): each side with its clan, players, sums and MVP; the winner first-class. */
    function renderTeams() {
        page.innerHTML = `<div class="st-teams" data-test="hyper-stats-teams">${data.teams.map((row) => {
            const side = sides?.[row.team];
            const sub = side?.meetup ? (side.city ? t('Meetup · :city', { city: side.city }) : t('Meetup')) : side?.clan_id ? t('Clan') : t('Bots');
            const mvp = row.mvp;

            return `<article class="st-team${row.won ? ' won' : ''}" data-team="${row.team}" data-test="hyper-stats-team">
                <header>${logoHtml(row.team)}<div><small>${esc(row.won ? t('Winner') : sub)}</small><b>${esc(clan(row.team))}</b>${side?.tag ? `<span class="chip">${esc(side.tag)}</span>` : ''}</div></header>
                <ul class="tm-seats">${row.seats.map((seat) => `<li style="--c:${esc(color(seat))}">${faces([seat])}<span>${esc(n(seat))}</span>${mvp?.seat === seat ? `<em class="chip mvp" data-test="hyper-stats-mvp">${esc(t('MVP'))}</em>` : ''}</li>`).join('')}</ul>
                <dl class="tm-sums">
                    <div><dt>${esc(t('Conquests'))}</dt><dd><span class="st-num" data-to="${row.conquests}">${row.conquests}</span></dd></div>
                    <div><dt>${esc(t('Loot'))}</dt><dd><span class="st-num" data-to="${row.loot}">${esc(fmt(row.loot))}</span> ₿</dd></div>
                    <div><dt>${esc(t('Central banks'))}</dt><dd><span class="st-num" data-to="${row.banks}">${row.banks}</span></dd></div>
                </dl>
            </article>`;
        }).join(`<span class="st-vs" aria-hidden="true">${esc(t('vs'))}</span>`)}</div>`;
        const cards = !REDUCED && window.gsap ? window.gsap.from('.st-team', { y: 30, opacity: 0, duration: 0.7, stagger: 0.3, ease: 'back.out(1.5)' }) : null;
        const counts = countUp();
        finishPage = () => { cards?.progress(1); counts(); };

        return COUNT_MS + 600;
    }

    function renderMoment(mo) {
        if (!mo) return;
        const def = MOMENTS[mo.key];
        if (!def) return;
        const por = FACTIONS[mo.faction]?.por ?? (mo.seat !== undefined && mo.seat !== null ? FACTIONS[data.factions[mo.seat]]?.por : null);
        const winBg = (key) => `${A}art/${key === 'you' || !key ? 'win' : 'win-' + key}.jpg?v=1`;
        const bg = mo.key === 'finale' || mo.key === 'team_win' ? winBg(por) : mo.key === 'bitcoin_dead' ? `${A}art/win-no.jpg?v=1` : `${A}art/plate-stage.jpg?v=1`;
        const art = `${A}art/${def.art}.webp?v=1`;
        // A clan's moment: its logo beside the medal and its players' avatars under the line.
        const crest = def.team ? `<div class="sc-crest" data-test="hyper-stats-crest">${logoHtml(mo.team, 88)}</div>` : '';
        const teamSeats = def.team ? (data.teams?.find((row) => row.team === mo.team)?.seats ?? []) : [];
        const chips = [mo.seat !== undefined && mo.seat !== null ? `<span class="chip who" style="--c:${esc(color(mo.seat))}">${esc(n(mo.seat))}</span>` : '', `<span class="chip">${esc(t('Round :round', { round: mo.round }))}</span>`, def.joke ? `<span class="chip joke">${esc(def.joke())}</span>` : ''].join('');
        page.innerHTML = `<section class="st-scene" data-moment="${esc(mo.key)}" data-kind="${def.kind}" data-test="hyper-stats-moment" style="--c:${esc(color(mo.seat))}">
            <div class="sc-bg" style="background-image:url(${bg})"></div>
            <div class="sc-fx" aria-hidden="true"></div>
            <div class="sc-arts">${crest}<img class="sc-art" src="${art}" alt=""></div>
            <div class="sc-txt"><small>${esc(def.kind === 'pride' ? (def.team ? t('Clan pride') : t('Pride moment')) : t('Meme moment'))}</small><h3>${esc(def.title(mo, n, tn, clan))}</h3><p>${esc(def.line(mo, n, tn, clan))}</p>${teamSeats.length ? faces(teamSeats) : ''}<div class="sc-chips">${chips}</div></div>
        </section>`;
        if (human(mo.seat)) { if (def.kind === 'pride') sfx.fanfare(); else sfx.sting(); }
        if (REDUCED) return 0;
        const tweens = [];
        if (window.gsap) {
            tweens.push(window.gsap.from('.sc-art, .sc-crest', { scale: 0.3, rotate: def.kind === 'meme' ? -25 : 0, opacity: 0, duration: 0.9, stagger: 0.25, ease: 'back.out(2)' }));
            tweens.push(window.gsap.from('.sc-txt > *', { y: 18, opacity: 0, duration: 0.5, stagger: 0.18, delay: 0.4 }));
        }
        rain(page.querySelector('.sc-fx'), def.kind);
        finishPage = () => tweens.forEach((tw) => tw.progress(1));

        return SCENE_MS;
    }

    /** Confetti for pride, coins for memes: plain elements with a CSS fall, removed with the page. */
    function rain(fx, kind) {
        const colors = ['#f7931a', '#ffb54d', '#ffffff', '#57a6ff', '#43d17a', '#ff5fb0'];
        let html = '';
        for (let i = 0; i < (kind === 'pride' ? 46 : 18); i++) {
            const left = Math.random() * 100; const delay = Math.random() * 1.6; const dur = 2.4 + Math.random() * 1.8; const rot = Math.round(Math.random() * 360);
            html += kind === 'pride'
                ? `<i class="cf" style="left:${left}%;background:${colors[i % colors.length]};animation-delay:${delay}s;animation-duration:${dur}s;--r:${rot}deg"></i>`
                : `<img class="cn" src="${A}art/fx-coin.webp?v=1" alt="" style="left:${left}%;animation-delay:${delay}s;animation-duration:${dur}s;--r:${rot}deg">`;
        }
        fx.innerHTML = html;
    }

    /* ---------------- controls ---------------- */
    skip.addEventListener('click', close);
    $('#st-prev').addEventListener('click', () => turn(index - 1));
    next.addEventListener('click', advance);
    // A click on a page still arriving finishes it (the Next button shows); it never turns the page.
    page.addEventListener('click', (e) => { if (!seq.ready && !e.target.closest('button')) advance(); });
    if (openBtn) openBtn.addEventListener('click', () => open());
    addEventListener('keydown', (e) => {
        if (root.hidden || e.target?.closest?.('input, textarea, select, [contenteditable]')) return;
        // A focused button (Next included) turns Enter and Space into its own click: advancing here too would skip a page.
        const onButton = !!e.target?.closest?.('button');
        if (e.key === 'Escape') { e.preventDefault(); close(); } else if (e.key === 'ArrowRight' || ((e.key === 'Enter' || e.key === ' ') && !onButton)) { e.preventDefault(); e.stopPropagation(); advance(); } else if (e.key === 'ArrowLeft') turn(index - 1);
    }, { capture: true });
    addEventListener('resize', () => { if (!root.hidden && pages[index] === 'charts') drawCharts(); });

    return {
        ended,
        open,
        close,
        state: () => ({ open: !root.hidden, ready: seq.ready, page: pages[index] ?? null, index, pages: [...pages], loaded: data !== null }),
        next: advance,
    };
}
