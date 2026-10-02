/**
 * NIP-17 private messages for the game chat (NIP "Chat" in docs/nips/esports.md):
 * a kind-14 rumor, sealed (kind 13) by the sender's own signer with NIP-44,
 * gift-wrapped (kind 1059) by a fresh random key. Pure functions over a
 * signer with the NIP-07 shape ({ getPublicKey, signEvent, nip44: { encrypt,
 * decrypt } }); the browser passes window.nostr (extension, or the NIP-46 /
 * Google signer the login module installs), tests pass a key-backed signer.
 *
 * The player's secret key never reaches this code: the seal is signed and
 * both layers are opened through the signer. Only the throwaway wrap key is
 * local, as NIP-59 requires. The cryptography is nostr-tools' (BIP-340,
 * NIP-44 v2); nothing here is home-made crypto.
 */
import { finalizeEvent, generateSecretKey, getEventHash, verifyEvent } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';

/** NIP-59: seal and wrap timestamps up to two days in the past. */
export const TWO_DAYS = 2 * 24 * 60 * 60;

/** NIP "Relay behaviour": a live gift-wrap subscription needs `since` at least two days back. */
export const SINCE_MARGIN = TWO_DAYS + 60 * 60;

/**
 * `since` of a chat subscription: the game's start (unix seconds; now if
 * unknown or ahead of this clock) minus SINCE_MARGIN, so every message of a
 * daily game that runs for weeks is read back, backdated wraps included.
 */
export function chatSince(gameStart, now = Math.floor(Date.now() / 1000)) {
    return Math.min(now, gameStart ?? now) - SINCE_MARGIN;
}

// In its own module, so the casual 1v1 gate on every page (casualPlay.js) needs no nostr-tools.
export { canEncrypt } from './signerCapabilities.js';

function randomPast(now) {
    return now - Math.floor(Math.random() * TWO_DAYS);
}

/**
 * The unsigned kind-14 message. `match` is the game number (NIP tag `match`),
 * so a chat never shows messages that belong to another game.
 */
export function makeRumor({ sender, recipient, content, match, now = Math.floor(Date.now() / 1000) }) {
    const rumor = {
        pubkey: sender,
        created_at: now,
        kind: 14,
        tags: [['p', recipient], ['match', String(match)]],
        content,
    };

    return { ...rumor, id: getEventHash(rumor) };
}

/**
 * NIP-40 `expiration` on the seal and the wrap of a casual 1v1 room (NIP
 * "Lobby and account cards", Expiration): the seal as NIP-17 asks, although
 * NIP-59 wants its tags empty; the wrap so relays drop it. Null: no tag.
 */
function expirationTags(expiration) {
    return expiration == null ? [] : [['expiration', String(expiration)]];
}

async function seal(signer, rumor, recipient, now, expiration = null) {
    const content = await signer.nip44.encrypt(recipient, JSON.stringify(rumor));

    return signer.signEvent({ kind: 13, created_at: randomPast(now), tags: expirationTags(expiration), content });
}

function giftWrap(sealed, recipient, now, expiration = null) {
    const key = generateSecretKey();
    const content = nip44.encrypt(JSON.stringify(sealed), nip44.getConversationKey(key, recipient));

    return finalizeEvent({ kind: 1059, created_at: randomPast(now), tags: [['p', recipient], ...expirationTags(expiration)], content }, key);
}

/**
 * One message, wrapped twice: to the recipient, and to the sender herself,
 * so her other devices show what she wrote (NIP-17).
 */
export async function wrapMessage(signer, { sender, recipient, content, match, now = Math.floor(Date.now() / 1000) }) {
    const rumor = makeRumor({ sender, recipient, content, match, now });
    const toRecipient = giftWrap(await seal(signer, rumor, recipient, now), recipient, now);
    const toSelf = giftWrap(await seal(signer, rumor, sender, now), sender, now);

    return { rumor, toRecipient, toSelf };
}

/**
 * A direct message between two players outside any match (P45, the Nostr
 * bar's "Message"): a kind-14 rumor with only `p`, the way any NIP-17 client
 * writes it, sealed and wrapped to the recipient and to the sender herself.
 */
export async function wrapDirectMessage(signer, { sender, recipient, content, now = Math.floor(Date.now() / 1000) }) {
    const plain = { pubkey: sender, created_at: now, kind: 14, tags: [['p', recipient]], content };
    const rumor = { ...plain, id: getEventHash(plain) };
    const toRecipient = giftWrap(await seal(signer, rumor, recipient, now), recipient, now);
    const toSelf = giftWrap(await seal(signer, rumor, sender, now), sender, now);

    return { rumor, toRecipient, toSelf };
}

/**
 * nostr-tools' verifyEvent() caches its verdict on the event object under a
 * symbol, and an object spread copies that symbol: a changed copy of a
 * verified event would "verify" without being checked (found by
 * tests/js/nip17.test.mjs). A JSON copy carries no symbol, so it is checked.
 */
function verifiedAfresh(event) {
    return verifyEvent(JSON.parse(JSON.stringify(event)));
}

export class UnwrapError extends Error {
    constructor(code) {
        super(code);
        this.code = code;
    }
}

/**
 * A kind-14 rumor as the chats may hold it: every field of the right type,
 * every tag an array of strings. Checked on every opened wrap and on every
 * cache entry, so a malformed tag (`[null]`) can neither break the message
 * filters below nor sit in a cache and break the chat after each reload.
 */
export function isRumor(value) {
    return value !== null && typeof value === 'object'
        && typeof value.id === 'string' && typeof value.pubkey === 'string' && Number.isSafeInteger(value.created_at)
        && value.kind === 14 && typeof value.content === 'string'
        && Array.isArray(value.tags) && value.tags.every((tag) => Array.isArray(tag) && tag.every((part) => typeof part === 'string'));
}

/**
 * Open a gift wrap addressed to `me` and return the rumor. Throws
 * UnwrapError for anything that is not a valid message, above all when the
 * seal's key is not the rumor's author: without this check anyone can write
 * as anyone (NIP-17; relay proof round 4 caught `nak gift unwrap` passing
 * such a forgery).
 */
export async function unwrapMessage(signer, wrap, me) {
    if (wrap?.kind !== 1059 || !verifiedAfresh(wrap) || !wrap.tags.some((t) => t[0] === 'p' && t[1] === me)) {
        throw new UnwrapError('wrap');
    }

    let sealed;
    try {
        sealed = JSON.parse(await signer.nip44.decrypt(wrap.pubkey, wrap.content));
    } catch {
        throw new UnwrapError('wrap_decrypt');
    }

    if (sealed?.kind !== 13 || !verifiedAfresh(sealed)) {
        throw new UnwrapError('seal');
    }

    let rumor;
    try {
        rumor = JSON.parse(await signer.nip44.decrypt(sealed.pubkey, sealed.content));
    } catch {
        throw new UnwrapError('seal_decrypt');
    }

    if (rumor?.pubkey !== sealed.pubkey) {
        throw new UnwrapError('sender_mismatch');
    }

    if (!isRumor(rumor) || getEventHash(rumor) !== rumor.id) {
        throw new UnwrapError('rumor');
    }

    return rumor;
}

/**
 * The unsigned kind-14 message to a group (NIP-17 chat room: the author plus
 * the `p` set), here the players of both lineups of a series. `match` is the
 * league match number. `tags` are extra tags (a lobby or account card,
 * resources/js/lobbyCards.js); `expiration` the NIP-40 time of a casual 1v1
 * room, or null.
 */
export function makeGroupRumor({ sender, recipients, content, match, tags = [], expiration = null, now = Math.floor(Date.now() / 1000) }) {
    const rumor = {
        pubkey: sender,
        created_at: now,
        kind: 14,
        tags: [...recipients.filter((p) => p !== sender).map((p) => ['p', p]), ['match', String(match)], ...tags, ...expirationTags(expiration)],
        content,
    };

    return { ...rumor, id: getEventHash(rumor) };
}

/**
 * One group message: the same rumor sealed and wrapped to every member
 * separately, and once to the sender (NIP-17 "publish to each receiver").
 * `wraps[i]` goes to `targets[i]`; the copy to self is the last one. With
 * `expiration`, the rumor, every seal and every wrap carry the same value.
 */
export async function wrapGroupMessage(signer, { sender, recipients, content, match, tags = [], expiration = null, now = Math.floor(Date.now() / 1000) }) {
    const rumor = makeGroupRumor({ sender, recipients, content, match, tags, expiration, now });
    const targets = [...new Set([...recipients.filter((p) => p !== sender), sender])];
    const wraps = [];

    for (const target of targets) {
        wraps.push(giftWrap(await seal(signer, rumor, target, now, expiration), target, now, expiration));
    }

    return { rumor, wraps, targets };
}

/** After a match or game is settled, an untagged reply still counts this long (seconds): the "gg" after the result. */
export const DM_GRACE = 60 * 60;

/** A rumor without the league's `match` tag, as every other NIP-17 client writes it. */
export function isUntagged(rumor) {
    return !rumor.tags.some((t) => t[0] === 'match');
}

/** NIP-17 chat room of a rumor: its author and its `p` set. */
function participants(rumor) {
    return new Set([rumor.pubkey, ...rumor.tags.filter((t) => t[0] === 'p').map((t) => t[1])]);
}

function sameSet(a, b) {
    return a.size === b.size && [...a].every((x) => b.has(x));
}

/**
 * Untagged replies of one room or game: kind-14 rumors another NIP-17
 * client (Amethyst, 0xchat, Coracle …) wrote in answer to the league's chat,
 * which carry no `match` tag. Amethyst writes `p` = the other participants
 * for a plain message, and for a swipe reply a marked `e` plus `p` = the
 * replied message's recipients and its author, nothing else
 * (ChatMessageEvent.build / .reply), so the `match` tag never comes back.
 *
 * One is shown only when ALL of these hold:
 *
 *   1. its author is one of `authors` (the opponents), never a non-member,
 *      a teammate or me;
 *   2. its chat room (author + `p`) is exactly the room's member set, the
 *      way NIP-17 itself defines a conversation: a 1:1 inside a team room
 *      or a DM that pulls in an outsider is another conversation;
 *   3. it was written inside the match window: from `since` (the match's
 *      creation; unknown = none shown) to `settled` plus DM_GRACE (open
 *      while not settled);
 *   4. it belongs to THIS room: the tagged message it answers (`e`) is one
 *      of this room's, or, without such an `e`, the newest tagged message
 *      of the same conversation written before it is. A DM before any room
 *      message, or one written after a message of another room or game
 *      with the same players, is not this room's.
 *
 * Trade-off, kept on purpose: rule 4 is the strictest that still catches a
 * real Amethyst reply, because most replies there are typed into the
 * conversation without a swipe, so requiring an `e` would miss them. The
 * price: an unrelated DM between the same players, written inside the
 * window and after a room message (and before any message of another of
 * their rooms or games), shows here; and a reply typed after another
 * room's message lands there, not here. A rumor that carries a `match` tag
 * of another room never shows (it is that room's). Own replies sent from
 * another client and teammates' are not shown (rule 1). Game and match
 * numbers share the `match` tag, as for the tagged messages.
 */
export function dmReplies(rumors, { me, members, match, authors, since, settled = null }) {
    if (!Number.isSafeInteger(since)) return [];

    const room = new Set(members);
    const authorSet = new Set(authors.filter((p) => p !== me && room.has(p)));
    const until = Number.isSafeInteger(settled) ? settled + DM_GRACE : null;
    const inRoom = (rumor) => isRumor(rumor) && sameSet(participants(rumor), room);
    const tagged = rumors.filter((rumor) => inRoom(rumor) && !isUntagged(rumor));
    const byId = new Map(tagged.map((rumor) => [rumor.id, rumor]));
    const matchOf = (rumor) => rumor?.tags.find((t) => t[0] === 'match')?.[1] ?? null;

    return rumors.filter((rumor) => {
        if (!inRoom(rumor) || !isUntagged(rumor) || !authorSet.has(rumor.pubkey)) return false;
        if (rumor.created_at < since || (until !== null && rumor.created_at > until)) return false;

        const answered = rumor.tags.filter((t) => t[0] === 'e').map((t) => byId.get(t[1])).find(Boolean);
        const anchor = answered ?? tagged
            .filter((t) => t.created_at <= rumor.created_at)
            .reduce((latest, t) => (latest === null || t.created_at > latest.created_at ? t : latest), null);

        return matchOf(anchor) === String(match);
    });
}

/** The tagged list plus the untagged replies, each id once, muted senders left out, oldest first. */
function withReplies(tagged, replies, muted) {
    const mutedSet = new Set(muted);
    const seen = new Set();

    return [...tagged, ...replies]
        .filter((rumor) => {
            if (seen.has(rumor.id) || mutedSet.has(rumor.pubkey)) return false;
            seen.add(rumor.id);

            return true;
        })
        .sort((a, b) => a.created_at - b.created_at);
}

/**
 * The messages of one match room: the right `match`, written by a member of
 * the room, addressed to me (or written by me), each once, oldest first.
 * Messages from muted pubkeys are left out. With `dm` ({ opponents, since,
 * settled }), the opponents' untagged replies of this room join them
 * (dmReplies above); isUntagged() tells them apart.
 */
export function roomMessages(rumors, { me, members, match, muted = [], dm = null }) {
    const memberSet = new Set(members);

    const tagged = rumors.filter((rumor) => {
        if (!isRumor(rumor)) return false;
        const recipients = rumor.tags.filter((t) => t[0] === 'p').map((t) => t[1]);
        const forMatch = rumor.tags.some((t) => t[0] === 'match' && t[1] === String(match));
        const toMe = rumor.pubkey === me || recipients.includes(me);

        return forMatch && toMe && memberSet.has(rumor.pubkey) && recipients.length > 0 && recipients.every((p) => memberSet.has(p));
    });
    const replies = dm === null ? [] : dmReplies(rumors, { me, members, match, authors: dm.opponents ?? [], since: dm.since, settled: dm.settled ?? null });

    return withReplies(tagged, replies, muted);
}

/**
 * The messages of one game between two players: the right `match`, written
 * by one of the two to the other, each once (a message arrives as the
 * recipient's copy and, for the sender, as her own copy), oldest first.
 * Messages from muted pubkeys are left out (mute is only ever for oneself).
 * With `dm` ({ since, settled }), the opponent's untagged replies of this
 * game join them (dmReplies above).
 */
export function gameMessages(rumors, { me, opponent, match, muted = [], dm = null }) {
    const tagged = rumors.filter((rumor) => {
        if (!isRumor(rumor)) return false;
        const recipient = rumor.tags.find((t) => t[0] === 'p')?.[1];
        const forGame = rumor.tags.some((t) => t[0] === 'match' && t[1] === String(match));
        const between = (rumor.pubkey === me && recipient === opponent) || (rumor.pubkey === opponent && recipient === me);

        return forGame && between;
    });
    const replies = dm === null ? [] : dmReplies(rumors, { me, members: [me, opponent], match, authors: [opponent], since: dm.since, settled: dm.settled ?? null });

    return withReplies(tagged, replies, muted);
}
