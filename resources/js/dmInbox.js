/**
 * The players' NIP-17 DM relays (kind 10050) for the match room and the
 * game chat (resources/js/roomChat.js, gameChat.js).
 *
 * NIP-17: a gift wrap goes to the relays of the recipient's 10050, and a
 * client reads its own DMs there. Other clients do exactly that: Amethyst
 * publishes each wrap ONLY to the recipient's 10050 relays (with a 10050;
 * EventBroadcaster.computeRelayListToBroadcast), so a reply written there
 * never reaches the league's chat relays unless the player listed one of
 * them. The chats therefore also read on the player's own 10050 relays and
 * also publish each wrap to its recipient's 10050 relays. The league's chat
 * relays stay in both: the room keeps working for a player without a 10050.
 *
 * A 10050 is signed by a member of the room, not by the league, so what it
 * may make the browser connect to is bounded: secure websockets (`wss://`)
 * on a public host name, or a relay the league configured itself (the
 * local test bed runs plain `ws://127.0.0.1`), at most MAX_INBOX_RELAYS per
 * player, the same cap as the server's lookup (App\Support\Notifications\DmRelays).
 *
 * No DOM or Alpine import: tests/js/dmReplies.test.mjs runs it in Node.
 */
import { normalizeURL } from 'nostr-tools/utils';
import { newest, readRelays, relayUrls } from './relayRead.js';

export const MAX_INBOX_RELAYS = 5;

/** How long the lookup waits for a relay; a silent one counts as "no 10050 there". */
export const INBOX_LOOKUP_MS = 3000;

/** Normalised (nostr-tools' normalizeURL, as its pool does), deduplicated, websocket URLs only. */
export function normalizedRelays(list) {
    const urls = [];

    for (const url of list ?? []) {
        if (typeof url !== 'string' || !/^wss?:\/\//i.test(url)) continue;
        try {
            urls.push(normalizeURL(url));
        } catch {
            // not a URL: skipped
        }
    }

    return [...new Set(urls)];
}

const PRIVATE_V4 = [/^0\./, /^10\./, /^127\./, /^169\.254\./, /^172\.(1[6-9]|2\d|3[01])\./, /^192\.168\./, /^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./];

/** A `wss://` URL on a host name the open internet can reach: no IP of a private range, no localhost, no IPv6 literal. */
export function isPublicWss(url) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return false;
    }
    const host = parsed.hostname.toLowerCase();

    if (parsed.protocol !== 'wss:' || host === '' || host.startsWith('[') || !host.includes('.')) return false;
    if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local') || host.endsWith('.internal')) return false;

    return !(/^\d+\.\d+\.\d+\.\d+$/.test(host) && PRIVATE_V4.some((range) => range.test(host)));
}

/** The relays of one 10050 the browser may use: public `wss://` or configured, at most MAX_INBOX_RELAYS. */
export function inboxRelaysOf(list, trusted = []) {
    const configured = new Set(normalizedRelays(trusted));
    const named = normalizedRelays((list?.tags ?? []).filter((tag) => tag[0] === 'relay').map((tag) => tag[1]));

    return named.filter((url) => configured.has(url) || isPublicWss(url)).slice(0, MAX_INBOX_RELAYS);
}

/**
 * The newest valid 10050 of each pubkey, read from `relays` (signature
 * checked, newest wins: resources/js/relayRead.js), as the relays the
 * browser may use. A pubkey without one maps to [].
 *
 * @returns {Promise<Map<string, string[]>>}
 */
export async function lookupInboxes(pubkeys, relays, { trusted = relays, ...options } = {}) {
    const authors = [...new Set((pubkeys ?? []).filter((p) => typeof p === 'string' && /^[0-9a-f]{64}$/.test(p)))];
    // As configured (relayRead.js opens one socket per distinct URL).
    const asked = relayUrls(relays);

    if (authors.length === 0 || asked.length === 0) return new Map(authors.map((p) => [p, []]));

    const results = await readRelays(asked, [{ kinds: [10050], authors }], { timeoutMs: INBOX_LOOKUP_MS, ...options });
    const events = results.flatMap((result) => result.events);

    return new Map(authors.map((p) => [p, inboxRelaysOf(newest(events, p, 10050), trusted)]));
}

/** Where a wrap to `target` goes: the chat relays and the target's 10050 relays. */
export function relaysFor(target, chatRelays, inboxes) {
    return normalizedRelays([...(chatRelays ?? []), ...(inboxes?.get(target) ?? [])]);
}

/** My 10050 relays that the chat does not read already: the second subscription. */
export function extraInboxRelays(me, chatRelays, inboxes) {
    const reading = new Set(normalizedRelays(chatRelays));

    return (inboxes?.get(me) ?? []).filter((url) => !reading.has(url));
}
