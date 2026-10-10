/**
 * /broadcast/{token}: an admin's OBS overlay preset on the broadcast engine (plan "OBS-Broadcast-Overlays", P2). The
 * shell every variant shares: the preset's name as the first lower third, the ticker crawling the snapshot's
 * segments, the league's live feed as lower thirds, and the free centre left alone. P3-P6 build the variants on it.
 *
 * Data: the first snapshot comes with the page (#broadcast-config); the public `league.feed` channel pushes what
 * happens (App\Events\LeagueFeedEvent); a tournament overlay also listens on `tournament.{id}`. The snapshot is
 * polled every 20 s whatever the socket does, so a dropped websocket costs at most one poll: a win the push missed
 * still shows from the snapshot's latest win.
 *
 * window.broadcastOverlay (tests): snapshot(), feed() (items shown or queued), polls().
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { createBroadcast, webglAvailable } from './engine.js';
import { createSound } from './sound.js';
import { TIMING } from './timing.js';

const config = JSON.parse(document.getElementById('broadcast-config').textContent);
const params = new URLSearchParams(location.search);
let snapshot = config.snapshot;
let polls = 0;
const shown = [];

function fill(template, values) {
    return String(template).replace(/:([a-z]+)/gi, (all, key) => (values[key] === undefined || values[key] === null ? all : String(values[key])));
}

/** A feed item as a lower third: who, and what happened, in the preset's language. */
function describe(item) {
    const t = config.texts;
    const names = (item.winners || []).filter(Boolean).join(' & ');
    const game = item.gameName || item.game || '';
    switch (item.kind) {
        case 'win':
            return { name: names, line: item.losers?.length ? fill(t.win, { losers: item.losers.join(' & '), game }) + (item.score ? ` ${item.score}` : '') : fill(t.winAlone, { game }) };
        case 'score':
            return { name: names, line: fill(t.score, { score: item.score, game }) };
        case 'rank-up':
            return { name: names, line: fill(t.rankUp, { tier: item.tier, game }) };
        case 'signup':
            return { name: names, line: fill(t.signup, { tournament: item.tournament }) };
        case 'round':
            return { name: item.tournament, line: fill(t.round, { round: item.round }) };
        case 'champion':
            return { name: names, line: fill(t.champion, { tournament: item.tournament }) };
        case 'payout':
            return { name: names, line: item.season ? fill(t.seasonPayout, { sats: item.sats, blocks: item.blocks }) : fill(t.payout, { sats: item.sats, place: item.place }) };
        default:
            return null;
    }
}

function emblemFor(slug) {
    const map = {
        chess: 'emblem-chess', 'rocket-league': 'emblem-rocket-league', 'age-of-empires-2': 'emblem-age-of-empires-2', tmnf: 'emblem-tmnf',
        'proof-of-pong': 'emblem-pong', hyperbitcoinization: 'emblem-hyper', blockfill: 'emblem-blockfill', checkers: 'emblem-checkers',
        'nine-mens-morris': 'emblem-mill', blockli: 'emblem-blockli',
    };
    const id = map[slug] || (String(slug).startsWith('ea-sports-fc') ? 'emblem-football' : null);

    return id ? config.art[id] : null;
}

/** Whether this overlay shows a feed item: a tournament overlay only its tournament's, every other variant all. */
function wanted(item) {
    if (!snapshot.preset.modules.pride) return false;
    if (config.tournamentId) return item.tournamentId === config.tournamentId;

    return true;
}

async function start() {
    const canvas = document.getElementById('broadcast-canvas');
    if (!webglAvailable() || !window.THREE) {
        document.body.dataset.broadcast = 'no-webgl';
        window.broadcast = { webgl: false };

        return;
    }

    const b = await createBroadcast(canvas, { tier: params.get('tier') });
    const sound = createSound();
    if (snapshot.preset.modules.sound) sound.enable();

    // Lower thirds take turns: one at most every TIMING.rotationMs, in the order they came.
    let nextSlot = 0;
    const lowerThird = (name, line, emblem = null) => {
        const start = Math.max(b.stage.now() + 300, nextSlot);
        nextSlot = start + TIMING.rotationMs;
        b.lowerThird({ start, name, line, emblem });
    };
    const announce = (item) => {
        if (!wanted(item)) return;
        const text = describe(item);
        if (!text || !text.name) return;
        shown.push(item);
        lowerThird(text.name, text.line, emblemFor(item.game));
    };

    window.broadcastOverlay = { snapshot: () => snapshot, feed: () => shown.slice(), polls: () => polls };

    b.start();
    lowerThird(snapshot.preset.name, config.texts.onAir);
    if (snapshot.ticker.length > 0) {
        b.ticker({ start: b.stage.now() + 600, label: config.texts.live, items: snapshot.ticker.map((it) => ({ ...it, emblem: it.emblem === 'mark' ? 'mark' : config.art[it.emblem] || 'mark' })) });
    }
    document.body.dataset.broadcast = 'running';

    // The latest win the snapshot knows: a new one after a poll that no push announced is shown from here.
    const winKey = (s) => (s.pride?.win ? `${s.pride.win.kind}:${s.pride.win.gameId}` : null);
    let lastWin = winKey(snapshot);
    // A win pushed since the last poll is the snapshot's latest win too: the poll then only takes note of it.
    let heardWin = false;

    const refresh = async () => {
        try {
            const response = await fetch(config.snapshotUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            snapshot = await response.json();
            polls++;
            const key = winKey(snapshot);
            if (key && key !== lastWin && !heardWin && snapshot.pride.win.winner && !config.tournamentId) {
                announce({ kind: 'win', game: null, gameName: snapshot.pride.win.shownMode || snapshot.pride.win.mode, winners: [snapshot.pride.win.winner], losers: snapshot.pride.win.loser ? [snapshot.pride.win.loser] : [], score: snapshot.pride.win.score || null });
            }
            lastWin = key;
            heardWin = false;
        } catch {
            // Offline for a moment: the next poll tries again.
        }
    };
    setInterval(refresh, config.pollMs);

    const meta = document.querySelector('meta[name="reverb"]');
    if (meta) {
        window.Pusher = Pusher;
        const reverb = JSON.parse(meta.content);
        const echo = new Echo({ broadcaster: 'reverb', key: reverb.key, wsHost: reverb.host, wsPort: reverb.port, wssPort: reverb.port, forceTLS: reverb.scheme === 'https', enabledTransports: ['ws', 'wss'] });
        echo.channel('league.feed').listen('.league.feed', (event) => (event.items || []).forEach((item) => {
            if (item.kind === 'win') heardWin = true;
            announce(item);
        }));
        if (config.tournamentId) echo.channel(`tournament.${config.tournamentId}`).listen('.tournament.changed', () => refresh());
    }
}

start();
