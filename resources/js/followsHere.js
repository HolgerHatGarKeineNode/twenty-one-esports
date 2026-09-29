/**
 * "Your follows here" (P47): which accounts a player follows on Nostr
 * (their NIP-02 kind 3) already play in the league, and who does not yet.
 *
 * Read-only: nothing is signed or written here, so a partial read is shown
 * as partial instead of refused (follow.js refuses, because it replaces the
 * list). The newest valid kind 3 of the player's NIP-65 write relays and the
 * configured relays wins (relayRead.js: signature-checked, NIP-01 order);
 * `complete` says whether every relay answered.
 *
 * The league matches the pubkeys with one query (the follows-here
 * component's match(), one whereIn); this module splits the follows into
 * "here" and "not here" in list order and reads names for the ones not here
 * (kind 0), for the invite picker.
 *
 * No DOM or Alpine import: tests/js/followsHere.test.mjs runs it in Node with
 * fake relays.
 */
import { followedPubkeys } from './follow.js';
import { newest, readOwnWriteRelays, readRelays, relayUrls } from './relayRead.js';

/** Follows sent to the league at most (one whereIn); a kind 3 rarely holds more. */
export const MAX_FOLLOWS = 5000;

/** Profiles (kind 0) read for the invite picker at most, 100 authors per filter. */
export const MAX_NAMES = 500;

const HEX_PUBKEY = /^[0-9a-f]{64}$/;

/**
 * The player's follows: unique hex pubkeys in list order, without the
 * player, at most MAX_FOLLOWS.
 *
 * @returns {Promise<{ pubkeys: string[], found: boolean, complete: boolean, answered: number, asked: number, truncated: boolean }>}
 */
export async function readFollows(me, configured, options = {}) {
    const relays = relayUrls(configured);
    const own = await readOwnWriteRelays(me, relays, options);
    const urls = [...new Set([...own.writeRelays, ...relays])];
    const results = await readRelays(urls, [{ kinds: [3], authors: [me] }], options);
    const list = newest(results.flatMap((result) => result.events), me, 3);
    const all = [...new Set(followedPubkeys(list))].filter((pubkey) => pubkey !== me);
    const answered = results.filter((result) => result.eose).length;

    return {
        pubkeys: all.slice(0, MAX_FOLLOWS),
        found: list !== null,
        complete: urls.length > 0 && answered === urls.length,
        answered,
        asked: urls.length,
        truncated: all.length > MAX_FOLLOWS,
    };
}

/**
 * Follows split by whether they play here (`here`: the pubkeys the league
 * matched), both in the follow list's order.
 *
 * @returns {{ here: string[], notHere: string[] }}
 */
export function splitFollows(pubkeys, herePubkeys) {
    const here = new Set((herePubkeys ?? []).filter((pubkey) => HEX_PUBKEY.test(pubkey ?? '')));

    return {
        here: pubkeys.filter((pubkey) => here.has(pubkey)),
        notHere: pubkeys.filter((pubkey) => !here.has(pubkey)),
    };
}

/**
 * The name a kind 0 gives (display_name, else name), trimmed to 64
 * characters, or null. Shown as text only.
 */
export function profileName(event) {
    try {
        const profile = JSON.parse(event?.content ?? '{}');
        const name = [profile.display_name, profile.displayName, profile.name].find((value) => typeof value === 'string' && value.trim() !== '');

        return name ? name.trim().slice(0, 64) : null;
    } catch {
        return null;
    }
}

/**
 * Names of up to MAX_NAMES accounts from their newest valid kind 0 on the
 * relays: one REQ per relay, 100 authors per filter.
 *
 * @returns {Promise<Map<string, string>>} pubkey -> name (accounts without a name are left out)
 */
export async function readNames(pubkeys, relays, options = {}) {
    const authors = pubkeys.filter((pubkey) => HEX_PUBKEY.test(pubkey ?? '')).slice(0, MAX_NAMES);
    const names = new Map();

    if (authors.length === 0) {
        return names;
    }

    const filters = [];
    for (let at = 0; at < authors.length; at += 100) {
        filters.push({ kinds: [0], authors: authors.slice(at, at + 100) });
    }

    const events = (await readRelays(relays, filters, options)).flatMap((result) => result.events);

    for (const pubkey of authors) {
        const name = profileName(newest(events, pubkey, 0));
        if (name !== null) {
            names.set(pubkey, name);
        }
    }

    return names;
}
