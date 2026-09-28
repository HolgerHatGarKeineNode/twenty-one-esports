/**
 * The rules of a game's global chat (P21, NIP "Game channels"), without DOM
 * or Alpine, so tests/js/channelChat.test.mjs runs them in Node:
 *
 * - NIP-28: a message is a kind 42 whose NIP-10 root `e` tag names the
 *   channel (the kind-40 id); the channel's creator may hide a message
 *   (kind 43) or mute a pubkey (kind 44) for every reader of this app.
 * - NIP-88: a poll is a kind 1068 carrying the same root `e` tag (our scope:
 *   NIP-88 itself has no channel), single choice, with an `endsAt`; a vote
 *   is a kind 1018 with the poll's `e` and a `response`. One vote per
 *   pubkey: the newest within the poll's limits (created after the poll, not
 *   after its end), the lowest id on a tie; only the first `response` tag
 *   counts (singlechoice). Whose votes count is the caller's (the page counts
 *   league players only).
 * - Everything read from a relay is anybody's input: tags are looked at up
 *   to MAX_TAGS, texts are clipped before they are read, a poll has at most
 *   MAX_OPTIONS answers, a vote book at most MAX_VOTERS pubkeys per poll.
 *   Message text goes through streamChat.js tokenize() (P24, audited).
 */
import { clip, emojiTagsForContent, length } from './streamChat.js';

export const KIND_CREATE = 40;
export const KIND_META = 41;
export const KIND_MESSAGE = 42;
export const KIND_HIDE = 43;
export const KIND_MUTE = 44;
export const KIND_POLL = 1068;
export const KIND_VOTE = 1018;

/** Tags of one event that are looked at. */
export const MAX_TAGS = 64;

/** Answers a received poll may have to be shown at all (our own have 2 to 4). */
export const MAX_OPTIONS = 10;

/** Code points of a received poll question and of an answer that are shown. */
export const MAX_QUESTION = 280;
export const MAX_LABEL = 120;

/** Pubkeys one poll's vote book holds; further voters are not counted (and the page says how many were dropped). */
export const MAX_VOTERS = 5000;

/** Creator hides and mutes kept. */
export const MAX_MODERATION = 500;

const HEX64 = /^[0-9a-f]{64}$/;
const OPTION_ID = /^[A-Za-z0-9]{1,32}$/;
const CONTROL = /[\u0000-\u001f\u007f-\u009f\u2028\u2029]/u;

function tagsOf(event) {
    return Array.isArray(event?.tags) ? event.tags.slice(0, MAX_TAGS).filter((tag) => Array.isArray(tag) && typeof tag[0] === 'string') : [];
}

/** The event's NIP-10 root `e` names this channel (a positional `e` without marker counts, as in older NIP-28 clients). */
export function inChannel(event, channel) {
    return tagsOf(event).some((tag) => tag[0] === 'e' && tag[1] === channel && (tag[3] === undefined || tag[3] === '' || tag[3] === 'root'));
}

export function isChannelMessage(event, channel) {
    return event?.kind === KIND_MESSAGE && typeof event.content === 'string' && event.content.trim() !== '' && inChannel(event, channel);
}

/** The kind-42 template: the channel as root (NIP-28), then the emoji tags the text needs. No `t` tags. */
export function messageTemplate(content, { channel, relayHint = '', custom = [], now = Date.now() }) {
    const text = String(content).trim();

    return {
        kind: KIND_MESSAGE,
        created_at: Math.floor(now / 1000),
        tags: [['e', channel, relayHint, 'root'], ...emojiTagsForContent(text, custom)],
        content: text,
    };
}

/* ---------- Polls ------------------------------------------------------------------------------------------- */

function oneLine(text) {
    return typeof text === 'string' && !CONTROL.test(text) ? text.trim() : null;
}

/**
 * Why a poll may not be sent, null when it may: `question` (empty or over
 * `questionMax`), `options` (fewer than 2 or more than `maxOptions`
 * non-empty answers, one over `optionMax`, a line break, or two alike).
 */
export function pollBlocker({ question, options }, { questionMax = 140, optionMax = 60, maxOptions = 4 } = {}) {
    const q = oneLine(question);
    if (!q || length(q) > questionMax) return 'question';

    const answers = (Array.isArray(options) ? options : []).map(oneLine);
    const given = answers.filter((answer) => answer !== '');
    if (answers.some((answer) => answer === null) || given.length < 2 || given.length > maxOptions) return 'options';
    if (given.some((answer) => length(answer) > optionMax)) return 'options';
    if (new Set(given.map((answer) => answer.toLocaleLowerCase())).size !== given.length) return 'options';

    return null;
}

/** An option id (NIP-88: alphanumeric), random. */
export function optionId(random = (n) => crypto.getRandomValues(new Uint8Array(n))) {
    const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';

    return [...random(9)].map((byte) => alphabet[byte % alphabet.length]).join('');
}

/**
 * The kind-1068 template of a poll in the channel: the question as content,
 * the channel as root `e`, one `option` per answer, the chat relays, single
 * choice, `endsAt` = now + duration. Throws what pollBlocker() refuses.
 */
export function pollTemplate({ question, options, duration, channel, relayHint = '', relays = [], now = Date.now(), newId = optionId, limits = {} }) {
    const blocker = pollBlocker({ question, options }, limits);
    if (blocker !== null) throw new Error('poll_' + blocker);
    if (!Number.isSafeInteger(duration) || duration <= 0) throw new Error('poll_duration');

    const created = Math.floor(now / 1000);
    const ids = new Set();
    const optionTags = options.map((label) => label.trim()).filter((label) => label !== '').map((label) => {
        let id = newId();
        while (ids.has(id)) id = newId();
        ids.add(id);

        return ['option', id, label];
    });

    return {
        kind: KIND_POLL,
        created_at: created,
        tags: [['e', channel, relayHint, 'root'], ...optionTags, ...relays.map((relay) => ['relay', relay]), ['polltype', 'singlechoice'], ['endsAt', String(created + duration)]],
        content: question.trim(),
    };
}

/**
 * A poll of this channel as the page shows it, or null: kind 1068 with the
 * channel's root `e`, single choice (a `multiplechoice` poll is not ours and
 * not shown), an integer `endsAt` after its creation, 2 to MAX_OPTIONS
 * answers with distinct alphanumeric ids and a non-empty one-line label.
 * Question and labels are clipped; anything past MAX_TAGS tags is not read.
 */
export function parsePoll(event, channel) {
    if (event?.kind !== KIND_POLL || typeof event.content !== 'string' || !HEX64.test(event.id ?? '') || !Number.isSafeInteger(event.created_at) || !inChannel(event, channel)) {
        return null;
    }

    const tags = tagsOf(event);
    const type = tags.find((tag) => tag[0] === 'polltype')?.[1];
    if (type !== undefined && type !== 'singlechoice') return null;

    const ends = tags.find((tag) => tag[0] === 'endsAt')?.[1];
    const endsAt = /^\d{1,12}$/.test(String(ends ?? '')) ? Number(ends) : null;
    if (endsAt === null || endsAt <= event.created_at) return null;

    const options = [];
    const seen = new Set();
    for (const tag of tags) {
        if (tag[0] !== 'option' || !OPTION_ID.test(tag[1] ?? '') || seen.has(tag[1])) continue;
        const label = clip(typeof tag[2] === 'string' ? tag[2] : '', MAX_LABEL).text.trim();
        if (label === '' || CONTROL.test(label)) continue;
        seen.add(tag[1]);
        options.push({ id: tag[1], label });
        if (options.length > MAX_OPTIONS) return null;
    }
    if (options.length < 2) return null;

    const { text, clipped } = clip(event.content, MAX_QUESTION);
    const question = (clipped ? text + '…' : text).trim();
    if (question === '') return null;

    return { id: event.id, pubkey: event.pubkey, created_at: event.created_at, question, options, endsAt };
}

/** A kind 1018 as the vote book needs it, or null: the poll it names (first `e`) and the first `response`. */
export function parseVote(event) {
    if (event?.kind !== KIND_VOTE || !HEX64.test(event.id ?? '') || !HEX64.test(event.pubkey ?? '') || !Number.isSafeInteger(event.created_at)) {
        return null;
    }

    const tags = tagsOf(event);
    const poll = tags.find((tag) => tag[0] === 'e')?.[1];
    const option = tags.find((tag) => tag[0] === 'response')?.[1];
    if (!HEX64.test(poll ?? '') || !OPTION_ID.test(option ?? '')) return null;

    return { id: event.id, pubkey: event.pubkey, created_at: event.created_at, poll, option };
}

/** The kind-1018 template of a vote. */
export function voteTemplate(poll, option, { relayHint = '', now = Date.now() } = {}) {
    return { kind: KIND_VOTE, created_at: Math.floor(now / 1000), tags: [['e', poll.id, relayHint], ['response', option]], content: '' };
}

export function isClosed(poll, now = Math.floor(Date.now() / 1000)) {
    return now >= poll.endsAt;
}

/** Whether `vote` beats `held` for the same pubkey: newer, or as new with a lower id. */
function beats(vote, held) {
    return held === undefined || vote.created_at > held.created_at || (vote.created_at === held.created_at && vote.id < held.id);
}

/**
 * Put a vote into a book (`Map<pollId, Map<pubkey, vote>>`), keeping only
 * each pubkey's best vote within the poll's limits. `polls` maps poll id to
 * the parsed poll; a vote for an unknown poll, outside its limits or for an
 * option it does not have is dropped (the pubkey's earlier valid vote stays).
 * Returns what happened: `added`, `replaced`, `ignored` or `full`.
 */
export function addVote(book, vote, polls, maxVoters = MAX_VOTERS) {
    const poll = polls.get(vote?.poll);
    if (!poll || vote.created_at < poll.created_at || vote.created_at > poll.endsAt || !poll.options.some((option) => option.id === vote.option)) {
        return 'ignored';
    }

    if (!book.has(poll.id)) book.set(poll.id, new Map());
    const voters = book.get(poll.id);
    const held = voters.get(vote.pubkey);
    if (held === undefined && voters.size >= maxVoters) return 'full';
    if (!beats(vote, held)) return 'ignored';

    voters.set(vote.pubkey, vote);

    return held === undefined ? 'added' : 'replaced';
}

/**
 * The result of a poll: votes per option of the pubkeys `counts(pubkey)`
 * accepts, their total, how many valid votes were not counted, and `mine`
 * (the option `me` voted for, counted or not).
 */
export function tally(poll, book, { counts = () => true, me = null } = {}) {
    const result = Object.fromEntries(poll.options.map((option) => [option.id, 0]));
    let total = 0;
    let uncounted = 0;
    let mine = null;

    for (const [pubkey, vote] of book.get(poll.id) ?? new Map()) {
        if (pubkey === me) mine = vote.option;
        if (counts(pubkey)) {
            result[vote.option] += 1;
            total += 1;
        } else {
            uncounted += 1;
        }
    }

    return { counts: result, total, uncounted, mine };
}

/**
 * The creator's moderation: ids of messages hidden (kind 43, `e`) and pubkeys
 * muted (kind 44, `p`) by the channel's creator, from events of any author
 * (only the creator's count), at most MAX_MODERATION each.
 */
export function moderation(events, creator) {
    const hidden = new Set();
    const muted = new Set();

    for (const event of events) {
        if (event?.pubkey !== creator) continue;
        for (const tag of tagsOf(event)) {
            if (event.kind === KIND_HIDE && tag[0] === 'e' && HEX64.test(tag[1] ?? '') && hidden.size < MAX_MODERATION) hidden.add(tag[1]);
            if (event.kind === KIND_MUTE && tag[0] === 'p' && HEX64.test(tag[1] ?? '') && muted.size < MAX_MODERATION) muted.add(tag[1]);
        }
    }

    return { hidden, muted };
}

/** "in 2 h", "in 3 d", "in 12 min" for the time until `endsAt`, with Intl. */
export function timeLeft(endsAt, { now = Math.floor(Date.now() / 1000), locale = 'en' } = {}) {
    const seconds = Math.max(0, endsAt - now);
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto', style: 'short' });
    if (seconds >= 2 * 86400) return format.format(Math.round(seconds / 86400), 'day');
    if (seconds >= 2 * 3600) return format.format(Math.round(seconds / 3600), 'hour');

    return format.format(Math.max(1, Math.round(seconds / 60)), 'minute');
}
