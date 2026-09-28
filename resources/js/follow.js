/**
 * Follow a player (P45): the player's own NIP-02 follow list (kind 3), read
 * from their relays, with one `p` added, signed only after a click on the
 * preview, and published to their write relays.
 *
 * A kind 3 replaces the whole list. Publishing it from a stale or partial
 * copy drops follows the player made elsewhere, with no way back. So this
 * module fails closed:
 *
 * - The list counts as read only when every relay that must hold it, the
 *   player's NIP-65 write relays, delivered EOSE (relayRead.js). Without a
 *   relay list nobody knows where the list lives: refused (P45 audit F2;
 *   the configured relays are no proof), with one exception, a new
 *   identity: every configured relay answered, none has a relay list and
 *   none has a kind 3, so there is nothing to shorten; the list starts on
 *   the configured relays. Anything less: refused, nothing signed or sent.
 * - Of the valid lists the newest wins (NIP-01), never the longest or the
 *   first to arrive; a forged copy does not count (signature checked).
 * - The new list is the old one unchanged (every tag, the content) plus one
 *   `p`; the signed event is compared against it before it is sent.
 * - Following is re-read at the click, so a list changed on another device
 *   between preview and click is not overwritten.
 *
 * No DOM or Alpine import: tests/js/follow.test.mjs runs it in Node with fake
 * relays and a key-backed signer.
 */
import { newest, publishToRelays, readOwnWriteRelays, readRelays, relayUrls } from './relayRead.js';
import { signTemplate } from './signing.js';

const HEX_PUBKEY = /^[0-9a-f]{64}$/;

export class FollowRefused extends Error {
    /**
     * @param {'not_read'|'no_relay_list'|'bad_pubkey'|'self'|'already'|'changed'|'mismatch'} code
     */
    constructor(code) {
        super(code);
        this.name = 'FollowRefused';
        this.code = code;
    }
}

/**
 * The player's follow list as the league needs it: the newest valid kind 3,
 * and whether every relay that must answer did.
 *
 * @returns {Promise<{ read: boolean, reason: 'not_read'|'no_relay_list'|null, list: object|null, writeRelays: string[], answered: number, asked: number }>}
 */
export async function readFollowList(pubkey, configured, options = {}) {
    const relays = relayUrls(configured);
    const own = await readOwnWriteRelays(pubkey, relays, options);

    // Where the list lives is unknown and not every relay answered: refuse (audit F2).
    if (own.writeRelays.length === 0 && !own.complete) {
        return { read: false, reason: 'not_read', list: null, writeRelays: [], answered: own.answered, asked: own.asked };
    }

    // No relay list anywhere: only a new identity may go on (see newIdentity below).
    const newIdentity = own.writeRelays.length === 0;
    const mustAnswer = newIdentity ? relays : own.writeRelays;
    const results = await readRelays([...new Set([...mustAnswer, ...relays])], [{ kinds: [3], authors: [pubkey] }], options);
    const answered = results.filter((result) => result.eose && mustAnswer.includes(result.url)).length;
    const read = mustAnswer.length > 0 && answered === mustAnswer.length;
    const list = newest(results.flatMap((result) => result.events), pubkey, 3);

    // A new identity: every configured relay answered, none has a relay list, none has a kind 3.
    // A kind 3 found without own write relays is refused: it could be a stale copy of a longer list
    // kept elsewhere. (A relay silent on this read already makes `read` false below.)
    if (newIdentity && read && list !== null) {
        return { read: false, reason: 'no_relay_list', list: null, writeRelays: [], answered, asked: mustAnswer.length };
    }

    return {
        read,
        reason: read ? null : 'not_read',
        list,
        writeRelays: mustAnswer,
        answered,
        asked: mustAnswer.length,
        newIdentity,
    };
}

function followed(list) {
    return (list?.tags ?? []).filter((tag) => tag[0] === 'p' && HEX_PUBKEY.test(tag[1] ?? '')).map((tag) => tag[1]);
}

/**
 * What the click changes, for the preview. `fresh`: no follow list was found
 * on any relay, so the new list holds this one player only.
 */
export function followPreview(read, target) {
    const before = new Set(followed(read.list)).size;
    const already = followed(read.list).includes(target);

    return { before, after: already ? before : before + 1, already, fresh: read.list === null };
}

/**
 * The kind 3 that adds `target`: every tag of the current list, unchanged
 * and in order, plus `["p", target]`; the same content; a `created_at` after
 * the current list's, so it replaces it. Throws FollowRefused otherwise.
 */
export function followTemplate(read, target, { me = null, now = Math.floor(Date.now() / 1000) } = {}) {
    if (!read?.read) throw new FollowRefused(read?.reason === 'no_relay_list' ? 'no_relay_list' : 'not_read');
    if (!HEX_PUBKEY.test(target ?? '')) throw new FollowRefused('bad_pubkey');
    if (me !== null && target === me) throw new FollowRefused('self');
    if (followed(read.list).includes(target)) throw new FollowRefused('already');

    const tags = (read.list?.tags ?? []).map((tag) => [...tag]);

    return {
        kind: 3,
        created_at: Math.max(now, (read.list?.created_at ?? 0) + 1),
        tags: [...tags, ['p', target]],
        content: read.list?.content ?? '',
    };
}

/**
 * The signed event is exactly the template (the signer may set only id,
 * pubkey, sig and a later created_at).
 */
export function checkSigned(signed, template, me) {
    const same = signed?.kind === 3 && signed.pubkey === me && signed.content === template.content
        && Number.isSafeInteger(signed.created_at) && signed.created_at >= template.created_at
        && JSON.stringify(signed.tags) === JSON.stringify(template.tags);

    if (!same) throw new FollowRefused('mismatch');

    return signed;
}

/**
 * The click: read the list again (it must still be readable and hold the
 * follows the preview counted), sign the new list, check it, publish it to
 * the write relays and the configured relays.
 *
 * @param {{ me: string, target: string, relays: string[], expectBefore?: number|null, signer?: object, now?: number, options?: object }} args
 * @returns {Promise<{ event: object, published: number, before: number, after: number }>}
 */
export async function follow({ me, target, relays, expectBefore = null, signer = globalThis.window?.nostr, now = Math.floor(Date.now() / 1000), options = {} }) {
    const read = await readFollowList(me, relays, options);
    const template = followTemplate(read, target, { me, now });
    const preview = followPreview(read, target);

    if (expectBefore !== null && preview.before !== expectBefore) throw new FollowRefused('changed');

    const signed = checkSigned(await signTemplate(template, { pubkey: me, signer }), template, me);
    const published = await publishToRelays([...read.writeRelays, ...relayUrls(relays)], signed, options);

    return { event: signed, published, before: preview.before, after: preview.after };
}
