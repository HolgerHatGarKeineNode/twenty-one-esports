/**
 * Reading and publishing a player's own lists on their relays (P11), one
 * websocket per relay, with the checks the league relies on:
 *
 * - A relay counts as READ only after its real EOSE frame. An open socket, a
 *   timeout, a CLOSED or an error is "not read", whatever arrived before it.
 *   (nostr-tools fires `oneose` on its own EOSE timeout as well, so its
 *   subscription callbacks cannot tell the two apart.)
 * - Every event is signature-checked before it is kept, and ids are never
 *   deduplicated before that check: a relay that answers first with a forged
 *   copy of the right id (junk signature) cannot hide the real event. The
 *   pool of nostr-tools marks an id as known before it verifies it.
 * - Of the valid events the NEWEST per kind wins (NIP-01: highest
 *   created_at, then lowest id), not the first to arrive.
 *
 * No DOM or Alpine import: tests/js/relayRead.test.mjs runs it in Node with a
 * fake WebSocket.
 */
import { verifyEvent } from 'nostr-tools/pure';

export const READ_TIMEOUT_MS = 4000;

export const PUBLISH_TIMEOUT_MS = 4000;

let subscriptions = 0;

export function relayUrls(list) {
    return [...new Set((list ?? []).filter((url) => typeof url === 'string' && /^wss?:\/\//.test(url)))];
}

/**
 * One REQ on one relay.
 *
 * @returns {Promise<{ url: string, eose: boolean, events: object[] }>}
 */
export function readRelay(url, filters, { WebSocketImpl = globalThis.WebSocket, verify = verifyEvent, timeoutMs = READ_TIMEOUT_MS } = {}) {
    return new Promise((resolve) => {
        const subscription = 'p11-' + (++subscriptions).toString(36);
        const events = [];
        let done = false;
        let socket;

        const finish = (eose) => {
            if (done) {
                return;
            }
            done = true;
            clearTimeout(timer);
            try {
                socket?.close();
            } catch {
                // already closed
            }
            resolve({ url, eose, events });
        };

        const timer = setTimeout(() => finish(false), timeoutMs);

        try {
            socket = new WebSocketImpl(url);
        } catch {
            finish(false);

            return;
        }

        socket.onopen = () => socket.send(JSON.stringify(['REQ', subscription, ...filters]));
        socket.onerror = () => finish(false);
        socket.onclose = () => finish(false);
        socket.onmessage = (message) => {
            let frame;
            try {
                frame = JSON.parse(typeof message.data === 'string' ? message.data : String(message.data));
            } catch {
                return;
            }

            if (! Array.isArray(frame) || frame[1] !== subscription) {
                return;
            }

            if (frame[0] === 'EVENT' && frame[2] && typeof frame[2] === 'object') {
                // A fresh copy: verifyEvent caches its verdict on the object it is given.
                const event = JSON.parse(JSON.stringify(frame[2]));
                let valid = false;
                try {
                    valid = verify(event) === true;
                } catch {
                    valid = false;
                }
                if (valid) {
                    events.push(event);
                }
            } else if (frame[0] === 'EOSE') {
                finish(true);
            } else if (frame[0] === 'CLOSED') {
                finish(false);
            }
        };
    });
}

/** Read several relays side by side, each on its own socket. */
export function readRelays(urls, filters, options = {}) {
    return Promise.all(relayUrls(urls).map((url) => readRelay(url, filters, options)));
}

/**
 * The newest event of `kind` by `pubkey` (and `d`, when given) among
 * already verified events: highest created_at, a tie to the lowest id.
 */
export function newest(events, pubkey, kind, d = null) {
    return events
        .filter((event) => event.pubkey === pubkey && event.kind === kind)
        .filter((event) => d === null || (event.tags ?? []).some((tag) => tag[0] === 'd' && tag[1] === d))
        .sort((a, b) => b.created_at - a.created_at || (a.id < b.id ? -1 : 1))[0] ?? null;
}

/** The write relays of a NIP-65 list (`r` without marker, or `write`). */
export function writeRelaysOf(relayList) {
    return relayUrls((relayList?.tags ?? [])
        .filter((tag) => tag[0] === 'r' && (tag[2] === undefined || tag[2] === 'write'))
        .map((tag) => tag[1]));
}

/**
 * The player's profile badge lists as the league needs them:
 *
 * 1. the NIP-65 relay list (10002) from the configured relays; if none of
 *    them delivered EOSE, the write relays are unknown and nothing is read;
 * 2. the newest valid 10008 and 30008 `profile_badges` from the write relays
 *    and the configured relays; the write relays (or, without a relay list,
 *    the configured relays) are the ones that must answer.
 *
 * `read` is true only when at least one of those relays delivered EOSE.
 *
 * @returns {Promise<{ read: boolean, found: object[], answered: number, asked: number, writeRelays: string[] }>}
 */
export async function readProfileBadges(pubkey, configured, options = {}) {
    const relays = relayUrls(configured);
    const lists = await readRelays(relays, [{ kinds: [10002], authors: [pubkey] }], options);

    if (! lists.some((result) => result.eose)) {
        return { read: false, found: [], answered: 0, asked: relays.length, writeRelays: [] };
    }

    const relayList = newest(lists.flatMap((result) => result.events), pubkey, 10002);
    const own = writeRelaysOf(relayList);
    const mustAnswer = own.length > 0 ? own : relays;
    const results = await readRelays([...mustAnswer, ...relays], [
        { kinds: [10008], authors: [pubkey] },
        { kinds: [30008], authors: [pubkey], '#d': ['profile_badges'] },
    ], options);
    const answered = results.filter((result) => result.eose && mustAnswer.includes(result.url)).length;
    const events = results.flatMap((result) => result.events);

    return {
        read: answered > 0,
        found: [newest(events, pubkey, 10008), newest(events, pubkey, 30008, 'profile_badges')].filter(Boolean),
        answered,
        asked: mustAnswer.length,
        writeRelays: mustAnswer,
    };
}

/**
 * Send one event to one relay; true only on `["OK", id, true, …]`.
 */
export function publishToRelay(url, event, { WebSocketImpl = globalThis.WebSocket, timeoutMs = PUBLISH_TIMEOUT_MS } = {}) {
    return new Promise((resolve) => {
        let done = false;
        let socket;
        const finish = (ok) => {
            if (done) {
                return;
            }
            done = true;
            clearTimeout(timer);
            try {
                socket?.close();
            } catch {
                // already closed
            }
            resolve(ok);
        };
        const timer = setTimeout(() => finish(false), timeoutMs);

        try {
            socket = new WebSocketImpl(url);
        } catch {
            finish(false);

            return;
        }

        socket.onopen = () => socket.send(JSON.stringify(['EVENT', event]));
        socket.onerror = () => finish(false);
        socket.onclose = () => finish(false);
        socket.onmessage = (message) => {
            try {
                const frame = JSON.parse(typeof message.data === 'string' ? message.data : String(message.data));
                if (Array.isArray(frame) && frame[0] === 'OK' && frame[1] === event.id) {
                    finish(frame[2] === true);
                }
            } catch {
                // not a frame for us
            }
        };
    });
}

/** Publish to every relay; the number that answered OK true. */
export async function publishToRelays(urls, event, options = {}) {
    const results = await Promise.all(relayUrls(urls).map((url) => publishToRelay(url, event, options)));

    return results.filter(Boolean).length;
}
