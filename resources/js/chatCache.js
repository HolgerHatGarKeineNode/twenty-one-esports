/**
 * The local caches of the two NIP-17 chats: decrypted rumors per wrap id,
 * so a reload does not ask the signer to open every wrap again (NIP-17
 * allows it). Both chats subscribe to every gift wrap addressed to the
 * player, so each sees the other's messages too. They keep separate keys:
 *
 *   esports.chat.cache.room.<pubkey>  the match room (resources/js/roomChat.js)
 *   esports.chat.cache.game.<pubkey>  the game chat (resources/js/gameChat.js)
 *
 * The shared key before P23 S2 (`esports.chat.cache.<pubkey>`) is read once
 * into both, through the same checks, and removed.
 *
 * What an entry may be (NIP "Cards are not cached"):
 *   - null: opened, and nothing to show (not valid, expired, or for the game
 *     chat a card); the wrap is not opened again;
 *   - a text rumor, checked with isRumor();
 *   - in the room cache only, a stub `{ stub: true }` for a card: the card
 *     is opened again from the relays after a reload and never stored.
 * A card never reaches either cache as a rumor. On load, a card stored as a
 * rumor (by the shared cache of before) becomes null in the game cache and
 * is dropped from the room cache, so the room opens it again; a stub in the
 * game cache and any malformed entry are dropped; expired rumors become null.
 */
import { cacheEntry, isExpired, parseCard } from './lobbyCards.js';
import { isRumor } from './nostrChat.js';

export const CACHE_LIMIT = 500;

const LEGACY_PREFIX = 'esports.chat.cache.';
const CHATS = ['room', 'game'];

export function cacheKey(chat, me) {
    return `${LEGACY_PREFIX}${chat}.${me}`;
}

const nowSeconds = () => Math.floor(Date.now() / 1000);

/** The game chat's entry for an opened wrap: a text rumor, else null. A card is never a game message. */
export function gameEntry(rumor, now = nowSeconds()) {
    if (!isRumor(rumor) || isExpired(rumor, now) || parseCard(rumor, Number.MAX_SAFE_INTEGER) !== null) return null;

    return rumor;
}

/** The room chat's entry: a text rumor, a stub for a card, else null. */
export function roomEntry(rumor, now = nowSeconds()) {
    return isRumor(rumor) ? cacheEntry(rumor, now) : null;
}

export function entryFor(chat, rumor, now = nowSeconds()) {
    return chat === 'room' ? roomEntry(rumor, now) : gameEntry(rumor, now);
}

/**
 * A stored entry as it may stay: null stays null; a stub stays in the room
 * cache; a rumor goes through entryFor(). `undefined`: drop the entry, so
 * the wrap is opened again.
 */
export function sanitizeEntry(chat, entry, now = nowSeconds()) {
    if (entry === null) return null;
    if (entry?.stub === true && Object.keys(entry).length === 1) return chat === 'room' ? { stub: true } : undefined;
    if (!isRumor(entry)) return undefined;

    const kept = entryFor(chat, entry, now);

    // A card stored as a rumor (by an older writer) is opened again, never kept.
    return kept !== null && kept.stub ? undefined : kept;
}

function read(storage, key) {
    try {
        const value = JSON.parse(storage.getItem(key) ?? 'null');

        return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null;
    } catch {
        return null;
    }
}

function write(storage, key, value) {
    try {
        storage.setItem(key, JSON.stringify(value));
    } catch {
        // private mode or full storage: the chat still works, it just forgets
    }
}

function sanitize(chat, raw, now) {
    const cache = {};

    for (const [id, entry] of Object.entries(raw ?? {})) {
        const kept = sanitizeEntry(chat, entry, now);
        if (kept !== undefined) cache[id] = kept;
    }

    return cache;
}

/** Move the shared cache of before P23 S2 into both keys, checked, and remove it. */
export function migrateLegacy(me, storage = globalThis.localStorage, now = nowSeconds()) {
    const legacyKey = LEGACY_PREFIX + me;
    const legacy = read(storage, legacyKey);

    if (storage.getItem(legacyKey) === null) return;

    for (const chat of CHATS) {
        if (legacy !== null && storage.getItem(cacheKey(chat, me)) === null) write(storage, cacheKey(chat, me), sanitize(chat, legacy, now));
    }

    try {
        storage.removeItem(legacyKey);
    } catch {
        // nothing to do: the key is never read again
    }
}

/**
 * The cache of one chat, checked entry by entry (and written back if that
 * changed anything), with the rumors to show from it.
 */
export function loadCache(chat, me, storage = globalThis.localStorage, now = nowSeconds()) {
    migrateLegacy(me, storage, now);

    const raw = read(storage, cacheKey(chat, me)) ?? {};
    const cache = sanitize(chat, raw, now);

    if (JSON.stringify(cache) !== JSON.stringify(raw)) write(storage, cacheKey(chat, me), cache);

    return { cache, rumors: Object.values(cache).filter((entry) => entry !== null && !entry.stub) };
}

/** Write a chat's cache, keeping the newest CACHE_LIMIT wraps. */
export function saveCache(chat, me, cache, storage = globalThis.localStorage) {
    const ids = Object.keys(cache);
    if (ids.length > CACHE_LIMIT) ids.slice(0, ids.length - CACHE_LIMIT).forEach((id) => delete cache[id]);
    write(storage, cacheKey(chat, me), cache);
}
