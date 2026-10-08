/**
 * The end-of-match statistics of a Hyperbitcoinization match (plan "Hyperbitcoinization", P3, "Nachspiel-Statistik"):
 * after the end screen a sequence of 30 to 60 seconds plays over the table, skippable at any time and freely
 * browsable afterwards (tabs, previous/next, arrow keys): line charts per player over the rounds (drawn with d3,
 * turning points marked), the leaderboard tiles, then one scene per pride or meme moment.
 *
 * The numbers come from `config.urls.stats` (App\Support\Hyper\HyperStats, computed from the replay). The page and
 * the replay both open it; a player, a spectator and a guest see the same. Scenes are 2D and CSS (no WebGL), so a
 * headless browser shows them as a player does. Reduced motion: no drawing, no confetti, the pages still turn.
 * Sound: effects only (the effects switch), and none for a moment of a bot, as in a bot's turn.
 *
 * The sequence plays by itself once per match and browser (localStorage `hb-stats-seen`) when the page opens on a
 * finished match, and always when the match ends while the page is open. The end screen's "Match statistics"
 * button opens it again for browsing.
 */
import { FACTIONS, TDEF, ZONES, CARDS } from './data.js';
import { fmt, t } from './i18n.js';
import { sfx } from './audio.js';
import { Sequence, sequencePlan } from './statsPlan.js';

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
    let seq = new Sequence([]);
    let started = 0;
    let raf = 0;
    let loading = null;

    const n = (i) => (i === null || i === undefined ? t('neutral') : game.seatName(i));
    const color = (i) => (i === null || i === undefined ? '#5b6680' : game.seatColor(i));
    const tn = (id) => t(NAMES[id] ?? id);
    const human = (i) => i !== null && i !== undefined && !game.isBot(i);

    function load() {
        loading ??= net.get(config.urls.stats).then((r) => {
            if (r.ok && r.data && r.data.series) {
                data = r.data;
                pages = ['charts', 'leaders', ...data.moments.map((m) => 'moment:' + m.key)];
                seq = new Sequence(sequencePlan(data.moments.length));
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
        open(true);
    }

    function open(play) {
        if (!data) return;
        root.hidden = false;
        document.body.dataset.stats = 'open';
        buildTabs();
        if (play) startAuto(); else { stopAuto(); show(seq.go(0)); }
    }

    function close() {
        seq.skip();
        stopAuto();
        if (root.hidden) return;
        root.hidden = true;
        document.body.dataset.stats = 'closed';
        page.innerHTML = '';
        $('#rematch-btn, #again-btn')?.focus?.();
    }

    /* ---------------- the autoplay: 30 to 60 seconds in all (statsPlan.js) ---------------- */
    function startAuto() {
        seq.play();
        started = performance.now();
        root.dataset.auto = '1';
        skip.textContent = t('Skip');
        metric = 'territories';
        show(0);
        tick();
    }
    /** The page shows what the sequence says: no autoplay left, the progress bar empty, Skip reads Close. */
    function stopAuto() {
        cancelAnimationFrame(raf);
        root.dataset.auto = '0';
        skip.textContent = t('Close');
        $('#st-bar').style.width = '0%';
    }
    function tick() {
        if (!seq.auto) return;
        const elapsed = performance.now() - started;
        const want = seq.at(elapsed);
        if (want !== index) show(want);
        if (!seq.auto) { stopAuto(); return; }
        $('#st-bar').style.width = Math.min(100, (elapsed / seq.total) * 100) + '%';
        // The charts page switches from territories to the sats collected halfway.
        if (want === 0 && metric === 'territories' && elapsed > seq.plan[0] / 2) { metric = 'loot'; drawCharts(); }
        raf = requestAnimationFrame(tick);
    }
    /** A page turned by hand: the autoplay stops there. */
    function turn(i) {
        seq.go(i);
        stopAuto();
        show(seq.index);
    }

    /* ---------------- pages ---------------- */
    function buildTabs() {
        const tabs = [['charts', t('Charts')], ['leaders', t('Leaders')]];
        if (data.moments.length) tabs.push(['moment:' + data.moments[0].key, t('Moments (:count)', { count: data.moments.length })]);
        $('#st-tabs').innerHTML = tabs.map(([p, label]) => `<button type="button" role="tab" data-page="${esc(p)}" data-test="hyper-stats-tab">${esc(label)}</button>`).join('');
        $('#st-tabs').querySelectorAll('button').forEach((b) => { b.onclick = () => turn(pages.indexOf(b.dataset.page)); });
    }
    function show(i) {
        if (i < 0 || i >= pages.length) return;
        index = i;
        const p = pages[i];
        root.dataset.page = p;
        $('#st-count').textContent = `${i + 1} / ${pages.length}`;
        $('#st-prev').disabled = i === 0;
        $('#st-next').disabled = i === pages.length - 1;
        $('#st-tabs').querySelectorAll('button').forEach((b) => {
            const on = b.dataset.page === p || (b.dataset.page.startsWith('moment:') && p.startsWith('moment:'));
            b.classList.toggle('on', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (p === 'charts') renderCharts();
        else if (p === 'leaders') renderLeaders();
        else renderMoment(data.moments.find((m) => 'moment:' + m.key === p));
    }

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
        page.querySelectorAll('[data-metric]').forEach((b) => { b.onclick = () => { seq.skip(); stopAuto(); metric = b.dataset.metric; renderCharts(); }; });
        drawCharts();
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
                    .transition().duration(2400).delay(seat * 180).ease(d3.easeCubicOut).attr('stroke-dashoffset', 0)
                    .on('end', function () { d3.select(this).attr('stroke-dasharray', null); });
            }
        });
    }

    function renderLeaders() {
        const L = data.leaders;
        const tile = (key, icon, title, holder, value, extra = '') => `<article class="st-tile" data-leader="${key}" style="--c:${esc(holder === null ? '#5b6680' : color(holder))}">
            <img src="${A}art/${icon}?v=1" alt="">
            <div><small>${esc(title)}</small><b>${esc(holder === null ? t('Nobody') : n(holder))}</b><span class="chip">${esc(value)}</span>${extra}</div>
        </article>`;
        const chip = (text) => `<span class="chip">${esc(text)}</span>`;
        page.innerHTML = `<div class="st-tiles" data-test="hyper-stats-leaders">
            ${tile('battles', 'ph-attack.webp', t('Most battles'), L.battles.seat, t(':count battles', { count: L.battles.value }))}
            ${tile('conquest_run', 'medal-knockout.webp', t('Biggest conquest run'), L.conquest_run.seat, t(':count territories in one turn', { count: L.conquest_run.value }), L.conquest_run.seat === null ? '' : chip(t('Round :round', { round: L.conquest_run.round })))}
            ${tile('defence', 'ph-fortify.webp', t('Longest defence'), L.defence.seat, L.defence.seat === null ? '–' : t(':territory held against :count attacks', { territory: tn(L.defence.territory), count: L.defence.value }))}
            ${tile('loot', 'ico-sats.webp', t('Most loot'), L.loot.seat, t(':sats M sats', { sats: fmt(L.loot.value) }))}
            ${tile('unlucky', 'medal-dice.webp', t('Unluckiest roller'), L.unlucky.seat, t(':pips pips against the odds', { pips: (L.unlucky.value > 0 ? '+' : '') + fmt(L.unlucky.value) }), L.unlucky.seat === null ? '' : chip(t(':count dice', { count: L.unlucky.dice })))}
        </div>`;
        if (!REDUCED && window.gsap) window.gsap.from('.st-tile', { y: 24, opacity: 0, duration: 0.45, stagger: 0.12, ease: 'back.out(1.6)' });
    }

    function renderMoment(mo) {
        if (!mo) return;
        const def = MOMENTS[mo.key];
        if (!def) return;
        const por = FACTIONS[mo.faction]?.por;
        const bg = mo.key === 'finale' ? `${A}art/${por === 'you' || !por ? 'win' : 'win-' + por}.jpg?v=1` : mo.key === 'bitcoin_dead' ? `${A}art/win-no.jpg?v=1` : `${A}art/plate-stage.jpg?v=1`;
        const art = `${A}art/${def.art}.webp?v=1`;
        const chips = [mo.seat !== undefined && mo.seat !== null ? `<span class="chip who" style="--c:${esc(color(mo.seat))}">${esc(n(mo.seat))}</span>` : '', `<span class="chip">${esc(t('Round :round', { round: mo.round }))}</span>`, def.joke ? `<span class="chip joke">${esc(def.joke())}</span>` : ''].join('');
        page.innerHTML = `<section class="st-scene" data-moment="${esc(mo.key)}" data-kind="${def.kind}" data-test="hyper-stats-moment" style="--c:${esc(color(mo.seat))}">
            <div class="sc-bg" style="background-image:url(${bg})"></div>
            <div class="sc-fx" aria-hidden="true"></div>
            <img class="sc-art" src="${art}" alt="">
            <div class="sc-txt"><small>${esc(def.kind === 'pride' ? t('Pride moment') : t('Meme moment'))}</small><h3>${esc(def.title(mo))}</h3><p>${esc(def.line(mo, n, tn))}</p><div class="sc-chips">${chips}</div></div>
        </section>`;
        if (human(mo.seat)) { if (def.kind === 'pride') sfx.fanfare(); else sfx.sting(); }
        if (REDUCED) return;
        if (window.gsap) {
            window.gsap.from('.sc-art', { scale: 0.3, rotate: def.kind === 'meme' ? -25 : 0, opacity: 0, duration: 0.7, ease: 'back.out(2)' });
            window.gsap.from('.sc-txt > *', { y: 18, opacity: 0, duration: 0.4, stagger: 0.1, delay: 0.25 });
        }
        rain(page.querySelector('.sc-fx'), def.kind);
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
    $('#st-next').addEventListener('click', () => turn(index + 1));
    if (openBtn) openBtn.addEventListener('click', () => open(false));
    addEventListener('keydown', (e) => {
        if (root.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); } else if (e.key === 'ArrowRight') turn(index + 1); else if (e.key === 'ArrowLeft') turn(index - 1);
    }, { capture: true });
    addEventListener('resize', () => { if (!root.hidden && pages[index] === 'charts') drawCharts(); });

    return {
        ended,
        open,
        close,
        state: () => ({ open: !root.hidden, auto: seq.auto, page: pages[index] ?? null, index, pages: [...pages], total: seq.total, loaded: data !== null }),
    };
}
