/**
 * Reactions on table chat messages (plan "Hyperbitcoinization", P2, Ansatz 6): NIP-25 kind 7 with one of a
 * small fixed set of emojis, counted under the message.
 *
 * A reaction tags the match's channel as its root `e` and the message as its LAST `e` (NIP-25: the last `e`
 * is the event reacted to), so one subscription `{"kinds":[7],"#e":[channel]}` reads every reaction of the
 * table, and any other client sees an ordinary reaction to the message. A pubkey counts once per message and
 * emoji, however often it sends it; an emoji outside the set, a reaction to another channel's message or one
 * of a hidden or muted pubkey counts nowhere. Content is never rendered as HTML.
 *
 * No DOM, so tests/js/hyperReactions.test.mjs runs it in Node.
 */
export const KIND_REACTION = 7;

export const REACTIONS = ['🔥', '😂', '🧡', '⚡', '🫡', '💀'];

/** Tags of a reaction that are looked at. */
const MAX_TAGS = 16;

/** Messages and reacting pubkeys one table keeps (a flood of keys cannot grow the book without end). */
export const MAX_MESSAGES = 400;
export const MAX_PER_EMOJI = 500;

const tagsOf = (event) => (Array.isArray(event?.tags) ? event.tags.slice(0, MAX_TAGS).filter((tag) => Array.isArray(tag) && typeof tag[0] === 'string') : []);

/** The kind-7 template for `emoji` on `message` (an event with id and pubkey) in `channel`. */
export function reactionTemplate(message, emoji, { channel, relayHint = '', now = Date.now() }) {
    return {
        kind: KIND_REACTION,
        created_at: Math.floor(now / 1000),
        tags: [['e', channel, relayHint, 'root'], ['e', message.id, relayHint], ['p', message.pubkey], ['k', '42']],
        content: emoji,
    };
}

/** The message a reaction is to, or null: a kind 7 in `channel` with an allowed emoji. */
export function reactionTarget(event, channel, allowed = REACTIONS) {
    if (event?.kind !== KIND_REACTION || typeof event.pubkey !== 'string' || !allowed.includes(event.content)) {
        return null;
    }

    const ids = tagsOf(event).filter((tag) => tag[0] === 'e' && typeof tag[1] === 'string');

    if (ids.length < 2 || ids[0][1] !== channel) {
        return null;
    }

    const target = ids[ids.length - 1][1];

    return target === channel ? null : target;
}

/**
 * Adds a reaction to the book (message id -> emoji -> set of pubkeys). Returns the message id it counted
 * for, or null when it changed nothing.
 */
export function addReaction(book, event, { channel, allowed = REACTIONS } = {}) {
    const target = reactionTarget(event, channel, allowed);

    if (target === null) {
        return null;
    }

    if (!book.has(target)) {
        if (book.size >= MAX_MESSAGES) return null;
        book.set(target, new Map());
    }

    const byEmoji = book.get(target);
    const who = byEmoji.get(event.content) ?? new Set();

    if (who.has(event.pubkey) || who.size >= MAX_PER_EMOJI) {
        return null;
    }

    who.add(event.pubkey);
    byEmoji.set(event.content, who);

    return target;
}

/**
 * The counts under one message, in the set's order, empty ones left out: [{emoji, count, mine}].
 * `hidden` pubkeys (muted, hidden by the league) are not counted.
 */
export function countsFor(book, messageId, { me = null, hidden = new Set(), allowed = REACTIONS } = {}) {
    const byEmoji = book.get(messageId);

    if (!byEmoji) {
        return [];
    }

    return allowed.map((emoji) => {
        const who = [...(byEmoji.get(emoji) ?? [])].filter((pubkey) => !hidden.has(pubkey));

        return { emoji, count: who.length, mine: me !== null && who.includes(me) };
    }).filter((entry) => entry.count > 0);
}
