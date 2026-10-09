import Echo from 'laravel-echo';

import { startAlerts } from './alerts.js';
import { startOnSitePing } from './onSite.js';

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
/*
 * Once per document: a tab opened before a deploy gets the new build's entry
 * injected by wire:navigate next to the running one (reviewer gate P6b,
 * 2026-10-05: a second Echo, a second socket, every alert twice). The layout's
 * build marker reloads such a tab; this keeps the second run inert meanwhile.
 */
const alreadyBooted = window.__esportsEchoBooted === true;
window.__esportsEchoBooted = true;

const meta = document.querySelector('meta[name="reverb"]');

if (meta && !alreadyBooted) {
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

/*
 * "Looking to play" (plan "Proof of Pong", P4): a member's data on the channel is what they had when they JOINED
 * it, so a player who switched later showed to everyone who joined after that as they were at their join, until
 * their next page load (measured: tests/Browser/OnlineLookingTest.php). `known` holds what is known to be newer: a
 * lobby's server-rendered list of everyone looking right now (seed(), components/lobby/online-now; after it a member
 * missing from it is not looking), each `.presence.looking` push, and a member's data at their own join. The online
 * list reads the members with it applied.
 *
 * Each of the three carries the server time it was read at (`at`, `lookingAt`), and the newest wins, not the last one
 * heard (P6): a player who reloads and switches at once sends a push that can arrive BEFORE their rejoin, whose data
 * was read before the switch; heard last, it put the old state back for good (OnlineLookingTest, 1 in 4 runs).
 * Without a time (an older server) a source counts as the newest, as before.
 */
window.esportsPresence = alreadyBooted ? window.esportsPresence : {
    members: [],
    ready: false,
    listeners: new Set(),
    // id => { looking, at }
    known: new Map(),
    seeded: false,
    seedAt: -Infinity,

    subscribe(listener) {
        this.listeners.add(listener);
        listener(this.members, this.ready);

        return () => this.listeners.delete(listener);
    },

    set(members) {
        this.members = members.map((m) => this.fresh(m));
        this.ready = true;
        this.listeners.forEach((listener) => listener(this.members, this.ready));
    },

    /** The member with the newest known "looking": what was learned or seeded, unless the member's own data is newer. */
    fresh(member) {
        const known = this.known.get(member.id) ?? (this.seeded ? { looking: null, at: this.seedAt } : null);
        const own = { looking: member.looking ?? null, at: member.lookingAt ?? -Infinity };

        return known === null || own.at > known.at ? member : { ...member, looking: known.looking };
    },

    /** A lobby's list of everyone looking at server time `at` ({ userId: key }): newer than anything read before it. */
    seed(looking, at = -Infinity) {
        const known = new Map(Object.entries(looking ?? {}).map(([id, key]) => [Number(id), { looking: key, at }]));
        this.known.forEach((state, id) => {
            if (state.at > at) known.set(id, state);
        });
        this.known = known;
        this.seedAt = at;
        this.seeded = true;
        if (this.ready) this.set(this.members);
    },

    /** A state of one member read at server time `at` (their own join, or a push): kept unless a newer one is known. */
    learn(id, looking, at) {
        const known = this.known.get(id)?.at ?? (this.seeded ? this.seedAt : -Infinity);
        if (at === undefined || at === null) {
            this.known.set(id, { looking: looking ?? null, at: known });
        } else if (at >= known) {
            this.known.set(id, { looking: looking ?? null, at });
        }
    },
};

// A lobby that rendered before this module ran left its list here.
if (!alreadyBooted && window.esportsPresenceSeed) {
    window.esportsPresence.seed(window.esportsPresenceSeed.looking, window.esportsPresenceSeed.at);
    delete window.esportsPresenceSeed;
}

/*
 * A player's page load closes their websocket, so Reverb sends `leaving` and,
 * a moment later, `joining` for someone who never went anywhere: the lobby's
 * online list dropped them on every link they clicked (measured, P5e). A
 * leave therefore takes effect only after LEAVE_GRACE_MS without a rejoin.
 */
const LEAVE_GRACE_MS = 5000;

if (!alreadyBooted && window.Echo && presenceUser) {
    const presence = window.esportsPresence;
    const leaving = new Map();

    const cancelLeave = (id) => {
        clearTimeout(leaving.get(id));
        leaving.delete(id);
    };

    window.Echo.join('online')
        .here((members) => {
            leaving.forEach((timer) => clearTimeout(timer));
            leaving.clear();
            presence.set(members);
        })
        .joining((member) => {
            cancelLeave(member.id);
            presence.learn(member.id, member.looking, member.lookingAt);
            presence.set([...presence.members.filter((m) => m.id !== member.id), member]);
        })
        .leaving((member) => {
            cancelLeave(member.id);
            leaving.set(
                member.id,
                setTimeout(() => {
                    leaving.delete(member.id);
                    presence.set(presence.members.filter((m) => m.id !== member.id));
                }, LEAVE_GRACE_MS),
            );
        })
        .listen('.presence.looking', ({ id, looking, at }) => {
            presence.learn(id, looking, at);
            presence.set(presence.members);
        });
}

// Notifications on every logged-in page (P5c): toast, sound, tab title, desktop notification.
if (!alreadyBooted) {
    startAlerts();
}
// While a page is visible, no push or DM is sent; the bell and the toast reach the player (App\Support\Notifications\OnSite).
if (!alreadyBooted) {
    startOnSitePing();
}
