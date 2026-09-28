/**
 * Reading comments, likes and RSVPs of one league event from relays (P48,
 * NIP "Comments, likes and RSVPs"), without DOM or Alpine, so
 * tests/js/commentsRead.test.mjs runs it in Node:
 *
 * - comments (NIP-22 kind 1111) whose ROOT is the target: `#A` the address of
 *   a tournament's calendar event, or `#E` the id of a game record or a
 *   series' challenge. A relay may ignore a filter, so every event is checked
 *   again here: exactly one root tag with the target, `K` its kind. Pages of
 *   `limit` newest first, the next page with `until`, duplicates by id dropped;
 * - likes (NIP-25 kind 7): the newest reaction per author counts, `+` or empty
 *   is a like (NIP-25), anything else is not;
 * - RSVPs (NIP-52 kind 31925): the newest per author to the address, whatever
 *   its `d`;
 * - the league's moderation: kind 43 hides an event, kind 44 a pubkey, only
 *   from the channel creator (the league's NIP-28 moderation of the game
 *   channels, `php artisan esports:game-channels --mute/--hide`).
 *
 * Every event arriving through relayRead.js has a checked signature; content
 * is plain text and is only ever rendered with x-text.
 */
import { readRelays, relayUrls } from './relayRead.js';

export const KIND_COMMENT = 1111;
export const KIND_REACTION = 7;
export const KIND_RSVP = 31925;
export const KIND_HIDE = 43;
export const KIND_MUTE = 44;

/** Characters of one comment that are shown; a longer one ends in "…". */
export const MAX_TEXT = 2000;

/** Hidden events and muted pubkeys kept from the moderation events. */
export const MAX_MODERATION = 500;

export const RSVP_STATUSES = ['accepted', 'declined', 'tentative'];

const HEX64 = /^[0-9a-f]{64}$/;

const tagsOf = (event) => (Array.isArray(event?.tags) ? event.tags.filter((tag) => Array.isArray(tag) && typeof tag[0] === 'string') : []);

const named = (event, name) => tagsOf(event).filter((tag) => tag[0] === name);

/** NIP-01 order: the newest first, a tie to the lowest id. */
export function newestFirst(a, b) {
    return b.created_at - a.created_at || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
}

/** The relays the page reads from: the league relays, at most `max`. */
export function readRelayList(relays, max = 5) {
    return relayUrls(relays).slice(0, Math.max(1, max));
}

/** The comment filter of one page. */
export function commentFilter(target, { limit = 20, until = null } = {}) {
    const filter = { kinds: [KIND_COMMENT], limit };

    if (target.address) {
        filter['#A'] = [target.address];
    } else {
        filter['#E'] = [target.eventId];
    }

    if (until !== null) {
        filter.until = until;
    }

    return filter;
}

/** Reactions to the target: by address for a tournament (every version), else by id. */
export function reactionFilter(target, limit = 500) {
    return target.address
        ? { kinds: [KIND_REACTION], '#a': [target.address], limit }
        : { kinds: [KIND_REACTION], '#e': [target.eventId], limit };
}

export function rsvpFilter(address, limit = 500) {
    return { kinds: [KIND_RSVP], '#a': [address], limit };
}

export function moderationFilter(creator, limit = MAX_MODERATION) {
    return { kinds: [KIND_HIDE, KIND_MUTE], authors: [creator], limit };
}

/** Whether a comment's root is the target (NIP-22: uppercase root, `K` its kind). */
export function inScope(event, target) {
    if (event?.kind !== KIND_COMMENT || typeof event.content !== 'string' || typeof event.id !== 'string') {
        return false;
    }

    const roots = named(event, target.address ? 'A' : 'E');
    const kinds = named(event, 'K');

    return roots.length === 1 && roots[0][1] === (target.address ?? target.eventId)
        && kinds.length === 1 && kinds[0][1] === String(target.kind);
}

/** A reply to another comment (parent kind 1111), not to the target itself. */
export function isReply(event) {
    return named(event, 'k').some((tag) => tag[1] === String(KIND_COMMENT));
}

/**
 * Add one page to the list: comments in scope, not seen before, newest
 * first, at most `max`. `added` counts the new ones.
 */
export function mergeComments(list, events, target, max = 200) {
    const seen = new Set(list.map((comment) => comment.id));
    const fresh = [];

    for (const event of events) {
        if (!seen.has(event?.id) && inScope(event, target)) {
            seen.add(event.id);
            fresh.push(event);
        }
    }

    return { list: [...list, ...fresh].sort(newestFirst).slice(0, max), added: fresh.length };
}

/**
 * Whether another page may hold more: at least one relay answered with a
 * full page. A relay that did not answer tells nothing.
 */
export function mayHaveMore(results, limit) {
    return results.some((result) => result.eose && result.events.length >= limit);
}

/** The oldest `created_at` of the list: the `until` of the next page. */
export function oldest(list) {
    return list.length === 0 ? null : Math.min(...list.map((comment) => comment.created_at));
}

/** The league's moderation: hidden ids (43 `e`) and muted pubkeys (44 `p`), only from the creator. */
export function moderation(events, creator) {
    const hidden = [];
    const muted = [];

    for (const event of events) {
        if (!creator || event?.pubkey !== creator) continue;
        for (const tag of tagsOf(event)) {
            if (event.kind === KIND_HIDE && tag[0] === 'e' && HEX64.test(tag[1] ?? '') && hidden.length < MAX_MODERATION && !hidden.includes(tag[1])) hidden.push(tag[1]);
            if (event.kind === KIND_MUTE && tag[0] === 'p' && HEX64.test(tag[1] ?? '') && muted.length < MAX_MODERATION && !muted.includes(tag[1])) muted.push(tag[1]);
        }
    }

    return { hidden, muted };
}

/** Whether a reaction is to the target: its address (any version), or its id as the last `e` (NIP-25). */
export function reactsTo(event, target) {
    if (event?.kind !== KIND_REACTION) return false;
    if (target.address && named(event, 'a').some((tag) => tag[1] === target.address)) return true;
    const ids = named(event, 'e');

    return ids.length > 0 && ids[ids.length - 1][1] === target.eventId;
}

/** NIP-25: `+` or an empty content is a like. */
export function isLike(event) {
    return event.content === '+' || event.content === '';
}

/**
 * The likes of the target: the newest reaction per author, muted authors
 * left out. `mine` is whether `me` likes it.
 */
export function tallyLikes(events, target, { me = null, muted = [] } = {}) {
    const newest = new Map();

    for (const event of events) {
        if (!reactsTo(event, target) || muted.includes(event.pubkey)) continue;
        const known = newest.get(event.pubkey);
        if (!known || newestFirst(event, known) < 0) newest.set(event.pubkey, event);
    }

    const likers = [...newest.values()].filter(isLike).map((event) => event.pubkey);

    return { count: likers.length, mine: me !== null && likers.includes(me) };
}

/**
 * The RSVPs to an address: the newest per author, grouped by status. An
 * author whose newest RSVP has no known status counts nowhere.
 */
export function tallyRsvps(events, address, { muted = [] } = {}) {
    const newest = new Map();

    for (const event of events) {
        if (event?.kind !== KIND_RSVP || muted.includes(event.pubkey) || !named(event, 'a').some((tag) => tag[1] === address)) continue;
        const known = newest.get(event.pubkey);
        if (!known || newestFirst(event, known) < 0) newest.set(event.pubkey, event);
    }

    const byStatus = { accepted: [], declined: [], tentative: [] };

    for (const event of [...newest.values()].sort(newestFirst)) {
        const status = named(event, 'status')[0]?.[1];
        if (RSVP_STATUSES.includes(status)) byStatus[status].push(event.pubkey);
    }

    return byStatus;
}

/** At most `max` characters (code points), then "…". */
export function clipText(text, max = MAX_TEXT) {
    const chars = [...String(text ?? '')];

    return chars.length > max ? chars.slice(0, max).join('') + '…' : chars.join('');
}

/**
 * Read one set of filters from the relays. `reached` is true when at least
 * one relay answered with EOSE; without it an empty list is "unknown", not
 * "none".
 */
export async function readAll(relays, filters, options = {}) {
    const results = await readRelays(relays, filters, options);

    return { results, reached: results.some((result) => result.eose), events: results.flatMap((result) => result.events) };
}
