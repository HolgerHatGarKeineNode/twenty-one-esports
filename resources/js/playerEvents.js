/**
 * One dispatcher for the shell of a logged-in player (performance plan P3,
 * F5): the match dock, the cup-match badge and banner, and the bell used to
 * listen on the player's private channel each, with a 120-second poll each,
 * so one event cost two to four Livewire requests per tab. Now this module
 * listens once and polls once, and hands every subscriber the same batch of
 * reasons in the same task, so their `$refresh` calls leave as ONE request
 * (Livewire bundles the messages of different components that are queued
 * within its 5 ms buffer). On the server the badge then reads the dock's
 * CupMatchNow::for() (memoised per request) instead of finding it again.
 *
 * Reasons: `notification` (alerts.js raises `esports-notification` for every
 * one), `game` (a chess or board game started), `invite` (a chess, board or
 * series invite changed), `series` (SeriesMatchChanged), `poll` (the slow
 * fallback: livePoll.js, every `poll` seconds without a live websocket,
 * every `pollWithSocket` seconds with one, never in a hidden tab).
 *
 *   const off = subscribePlayerEvents(config, (reasons) => reasons.has('series') && this.$wire.$refresh());
 *   // destroy(): off();
 */
import { livePoll } from './livePoll.js';

/** Events close together make one batch. */
export const BATCH_MS = 250;

const CHANNEL_EVENTS = {
    '.chess.game-started': 'game',
    '.board.game-started': 'game',
    '.chess.invite': 'invite',
    '.board.invite': 'invite',
    '.series.invite': 'invite',
    '.series.changed': 'series',
};

const hub = {
    listeners: new Set(),
    reasons: new Set(),
    timer: null,
    started: false,
    stopPoll: null,
    channel: null,
    userId: null,
    handlers: [],
};

function flush() {
    hub.timer = null;
    const reasons = new Set(hub.reasons);
    hub.reasons.clear();
    hub.listeners.forEach((listener) => listener(reasons));
}

/** Collect a reason; the batch goes out BATCH_MS after the first one. */
export function raisePlayerEvent(reason) {
    hub.reasons.add(reason);
    if (hub.timer === null) {
        hub.timer = setTimeout(flush, BATCH_MS);
    }
}

function onNotification() {
    raisePlayerEvent('notification');
}

function listenOnChannel() {
    if (hub.channel || !window.Echo || !hub.userId) return;
    hub.channel = window.Echo.private('App.Models.User.' + hub.userId);
    Object.entries(CHANNEL_EVENTS).forEach(([event, reason]) => {
        const handler = () => raisePlayerEvent(reason);
        hub.handlers.push([event, handler]);
        hub.channel.listen(event, handler);
    });
}

function start(config) {
    hub.started = true;
    hub.userId = config.userId ?? document.querySelector('meta[name="presence-user"]')?.content ?? null;
    window.addEventListener('esports-notification', onNotification);
    listenOnChannel();
    // echo.js is its own entry: it may run after Alpine started the first subscriber.
    if (!hub.channel && document.readyState !== 'complete') {
        window.addEventListener('load', listenOnChannel, { once: true });
    }
    hub.stopPoll = livePoll({ seconds: config.poll, withSocket: config.pollWithSocket, run: () => raisePlayerEvent('poll') });
}

function stop() {
    hub.started = false;
    clearTimeout(hub.timer);
    hub.timer = null;
    hub.reasons.clear();
    hub.stopPoll?.();
    window.removeEventListener('esports-notification', onNotification);
    window.removeEventListener('load', listenOnChannel);
    // Stop listening, never leave: alerts.js and the presence keep the player's channel.
    hub.handlers.forEach(([event, handler]) => hub.channel?.stopListening(event, handler));
    hub.handlers = [];
    hub.channel = null;
}

/**
 * Subscribe a shell component; the first one starts the listening and the
 * poll with its config ({ userId, poll, pollWithSocket }), the last one to go
 * stops them. Returns the unsubscribe function.
 */
export function subscribePlayerEvents(config, listener) {
    hub.listeners.add(listener);
    if (!hub.started) start(config);

    return () => {
        hub.listeners.delete(listener);
        if (hub.listeners.size === 0 && hub.started) stop();
    };
}
