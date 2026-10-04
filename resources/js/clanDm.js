/**
 * Clan application DMs (plan "Clan-Bewerbungen", P3/P4): after a player
 * applies, their own signer sends the owner and every captain a short text
 * (one per captain, in that captain's language, made by the league:
 * ClanJoinRequests::applicationDms()); a captain who declines with a reply
 * sends it to the applicant the same way.
 *
 * Every text goes through the shared helper (directMessage.js,
 * sendDirectMessages()), so its rules apply: NIP-17, gift-wrapped, to the
 * recipient's DM inbox relays (kind 10050); NIP-04 only for a recipient
 * without one and only once the sender said yes (`confirm` until a later
 * call lists them in `allowNip04`); never a downgrade for a signer without
 * NIP-44 (`refused`, `no_nip44`). The helper takes one text for all its
 * recipients, so this calls it once per recipient; a signer that fails or
 * declines stops the run (the rest stays `pending`).
 *
 * No DOM or Alpine import: runs in Node with fake relays and signers.
 */
import { sendDirectMessages } from './directMessage.js';

/**
 * @param {{ sender: string, messages: Array<{ pubkey: string, content: string }>, signer: object, relays: string[], allowNip04?: string[], send?: Function, options?: object }} args
 * @returns {Promise<Array<{ recipient: string, status: 'sent'|'unsent'|'confirm'|'refused'|'error'|'pending', format?: string, reason?: string, code?: string, error?: unknown }>>}
 */
export async function sendClanDms({ sender, messages, signer, relays, allowNip04 = [], send = undefined, options = {} }) {
    const results = [];
    let stopped = false;

    for (const { pubkey, content } of messages ?? []) {
        if (stopped) {
            results.push({ recipient: pubkey, status: 'pending' });
            continue;
        }

        const [result] = await sendDirectMessages({ sender, recipients: [pubkey], content, signer, relays, allowNip04, options, ...(send ? { send } : {}) });
        results.push(result);
        stopped = result.status === 'error' || result.status === 'pending';
    }

    return results;
}
