/**
 * Signing a server-prepared template with the player's signer (a NIP-07
 * extension, or the NIP-46 / Google signer mill installs as window.nostr), and
 * telling apart why it failed.
 *
 * Every failure is logged with console.warn together with the signer's own
 * error, so it is visible in the browser console. The player sees one of four
 * messages instead of one catch-all:
 *
 *   declined     the player said no, or closed the prompt
 *   unreachable  the signer did not answer: a remote signer that is offline,
 *                a timeout, no relay reached
 *   wrongKey     the signer holds another key than the logged-in one
 *   failed       anything else, with the signer's reason in the message
 *
 * The patterns follow the errors the signers in use actually throw: nos2x
 * ("denied"), nos2x-fox ("Insufficient permissions, required …", "PIN prompt
 * window closed"), mill's NIP-46 client ("NIP-46 sign_event timed out",
 * "Failed to publish NIP-46 request to any relay", "NIP-46 disconnected") and
 * mill's key signer ("Signing rejected: …", "Blocked by your permissions: …").
 *
 * No DOM or Alpine import here, so tests/js/signing.test.mjs runs it in Node.
 */

export const DECLINED = 'declined';
export const UNREACHABLE = 'unreachable';
export const WRONG_KEY = 'wrongKey';
export const FAILED = 'failed';

const UNREACHABLE_PATTERN = /timed out|timeout|disconnected|not connected|client closed|failed to publish|failed to fetch|network ?error|websocket|connection (closed|refused|lost)|could ?n[o']?t (reach|connect)|unreachable|offline/i;
const DECLINED_PATTERN = /denied|reject|declin|cancel|refus|insufficient permission|not (allowed|authori[sz]ed|permitted)|unauthori[sz]ed|blocked by your permissions|password required|closed/i;

const REASON_MAX = 160;

export class SignerError extends Error {
    /**
     * @param {string} kind   DECLINED | UNREACHABLE | WRONG_KEY | FAILED
     * @param {unknown} cause the signer's own error (or a description)
     */
    constructor(kind, cause) {
        super(reasonOf(cause));
        this.name = 'SignerError';
        this.kind = kind;
        this.cause = cause;
    }
}

/**
 * The signer's error as one line of text. Signers reject with an Error, a
 * plain string, or an object with a message.
 */
export function reasonOf(error) {
    if (typeof error === 'string') return error;
    const name = typeof error?.name === 'string' && error.name !== 'Error' ? error.name + ': ' : '';
    const message = typeof error?.message === 'string' ? error.message : '';
    if (name || message) return (name + message).trim();
    try {
        return JSON.stringify(error) ?? String(error);
    } catch {
        return String(error);
    }
}

/**
 * Which of the four cases a thrown error is.
 */
export function signerFailure(error) {
    if (error instanceof SignerError) return error.kind;
    const text = reasonOf(error);
    if (UNREACHABLE_PATTERN.test(text)) return UNREACHABLE;
    if (DECLINED_PATTERN.test(text)) return DECLINED;

    return FAILED;
}

/**
 * A plain copy of the template to hand to the signer. Templates held in Alpine
 * state are reactive proxies, and NIP-07 extensions (nos2x, nos2x-fox, Alby)
 * pass the draft on with window.postMessage, whose structured clone throws a
 * DataCloneError on a proxy.
 */
export function plainDraft(template, now = Date.now()) {
    return JSON.parse(JSON.stringify({
        kind: template.kind,
        created_at: Math.max(template.created_at ?? 0, Math.floor(now / 1000)),
        tags: template.tags ?? [],
        content: template.content ?? '',
    }));
}

/**
 * Sign the template and return the signed event as a plain object.
 *
 * @param {{ kind: number, created_at?: number, tags?: string[][], content?: string }} template
 * @param {{ pubkey?: string|null, signer?: object }} options  pubkey: the logged-in key the event must carry
 * @throws {SignerError}
 */
export async function signTemplate(template, { pubkey = null, signer = globalThis.window?.nostr } = {}) {
    let signed;
    try {
        if (typeof signer?.signEvent !== 'function') {
            throw new Error('No signer available');
        }
        signed = await signer.signEvent(plainDraft(template));
        if (!signed || typeof signed !== 'object' || typeof signed.sig !== 'string') {
            throw new Error('The signer returned no signed event');
        }
        // Extensions may hand back proxies (cloneInto); a JSON round trip gives a plain object.
        signed = JSON.parse(JSON.stringify(signed));
    } catch (error) {
        const kind = signerFailure(error);
        console.warn(`[signer] signing kind ${template?.kind} failed (${kind}):`, error);
        throw new SignerError(kind, error);
    }

    if (pubkey && signed.pubkey !== pubkey) {
        console.warn(`[signer] signed with ${signed.pubkey}, logged in as ${pubkey}`);
        throw new SignerError(WRONG_KEY, `signed with ${signed.pubkey}, logged in as ${pubkey}`);
    }

    return signed;
}

/**
 * The message for the player. `messages` carries rejected, unreachable,
 * wrongKey and signerFailed (":reason" is filled in); a missing key falls back
 * to `rejected`, so an older page still says something.
 */
export function signerMessage(messages, error) {
    const kind = signerFailure(error);
    const fallback = messages.rejected;

    if (kind === DECLINED) return fallback;
    if (kind === UNREACHABLE) return messages.unreachable ?? fallback;
    if (kind === WRONG_KEY) return messages.wrongKey ?? fallback;

    const cause = error instanceof SignerError ? error.cause : error;
    let reason = reasonOf(cause);
    if (reason.length > REASON_MAX) reason = reason.slice(0, REASON_MAX - 1) + '…';

    return (messages.signerFailed ?? fallback).replace(':reason', reason);
}
