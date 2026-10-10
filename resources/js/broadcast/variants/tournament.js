/**
 * The tournament overlay (plan P4): one tournament, chosen in the preset, live.
 *
 * Hierarchy: 1. the moments in the top right corner (a match won, an upset, a place in the final, the champion with
 * the crown), 2. the banner top left (what this is: the tournament, its game, where it stands), 3. the board in the
 * left strip (the round on now: pairings and results, a table's top rows or the heats; before the start the countdown
 * with the QR code to sign up; the prize pot when there is one), 4. the ticker (matches up now, the latest results).
 *
 * Live: every `.tournament.changed` on `tournament.{id}` and every 20 s poll fetch the snapshot; what changed between
 * two snapshots becomes the moments (OverlaySnapshot `boxes`: a match that turned `done`, a final whose sides became
 * known, a champion). An upset is a win of the lower seed (the higher seed number) over the higher one. A burst (a
 * round closing at once) keeps every upset, the final and the champion, and three plain wins; the rest stays in the
 * ticker and on the board. The banner and the board take new data at their own boundaries only, so nothing changes
 * while it is read; a finished tournament brings its champion back every TIMING.championRepeatMs.
 *
 * Every format: an elimination bracket (single, double: the board names the section), a table (round robin, Swiss,
 * the group stage of a two-stage tournament: the round's pairings, then the standings) and heats (free-for-all
 * lobbies: the top three of each).
 */

import { SLOTS } from '../tokens.js';
import { TIMING } from '../timing.js';
import { describeFeed, prideFromFeed, tickerItems } from './shared.js';
import { runShell } from './shell.js';

const PER_PAGE = 5;
const HEATS_PER_PAGE = 3;
const DAY = 86_400_000;

const chunk = (list, n) => {
    const out = [];
    for (let i = 0; i < list.length; i += n) out.push(list.slice(i, i + n));

    return out;
};

export function runTournament(ctx) {
    const { b, director, texts: t, sound } = ctx;
    const tour = () => ctx.snapshot().tournament;
    if (!tour()) return runShell(ctx);

    const modules = () => ctx.snapshot().preset.modules;
    const corners = director.corners({ sides: ['right'] });
    const lower = director.lowerThirds({ yieldTo: corners });
    const announced = new Set();
    const t0 = b.stage.now();
    const art = ctx.art;
    const rays = art('energy-rays');

    b.stinger({ start: t0 + 300, onPeak: () => sound.play('hit') });
    director.later(t0 + 300, () => sound.play('whoosh'));
    const after = t0 + 300 + TIMING.stingerMs;

    if (modules().ticker && ctx.snapshot().ticker.length > 0) {
        b.ticker({ start: after, label: t.live, items: tickerItems(ctx, []), source: () => tickerItems(ctx, []) });
    }

    // The banner: changes words only by leaving and coming back, at most once per rotation.
    let banner = null;
    let bannerKey = '';
    // The banner turns when the tournament's state or round turns, not with every result (the count rides along).
    const keyOf = (T) => `${T.name}|${T.status}|${T.board?.round ?? ''}|${T.champion ?? ''}`;
    const bannerFor = (start) => {
        const T = tour();
        bannerKey = keyOf(T);
        banner = b.lowerThird({ slot: SLOTS.banner, kind: 'banner', start, name: T.name, line: T.statusLine, emblem: art(T.emblem) === 'mark' ? null : art(T.emblem), mark: art(T.emblem) === 'mark', holdMs: 3_600_000 });
    };
    bannerFor(after + 100);
    setInterval(() => {
        const T = tour();
        if (!T || !banner || keyOf(T) === bannerKey) return;
        const now = b.stage.now();
        if (now < banner.seg.start + TIMING.rotationMs || now < banner.seg.start + banner.seg.introMs + banner.seg.holdRuleMs) return;
        banner.retire(now);
        bannerFor(banner.seg.end + 400);
    }, 500);

    // The board: pages in turn, built from the snapshot of the moment each page starts.
    const pages = () => {
        const T = tour();
        const list = [];
        const left = Date.parse(T.startsAt) - Date.now();
        if (T.status === 'signup' || T.status === 'drawing' || (T.status === 'running' && !T.board)) {
            list.push({
                kind: 'countdown',
                title: T.status === 'drawing' ? t.boardDrawing : t.boardSignup,
                label: left > 0 && left < DAY ? t.startsIn : t.starts,
                startsAt: left > 0 && left < DAY ? T.startsAt : null,
                big: left >= DAY ? T.starts : '00:00:00',
                when: left >= DAY ? T.gameName : T.starts,
                entries: T.entries ? ctx.fill(T.entries === 1 ? t.entry : t.entries, { count: T.entries }) : null,
                qr: modules().qr ? T.qr : null,
                scan: t.scan,
            });
        }
        const board = T.board;
        if (board) {
            const title = board.title && board.kind !== 'heats' ? `${board.title}, ${board.round}` : board.round;
            if (board.kind === 'heats') chunk(board.matches, HEATS_PER_PAGE).forEach((m) => list.push({ kind: 'heats', title, matches: m }));
            else chunk(board.matches, PER_PAGE).forEach((m) => list.push({ kind: 'matches', title, matches: m }));
            if (board.kind === 'table' && board.rows.length > 0) list.push({ kind: 'table', title: board.title ? `${board.title}, ${t.standings}` : t.standings, rows: board.rows });
        }
        if (modules().pots && T.pot) list.push({ kind: 'pot', title: t.prizePot, amount: ctx.group(T.pot), unit: 'sats' });

        return list;
    };
    let page = null;
    let pageAt = 0;
    // What the board shows; when it changes, the page on air turns as soon as it has been read (TIMING.boardPageMs).
    const dataKey = (T) => JSON.stringify([T.status, T.board, T.pot, T.entries]);
    let pageData = '';
    const words = { live: t.live };
    const nextPage = (start) => {
        const list = pages();
        if (list.length === 0) {
            director.later(start + 5000, () => nextPage(b.stage.now() + 300));

            return;
        }
        turning = true;
        const spec = { ...list[pageAt++ % list.length] };
        spec.holdMs = list.length === 1 ? TIMING.boardAloneMs : TIMING.boardPageMs;
        pageData = dataKey(tour());
        page = b.board({ start, page: spec, words, art: { trophy: art('trophy') } });
        turning = false;
    };
    // The next page is decided just before this one ends (its end moves when a state change retires it early).
    let turning = false;
    setInterval(() => {
        if (!page) return;
        const now = b.stage.now();
        const read = page.seg.start + page.seg.introMs + Math.max(page.seg.holdRuleMs, TIMING.boardPageMs);
        if (!turning && now >= read && now < page.seg.start + page.seg.introMs + page.seg.holdMs && dataKey(tour()) !== pageData) page.retire(now);
        if (turning || now < page.seg.end - 150) return;
        turning = true;
        nextPage(Math.max(b.stage.now() + 100, page.seg.end + 300));
    }, 100);
    nextPage(after + 500);

    // Moments from what changed between two snapshots.
    // A plain win is news for half a minute; a place in the final drops the plain wins still waiting.
    const SHELF = { 'match-won': 30000, upset: 60000 };
    const moment = (kind, m, priority) => {
        const key = `${kind}:${m.name}:${m.key || ''}`;
        if (announced.has(key)) return;
        announced.add(key);
        if (kind === 'final') corners.drop((q) => q.kind === 'match-won');
        if (modules().pride) corners.push({ kind, rays, ...m }, priority, SHELF[kind] ?? Infinity);
    };
    const champion = (name) => {
        const T = tour();
        moment('champion', { name, line: t.winsTournament, context: `${T.name}, ${T.gameName}`, trophy: art('crown'), figure: { w: 250, h: 300 }, targetMs: TIMING.championTargetMs, landSound: 'hit' }, 5);
    };
    const diff = (prev, next) => {
        if (!prev || !next) return;
        const before = new Map((prev.boxes || []).map((x) => [x.key, x]));
        const finalists = new Set();
        if (next.champion && !prev.champion) {
            // Decided: the way there (a place in the final, the last wins) is old news next to the champion.
            corners.drop((m) => m.kind === 'final' || m.kind === 'match-won');
            next.boxes.forEach((x) => x.sides.forEach((side) => finalists.add(side.name)));
            champion(next.champion);
        }
        (next.boxes || []).filter((x) => x.final).forEach((x) => {
            const was = before.get(x.key);
            x.sides.forEach((side, i) => {
                if (side.known && was && !was.sides?.[i]?.known && !finalists.has(side.name)) {
                    finalists.add(side.name);
                    moment('final', { key: x.key, name: side.name, line: t.reachesFinal, context: next.name, trophy: art('medal-gold') }, 4);
                }
            });
        });
        let plain = 0;
        (next.boxes || []).forEach((x) => {
            const was = before.get(x.key);
            if (x.status !== 'done' || !was || was.status === 'done') return;
            const w = x.sides.find((s) => s.won);
            if (!w || finalists.has(w.name) || (x.final && next.champion === w.name)) return;
            const others = x.sides.filter((s) => s !== w && s.known);
            const l = others.length === 1 ? others[0] : null;
            // A one-game result (1, 0, ½) says nothing "beats" does not; a series score ("3:1") and points do.
            const oneGame = (v) => /^[01½]$/.test(String(v));
            const score = l && w.score && l.score && !String(w.score).startsWith('#') && !(oneGame(w.score) && oneGame(l.score)) ? ` ${w.score}:${l.score}` : '';
            const line = l ? `${ctx.fill(t.beats, { loser: l.name })}${score}` : ctx.fill(t.winsIn, { game: x.round });
            const upset = l && w.seed && l.seed && w.seed > l.seed;
            if (upset) {
                moment('upset', { key: x.key, name: w.name, line, context: ctx.fill(t.upsetContext, { winner: w.seed, loser: l.seed, round: x.round }), trophy: art('rank-up') }, 3);
            } else if (plain < 3) {
                plain++;
                moment('match-won', { key: x.key, name: w.name, line, context: `${x.round}, ${next.name}`, trophy: art('trophy') }, 2);
            }
        });
    };
    ctx.onSnapshot((next, prev) => {
        diff(prev.tournament, next.tournament);
    });

    // The feed: this tournament's items only (sign-ups, closed rounds, the champion, the prizes paid).
    ctx.onFeed((item) => {
        if (item.tournamentId !== ctx.config.tournamentId) return;
        if (item.kind === 'champion') {
            champion((item.winners || []).join(' & '));

            return;
        }
        if (item.kind === 'payout') {
            const pride = prideFromFeed(ctx, item);
            if (pride && modules().pride) corners.push(pride.moment, pride.priority);

            return;
        }
        const text = describeFeed(ctx, item);
        if (text && modules().pride) lower.push({ name: text.name, line: text.line, emblem: art(text.emblem) === 'mark' ? null : art(text.emblem), mark: art(text.emblem) === 'mark' });
        if (item.kind === 'round') ctx.refresh();
    });

    // A finished tournament: its champion now, and again whenever the corner has been quiet long enough.
    const repeatChampion = () => {
        const T = tour();
        if (T && T.champion && modules().pride) {
            announced.delete(`champion:${T.champion}:`);
            champion(T.champion);
        }
    };
    if (tour().champion) director.later(after + 400, repeatChampion);
    setInterval(() => {
        if (tour()?.champion && corners.idleMs() >= TIMING.championRepeatMs) repeatChampion();
    }, 2000);

    return () => ({ corners: corners.state(), page: page?.seg.page ?? null, pages: pages().map((p) => p.kind) });
}
