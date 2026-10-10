/**
 * What the overlay variants share: the words for a feed item or a pride entry, in the preset's language
 * (config.texts, worded by BroadcastOverlayController), and the ticker's items with their art.
 */

import { TIMING } from '../timing.js';

const EMBLEMS = {
    chess: 'emblem-chess', 'rocket-league': 'emblem-rocket-league', 'age-of-empires-2': 'emblem-age-of-empires-2', tmnf: 'emblem-tmnf',
    'proof-of-pong': 'emblem-pong', hyperbitcoinization: 'emblem-hyper', blockfill: 'emblem-blockfill', checkers: 'emblem-checkers',
    'nine-mens-morris': 'emblem-mill', blockli: 'emblem-blockli',
};

/** The emblem id of a game slug (as OverlaySnapshot::emblem()), 'mark' for a game without one. */
export function emblemOf(slug) {
    if (!slug) return 'mark';

    return EMBLEMS[slug] || (String(slug).startsWith('ea-sports-fc') ? 'emblem-football' : 'mark');
}

const names = (list) => (list || []).filter(Boolean).join(' & ');

/** A feed item's game in the preset's language (the snapshot's game list), the feed's own name otherwise. */
const gameOf = (ctx, item) => (ctx.snapshot().games || []).find((g) => g.slug === item.game)?.name || item.gameName || item.game || '';

/** A ticker item list: what the feed brought since the page opened (newest first), then the snapshot's crawl. */
export function tickerItems(ctx, recent) {
    return [...recent, ...ctx.snapshot().ticker].map((it) => ({ ...it, emblem: ctx.art(it.emblem) }));
}

/** A feed item as a ticker item, or null for what the ticker does not carry. */
export function tickerFromFeed(ctx, item) {
    const t = ctx.texts;
    const who = names(item.winners);
    if (!who) return null;
    const head = gameOf(ctx, item);
    switch (item.kind) {
        case 'win':
            return { emblem: emblemOf(item.game), head, text: item.losers?.length ? `${ctx.fill(t.tickerBeats, { winner: who, loser: names(item.losers) })}${item.score ? ` ${item.score}` : ''}` : ctx.fill(t.tickerWins, { winner: who }) };
        case 'score':
            return { emblem: emblemOf(item.game), head, text: `${who} ${item.score}` };
        case 'rank-up':
            return { emblem: 'rank-up', head, text: ctx.fill(t.tickerRankUp, { name: who, tier: item.tier }) };
        default:
            return null;
    }
}

/** A feed item that belongs in the lower third (a sign-up, a closed round): name and line, or null. */
export function describeFeed(ctx, item) {
    const t = ctx.texts;
    const who = names(item.winners);
    switch (item.kind) {
        case 'signup':
            return who ? { name: who, line: ctx.fill(t.signsUp, { tournament: item.tournament }), emblem: emblemOf(item.game) } : null;
        case 'round':
            return item.tournament ? { name: item.tournament, line: ctx.fill(t.roundDone, { round: item.round }), emblem: emblemOf(item.game) } : null;
        default:
            return null;
    }
}

const MEDALS = { 1: 'medal-gold', 2: 'medal-silver', 3: 'medal-bronze' };

/** A feed item as a corner moment: { moment, priority }, or null for what is no pride moment. */
export function prideFromFeed(ctx, feedItem) {
    const t = ctx.texts;
    // Ladder titles (a rank-up's gameName) are worded by the server already; a game's name comes from the snapshot.
    const item = feedItem.kind === 'rank-up' ? feedItem : { ...feedItem, gameName: gameOf(ctx, feedItem) };
    const who = names(item.winners);
    if (!who) return null;
    const art = ctx.art;
    const rays = art('energy-rays');
    switch (item.kind) {
        case 'win':
            return {
                priority: 1,
                moment: {
                    kind: 'win', name: who, trophy: art('trophy'), rays,
                    line: item.losers?.length ? `${ctx.fill(t.beats, { loser: names(item.losers) })}${item.score ? ` ${item.score}` : ''}` : ctx.fill(t.winsIn, { game: item.gameName || item.game }),
                    context: item.tournament ? `${item.gameName}, ${item.tournament}` : item.gameName || '',
                },
            };
        case 'score':
            return { priority: 1, moment: { kind: 'score', name: who, line: ctx.fill(t.sets, { score: item.score }), context: ctx.fill(t.scoreContext, { game: item.gameName }), trophy: art('medal-gold'), rays } };
        case 'rank-up':
            return { priority: 2, moment: { kind: 'rank-up', name: who, line: ctx.fill(t.climbsTo, { tier: item.tier }), context: item.gameName || '', trophy: art('rank-up'), rays } };
        case 'champion':
            return {
                priority: 5,
                moment: {
                    kind: 'champion', name: who, line: t.winsTournament, context: item.gameName ? `${item.tournament}, ${item.gameName}` : item.tournament,
                    trophy: art('crown'), rays, figure: { w: 250, h: 300 }, targetMs: TIMING.championTargetMs, landSound: 'hit',
                },
            };
        case 'payout':
            return {
                priority: 2,
                moment: {
                    kind: 'payout', name: who, rays,
                    line: item.season ? ctx.fill(t.seasonPrize, { sats: ctx.group(item.sats), blocks: item.blocks }) : ctx.fill(t.placePrize, { sats: ctx.group(item.sats), place: item.place }),
                    context: item.tournament || '', trophy: art(MEDALS[item.place] || 'medal-gold'),
                },
            };
        default:
            return null;
    }
}

/** The league's pride list (snapshot `pride`) as corner moments, in the order a quiet league replays them. */
export function pridePool(ctx, pride) {
    if (!pride) return [];
    const t = ctx.texts;
    const art = ctx.art;
    const rays = art('energy-rays');
    const gameName = (slug) => (ctx.snapshot().games || []).find((g) => g.slug === slug)?.name || slug;
    const pool = [];
    const win = pride.win;
    if (win && win.winner) {
        const mode = win.shownMode || win.mode || '';
        pool.push({
            key: `win:${win.kind}:${win.gameId}`, kind: 'win', name: win.winner, trophy: art('trophy'), rays,
            line: win.loser ? `${ctx.fill(t.beats, { loser: win.loser })}${win.score ? ` ${win.score}` : ''}` : ctx.fill(t.winsIn, { game: mode }),
            context: win.tournament ? `${mode}, ${win.tournament}` : mode,
        });
    }
    (pride.rankUps || []).slice(0, 2).forEach((r) => pool.push({ key: `rank:${r.name}:${r.tier}`, kind: 'rank-up', name: r.name, line: ctx.fill(t.climbsTo, { tier: r.tier }), context: r.ladder || '', trophy: art('rank-up'), rays }));
    (pride.climbers || []).slice(0, 2).forEach((c) => pool.push({ key: `climb:${c.name}`, kind: 'climb', name: c.name, line: ctx.fill(t.gains, { gain: c.gain }), context: (c.from || []).join(', '), trophy: art('rank-up'), rays }));
    (pride.streaks || []).slice(0, 1).forEach((s) => pool.push({ key: `streak:${s.name}`, kind: 'streak', name: s.name, line: ctx.fill(t.streak, { wins: s.wins }), context: (s.games || []).map(gameName).join(', '), trophy: art('medal-gold'), rays }));

    return pool.filter((m) => m.name);
}
