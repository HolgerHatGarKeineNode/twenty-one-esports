/**
 * Player-to-player DMs (resources/js/directMessage.js) against fake relays
 * and key-backed signers: NIP-17 to the recipient's DM relays when they have
 * a `10050` and the signer can do NIP-44, NIP-04 when they have none or the
 * signer cannot, refused when nothing fits. The recipient opens what was
 * sent with their own key. Run by tests/Nostr/DirectMessageTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip04 from 'nostr-tools/nip04';
import * as nip44 from 'nostr-tools/nip44';
import { DirectMessageRefused, sendDirectMessage } from '../../resources/js/directMessage.js';
import { unwrapMessage } from '../../resources/js/nostrChat.js';

function keySigner(secret, { with44 = true, with04 = true } = {}) {
    const signer = { getPublicKey: async () => getPublicKey(secret), signEvent: async (draft) => finalizeEvent(draft, secret) };
    if (with44) {
        signer.nip44 = {
            encrypt: async (pubkey, text) => nip44.encrypt(text, nip44.getConversationKey(secret, pubkey)),
            decrypt: async (pubkey, text) => nip44.decrypt(text, nip44.getConversationKey(secret, pubkey)),
        };
    }
    if (with04) {
        signer.nip04 = { encrypt: async (pubkey, text) => nip04.encrypt(secret, pubkey, text) };
    }

    return signer;
}

const aliceSecret = generateSecretKey();
const alice = getPublicKey(aliceSecret);
const erinSecret = generateSecretKey();
const erin = getPublicKey(erinSecret);

function signed(secret, kind, tags) {
    return finalizeEvent({ kind, tags, content: '', created_at: 1_700_000_000 }, secret);
}

/** Fake relays; every EVENT any socket gets is recorded in `sent` and answered OK true. */
function fakeSockets(relays, sent) {
    return class FakeSocket {
        constructor(url) {
            this.url = url;
            this.behaviour = relays[url] ?? { events: [], eose: true };
            queueMicrotask(() => (this.behaviour.down ? this.onerror?.({}) : this.onopen?.()));
        }

        send(text) {
            const frame = JSON.parse(text);
            const reply = (data) => queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(data) }));

            if (frame[0] === 'REQ') {
                const kinds = frame.slice(2).flatMap((filter) => filter.kinds ?? []);
                for (const event of this.behaviour.events ?? []) {
                    if (kinds.includes(event.kind)) reply(['EVENT', frame[1], event]);
                }
                if (this.behaviour.eose) reply(['EOSE', frame[1]]);
            } else if (frame[0] === 'EVENT') {
                sent.push({ url: this.url, event: frame[1] });
                reply(['OK', frame[1].id, true, '']);
            }
        }

        close() {}
    };
}

const erinDmList = signed(erinSecret, 10050, [['relay', 'ws://erin-dm']]);
const erinRelayList = signed(erinSecret, 10002, [['r', 'ws://erin-inbox', 'read'], ['r', 'ws://erin-outbox', 'write']]);
const aliceDmList = signed(aliceSecret, 10050, [['relay', 'ws://alice-dm']]);

test('recipient with a DM relay list: NIP-17 to those relays, a copy to the sender, and erin reads it', async () => {
    const sent = [];
    const WebSocketImpl = fakeSockets({ 'ws://lookup': { events: [erinDmList, erinRelayList, aliceDmList], eose: true } }, sent);

    const result = await sendDirectMessage({ sender: alice, recipient: erin, content: 'gg, rematch tomorrow?', signer: keySigner(aliceSecret), relays: ['ws://lookup'], options: { WebSocketImpl, timeoutMs: 200 } });

    assert.equal(result.format, 'nip17');
    const toErin = sent.filter((s) => s.event.tags.some((t) => t[0] === 'p' && t[1] === erin));
    const toAlice = sent.filter((s) => s.event.tags.some((t) => t[0] === 'p' && t[1] === alice));
    assert.deepEqual(toErin.map((s) => s.url).sort(), ['ws://erin-dm', 'ws://lookup']);
    assert.deepEqual(toAlice.map((s) => s.url), ['ws://alice-dm']);
    assert.ok(sent.every((s) => s.event.kind === 1059));

    const rumor = await unwrapMessage(keySigner(erinSecret), toErin[0].event, erin);
    assert.equal(rumor.pubkey, alice);
    assert.equal(rumor.content, 'gg, rematch tomorrow?');
    assert.deepEqual(rumor.tags, [['p', erin]]);
});

test('recipient without a DM relay list: NIP-04 kind 4 to their inbox, readable with their key', async () => {
    const sent = [];
    const WebSocketImpl = fakeSockets({ 'ws://lookup': { events: [erinRelayList], eose: true } }, sent);

    const result = await sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: keySigner(aliceSecret), relays: ['ws://lookup'], options: { WebSocketImpl, timeoutMs: 200 } });

    assert.equal(result.format, 'nip04');
    assert.ok(sent.every((s) => s.event.kind === 4 && s.event.pubkey === alice));
    assert.deepEqual(sent.map((s) => s.url).sort(), ['ws://erin-inbox', 'ws://lookup']);
    assert.deepEqual(sent[0].event.tags, [['p', erin]]);
    assert.equal(nip04.decrypt(erinSecret, alice, sent[0].event.content), 'hi');
});

test('a signer without NIP-44 falls back to NIP-04 even when the recipient has a DM relay list', async () => {
    const sent = [];
    const WebSocketImpl = fakeSockets({ 'ws://lookup': { events: [erinDmList, erinRelayList], eose: true } }, sent);

    const result = await sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: keySigner(aliceSecret, { with44: false }), relays: ['ws://lookup'], options: { WebSocketImpl, timeoutMs: 200 } });

    assert.equal(result.format, 'nip04');
    assert.ok(sent.length > 0 && sent.every((s) => s.event.kind === 4));
});

test('refused, with nothing sent: no encryption at all; no DM list and no NIP-04; NIP-04 but no relay answered', async () => {
    const cases = [
        [{ 'ws://lookup': { events: [erinDmList], eose: true } }, keySigner(aliceSecret, { with44: false, with04: false }), 'no_encryption'],
        [{ 'ws://lookup': { events: [erinRelayList], eose: true } }, keySigner(aliceSecret, { with04: false }), 'no_dm_relays'],
        [{ 'ws://lookup': { down: true } }, keySigner(aliceSecret, { with44: false }), 'not_read'],
    ];

    for (const [relays, signer, code] of cases) {
        const sent = [];
        await assert.rejects(
            sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer, relays: ['ws://lookup'], options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 } }),
            (error) => error instanceof DirectMessageRefused && error.code === code,
        );
        assert.deepEqual(sent, [], code);
    }
});

test('no relay answered the lookup but the signer has NIP-44: NIP-17 to the fallback relays', async () => {
    const sent = [];
    const WebSocketImpl = fakeSockets({ 'ws://lookup': { down: true }, 'ws://fallback': { events: [], eose: false } }, sent);

    const result = await sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: keySigner(aliceSecret), relays: ['ws://lookup', 'ws://fallback'], options: { WebSocketImpl, timeoutMs: 100 } });

    assert.equal(result.format, 'nip17');
    assert.ok(sent.every((s) => s.event.kind === 1059 && s.url === 'ws://fallback'));
});

test('an empty message or one to oneself is refused', async () => {
    await assert.rejects(sendDirectMessage({ sender: alice, recipient: erin, content: '   ', signer: keySigner(aliceSecret), relays: [] }), (error) => error.code === 'empty');
    await assert.rejects(sendDirectMessage({ sender: alice, recipient: alice, content: 'x', signer: keySigner(aliceSecret), relays: [] }), (error) => error.code === 'self');
});
