import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/*
 * Reverb settings come from the page (<meta name="reverb">, rendered from
 * config/broadcasting.php), not from build-time VITE_ variables, so the same
 * build works locally, in the browser tests and in production. No meta tag
 * (no app key configured) means no websocket: pages that listen check for
 * window.Echo and fall back to asking the server.
 *
 * Loaded on every page of a logged-in player (partials/head.blade.php) and on
 * the realtime pages guests may watch; never elsewhere for guests.
 */
const meta = document.querySelector('meta[name="reverb"]');

if (meta) {
    const reverb = JSON.parse(meta.content);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverb.key,
        wsHost: reverb.host,
        wsPort: reverb.port,
        wssPort: reverb.port,
        forceTLS: reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

/*
 * Global presence (CEO decision, P5b): a logged-in player is on the `online`
 * presence channel on every page, so the lobby shows everyone online, not only
 * who has the lobby open. One membership for the whole page; components read
 * it through window.esportsPresence.subscribe(fn) instead of joining again
 * (a second join() would never see its `here` callback fire).
 */
const presenceUser = document.querySelector('meta[name="presence-user"]');

window.esportsPresence = {
    members: [],
    ready: false,
    listeners: new Set(),

    subscribe(listener) {
        this.listeners.add(listener);
        listener(this.members, this.ready);

        return () => this.listeners.delete(listener);
    },

    set(members) {
        this.members = members;
        this.ready = true;
        this.listeners.forEach((listener) => listener(this.members, this.ready));
    },
};

if (window.Echo && presenceUser) {
    const presence = window.esportsPresence;

    window.Echo.join('online')
        .here((members) => presence.set(members))
        .joining((member) => presence.set([...presence.members.filter((m) => m.id !== member.id), member]))
        .leaving((member) => presence.set(presence.members.filter((m) => m.id !== member.id)))
        .listen('.presence.looking', ({ id, looking }) => presence.set(presence.members.map((m) => (m.id === id ? { ...m, looking } : m))));
}
