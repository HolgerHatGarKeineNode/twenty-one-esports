/**
 * Lobby and account cards of a casual 1v1 room (NIP "Lobby and account
 * cards" in docs/nips/esports.md): a kind-14 chat rumor with a few extra
 * tags, which the room draws as a card. Pure functions, no DOM and no
 * network; the room chat (resources/js/roomChat.js) sends and shows them,
 * tests/js/lobbyCards.test.mjs pins every rule here.
 *
 *   lobby card    ["lobby", <game>], ["lobby-name", …], ["lobby-password", …]
 *   account card  ["account", <service>], ["account-id", …]
 *
 * A lobby card without name and password closes the lobby, an account card
 * without an ID withdraws it. `content` is plain English generated from the
 * tags, for other NIP-17 clients; the app never draws a card from it.
 */

/** Games whose host shares a private match (the NIP's game registry slugs). */
export const LOBBY_GAMES = { 'rocket-league': 'Rocket League' };

/** Services of the app's gamer tag list an account card may name. */
export const ACCOUNT_SERVICES = { ea: 'EA ID' };

/** Which card is "the lobby" of a game: the host's card that sets `lobby_shared_at`. */
export const HOST_CARD = {
    'rocket-league': { marker: 'lobby', value: 'rocket-league' },
    'ea-sports-fc-26': { marker: 'account', value: 'ea' },
    'ea-sports-fc-27': { marker: 'account', value: 'ea' },
};

/** A value: 1 to 64 characters (code points), no control characters. */
export const MAX_VALUE = 64;

/** A card dated more than this far ahead of the reader's clock is a plain message. */
export const MAX_FUTURE = 10 * 60;

const DAY = 24 * 60 * 60;

const DATA_TAGS = { lobby: ['lobby-name', 'lobby-password'], account: ['account-id'] };
const ALL_DATA_TAGS = [...DATA_TAGS.lobby, ...DATA_TAGS.account];

// C0, DEL and C1 controls, plus the line and paragraph separators.
const CONTROL = /[\u0000-\u001f\u007f-\u009f\u2028\u2029]/u;

export function validValue(value) {
    return typeof value === 'string' && [...value].length >= 1 && [...value].length <= MAX_VALUE && !CONTROL.test(value);
}

/**
 * The tags of a lobby card; empty name and password close the lobby.
 * Throws on anything the parser would refuse, so nothing invalid is sent.
 */
export function lobbyTags({ game, name = '', password = '' }) {
    if (!Object.hasOwn(LOBBY_GAMES, game)) throw new Error('card_game');
    if (name === '' && password === '') return [['lobby', game]];
    if (!validValue(name) || !validValue(password)) throw new Error('card_value');

    return [['lobby', game], ['lobby-name', name], ['lobby-password', password]];
}

/** The tags of an account card; an empty ID withdraws it. */
export function accountTags({ service, id = '' }) {
    if (!Object.hasOwn(ACCOUNT_SERVICES, service)) throw new Error('card_service');
    if (id === '') return [['account', service]];
    if (!validValue(id)) throw new Error('card_value');

    return [['account', service], ['account-id', id]];
}

/** The plain-text `content` for other NIP-17 clients, from the parsed card. */
export function cardContent(card, match) {
    if (card.kind === 'lobby') {
        return card.closed
            ? `Lobby closed (match ${match})`
            : `${LOBBY_GAMES[card.game]} private match\nName: ${card.name}\nPassword: ${card.password}\n(lobby card for match ${match})`;
    }

    return card.closed ? `${ACCOUNT_SERVICES[card.service]} withdrawn (match ${match})` : `${ACCOUNT_SERVICES[card.service]}: ${card.id}\n(add me as a friend for match ${match})`;
}

/**
 * Read a rumor's card. Null: no card marker, a plain message. A card
 * breaking any rule (two markers, a data tag twice or of the other card,
 * an unknown game or service, a bad value, name without password, dated
 * more than MAX_FUTURE ahead) is `{ invalid: true }`: shown as a plain
 * message with its `content`, as other clients show it.
 */
export function parseCard(rumor, now = Math.floor(Date.now() / 1000)) {
    const tags = Array.isArray(rumor?.tags) ? rumor.tags : [];
    const markers = tags.filter((t) => t[0] === 'lobby' || t[0] === 'account');

    if (markers.length === 0) return null;

    const invalid = { invalid: true };
    if (markers.length > 1) return invalid;

    const [marker, value] = markers[0];
    const counts = {};
    for (const t of tags) if (ALL_DATA_TAGS.includes(t[0])) counts[t[0]] = (counts[t[0]] ?? 0) + 1;
    if (Object.values(counts).some((n) => n > 1)) return invalid;
    if (Object.keys(counts).some((name) => !DATA_TAGS[marker].includes(name))) return invalid;
    if (typeof rumor.created_at !== 'number' || rumor.created_at > now + MAX_FUTURE) return invalid;

    const read = (name) => tags.find((t) => t[0] === name)?.[1];

    if (marker === 'lobby') {
        if (!Object.hasOwn(LOBBY_GAMES, value)) return invalid;
        const name = read('lobby-name');
        const password = read('lobby-password');
        if (name === undefined && password === undefined) return { kind: 'lobby', game: value, key: `lobby:${value}`, closed: true };
        if (!validValue(name) || !validValue(password)) return invalid;

        return { kind: 'lobby', game: value, key: `lobby:${value}`, closed: false, name, password };
    }

    if (!Object.hasOwn(ACCOUNT_SERVICES, value)) return invalid;
    const id = read('account-id');
    if (id === undefined) return { kind: 'account', service: value, key: `account:${value}`, closed: true };
    if (!validValue(id)) return invalid;

    return { kind: 'account', service: value, key: `account:${value}`, closed: false, id };
}

/**
 * The newest card wins (NIP): per author and marker (the room is one
 * match already), only the card with the greatest `created_at` is open; on
 * equal `created_at` the lowest `id`, as NIP-01 decides for replaceable
 * events. Returns the set of rumor ids that are open.
 *
 * @param {Array<{ rumor: object, card: object }>} entries valid cards only
 */
export function openCardIds(entries) {
    const best = new Map();

    for (const entry of entries) {
        const slot = `${entry.rumor.pubkey}|${entry.card.key}`;
        const held = best.get(slot);
        if (held === undefined || entry.rumor.created_at > held.created_at || (entry.rumor.created_at === held.created_at && entry.rumor.id < held.id)) {
            best.set(slot, entry.rumor);
        }
    }

    return new Set([...best.values()].map((rumor) => rumor.id));
}

/**
 * The NIP-40 `expiration` of every message in a casual 1v1 room: the next
 * 00:00 UTC at or after max(A + D, now) + 7 days. `expiresFrom` is A + D in
 * unix seconds, as the league hands it to both clients.
 */
export function casualExpiration(expiresFrom, now = Math.floor(Date.now() / 1000)) {
    const at = Math.max(expiresFrom, now) + 7 * DAY;

    return Math.ceil(at / DAY) * DAY;
}

/** The rumor's NIP-40 `expiration` (unix seconds), null without a usable one. */
export function expirationOf(event) {
    const value = (Array.isArray(event?.tags) ? event.tags : []).find((t) => t[0] === 'expiration')?.[1];
    const number = Number(value);

    return value !== undefined && /^\d+$/.test(String(value)) && Number.isSafeInteger(number) ? number : null;
}

export function isExpired(event, now = Math.floor(Date.now() / 1000)) {
    const at = expirationOf(event);

    return at !== null && at <= now;
}

/**
 * What the chat's local cache keeps of an opened wrap: the rumor of a text
 * message; a stub without tags or content for anything with a card marker,
 * valid or not (NIP "Cards are not cached"), so the card is opened again
 * from the relays after a reload; null for no message or an expired one.
 */
export function cacheEntry(rumor, now = Math.floor(Date.now() / 1000)) {
    if (rumor === null || isExpired(rumor, now)) return null;

    return parseCard(rumor, Number.MAX_SAFE_INTEGER) === null ? rumor : { stub: true };
}

const PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

/** A fresh random lobby password (no look-alike characters), one per match (NIP threat table). */
export function randomPassword(length = 6, random = (n) => crypto.getRandomValues(new Uint8Array(n))) {
    const out = [];

    // Rejection sampling: 248 is the largest multiple of 31 below 256, so every character is equally likely.
    while (out.length < length) {
        for (const byte of random(length * 2)) {
            if (byte < 248 && out.length < length) out.push(PASSWORD_ALPHABET[byte % PASSWORD_ALPHABET.length]);
        }
    }

    return out.join('');
}
