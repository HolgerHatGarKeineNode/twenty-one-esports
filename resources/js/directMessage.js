/**
 * A direct message from one player to another (P45), written in the app,
 * signed and encrypted by the sender's own signer, published straight from
 * the browser to the recipient's relays. The league's server never sees the
 * text: nothing here talks to it.
 *
 * Format (NIP "Chat", rev. 9.7):
 *
 * - NIP-17 (kind 14 sealed in 13, wrapped in 1059) when the signer can do
 *   NIP-44 and the recipient has a DM relay list (`10050`): to those relays,
 *   and a copy to the sender's own DM relays (or the fallback relays).
 * - NIP-04 (kind 4) when EVERY lookup relay answered and none has a `10050`
 *   for the recipient, or the signer has no NIP-44 but has NIP-04: to the
 *   recipient's NIP-65 read relays and the sender's write relays, plus the
 *   fallback relays. Never silently: sendDirectMessage() refuses with
 *   `confirm_nip04` and the reason until the sender agreed (allowNip04).
 * - If not every relay answered, a missing `10050` is not known (P45 audit
 *   F3): NIP-17 to the fallback relays if the signer can, else refused
 *   when nothing answered. Without any encryption in the signer: refused.
 *
 * Loaded on demand by the Nostr bar (nostrBar.js), so the NIP-44 code is not
 * in every page's bundle. No DOM or Alpine import: tests/js/directMessage.test.mjs
 * runs it in Node with fake relays and key-backed signers.
 */
import { wrapDirectMessage } from './nostrChat.js';
import { canEncrypt } from './signerCapabilities.js';
import { newest, publishToRelays, readRelays, relayUrls, writeRelaysOf } from './relayRead.js';
import { signTemplate } from './signing.js';

/** NIP-17: the relays of a `10050`. */
export function dmRelaysOf(list) {
    return relayUrls((list?.tags ?? []).filter((tag) => tag[0] === 'relay').map((tag) => tag[1]));
}

/** The read relays of a NIP-65 list (`r` without marker, or `read`). */
export function readRelaysOf(relayList) {
    return relayUrls((relayList?.tags ?? [])
        .filter((tag) => tag[0] === 'r' && (tag[2] === undefined || tag[2] === 'read'))
        .map((tag) => tag[1]));
}

export function canNip04(signer) {
    return typeof signer?.signEvent === 'function' && typeof signer?.nip04?.encrypt === 'function';
}

export class DirectMessageRefused extends Error {
    constructor(code) {
        super(code);
        this.name = 'DirectMessageRefused';
        this.code = code;
    }
}

/**
 * Where a DM to `recipient` goes, and in which format, for this signer.
 * `read`: at least one relay answered the lookup.
 *
 * @returns {Promise<{ format: 'nip17'|'nip04', read: boolean, recipientRelays: string[], ownRelays: string[] }>}
 */
export async function routeDirectMessage({ sender, recipient, signer, relays, options = {} }) {
    const results = await readRelays(relays, [
        { kinds: [10050, 10002], authors: [recipient, sender] },
    ], options);
    const read = results.some((result) => result.eose);
    // Only an answer from EVERY lookup relay makes a missing 10050 a fact (P45 audit F3).
    const complete = results.length > 0 && results.every((result) => result.eose);
    const events = results.flatMap((result) => result.events);
    const theirDm = dmRelaysOf(newest(events, recipient, 10050));
    const ownDm = dmRelaysOf(newest(events, sender, 10050));
    const nip44 = canEncrypt(signer);
    const nip04 = canNip04(signer);
    const nip04Route = (reason) => ({
        format: 'nip04',
        reason,
        read,
        complete,
        recipientRelays: readRelaysOf(newest(events, recipient, 10002)),
        ownRelays: writeRelaysOf(newest(events, sender, 10002)),
    });

    if (nip44 && (theirDm.length > 0 || !complete)) {
        return { format: 'nip17', reason: null, read, complete, recipientRelays: theirDm, ownRelays: ownDm };
    }

    // No NIP-44 in the signer: NIP-04 is the only way, whatever the recipient has.
    if (!nip44 && nip04 && read) {
        return nip04Route('no_nip44');
    }

    // Every relay answered and none has a 10050: confirmed.
    if (nip04 && complete) {
        return nip04Route('no_dm_relays');
    }

    // NIP-17 without a `10050` "shouldn't try"; NIP-04 without an answered lookup has nowhere to go.
    throw new DirectMessageRefused(!nip44 && !nip04 ? 'no_encryption' : (nip44 ? 'no_dm_relays' : 'not_read'));
}

/**
 * Encrypt, sign and publish one message. `relays`: the configured lookup
 * and fallback relays. Returns what went out and how many relays took the
 * recipient's copy.
 *
 * @returns {Promise<{ format: 'nip17'|'nip04', delivered: number, events: object[] }>}
 */
export async function sendDirectMessage({ sender, recipient, content, signer, relays, allowNip04 = false, now = Math.floor(Date.now() / 1000), options = {} }) {
    const text = String(content ?? '').trim();

    if (text === '') throw new DirectMessageRefused('empty');
    if (recipient === sender) throw new DirectMessageRefused('self');

    const route = await routeDirectMessage({ sender, recipient, signer, relays, options });

    // Never a silent downgrade (P45 audit F3): NIP-04 only after the sender said yes to it, with the reason.
    if (route.format === 'nip04' && !allowNip04) {
        const refusal = new DirectMessageRefused('confirm_nip04');
        refusal.reason = route.reason;
        throw refusal;
    }
    const fallback = relayUrls(relays);

    if (route.format === 'nip17') {
        const { toRecipient, toSelf } = await wrapDirectMessage(signer, { sender, recipient, content: text, now });
        const delivered = await publishToRelays([...route.recipientRelays, ...fallback], toRecipient, options);
        await publishToRelays(route.ownRelays.length > 0 ? route.ownRelays : fallback, toSelf, options);

        return { format: 'nip17', delivered, events: [toRecipient, toSelf] };
    }

    const encrypted = await signer.nip04.encrypt(recipient, text);
    const signed = await signTemplate({ kind: 4, created_at: now, tags: [['p', recipient]], content: encrypted }, { pubkey: sender, signer });
    const delivered = await publishToRelays([...route.recipientRelays, ...route.ownRelays, ...fallback], signed, options);

    return { format: 'nip04', delivered, events: [signed] };
}
