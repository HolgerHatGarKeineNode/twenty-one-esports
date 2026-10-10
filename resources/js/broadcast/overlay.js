/**
 * /broadcast/{token}: an admin's OBS overlay preset on the broadcast engine (plan "OBS-Broadcast-Overlays"). This is
 * the part every variant shares: config, engine, sound, the snapshot poll and the live channels; the variant itself
 * (resources/js/broadcast/variants/*.js) decides what goes on air: league live (P3), tournament (P4), the break
 * scene (P5) and the bracket (P6); the shell is what a variant falls back to without its data.
 *
 * Data: the first snapshot comes with the page (#broadcast-config); the public `league.feed` channel pushes what
 * happens (App\Events\LeagueFeedEvent); a tournament overlay also listens on `tournament.{id}` and refreshes on every
 * `.tournament.changed`. The snapshot is polled every 20 s whatever the socket does, so a dropped websocket costs at
 * most one poll.
 *
 * window.broadcastOverlay (tests): snapshot(), feed() (feed items taken), polls(), receive(event) (exactly what the
 * `league.feed` listener does with a pushed event), tournamentChanged() (what `.tournament.changed` does), state().
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { createDirector } from './director.js';
import { createBroadcast, webglAvailable } from './engine.js';
import { createSound } from './sound.js';
import { runBreak } from './variants/break.js';
import { runBracket } from './variants/bracket.js';
import { runLeagueLive } from './variants/leagueLive.js';
import { runShell } from './variants/shell.js';
import { runTournament } from './variants/tournament.js';

const config = JSON.parse(document.getElementById('broadcast-config').textContent);
const params = new URLSearchParams(location.search);
let snapshot = config.snapshot;
let polls = 0;
const taken = [];
const snapshotListeners = new Set();
const feedListeners = new Set();
let changedListener = null;

function fill(template, values) {
    return String(template).replace(/:([a-z]+)/gi, (all, key) => (values[key] === undefined || values[key] === null ? all : String(values[key])));
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
    const director = createDirector(b, sound);

    const refresh = async () => {
        try {
            const response = await fetch(config.snapshotUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const previous = snapshot;
            snapshot = await response.json();
            polls++;
            snapshotListeners.forEach((fn) => fn(snapshot, previous));
        } catch {
            // Offline for a moment: the next poll tries again.
        }
    };
    const receive = (event) => (event.items || []).forEach((item) => {
        taken.push(item);
        feedListeners.forEach((fn) => fn(item));
    });
    const tournamentChanged = () => (changedListener ? changedListener() : refresh());

    const ctx = {
        b,
        sound,
        director,
        config,
        texts: config.texts,
        fill,
        locale: snapshot.preset.locale,
        snapshot: () => snapshot,
        onSnapshot: (fn) => snapshotListeners.add(fn),
        onFeed: (fn) => feedListeners.add(fn),
        onTournamentChanged: (fn) => { changedListener = fn; },
        refresh,
        /** An asset of the pack by its snapshot id ('trophy', 'emblem-chess', ...); 'mark' stays the league mark. */
        art: (id) => (id === 'mark' ? 'mark' : config.art[id] || 'mark'),
        // Grouped by a no-break space in both languages, as the site writes sats (App\Support\Cards\ShareCard::sats()).
        group: (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0'),
    };
    let variantState = () => ({});
    window.broadcastOverlay = {
        snapshot: () => snapshot,
        feed: () => taken.slice(),
        polls: () => polls,
        receive,
        tournamentChanged,
        refresh,
        state: () => variantState(),
    };

    b.start();
    const run = { 'league-live': runLeagueLive, tournament: runTournament, break: runBreak, bracket: runBracket }[config.variant] || runShell;
    variantState = run(ctx) || (() => ({}));
    document.body.dataset.broadcast = 'running';

    setInterval(refresh, config.pollMs);

    const meta = document.querySelector('meta[name="reverb"]');
    if (meta) {
        window.Pusher = Pusher;
        const reverb = JSON.parse(meta.content);
        const echo = new Echo({ broadcaster: 'reverb', key: reverb.key, wsHost: reverb.host, wsPort: reverb.port, wssPort: reverb.port, forceTLS: reverb.scheme === 'https', enabledTransports: ['ws', 'wss'] });
        echo.channel('league.feed').listen('.league.feed', receive);
        if (config.tournamentId) echo.channel(`tournament.${config.tournamentId}`).listen('.tournament.changed', tournamentChanged);
    }
}

start();
