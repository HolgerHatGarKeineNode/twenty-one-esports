/**
 * The league live overlay (plan P3): everyday streaming on any game of the league, the centre left to the stream.
 *
 * Hierarchy: 1. pride moments in the top corners (people, what they did), 2. the ticker along the foot (everything
 * else that happens: wins over every game, climbs, cups with their start, the invitation, a spot per game), 3. the
 * lower third's information cards (today's numbers, the next cup, the join card with its QR code, a game of the league),
 * 4. the camera frame (module `cam-frame`).
 *
 * Timing: a stinger opens the overlay (the scene cut in OBS), the ticker sets in behind it. Then one thing to read
 * at a time: the pride list's first entry in a corner, a card in the lower third once the corners have been quiet for
 * a breath, the list's next entry, and so on; cards at most every TIMING.infoEveryMs, the pride list again after the
 * corners have been empty for TIMING.prideIdleMs, so a quiet league still shows its people. Every feed event that is
 * a pride moment cuts in at once (a champion first), alternating corners, never two moments at a time. The ticker
 * takes a fresh list at each loop boundary: the snapshot's crawl, with the feed's latest news first. An empty league
 * still has the join card, the game spots and its numbers.
 *
 * Modules (the preset): ticker, pride, ads (join card, game spots), stats, qr, pots, sound, cam-frame.
 */

import { TIMING } from '../timing.js';
import { describeFeed, prideFromFeed, pridePool, tickerFromFeed, tickerItems } from './shared.js';

export function runLeagueLive(ctx) {
    const { b, director, texts: t, sound } = ctx;
    const modules = () => ctx.snapshot().preset.modules;
    const corners = director.corners({ sides: ['right', 'left'] });
    const lower = director.lowerThirds({ yieldTo: corners });
    const recent = [];
    const seen = new Set();
    const t0 = b.stage.now();

    // The cut: the stinger covers the scene change, everything else sets in behind it.
    b.stinger({ start: t0 + 300, onPeak: () => sound.play('hit') });
    director.later(t0 + 300, () => sound.play('whoosh'));
    const after = t0 + 300 + TIMING.stingerMs;

    if (modules().ticker && ctx.snapshot().ticker.length > 0) {
        b.ticker({ start: after, label: t.live, items: tickerItems(ctx, recent), source: () => tickerItems(ctx, recent) });
    }
    if (modules()['cam-frame']) b.camFrame({ start: after + 200 });

    // Pride: the list first (two of it), then whatever happens; the list again when the corners have been empty.
    let pool = modules().pride ? pridePool(ctx, ctx.snapshot().pride) : [];
    let poolAt = 0;
    const fromPool = () => {
        if (pool.length === 0) return;
        const m = pool[poolAt++ % pool.length];
        seen.add(m.key);
        corners.push(m, 0);
    };
    director.later(after, fromPool);

    // Information cards in the lower third, in turn, whenever it is free.
    const host = ctx.snapshot().site.host;
    let cardAt = 0;
    let gameAt = 0;
    const cards = () => {
        const s = ctx.snapshot();
        const m = s.preset.modules;
        const list = [];
        if (m.ads) list.push({ name: t.joinName, line: ctx.fill(t.joinLine, { host }), qr: s.site.qr || null, mark: !s.site.qr, holdMs: TIMING.qrHoldMs });
        if (m.stats && s.stats) {
            const today = s.stats.gamesToday;
            list.push({
                name: today > 0 ? ctx.fill(today === 1 ? t.statsGame : t.statsGames, { count: today }) : ctx.fill(t.statsPlayers, { count: s.stats.players }),
                line: ctx.fill(s.stats.liveNow > 0 ? t.statsLine : t.statsLineQuiet, { live: s.stats.liveNow, total: ctx.group(s.stats.gamesPlayed) }),
                mark: true,
            });
        }
        if (s.nextCup) list.push({ name: s.nextCup.name, line: ctx.fill(t.nextCupLine, { when: s.nextCup.starts, taken: s.nextCup.taken, places: s.nextCup.places }), emblem: ctx.art(s.nextCup.emblem) });
        const potted = (s.upcoming || []).find((u) => u.pot);
        if (m.pots && potted) list.push({ name: potted.name, line: ctx.fill(t.potLine, { sats: ctx.group(potted.pot), when: potted.starts }), emblem: ctx.art('trophy') });
        if (m.ads && (s.games || []).length > 0) {
            const g = s.games[gameAt % s.games.length];
            list.push({ name: g.name, line: ctx.fill(t.spotLine, { host }), emblem: ctx.art(g.emblem) === 'mark' ? null : ctx.art(g.emblem), mark: ctx.art(g.emblem) === 'mark', game: true });
        }

        return list;
    };
    let lastCard = -Infinity;
    let cardsShown = 0;
    const nextCard = () => {
        const list = cards();
        if (list.length === 0) return;
        const card = list[cardAt++ % list.length];
        if (card.game) gameAt++;
        lastCard = b.stage.now();
        cardsShown++;
        const { game, ...spec } = card;
        void game;
        lower.push(spec);
    };
    // The quiet rhythm: one thing to read at a time, a breath between. A card when the corners have been empty for
    // the breath and the last card is TIMING.infoEveryMs ago; the pride list's next entry once a card has been shown
    // (the second one) or the corners have been empty for TIMING.prideIdleMs (every later one). The feed cuts in.
    const BREATH = 3000;
    let poolPlayed = 1;
    setInterval(() => {
        const now = b.stage.now();
        if (now < after + 2400) return;
        const quietCorners = corners.idleMs();
        const quietCard = lower.idleMs();
        if (lower.free() && quietCorners >= BREATH && now - lastCard >= TIMING.infoEveryMs) {
            nextCard();

            return;
        }
        if (!modules().pride || pool.length === 0 || quietCorners < BREATH || quietCard < BREATH) return;
        if ((poolPlayed < 2 && cardsShown > 0) || quietCorners >= TIMING.prideIdleMs) {
            poolPlayed++;
            fromPool();
        }
    }, 500);

    // The feed: pride moments to the corners, sign-ups and closed rounds to the lower third, news to the ticker.
    // A win pushed since the last poll is the snapshot's latest win too: the poll then only takes note of it.
    let heardWin = false;
    ctx.onFeed((item) => {
        if (item.kind === 'win') heardWin = true;
        const news = tickerFromFeed(ctx, item);
        if (news && modules().ticker) {
            recent.unshift(news);
            recent.length = Math.min(recent.length, 4);
        }
        if (!modules().pride) return;
        const pride = prideFromFeed(ctx, item);
        if (pride) {
            corners.push(pride.moment, pride.priority);

            return;
        }
        const text = describeFeed(ctx, item);
        if (text) lower.push({ name: text.name, line: text.line, emblem: ctx.art(text.emblem) === 'mark' ? null : ctx.art(text.emblem), mark: ctx.art(text.emblem) === 'mark' });
    });

    // A poll: a win no push announced shows from the snapshot's latest win; the pride list is renewed.
    ctx.onSnapshot((next) => {
        if (!next.preset.modules.pride) return;
        pool = pridePool(ctx, next.pride);
        const win = pool.find((m) => m.kind === 'win');
        if (win && !seen.has(win.key)) {
            seen.add(win.key);
            if (!heardWin) corners.push(win, 1);
        }
        heardWin = false;
    });
    // The snapshot's first win is the pride list's, already on its way: a poll must not announce it again.
    pool.forEach((m) => seen.add(m.key));

    return () => ({ corners: corners.state(), pool: pool.length, recent: recent.length });
}
