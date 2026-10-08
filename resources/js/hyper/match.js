/**
 * Entry of a Hyperbitcoinization match page (plan "Hyperbitcoinization", P2; resources/views/hyper/match.blade.php):
 * the full-screen table (game.js), its chat drawer (chat.js) and emotes (emotes.js), on Reverb.
 *
 * three.js r128, GSAP and d3 are globals from public/hyper/vendor, and the map geometry is window.HB_MAP
 * (public/hyper/mapdata.js); the page loads them as classic scripts, which run before this module.
 *
 * Realtime: a seated player listens on the private `hyper.{ulid}` (and its seat's `.seat.{n}` for the cards,
 * and the presence `.here`), anybody else on the public `.watch`. Whenever the socket (re)connects or the
 * channel is subscribed, and when the tab becomes visible again, the page asks for the events it missed
 * (game.catchUp()); PlySync drops what it has seen. Without a websocket it asks every few seconds.
 *
 * A replay (P3, `config.replay`) listens to nothing: replay.js feeds the table from the replayed log. After a
 * match `hyper.rematch` says who accepted a rematch, and where the new match is once it started.
 */
import './arena.js';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { setTexts, t } from './i18n.js';
import { createNet } from './net.js';
import { startGame } from './game.js';
import { startChat } from './chat.js';
import { startEmotes } from './emotes.js';
import { replayNet, startReplay } from './replay.js';

const $ = (s) => document.querySelector(s);
const config = JSON.parse($('#hyper-config').textContent);
config.snapshot = JSON.parse($('#hyper-snapshot').textContent);
setTexts(config.texts, config.locale);
window.hyperT = (key) => t(key);

const replaying = Boolean(config.replay);
const http = createNet(config.csrf);
// The replay's position: the plies fed so far, and the ply the table shows (game.js asks for its snapshot).
const cursor = { fed: 0, plies: [], playing: false, shown: () => game.state().syncPly ?? 0 };
const net = replaying ? replayNet(http, config, cursor) : http;
const game = startGame(config, net);
let live = false;
const emotes = startEmotes(config, net, game, { live: () => live });

const badge = $('#chat-unread');
const chat = startChat(config.chat, {
    seats: config.snapshot.seats ?? [],
    onUnread: (n) => { badge.hidden = n === 0; badge.textContent = n > 99 ? '99+' : String(n); },
});
$('#chat-btn').addEventListener('click', () => { chat.toggle(); $('#emotes').hidden = true; });
$('#chat-close').addEventListener('click', () => chat.close());
window.hyperChat = chat;

const meta = replaying ? null : document.querySelector('meta[name="reverb"]');
const id = config.snapshot.id;
const me = config.snapshot.me;

if (meta) {
    window.Pusher = Pusher;
    const reverb = JSON.parse(meta.content);
    const echo = new Echo({
        broadcaster: 'reverb',
        key: reverb.key,
        wsHost: reverb.host,
        wsPort: reverb.port,
        wssPort: reverb.port,
        forceTLS: reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
    window.Echo = echo;

    const table = me !== null ? echo.private(`hyper.${id}`) : echo.channel(`hyper.${id}.watch`);
    table.listen('.hyper.updated', (payload) => game.onUpdated(payload))
        .listen('.hyper.emote', (payload) => emotes.show(payload))
        .listen('.hyper.rematch', (payload) => game.onRematch(payload));
    // Subscribed: whatever happened between rendering the page and now is fetched once.
    table.subscribed(() => { live = true; document.body.dataset.live = '1'; game.catchUp(); });

    if (me !== null) {
        echo.private(`hyper.${id}.seat.${me}`).listen('.hyper.hand', (payload) => game.onHand(payload));
        let here = [];
        const set = () => game.setConnected(here.map((member) => member.seat));
        echo.join(`hyper.${id}.here`)
            .here((members) => { here = members; set(); })
            .joining((member) => { here = [...here.filter((m) => m.id !== member.id), member]; set(); })
            .leaving((member) => { here = here.filter((m) => m.id !== member.id); set(); });
    }

    let wasConnected = false;
    echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        live = current === 'connected';
        document.body.dataset.live = live ? '1' : '0';
        if (current === 'connected') {
            if (wasConnected) game.catchUp();
            wasConnected = true;
        } else if (wasConnected && current !== 'connecting') {
            game.toast(t('Connection lost. Reconnecting …'));
        }
    });
} else if (!replaying) {
    // No websocket here: ask for news every few seconds.
    setInterval(() => game.catchUp(), 4000);
}

document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') game.catchUp(); });

// The table is drawn: the loading screen goes (a replay once its plies are here).
function ready() {
    const boot = $('#boot');
    if (boot) {
        boot.classList.add('done');
        setTimeout(() => boot.remove(), 600);
    }
    document.body.dataset.ready = '1';
}

if (replaying) {
    document.body.dataset.live = '1';
    http.get(config.replay.data).then((r) => {
        cursor.plies = r.ok && Array.isArray(r.data?.plies) ? r.data.plies : [];
        window.hyperReplay = startReplay(config, http, game, cursor);
        ready();
    });
} else {
    ready();
}
