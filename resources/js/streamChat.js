/**
 * The rules of the /live stream chat (P24), without DOM or Alpine, so
 * tests/js/streamChat.test.mjs runs them in Node:
 *
 * - which relay events are chat messages of this stream (NIP-53 kind 1311
 *   with the stream's `a` address) and which are zaps of it (NIP-57 kind
 *   9735 signed by a known LNURL server, for the stream's recipient and
 *   LNURL, its zap request valid, the invoice amount equal to the requested
 *   one);
 * - how a message's text splits into text, links, NIP-30 custom emoji and
 *   NIP-27 references (`nostr:` URIs of NIP-21, decoded per NIP-19: npub and
 *   nprofile become `mention`s, note, nevent and naddr `ref`s; nsec and
 *   anything that does not decode stay text), within fixed bounds: a message from a relay is anybody's input, and one
 *   63 KB event of `:a:` repeated with one emoji tag froze the tab for 7.2 s
 *   with 85 000 DOM nodes (security review of P24). So the text is clipped
 *   to MAX_CHARS code points before it is read, a message has at most
 *   MAX_TOKENS tokens, MAX_EMOJI images and MAX_REFS references, and every
 *   URL is at most MAX_URL long;
 * - who gets the bot mark: only the configured bot key; a profile that says
 *   `bot: true` about itself gets its npub shown instead (it could be anyone);
 * - which `emoji` tags a text to be sent needs (from the final text, never
 *   from a list of what the picker inserted);
 * - the send rules: not empty, at most `maxLength` characters, one message
 *   per `cooldownMs`;
 * - how muted messages collapse into one line per run;
 * - the list's order (created_at, then id) and its pages: the newest page of
 *   several relays' answers, and what a page of older messages brings.
 */
import { decode as decodeNip19, npubEncode } from 'nostr-tools/nip19';
import { verifyEvent } from 'nostr-tools/pure';

export const KIND_CHAT = 1311;
export const KIND_ZAP = 9735;
export const KIND_ZAP_REQUEST = 9734;

/** Code points of a received message (or zap comment) that are read at all: four times what this page lets anyone send. */
export const MAX_CHARS = 4 * 280;

/** Tokens (text, link, emoji) one message renders; the rest stays plain text in the last one. */
export const MAX_TOKENS = 64;

/** Emoji images one message renders; further shortcodes stay text. */
export const MAX_EMOJI = 20;

/** `emoji` tags of one event that are looked at. */
export const MAX_EMOJI_TAGS = 100;

/** Longest URL taken from anybody's event (picture, emoji, link target). */
export const MAX_URL = 2048;

/** NIP-27 references (mentions and quoted events) one message renders; further ones stay text. */
export const MAX_REFS = 10;

/** Relay hints kept from one nprofile (they widen the profile read, so only a few). */
export const MAX_HINTS = 3;

/** Where a reference to someone outside the league opens (NIP-19 code in the path). */
export const NJUMP = 'https://njump.me/';

/** NIP-30: alphanumeric characters and underscores between colons. */
const SHORTCODE = /:(\w+):/g;

/** http(s) links; closing punctuation that ends a sentence is not part of them. */
const LINK = /\bhttps?:\/\/[^\s<>"']+/gi;
const LINK_TAIL = /[.,;:!?)\]}'"]+$/;

/**
 * NIP-21 URIs of profiles and events, lowercase bech32 only (the charset
 * without 1, b, i, o). nsec is not matched: a secret pasted into a chat stays
 * the text it is and is never decoded here.
 */
const NOSTR_URI = /\bnostr:((npub|nprofile|note|nevent|naddr)1[02-9ac-hj-np-z]+)/g;
/** npub and note have a fixed length; a word glued to the end ("…s") is cut off and tried again. */
const FIXED_LENGTH = 63;
const HEX64 = /^[0-9a-f]{64}$/;

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
    return typeof url === 'string' && url.length <= MAX_URL && /^https:\/\/[^\s]+$/i.test(url);
}

/**
 * The first `max` code points of a text, and whether there was more. Walks
 * only as far as it needs to: a 63 KB string is never spread into an array.
 */
export function clip(text, max = MAX_CHARS) {
    const value = String(text ?? '');
    // At most `max` UTF-16 units are at most `max` code points.
    if (value.length <= max) return { text: value, clipped: false };

    let count = 0;
    let end = 0;
    for (const character of value) {
        if (count === max) return { text: value.slice(0, end), clipped: true };
        count += 1;
        end += character.length;
    }

    return { text: value, clipped: false };
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
 * receipt kind 9735 signed by one of `signers`, carrying the stream's `a` and
 * the stream's zap `recipient` as `p`; its `description` a validly signed
 * kind-9734 zap request for the same address and the same `p`, whose
 * `lnurl` (when it has one) is the `lnurl` of the receipt's signer (`lnurls`,
 * signer -> lnurl: each LNURL server signs only for its own address; without
 * `lnurls`, the one `lnurl`); an invoice amount that, when the request names
 * one, is exactly that amount. Without a recipient nothing counts: a receipt
 * the signer made for someone else's zap must not show as a zap of this stream.
 *
 * @returns {{ id: string, pubkey: string, created_at: number, sats: number, comment: string } | null}
 */
export function parseZap(receipt, { address, signers = [], recipient = null, lnurl = null, lnurls = null, until = null, verify = verifiedAfresh } = {}) {
    if (receipt?.kind !== KIND_ZAP || !recipient || !signers.includes(receipt.pubkey) || !tagValues(receipt, 'a').includes(address) || !tagValues(receipt, 'p').includes(recipient)) {
        return null;
    }

    // A legacy signer (the shared getalby key) counts only receipts made before its cutoff (audit L1, 2026-10-03).
    if (until && Object.hasOwn(until, receipt.pubkey) && !(Number(receipt.created_at) < Number(until[receipt.pubkey]))) {
        return null;
    }

    let request;
    try {
        request = JSON.parse(tagValues(receipt, 'description')[0] ?? '');
    } catch {
        return null;
    }

    if (request?.kind !== KIND_ZAP_REQUEST || !tagValues(request, 'a').includes(address) || !tagValues(request, 'p').includes(recipient)) {
        return null;
    }

    const asksLnurl = tagValues(request, 'lnurl')[0];
    const signersLnurl = lnurls ? (Object.hasOwn(lnurls, receipt.pubkey) ? lnurls[receipt.pubkey] : null) : lnurl;
    if (asksLnurl !== undefined && (!signersLnurl || String(asksLnurl).toLowerCase() !== String(signersLnurl).toLowerCase())) {
        return null;
    }

    if (!verify(request)) {
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
        comment: typeof request.content === 'string' ? clip(request.content).text : '',
    };
}

/** `shortcode -> https URL` of a message's own `emoji` tags (NIP-30); other URLs are dropped. */
export function emojiMap(tags) {
    const map = new Map();
    let seen = 0;
    for (const tag of tags ?? []) {
        if (!Array.isArray(tag) || tag[0] !== 'emoji') continue;
        if (++seen > MAX_EMOJI_TAGS) break;
        if (/^\w+$/.test(tag[1] ?? '') && isHttps(tag[2]) && !map.has(tag[1])) {
            map.set(tag[1], tag[2]);
        }
    }

    return map;
}

function splitEmoji(text, emoji, tokens, budget) {
    let last = 0;
    for (const match of text.matchAll(SHORTCODE)) {
        if (budget.emoji <= 0) break;
        const url = emoji.get(match[1]);
        if (!url) continue;
        if (match.index > last) tokens.push({ type: 'text', value: text.slice(last, match.index) });
        tokens.push({ type: 'emoji', value: match[1], url });
        budget.emoji -= 1;
        last = match.index + match[0].length;
    }
    if (last < text.length) tokens.push({ type: 'text', value: text.slice(last) });
}

/** At most `max` distinct wss:// relay URLs (a NIP-19 code's or a tag's hints), each at most 200 characters. */
export function relayHints(relays, max = MAX_HINTS) {
    return [...new Set((Array.isArray(relays) ? relays : []).filter((url) => typeof url === 'string' && url.length <= 200 && /^wss:\/\/[^\s/?#]+(\/[^\s]*)?$/i.test(url)))].slice(0, max);
}

/**
 * The token a NIP-19 code stands for, or null when it does not decode to a
 * well-formed profile or event reference:
 * - `mention` { pubkey, npub, relays } for npub and nprofile;
 * - `ref` { id | address, author, url } for note, nevent and naddr, `url`
 *   its njump.me page.
 * `value` is the code itself (what the text said, used when a token folds back into text).
 */
export function nostrToken(code) {
    let decoded;
    try {
        decoded = decodeNip19(code);
    } catch {
        return null;
    }

    const { type, data } = decoded;
    if (type === 'npub' || type === 'nprofile') {
        const pubkey = type === 'npub' ? data : data?.pubkey;
        if (!HEX64.test(pubkey ?? '')) return null;

        return { type: 'mention', value: code, pubkey, npub: npubEncode(pubkey), relays: type === 'nprofile' ? relayHints(data.relays) : [] };
    }

    const url = NJUMP + code;
    if (url.length > MAX_URL) return null;
    if (type === 'note') {
        return HEX64.test(data ?? '') ? { type: 'ref', value: code, id: data, author: null, url } : null;
    }
    if (type === 'nevent') {
        if (!HEX64.test(data?.id ?? '')) return null;

        return { type: 'ref', value: code, id: data.id, author: HEX64.test(data.author ?? '') ? data.author : null, url };
    }
    if (type === 'naddr') {
        if (!HEX64.test(data?.pubkey ?? '') || !Number.isInteger(data.kind) || typeof data.identifier !== 'string') return null;

        return { type: 'ref', value: code, address: `${data.kind}:${data.pubkey}:${data.identifier}`, author: data.pubkey, url };
    }

    return null;
}

/** Text with its `nostr:` references taken out (within budget.refs); the text between goes on to the emoji. */
function splitNostr(text, emoji, tokens, budget) {
    let last = 0;
    for (const match of text.matchAll(NOSTR_URI)) {
        if (budget.refs <= 0) break;
        let code = match[1];
        let token = nostrToken(code);
        if (!token && (match[2] === 'npub' || match[2] === 'note') && code.length > FIXED_LENGTH) {
            code = code.slice(0, FIXED_LENGTH);
            token = nostrToken(code);
        }
        if (!token) continue;
        if (match.index > last) splitEmoji(text.slice(last, match.index), emoji, tokens, budget);
        tokens.push(token);
        budget.refs -= 1;
        last = match.index + 'nostr:'.length + code.length;
    }
    if (last < text.length) splitEmoji(text.slice(last), emoji, tokens, budget);
}

function tokenText(token) {
    if (token.type === 'emoji') return ':' + token.value + ':';
    if (token.type === 'mention' || token.type === 'ref') return 'nostr:' + token.value;

    return token.type === 'link' ? token.url : token.value;
}

/**
 * A message's content as a list of tokens: `text`, `link` (http/https only;
 * the shown text is the URL without its scheme), `emoji` (a shortcode the
 * message's own tags give an https image), `mention` and `ref` (NIP-27
 * `nostr:` references, nostrToken()). Text stays text: the page renders it
 * with x-text, never as HTML.
 *
 * Bounded whatever comes in: the first `maxChars` code points only (a clipped
 * text ends in "…"), at most `maxEmoji` images and `maxRefs` references, at
 * most `maxTokens` tokens (what is beyond folds into the last one as plain
 * text), links of at most MAX_URL characters.
 *
 * @returns {Array<{ type: 'text'|'link'|'emoji'|'mention'|'ref', value: string, url?: string, pubkey?: string, npub?: string, relays?: string[], id?: string, address?: string, author?: string|null }>}
 */
export function tokenize(content, tags = [], { maxChars = MAX_CHARS, maxTokens = MAX_TOKENS, maxEmoji = MAX_EMOJI, maxRefs = MAX_REFS } = {}) {
    const { text: body, clipped } = clip(content, maxChars);
    const text = clipped ? body + '…' : body;
    const emoji = emojiMap(tags);
    const budget = { emoji: maxEmoji, refs: maxRefs };
    const tokens = [];
    let last = 0;

    for (const match of text.matchAll(LINK)) {
        const url = match[0].replace(LINK_TAIL, '');
        if (url.length > MAX_URL) continue;
        if (match.index > last) splitNostr(text.slice(last, match.index), emoji, tokens, budget);
        tokens.push({ type: 'link', value: url.replace(/^https?:\/\//i, ''), url });
        last = match.index + url.length;
    }
    if (last < text.length) splitNostr(text.slice(last), emoji, tokens, budget);

    // Neighbouring text pieces merge back into one.
    const merged = tokens.reduce((list, token) => {
        const previous = list[list.length - 1];
        if (token.type === 'text' && previous?.type === 'text') {
            previous.value += token.value;
        } else {
            list.push(token.relays ? { ...token, relays: [...token.relays] } : { ...token });
        }

        return list;
    }, []);

    if (merged.length > maxTokens) {
        const tail = merged.splice(maxTokens - 1).map(tokenText).join('');
        const previous = merged[merged.length - 1];
        if (previous?.type === 'text') {
            previous.value += tail;
        } else {
            merged.push({ type: 'text', value: tail });
        }
    }

    return merged;
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

/** The list's order: oldest first by created_at, a tie by id. */
export function compareItems(a, b) {
    if (a.created_at !== b.created_at) return a.created_at - b.created_at;
    if (a.id === b.id) return 0;

    return a.id < b.id ? -1 : 1;
}

/**
 * Insert an item into a list kept oldest first (created_at, then id), each id
 * once, at most `max` long (the oldest go). Returns whether it was new.
 */
export function insertSorted(list, item, max = 200) {
    if (list.some((known) => known.id === item.id)) return false;

    let index = list.length;
    while (index > 0 && compareItems(list[index - 1], item) > 0) {
        index -= 1;
    }
    list.splice(index, 0, item);
    if (list.length > max) list.splice(0, list.length - max);

    return true;
}

/**
 * The newest `max` items, each id once, oldest first: what one page of the
 * chat shows. Several relays each answer a `limit` of their own, so their
 * union is only complete down to its `max` newest items: below that, a relay
 * may hold events it did not send because its own page was full.
 */
export function newestPage(items, max) {
    const byId = new Map();
    for (const item of items) {
        if (!byId.has(item.id)) byId.set(item.id, item);
    }
    const sorted = [...byId.values()].sort(compareItems);

    return Number.isFinite(max) && sorted.length > max ? sorted.slice(sorted.length - max) : sorted;
}

/**
 * What a page of older messages means (the relays asked with `until` and
 * `limit: page`): `items` the newest `page` unknown ones, and whether the
 * start of the chat is reached (`end`): at least one relay answered with its
 * EOSE, every relay that answered sent less than a full page, and nothing
 * was cut. Relays that did not answer are not waited for.
 *
 * @param {Array<{ eose: boolean, events: object[] }>} results
 * @param {object[]} fresh the answered items not in the list yet
 * @returns {{ items: object[], end: boolean }}
 */
export function olderPage(results, fresh, page) {
    const answered = results.filter((result) => result.eose);
    const items = newestPage(fresh, page);
    const end = answered.length > 0 && answered.every((result) => result.events.length < page) && items.length === newestPage(fresh, Infinity).length;

    return { items, end };
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
    // A kind 0 is a few hundred bytes; one of 64 KB is not parsed at all.
    if (typeof event?.content !== 'string' || event.content.length > 65_536) return null;

    let data;
    try {
        data = JSON.parse(event.content);
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

/**
 * The mark a message author gets: `bot` for the configured bot key only;
 * `self` for a profile that calls itself a bot (anyone can: its npub is
 * shown next to the name instead of a badge); null otherwise.
 */
export function botMark(pubkey, { bot = null, profile = null } = {}) {
    if (bot && pubkey === bot) return 'bot';

    return profile?.bot === true ? 'self' : null;
}

/**
 * The profile cache as it may go to localStorage: newest seen first, at most
 * `maxCount` entries and `maxBytes` of JSON; the oldest go first. A profile
 * can hold at most a 48-character name and a 2048-character picture URL, so
 * the cap is about size, not trust.
 */
export function boundProfiles(store, { maxBytes = 256 * 1024, maxCount = 400 } = {}) {
    const kept = {};
    let bytes = 2;
    const entries = Object.entries(store ?? {}).sort(([, a], [, b]) => (b?.seen ?? 0) - (a?.seen ?? 0)).slice(0, maxCount);
    for (const [pubkey, profile] of entries) {
        const size = JSON.stringify(pubkey).length + JSON.stringify(profile).length + 2;
        if (bytes + size > maxBytes) break;
        kept[pubkey] = profile;
        bytes += size;
    }

    return kept;
}

/** The first 8 characters of an npub-like label for a pubkey without a profile. */
export function shortKey(pubkey) {
    return String(pubkey ?? '').slice(0, 8);
}

/** Sats with thin grouping, e.g. 21 000. */
export function formatSats(sats, locale = 'en') {
    return new Intl.NumberFormat(locale).format(sats);
}
