/**
 * A direct message from one player to another (P45), written in the app,
 * signed and encrypted by the sender's own signer, published straight from
 * the browser to the recipient's relays. The league's server never sees the
 * text: nothing here talks to it.
 *
 * Format (NIP "Chat", rev. 9.7; tightened by the user on 2026-10-04: "Die
 * veraltete am besten sperren und nur verwenden, wenn das Profil dafür nicht
 * ausgelegt ist (weil INBOX-Relay fehlt)"):
 *
 * - NIP-17 (kind 14 sealed in 13, wrapped in 1059) whenever the recipient
 *   names DM relays in a `10050`: to those relays, and a copy to the
 *   sender's own DM relays (or the fallback relays). A `10050` whose relays
 *   are no websocket URL still names relays: NIP-17 to the fallback relays.
 * - NIP-04 (kind 4) ONLY when EVERY lookup relay answered and none has a
 *   `10050` naming a relay for the recipient: to their NIP-65 read relays
 *   and the sender's write relays, plus the fallback relays. Never silently:
 *   sendDirectMessage() refuses with `confirm_nip04` and the reason until
 *   the sender agreed (allowNip04).
 * - If not every relay answered, a missing `10050` is not known (P45 audit
 *   F3): NIP-17 to the fallback relays.
 * - A signer without NIP-44 is refused (`no_nip44`) wherever NIP-17 is the
 *   format; it is never downgraded to NIP-04. Without any encryption in the
 *   signer and no `10050`: refused (`no_encryption`).
 *
 * sendDirectMessages() sends one text to several recipients, each on its own
 * route (invites, clan applications).
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

/**
 * Whether a `10050` names any relay at all, usable here or not: such a
 * profile reads NIP-17, so it never gets a kind 4. An empty list (or none)
 * names no inbox.
 */
export function namesDmRelays(list) {
    return (list?.tags ?? []).some((tag) => tag[0] === 'relay' && typeof tag[1] === 'string' && tag[1].trim() !== '');
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
    const theirList = newest(events, recipient, 10050);
    const theirDm = dmRelaysOf(theirList);
    const ownDm = dmRelaysOf(newest(events, sender, 10050));
    const nip44 = canEncrypt(signer);
    const nip04 = canNip04(signer);
    // NIP-17 is the format unless every relay answered and no 10050 names a relay for the recipient.
    const nip17 = namesDmRelays(theirList) || !complete;

    if (nip17 && nip44) {
        return { format: 'nip17', reason: null, read, complete, recipientRelays: theirDm, ownRelays: ownDm };
    }

    // The recipient reads NIP-17 (or it is not known that they do not): never downgraded to NIP-04.
    if (nip17) {
        throw new DirectMessageRefused('no_nip44');
    }

    // Every relay answered and none has a 10050 naming a relay: NIP-04, confirmed by the sender later.
    if (nip04) {
        return {
            format: 'nip04',
            reason: 'no_dm_relays',
            read,
            complete,
            recipientRelays: readRelaysOf(newest(events, recipient, 10002)),
            ownRelays: writeRelaysOf(newest(events, sender, 10002)),
        };
    }

    // NIP-17 without a `10050` "shouldn't try" (NIP-17), and the signer has no NIP-04.
    throw new DirectMessageRefused(nip44 ? 'no_dm_relays' : 'no_encryption');
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

/**
 * One text to several recipients (invites, clan applications to the owner
 * and captains), one after the other, each on its own route: NIP-17 to their
 * DM relays, NIP-04 only for a recipient without a `10050` whom the sender
 * listed in `allowNip04`. Never throws for one recipient; each result says
 * what happened:
 *
 *   sent      at least one relay took it (`format`)
 *   unsent    signed, but no relay took it
 *   confirm   only NIP-04 reaches them: nothing sent until a later call
 *             lists them in `allowNip04` (`reason`)
 *   refused   DirectMessageRefused (`code`, e.g. `no_nip44`)
 *   error     the signer failed or declined: the run stops there
 *   pending   not tried, because the signer failed for an earlier one
 *
 * `send` is for tests.
 *
 * @param {{ sender: string, recipients: string[], content: string, signer: object, relays: string[], allowNip04?: string[], send?: Function, options?: object, onResult?: Function }} args
 * @returns {Promise<Array<{ recipient: string, status: 'sent'|'unsent'|'confirm'|'refused'|'error'|'pending', format?: string, reason?: string, code?: string, error?: unknown }>>}
 */
export async function sendDirectMessages({ sender, recipients, content, signer, relays, allowNip04 = [], send = sendDirectMessage, options = {}, onResult = null }) {
    const results = [];
    let stopped = false;

    for (const recipient of [...new Set(recipients ?? [])]) {
        let result;

        if (stopped) {
            result = { recipient, status: 'pending' };
        } else {
            try {
                const sent = await send({ sender, recipient, content, signer, relays, allowNip04: allowNip04.includes(recipient), options });
                result = { recipient, status: sent.delivered > 0 ? 'sent' : 'unsent', format: sent.format };
            } catch (error) {
                if (error instanceof DirectMessageRefused && error.code === 'confirm_nip04') {
                    result = { recipient, status: 'confirm', reason: error.reason };
                } else if (error instanceof DirectMessageRefused) {
                    result = { recipient, status: 'refused', code: error.code };
                } else {
                    // The signer declined or failed: do not ask it again for the next one.
                    result = { recipient, status: 'error', error };
                    stopped = true;
                }
            }
        }

        results.push(result);
        onResult?.(result);
    }

    return results;
}
