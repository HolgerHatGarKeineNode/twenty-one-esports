/**
 * The rules of the /live stream chat (P24), without DOM or Alpine, so
 * tests/js/streamChat.test.mjs runs them in Node:
 *
 * - which relay events are chat messages of this stream (NIP-53 kind 1311
 *   with the stream's `a` address) and which are zaps of it (NIP-57 kind
 *   9735 signed by a known LNURL server, its zap request valid, the invoice
 *   amount equal to the requested one);
 * - how a message's text splits into text, links and NIP-30 custom emoji;
 * - which `emoji` tags a text to be sent needs (from the final text, never
 *   from a list of what the picker inserted);
 * - the send rules: not empty, at most `maxLength` characters, one message
 *   per `cooldownMs`;
 * - how muted messages collapse into one line per run.
 */
import { verifyEvent } from 'nostr-tools/pure';

export const KIND_CHAT = 1311;
export const KIND_ZAP = 9735;
export const KIND_ZAP_REQUEST = 9734;

/** NIP-30: alphanumeric characters and underscores between colons. */
const SHORTCODE = /:(\w+):/g;

/** http(s) links; closing punctuation that ends a sentence is not part of them. */
const LINK = /\bhttps?:\/\/[^\s<>"']+/gi;
const LINK_TAIL = /[.,;:!?)\]}'"]+$/;

/** A copy without verifyEvent()'s cached verdict (see nostrChat.js, verifiedAfresh). */
function verifiedAfresh(event) {
    try {
        return verifyEvent(JSON.parse(JSON.stringify(event)));
    } catch {
        return false;
    }
}

function tagValues(event, name) {
    return (event?.tags ?? []).filter((tag) => Array.isArray(tag) && tag[0] === name).map((tag) => tag[1]);
}

export function isHttps(url) {
    return typeof url === 'string' && /^https:\/\/[^\s]+$/i.test(url);
}

/** A kind 1311 of this stream: the `a` tag names its 30311 address. */
export function isStreamMessage(event, address) {
    return event?.kind === KIND_CHAT && typeof event.content === 'string' && tagValues(event, 'a').includes(address);
}

/**
 * Amount of a BOLT-11 invoice in millisatoshis (BigInt), null without one.
 * The human-readable part is `ln` + network + amount + multiplier (m, u, n, p).
 */
export function bolt11Msats(invoice) {
    const match = /^ln(?:bcrt|bc|tbs|tb)(\d+)([munp]?)1/i.exec(String(invoice ?? ''));
    if (!match) return null;

    const amount = BigInt(match[1]);
    switch (match[2].toLowerCase()) {
        case 'm': return amount * 100_000_000n;
        case 'u': return amount * 100_000n;
        case 'n': return amount * 100n;
        case 'p': return amount % 10n === 0n ? amount / 10n : null;
        default: return amount * 100_000_000_000n;
    }
}

/**
 * A zap of this stream as the chat shows it, or null when it does not count:
 * receipt kind 9735 signed by one of `signers`, carrying the stream's `a`; its
 * `description` a validly signed kind-9734 zap request for the same address;
 * an invoice amount that, when the request names one, is exactly that amount.
 *
 * @returns {{ id: string, pubkey: string, created_at: number, sats: number, comment: string } | null}
 */
export function parseZap(receipt, { address, signers = [], verify = verifiedAfresh } = {}) {
    if (receipt?.kind !== KIND_ZAP || !signers.includes(receipt.pubkey) || !tagValues(receipt, 'a').includes(address)) {
        return null;
    }

    let request;
    try {
        request = JSON.parse(tagValues(receipt, 'description')[0] ?? '');
    } catch {
        return null;
    }

    if (request?.kind !== KIND_ZAP_REQUEST || !tagValues(request, 'a').includes(address) || !verify(request)) {
        return null;
    }

    const msats = bolt11Msats(tagValues(receipt, 'bolt11')[0]);
    const asked = tagValues(request, 'amount')[0];
    if (msats === null || msats <= 0n || (asked !== undefined && String(msats) !== String(asked).trim())) {
        return null;
    }

    return {
        id: receipt.id,
        pubkey: request.pubkey,
        created_at: receipt.created_at,
        sats: Number(msats / 1000n),
        comment: typeof request.content === 'string' ? request.content : '',
    };
}

/** `shortcode -> https URL` of a message's own `emoji` tags (NIP-30); other URLs are dropped. */
export function emojiMap(tags) {
    const map = new Map();
    for (const tag of tags ?? []) {
        if (Array.isArray(tag) && tag[0] === 'emoji' && /^\w+$/.test(tag[1] ?? '') && isHttps(tag[2]) && !map.has(tag[1])) {
            map.set(tag[1], tag[2]);
        }
    }

    return map;
}

function splitEmoji(text, emoji, tokens) {
    let last = 0;
    for (const match of text.matchAll(SHORTCODE)) {
        const url = emoji.get(match[1]);
        if (!url) continue;
        if (match.index > last) tokens.push({ type: 'text', value: text.slice(last, match.index) });
        tokens.push({ type: 'emoji', value: match[1], url });
        last = match.index + match[0].length;
    }
    if (last < text.length) tokens.push({ type: 'text', value: text.slice(last) });
}

/**
 * A message's content as a list of tokens: `text`, `link` (http/https only;
 * the shown text is the URL without its scheme) and `emoji` (a shortcode the
 * message's own tags give an https image). Text stays text: the page renders
 * it with x-text, never as HTML.
 *
 * @returns {Array<{ type: 'text'|'link'|'emoji', value: string, url?: string }>}
 */
export function tokenize(content, tags = []) {
    const text = String(content ?? '');
    const emoji = emojiMap(tags);
    const tokens = [];
    let last = 0;

    for (const match of text.matchAll(LINK)) {
        const url = match[0].replace(LINK_TAIL, '');
        if (match.index > last) splitEmoji(text.slice(last, match.index), emoji, tokens);
        tokens.push({ type: 'link', value: url.replace(/^https?:\/\//i, ''), url });
        last = match.index + url.length;
    }
    if (last < text.length) splitEmoji(text.slice(last), emoji, tokens);

    // Neighbouring text pieces merge back into one.
    return tokens.reduce((merged, token) => {
        const previous = merged[merged.length - 1];
        if (token.type === 'text' && previous?.type === 'text') {
            previous.value += token.value;
        } else {
            merged.push({ ...token });
        }

        return merged;
    }, []);
}

/**
 * The NIP-30 tags a text to be sent needs: one `["emoji", shortcode, url]`
 * per KNOWN shortcode that is in the final text, in order of appearance, each
 * once. Derived from the text, not from what the picker inserted, so a
 * deleted emoji leaves no tag and a typed one gets its tag (ported from
 * einundzwanzig-group's emojiTags.ts). Fresh arrays, never an Alpine proxy.
 */
export function emojiTagsForContent(content, custom) {
    if (!Array.isArray(custom) || custom.length === 0 || !String(content ?? '').includes(':')) {
        return [];
    }

    const byShortcode = new Map(custom.map((emoji) => [emoji.shortcode, emoji.url]));
    const tags = [];
    const seen = new Set();
    for (const [, shortcode] of String(content).matchAll(SHORTCODE)) {
        const url = byShortcode.get(shortcode);
        if (url && isHttps(url) && !seen.has(shortcode)) {
            seen.add(shortcode);
            tags.push(['emoji', String(shortcode), String(url)]);
        }
    }

    return tags;
}

/** Characters as a reader counts them (code points, so one emoji is one). */
export function length(text) {
    return [...String(text ?? '')].length;
}

/**
 * Why a message may not go out now, null when it may:
 * `empty`, `tooLong` (over maxLength) or `wait` (within cooldownMs of the last post).
 */
export function sendBlocker(text, { maxLength = 280, cooldownMs = 2000, lastSentAt = null, now = Date.now() } = {}) {
    const trimmed = String(text ?? '').trim();
    if (trimmed === '') return 'empty';
    if (length(trimmed) > maxLength) return 'tooLong';
    if (lastSentAt !== null && now - lastSentAt < cooldownMs) return 'wait';

    return null;
}

/** The kind-1311 template for a message: the stream's `a` as root (NIP-53), then its emoji tags. No `t` tags. */
export function messageTemplate(content, { address, relayHint, custom = [], now = Date.now() }) {
    const text = String(content).trim();

    return {
        kind: KIND_CHAT,
        created_at: Math.floor(now / 1000),
        tags: [['a', address, relayHint ?? '', 'root'], ...emojiTagsForContent(text, custom)],
        content: text,
    };
}

/**
 * Insert an item into a list kept oldest first (created_at, then id), each id
 * once, at most `max` long (the oldest go). Returns whether it was new.
 */
export function insertSorted(list, item, max = 200) {
    if (list.some((known) => known.id === item.id)) return false;

    let index = list.length;
    while (index > 0 && (list[index - 1].created_at > item.created_at || (list[index - 1].created_at === item.created_at && list[index - 1].id > item.id))) {
        index -= 1;
    }
    list.splice(index, 0, item);
    if (list.length > max) list.splice(0, list.length - max);

    return true;
}

/**
 * What the list shows: messages and zaps in order, and every run of
 * messages from muted pubkeys folded into one `muted` row (with the ids it
 * holds), unless that run was opened (`revealed` holds its first id).
 */
export function displayRows(items, { muted = [], revealed = [] } = {}) {
    const mutedSet = new Set(muted);
    const open = new Set(revealed);
    const rows = [];

    for (const item of items) {
        const isMuted = mutedSet.has(item.pubkey);
        const previous = rows[rows.length - 1];

        if (isMuted && previous?.type === 'muted') {
            previous.ids.push(item.id);
            previous.items.push(item);
            continue;
        }

        rows.push(isMuted ? { type: 'muted', key: 'm-' + item.id, ids: [item.id], items: [item] } : { type: item.type, key: item.id, item });
    }

    return rows.flatMap((row) => (row.type === 'muted' && open.has(row.ids[0])
        ? [row, ...row.items.map((item) => ({ type: item.type, key: item.id, item, revealed: true }))]
        : [row]));
}

/**
 * A kind 0's fields as the chat needs them: a name, an https picture, and
 * whether it says it is a bot (NIP-24 `bot`). Null when the content is not JSON.
 */
export function profileOf(event) {
    let data;
    try {
        data = JSON.parse(event?.content ?? '');
    } catch {
        return null;
    }
    if (!data || typeof data !== 'object') return null;

    const name = [data.display_name, data.displayName, data.name].find((value) => typeof value === 'string' && value.trim() !== '');

    return {
        name: name ? [...name.trim()].slice(0, 48).join('') : null,
        picture: isHttps(data.picture) ? data.picture : null,
        bot: data.bot === true,
        at: event.created_at ?? 0,
    };
}

/** The first 8 characters of an npub-like label for a pubkey without a profile. */
export function shortKey(pubkey) {
    return String(pubkey ?? '').slice(0, 8);
}

/** Sats with thin grouping, e.g. 21 000. */
export function formatSats(sats, locale = 'en') {
    return new Intl.NumberFormat(locale).format(sats);
}
