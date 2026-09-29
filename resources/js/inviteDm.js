/**
 * Invite DMs (P47): the player picks follows who do not play here yet and
 * sends each one a direct message with their personal invite link, signed
 * and encrypted by the player's own signer and sent from the browser
 * (directMessage.js: NIP-17 where it can, NIP-04 only after the player
 * agreed for that recipient). The league never sees the text.
 *
 * Two steps, and only the second one sends:
 *
 * - inviteDraft() checks what the preview shows (1 to MAX_INVITES distinct
 *   recipients, the text holds the link); no relay is asked;
 * - sendInvites() is called by the "Sign and send" click. One recipient
 *   after the other; a recipient that could only get NIP-04 is reported as
 *   `confirm` with the reason and gets nothing until a later call lists them
 *   in `allowNip04`. A signer that fails or declines stops the run: the rest
 *   stays `pending` instead of asking again and again.
 *
 * No DOM or Alpine import: tests/js/inviteDm.test.mjs runs it in Node with
 * fake relays and key-backed signers.
 */
import { DirectMessageRefused, sendDirectMessage } from './directMessage.js';

/** Recipients of one invite round at most: every one is a signing prompt (or several) in the signer. */
export const MAX_INVITES = 10;

export const MAX_TEXT = 2000;

const HEX_PUBKEY = /^[0-9a-f]{64}$/;

export class InviteRefused extends Error {
    /**
     * @param {'no_recipients'|'too_many'|'bad_recipient'|'no_link'|'too_long'} code
     */
    constructor(code) {
        super(code);
        this.name = 'InviteRefused';
        this.code = code;
    }
}

/**
 * What the preview shows and the click sends: the recipients (distinct,
 * never the sender) and the text, which must contain the link.
 *
 * @returns {{ recipients: string[], content: string }}
 */
export function inviteDraft({ sender = null, recipients, text, link }) {
    const unique = [...new Set(recipients ?? [])];

    if (unique.length === 0) throw new InviteRefused('no_recipients');
    if (unique.length > MAX_INVITES) throw new InviteRefused('too_many');
    if (unique.some((pubkey) => !HEX_PUBKEY.test(pubkey ?? '') || pubkey === sender)) throw new InviteRefused('bad_recipient');

    const content = String(text ?? '').trim();

    if (typeof link !== 'string' || link === '' || !content.includes(link)) throw new InviteRefused('no_link');
    if (content.length > MAX_TEXT) throw new InviteRefused('too_long');

    return { recipients: unique, content };
}

/**
 * Send the invite to each recipient, one after the other.
 *
 * @param {{ sender: string, recipients: string[], text: string, link: string, signer: object, relays: string[], allowNip04?: string[], send?: Function, options?: object, onResult?: Function }} args
 * @returns {Promise<Array<{ recipient: string, status: 'sent'|'unsent'|'confirm'|'refused'|'error'|'pending', format?: string, reason?: string, code?: string, error?: unknown }>>}
 */
export async function sendInvites({ sender, recipients, text, link, signer, relays, allowNip04 = [], send = sendDirectMessage, options = {}, onResult = null }) {
    const draft = inviteDraft({ sender, recipients, text, link });
    const results = [];
    let stopped = false;

    for (const recipient of draft.recipients) {
        let result;

        if (stopped) {
            result = { recipient, status: 'pending' };
        } else {
            try {
                const sent = await send({ sender, recipient, content: draft.content, signer, relays, allowNip04: allowNip04.includes(recipient), options });
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
