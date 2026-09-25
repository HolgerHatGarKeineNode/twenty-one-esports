/**
 * NIP-17 private messages for the game chat (NIP "Chat" in docs/nips/esports.md):
 * a kind-14 rumor, sealed (kind 13) by the sender's own signer with NIP-44,
 * gift-wrapped (kind 1059) by a fresh random key. Pure functions over a
 * signer with the NIP-07 shape ({ getPublicKey, signEvent, nip44: { encrypt,
 * decrypt } }); the browser passes window.nostr (extension, or the NIP-46 /
 * Google signer the login module installs), tests pass a key-backed signer.
 *
 * The player's secret key never reaches this code: the seal is signed and
 * both layers are opened through the signer. Only the throwaway wrap key is
 * local, as NIP-59 requires. The cryptography is nostr-tools' (BIP-340,
 * NIP-44 v2); nothing here is home-made crypto.
 */
import { finalizeEvent, generateSecretKey, getEventHash, verifyEvent } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';

/** NIP-59: seal and wrap timestamps up to two days in the past. */
export const TWO_DAYS = 2 * 24 * 60 * 60;

/** NIP "Relay behaviour": a live gift-wrap subscription needs `since` at least two days back. */
export const SINCE_MARGIN = TWO_DAYS + 60 * 60;

export function canEncrypt(signer) {
    return typeof signer?.signEvent === 'function' && typeof signer?.nip44?.encrypt === 'function' && typeof signer?.nip44?.decrypt === 'function';
}

function randomPast(now) {
    return now - Math.floor(Math.random() * TWO_DAYS);
}

/**
 * The unsigned kind-14 message. `match` is the game number (NIP tag `match`),
 * so a chat never shows messages that belong to another game.
 */
export function makeRumor({ sender, recipient, content, match, now = Math.floor(Date.now() / 1000) }) {
    const rumor = {
        pubkey: sender,
        created_at: now,
        kind: 14,
        tags: [['p', recipient], ['match', String(match)]],
        content,
    };

    return { ...rumor, id: getEventHash(rumor) };
}

async function seal(signer, rumor, recipient, now) {
    const content = await signer.nip44.encrypt(recipient, JSON.stringify(rumor));

    return signer.signEvent({ kind: 13, created_at: randomPast(now), tags: [], content });
}

function giftWrap(sealed, recipient, now) {
    const key = generateSecretKey();
    const content = nip44.encrypt(JSON.stringify(sealed), nip44.getConversationKey(key, recipient));

    return finalizeEvent({ kind: 1059, created_at: randomPast(now), tags: [['p', recipient]], content }, key);
}

/**
 * One message, wrapped twice: to the recipient, and to the sender herself,
 * so her other devices show what she wrote (NIP-17).
 */
export async function wrapMessage(signer, { sender, recipient, content, match, now = Math.floor(Date.now() / 1000) }) {
    const rumor = makeRumor({ sender, recipient, content, match, now });
    const toRecipient = giftWrap(await seal(signer, rumor, recipient, now), recipient, now);
    const toSelf = giftWrap(await seal(signer, rumor, sender, now), sender, now);

    return { rumor, toRecipient, toSelf };
}

/**
 * nostr-tools' verifyEvent() caches its verdict on the event object under a
 * symbol, and an object spread copies that symbol: a changed copy of a
 * verified event would "verify" without being checked (found by
 * tests/js/nip17.test.mjs). A JSON copy carries no symbol, so it is checked.
 */
function verifiedAfresh(event) {
    return verifyEvent(JSON.parse(JSON.stringify(event)));
}

export class UnwrapError extends Error {
    constructor(code) {
        super(code);
        this.code = code;
    }
}

/**
 * Open a gift wrap addressed to `me` and return the rumor. Throws
 * UnwrapError for anything that is not a valid message, above all when the
 * seal's key is not the rumor's author: without this check anyone can write
 * as anyone (NIP-17; relay proof round 4 caught `nak gift unwrap` passing
 * such a forgery).
 */
export async function unwrapMessage(signer, wrap, me) {
    if (wrap?.kind !== 1059 || !verifiedAfresh(wrap) || !wrap.tags.some((t) => t[0] === 'p' && t[1] === me)) {
        throw new UnwrapError('wrap');
    }

    let sealed;
    try {
        sealed = JSON.parse(await signer.nip44.decrypt(wrap.pubkey, wrap.content));
    } catch {
        throw new UnwrapError('wrap_decrypt');
    }

    if (sealed?.kind !== 13 || !verifiedAfresh(sealed)) {
        throw new UnwrapError('seal');
    }

    let rumor;
    try {
        rumor = JSON.parse(await signer.nip44.decrypt(sealed.pubkey, sealed.content));
    } catch {
        throw new UnwrapError('seal_decrypt');
    }

    if (rumor?.pubkey !== sealed.pubkey) {
        throw new UnwrapError('sender_mismatch');
    }

    if (rumor.kind !== 14 || typeof rumor.content !== 'string' || !Array.isArray(rumor.tags) || getEventHash(rumor) !== rumor.id) {
        throw new UnwrapError('rumor');
    }

    return rumor;
}

/**
 * The messages of one game between two players: the right `match`, written
 * by one of the two to the other, each once (a message arrives as the
 * recipient's copy and, for the sender, as her own copy), oldest first.
 * Messages from muted pubkeys are left out (mute is only ever for oneself).
 */
export function gameMessages(rumors, { me, opponent, match, muted = [] }) {
    const seen = new Set();
    const mutedSet = new Set(muted);

    return rumors
        .filter((rumor) => {
            if (seen.has(rumor.id)) return false;
            seen.add(rumor.id);
            const recipient = rumor.tags.find((t) => t[0] === 'p')?.[1];
            const forGame = rumor.tags.some((t) => t[0] === 'match' && t[1] === String(match));
            const between = (rumor.pubkey === me && recipient === opponent) || (rumor.pubkey === opponent && recipient === me);

            return forGame && between && !mutedSet.has(rumor.pubkey);
        })
        .sort((a, b) => a.created_at - b.created_at);
}
