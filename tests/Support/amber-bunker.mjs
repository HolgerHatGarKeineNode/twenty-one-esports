/*
 * An emulated Amber NIP-46 bunker for the browser tests, never part of the app.
 *
 *   BUNKER_KEY="<hex>" BUNKER_SECRET="<hex>" BUNKER_RELAY=ws://127.0.0.1:<port> BUNKER_LOG=<file> node tests/Support/amber-bunker.mjs
 *
 * What it copies from Amber's "create a bunker" flow:
 * - the bunker:// URI carries a ONE-TIME secret: the first `connect` with it
 *   authorises that client key, and the secret is spent;
 * - a `connect` with the spent secret from any other client key is refused
 *   ("invalid secret"), so a page that re-pairs instead of reusing its client
 *   key fails exactly as it does with a real Amber bunker;
 * - every later request from an authorised client key is served without
 *   asking again (auto-approve): get_public_key, sign_event, nip44_*, nip04_*;
 * - a request from any other client key is answered "unauthorized".
 * The remote-signer key is the user's key, as with Amber.
 *
 * SIGUSR2 revokes every authorised client (the user removed the app in Amber).
 * The relay may go away and come back: the bunker reconnects and resubscribes.
 *
 * BUNKER_LOG gets one JSON line per request: method, client pubkey, outcome.
 * No key, secret or event content is ever written there or to stdout.
 * Stdout prints "ready" once the first subscription reached EOSE.
 */

import { appendFileSync } from 'node:fs';
import { finalizeEvent, getPublicKey } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';
import * as nip04 from 'nostr-tools/nip04';
import { hexToBytes } from 'nostr-tools/utils';

const secretKey = hexToBytes(process.env.BUNKER_KEY ?? '');
const oneTimeSecret = process.env.BUNKER_SECRET ?? '';
const relayUrl = process.env.BUNKER_RELAY ?? '';
const logFile = process.env.BUNKER_LOG ?? '';
const pubkey = getPublicKey(secretKey);

const authorised = new Set();
let secretSpent = false;
let announced = false;
const seen = new Set();

process.on('SIGUSR2', () => {
    authorised.clear();
    log({ method: 'revoke-all', client: null, ok: true });
});

function log(entry) {
    if (logFile) {
        appendFileSync(logFile, JSON.stringify({ at: Date.now(), ...entry }) + '\n');
    }
}

async function decrypt(sender, content) {
    try {
        return { text: nip44.v2.decrypt(content, nip44.v2.utils.getConversationKey(secretKey, sender)), scheme: 'nip44' };
    } catch {
        return { text: await nip04.decrypt(secretKey, sender, content), scheme: 'nip04' };
    }
}

async function encrypt(recipient, text, scheme) {
    return scheme === 'nip04'
        ? nip04.encrypt(secretKey, recipient, text)
        : nip44.v2.encrypt(text, nip44.v2.utils.getConversationKey(secretKey, recipient));
}

function answer(method, sender, params) {
    if (method === 'connect') {
        if (authorised.has(sender)) {
            return { result: 'ack' };
        }
        if (!secretSpent && oneTimeSecret !== '' && params?.[1] === oneTimeSecret) {
            secretSpent = true;
            authorised.add(sender);

            return { result: 'ack' };
        }

        return { error: 'invalid secret' };
    }

    if (!authorised.has(sender)) {
        return { error: 'unauthorized' };
    }

    switch (method) {
        case 'get_public_key':
            return { result: pubkey };
        case 'sign_event': {
            const draft = JSON.parse(params[0]);

            return { result: JSON.stringify(finalizeEvent({ kind: draft.kind, created_at: draft.created_at, tags: draft.tags ?? [], content: draft.content ?? '' }, secretKey)) };
        }
        case 'nip44_encrypt':
            return { result: nip44.v2.encrypt(params[1], nip44.v2.utils.getConversationKey(secretKey, params[0])) };
        case 'nip44_decrypt':
            return { result: nip44.v2.decrypt(params[1], nip44.v2.utils.getConversationKey(secretKey, params[0])) };
        case 'ping':
            return { result: 'pong' };
        default:
            return { error: 'unsupported method ' + method };
    }
}

function run() {
    const socket = new WebSocket(relayUrl);

    socket.addEventListener('open', () => {
        socket.send(JSON.stringify(['REQ', 'bunker', { kinds: [24133], '#p': [pubkey], since: Math.floor(Date.now() / 1000) - 10 }]));
    });

    socket.addEventListener('message', async (message) => {
        let frame;
        try {
            frame = JSON.parse(String(message.data));
        } catch {
            return;
        }

        if (frame[0] === 'EOSE' && !announced) {
            announced = true;
            process.stdout.write('ready\n');

            return;
        }

        if (frame[0] !== 'EVENT' || frame[2]?.kind !== 24133 || seen.has(frame[2].id)) {
            return;
        }
        const event = frame[2];
        seen.add(event.id);

        let request;
        let scheme;
        try {
            const decrypted = await decrypt(event.pubkey, event.content);
            scheme = decrypted.scheme;
            request = JSON.parse(decrypted.text);
        } catch {
            return;
        }

        let reply;
        try {
            reply = answer(request.method, event.pubkey, request.params);
        } catch (error) {
            reply = { error: String(error?.message ?? error) };
        }
        log({ method: request.method, client: event.pubkey, ok: reply.error === undefined, error: reply.error ?? null });

        const response = finalizeEvent({
            kind: 24133,
            created_at: Math.floor(Date.now() / 1000),
            tags: [['p', event.pubkey]],
            content: await encrypt(event.pubkey, JSON.stringify({ id: request.id, ...reply }), scheme),
        }, secretKey);
        socket.send(JSON.stringify(['EVENT', response]));
    });

    socket.addEventListener('close', () => setTimeout(run, 200));
    socket.addEventListener('error', () => {});
}

run();
