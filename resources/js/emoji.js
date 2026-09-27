/**
 * Emoji data for the chat's emoji picker (P24), ported from einundzwanzig-group
 * (packages/einundzwanzig-group/js/emoji.ts) onto nostr-tools. Two sources:
 *
 * 1. The Unicode set from `emojibase-data`, loaded lazily in the page's
 *    language: `?url` + fetch, so Vite copies the JSON as a plain asset and
 *    the browser's JSON parser reads it. A plain `import(…json)` would turn
 *    600 kB of JSON into a JS object literal (the original measured 4.2 MB in
 *    the dev server). The import sits inside the function, so Node (the unit
 *    tests) never evaluates the `?url` suffix. Nothing of it is in the
 *    initial bundle.
 * 2. The viewer's own NIP-30 custom emoji: their kind 10030 plus every kind
 *    30030 set it points to with an `a` tag, read with relayRead.js (every
 *    event signature-checked, the newest per author/`d` wins).
 *
 * Plus the recently used emoji (localStorage) and a flat search.
 */
import { newest, readRelays } from './relayRead.js';
import { isHttps } from './streamChat.js';

const USER_EMOJI_LIST = 10030;
const EMOJI_SET = 30030;

const RECENT_KEY = 'esports.emoji.recent';
const RECENT_MAX = 24;

const groupsPromises = new Map();

/**
 * The Unicode emoji by emojibase group, without the skin tone components.
 *
 * @param {string} locale  'de' or anything else (English)
 * @returns {Promise<Array<{ key: string, name: string, icon: string, emojis: Array<{ u: string, label: string, tags: string[] }> }>>}
 */
export function loadEmojiGroups(locale = 'en') {
    const lang = locale === 'de' ? 'de' : 'en';
    if (!groupsPromises.has(lang)) {
        const files = lang === 'de'
            ? Promise.all([import('emojibase-data/de/compact.json?url'), import('emojibase-data/de/messages.json?url')])
            : Promise.all([import('emojibase-data/en/compact.json?url'), import('emojibase-data/en/messages.json?url')]);

        const promise = files
            .then(([compact, messages]) => Promise.all([
                fetch(compact.default).then((response) => response.json()),
                fetch(messages.default).then((response) => response.json()),
            ]))
            .then(([list, messages]) => groupEmojis(list, messages));
        // A failed load is tried again on the next open, not remembered.
        promise.catch(() => groupsPromises.delete(lang));
        groupsPromises.set(lang, promise);
    }

    return groupsPromises.get(lang);
}

/** emojibase's compact list and messages as picker groups (separate, so Node tests it). */
export function groupEmojis(list, messages) {
    return (messages?.groups ?? [])
        .filter((group) => group.key !== 'component')
        .sort((a, b) => a.order - b.order)
        .map((group) => {
            const emojis = (list ?? [])
                .filter((emoji) => emoji.group === group.order && Boolean(emoji.unicode))
                .sort((a, b) => a.order - b.order)
                .map((emoji) => ({ u: emoji.unicode, label: emoji.label, tags: emoji.tags ?? [] }));

            return { key: group.key, name: group.message, icon: emojis[0]?.u ?? '·', emojis };
        })
        .filter((group) => group.emojis.length > 0);
}

/** The recently used emoji, newest first: `{ u, label }` or `{ custom: true, shortcode, url }`. */
export function loadRecentEmojis() {
    try {
        const parsed = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]');

        return Array.isArray(parsed) ? parsed.filter((emoji) => (emoji?.custom ? isHttps(emoji.url) && /^\w+$/.test(emoji.shortcode ?? '') : typeof emoji?.u === 'string')) : [];
    } catch {
        return [];
    }
}

const recentKey = (emoji) => (emoji.custom ? ':' + emoji.shortcode + ':' : emoji.u);

/** Put a used emoji first (each once, capped) and return the list. */
export function pushRecentEmoji(emoji) {
    const key = recentKey(emoji);
    const next = [emoji, ...loadRecentEmojis().filter((known) => recentKey(known) !== key)].slice(0, RECENT_MAX);
    try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
        // private mode: the list lives for this page only
    }

    return next;
}

/** `["emoji", shortcode, url]` tags as custom emoji, https images only. */
export function emojisFromTags(tags) {
    return (tags ?? [])
        .filter((tag) => Array.isArray(tag) && tag[0] === 'emoji' && /^\w+$/.test(tag[1] ?? '') && isHttps(tag[2]))
        .map((tag) => ({ shortcode: tag[1], url: tag[2] }));
}

/** The 30030 addresses (`30030:<pubkey>:<d>`) a 10030 list points to. */
export function setAddresses(list) {
    return (list?.tags ?? [])
        .filter((tag) => Array.isArray(tag) && tag[0] === 'a' && typeof tag[1] === 'string' && tag[1].startsWith(EMOJI_SET + ':'))
        .map((tag) => tag[1].split(':'))
        .filter((parts) => parts.length >= 3 && /^[0-9a-f]{64}$/.test(parts[1]))
        .map(([, author, ...d]) => ({ author, d: d.join(':') }));
}

const customCache = new Map();
let loadedFor = '';
let loadedEmojis = [];

/**
 * The viewer's own custom emoji (NIP-30): kind 10030 plus the 30030 sets it
 * names, each shortcode once. Memoized per pubkey; empty without one.
 *
 * @param {string|null} pubkey
 * @param {string[]} relays
 * @param {object} options  passed to readRelays (tests hand in a fake WebSocket)
 */
export function loadUserCustomEmojis(pubkey, relays, options = {}) {
    if (!pubkey || !relays?.length) return Promise.resolve([]);
    if (customCache.has(pubkey)) return customCache.get(pubkey);

    const promise = (async () => {
        const lists = await readRelays(relays, [{ kinds: [USER_EMOJI_LIST], authors: [pubkey] }], options);
        const list = newest(lists.flatMap((result) => result.events), pubkey, USER_EMOJI_LIST);
        if (!list) return [];

        const collected = emojisFromTags(list.tags);
        const addresses = setAddresses(list);
        if (addresses.length > 0) {
            const results = await readRelays(relays, addresses.map(({ author, d }) => ({ kinds: [EMOJI_SET], authors: [author], '#d': [d] })), options);
            const events = results.flatMap((result) => result.events);
            for (const { author, d } of addresses) {
                const set = newest(events, author, EMOJI_SET, d);
                if (set) collected.push(...emojisFromTags(set.tags));
            }
        }

        const seen = new Set();
        const unique = collected.filter((emoji) => (seen.has(emoji.shortcode) ? false : seen.add(emoji.shortcode)));
        loadedFor = pubkey;
        loadedEmojis = unique;

        return unique;
    })();
    promise.catch(() => customCache.delete(pubkey));
    customCache.set(pubkey, promise);

    return promise;
}

/**
 * The viewer's custom emoji known right now, synchronously: the send path
 * never waits for a relay. Until the load is done it is empty, and a
 * `:shortcode:` goes out as plain text (the harmless direction).
 */
export function knownCustomEmojis(pubkey) {
    return pubkey && pubkey === loadedFor ? loadedEmojis : [];
}

/**
 * Search over custom and Unicode emoji (shortcode; label and keywords), at most `limit` hits.
 */
export function searchEmojis(query, groups, custom, limit = 90) {
    const q = String(query ?? '').trim().toLowerCase();
    if (!q) return [];

    const hits = [];
    for (const emoji of custom ?? []) {
        if (emoji.shortcode.toLowerCase().includes(q)) hits.push({ ...emoji, custom: true });
    }
    for (const group of groups ?? []) {
        for (const emoji of group.emojis) {
            if (emoji.label.toLowerCase().includes(q) || emoji.tags.some((tag) => tag.includes(q))) {
                hits.push(emoji);
                if (hits.length >= limit) return hits;
            }
        }
    }

    return hits.slice(0, limit);
}
