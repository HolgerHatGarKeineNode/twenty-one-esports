/**
 * Player-to-player DMs (resources/js/directMessage.js) against fake relays
 * and key-backed signers: NIP-17 to the recipient's DM relays when they have
 * a `10050`, NIP-04 only when every relay answered and they have none, a
 * signer without NIP-44 refused instead of downgraded, refused when nothing
 * fits. The recipient opens what was
 * sent with their own key. Run by tests/Nostr/DirectMessageTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip04 from 'nostr-tools/nip04';
import * as nip44 from 'nostr-tools/nip44';
import { DirectMessageRefused, sendDirectMessage, sendDirectMessages } from '../../resources/js/directMessage.js';
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

    const result = await sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: keySigner(aliceSecret), relays: ['ws://lookup'], allowNip04: true, options: { WebSocketImpl, timeoutMs: 200 } });

    assert.equal(result.format, 'nip04');
    assert.ok(sent.every((s) => s.event.kind === 4 && s.event.pubkey === alice));
    assert.deepEqual(sent.map((s) => s.url).sort(), ['ws://erin-inbox', 'ws://lookup']);
    assert.deepEqual(sent[0].event.tags, [['p', erin]]);
    assert.equal(nip04.decrypt(erinSecret, alice, sent[0].event.content), 'hi');
});

test('a signer without NIP-44 is no longer downgraded to NIP-04 when the recipient has a DM relay list: nothing goes out', async () => {
    const sent = [];
    const WebSocketImpl = fakeSockets({ 'ws://lookup': { events: [erinDmList, erinRelayList], eose: true } }, sent);

    await assert.rejects(
        sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: keySigner(aliceSecret, { with44: false }), relays: ['ws://lookup'], allowNip04: true, options: { WebSocketImpl, timeoutMs: 200 } }),
        (error) => error instanceof DirectMessageRefused && error.code === 'no_nip44',
    );
    assert.deepEqual(sent, []);
});

test('refused, with nothing sent: no encryption at all; no DM list and no NIP-04; no NIP-44 and no relay answered', async () => {
    const cases = [
        [{ 'ws://lookup': { events: [erinRelayList], eose: true } }, keySigner(aliceSecret, { with44: false, with04: false }), 'no_encryption'],
        [{ 'ws://lookup': { events: [erinDmList], eose: true } }, keySigner(aliceSecret, { with44: false, with04: false }), 'no_nip44'],
        [{ 'ws://lookup': { events: [erinRelayList], eose: true } }, keySigner(aliceSecret, { with04: false }), 'no_dm_relays'],
        [{ 'ws://lookup': { down: true } }, keySigner(aliceSecret, { with44: false }), 'no_nip44'],
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

/*
 * P45 security audit F3, after the auditor's probe_dm_route.mjs: before the fix a lookup that only
 * some relays answered decided "no DM relay list" and sent NIP-04 without asking.
 */
test('audit F3: the relay holding the DM relay list is down, the other answers: NIP-17, never NIP-04', async () => {
    const sent = [];
    const WebSocketImpl = fakeSockets({ 'ws://a': { down: true }, 'ws://b': { events: [erinRelayList], eose: true } }, sent);

    const result = await sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: keySigner(aliceSecret), relays: ['ws://a', 'ws://b'], allowNip04: true, options: { WebSocketImpl, timeoutMs: 200 } });

    assert.equal(result.format, 'nip17');
    assert.ok(sent.length > 0 && sent.every((s) => s.event.kind === 1059));
});

test('audit F3: NIP-04 is never sent without the sender agreeing, and says why', async () => {
    for (const [relays, signer, reason] of [
        [{ 'ws://lookup': { events: [erinRelayList], eose: true } }, keySigner(aliceSecret), 'no_dm_relays'],
        [{ 'ws://lookup': { events: [erinRelayList], eose: true } }, keySigner(aliceSecret, { with44: false }), 'no_dm_relays'],
    ]) {
        const sent = [];
        let signed = 0;
        const counting = { ...signer, signEvent: async (draft) => { signed++; return signer.signEvent(draft); } };
        await assert.rejects(
            sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: counting, relays: ['ws://lookup'], options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 } }),
            (error) => error instanceof DirectMessageRefused && error.code === 'confirm_nip04' && error.reason === reason,
        );
        assert.equal(signed, 0, reason);
        assert.deepEqual(sent, [], reason);
    }
});

/*
 * P1 of the clan applications plan (user, 2026-10-04: "Die veraltete am besten sperren und nur
 * verwenden, wenn das Profil dafür nicht ausgelegt ist (weil INBOX-Relay fehlt)"): a recipient
 * who names DM relays (10050) never gets a kind 4, whatever the signer can, whatever the sender
 * agreed to; a signer without NIP-44 is refused instead of downgraded.
 */
test('a recipient with a DM relay list never gets a kind 4, for any signer, even with allowNip04', async () => {
    // A list whose only relay is no websocket URL still says "I read NIP-17".
    const unusable = signed(erinSecret, 10050, [['relay', 'erin-dm.example']]);
    for (const list of [erinDmList, unusable]) {
        for (const capabilities of [{}, { with44: false }, { with04: false }, { with44: false, with04: false }]) {
            const sent = [];
            const WebSocketImpl = fakeSockets({ 'ws://lookup': { events: [list, erinRelayList], eose: true } }, sent);
            try {
                await sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: keySigner(aliceSecret, capabilities), relays: ['ws://lookup'], allowNip04: true, options: { WebSocketImpl, timeoutMs: 200 } });
            } catch (error) {
                assert.ok(error instanceof DirectMessageRefused, String(error));
            }
            assert.ok(sent.every((s) => s.event.kind === 1059), JSON.stringify(capabilities) + ' ' + JSON.stringify(list.tags));
        }
    }
});

test('a signer without NIP-44 is refused with no_nip44, not downgraded: recipient with a DM relay list, or a lookup nobody answered', async () => {
    for (const relays of [{ 'ws://lookup': { events: [erinDmList, erinRelayList], eose: true } }, { 'ws://lookup': { down: true } }]) {
        const sent = [];
        let signedCount = 0;
        const signer = keySigner(aliceSecret, { with44: false });
        const counting = { ...signer, signEvent: async (draft) => { signedCount++; return signer.signEvent(draft); } };
        await assert.rejects(
            sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer: counting, relays: ['ws://lookup'], allowNip04: true, options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 } }),
            (error) => error instanceof DirectMessageRefused && error.code === 'no_nip44',
        );
        assert.equal(signedCount, 0);
        assert.deepEqual(sent, []);
    }
});

test('a signer without NIP-44 still reaches a recipient with no DM relay list by NIP-04, after the yes', async () => {
    const sent = [];
    const relays = { 'ws://lookup': { events: [erinRelayList], eose: true } };
    const signer = keySigner(aliceSecret, { with44: false });
    await assert.rejects(
        sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer, relays: ['ws://lookup'], options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 } }),
        (error) => error.code === 'confirm_nip04' && error.reason === 'no_dm_relays',
    );
    assert.deepEqual(sent, []);

    const result = await sendDirectMessage({ sender: alice, recipient: erin, content: 'hi', signer, relays: ['ws://lookup'], allowNip04: true, options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 } });
    assert.equal(result.format, 'nip04');
    assert.ok(sent.length > 0 && sent.every((s) => s.event.kind === 4));
});

test('sendDirectMessages: one text to several recipients, each on its own route', async () => {
    const finnSecret = generateSecretKey();
    const finn = getPublicKey(finnSecret);
    const finnRelayList = signed(finnSecret, 10002, [['r', 'ws://finn-inbox', 'read']]);
    const relays = { 'ws://lookup': { events: [erinDmList, erinRelayList, finnRelayList, aliceDmList], eose: true } };

    // NIP-44 signer: erin gets NIP-17, finn (no 10050) waits for a yes, then gets NIP-04.
    let sent = [];
    const seen = [];
    let results = await sendDirectMessages({ sender: alice, recipients: [erin, finn, erin], content: 'Application: chess, EU evenings', signer: keySigner(aliceSecret), relays: ['ws://lookup'], options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 }, onResult: (r) => seen.push(r.recipient) });
    assert.deepEqual(results.map((r) => [r.recipient, r.status, r.format ?? r.reason]), [[erin, 'sent', 'nip17'], [finn, 'confirm', 'no_dm_relays']]);
    assert.deepEqual(seen, [erin, finn]);
    assert.ok(sent.every((s) => s.event.kind === 1059));

    sent = [];
    results = await sendDirectMessages({ sender: alice, recipients: [finn], content: 'x', signer: keySigner(aliceSecret), relays: ['ws://lookup'], allowNip04: [finn], options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 } });
    assert.deepEqual(results.map((r) => [r.status, r.format]), [['sent', 'nip04']]);

    // Signer without NIP-44: erin is refused, never downgraded.
    sent = [];
    results = await sendDirectMessages({ sender: alice, recipients: [erin], content: 'x', signer: keySigner(aliceSecret, { with44: false }), relays: ['ws://lookup'], allowNip04: [erin], options: { WebSocketImpl: fakeSockets(relays, sent), timeoutMs: 200 } });
    assert.deepEqual(results.map((r) => [r.status, r.code]), [['refused', 'no_nip44']]);
    assert.deepEqual(sent, []);

    // A signer that fails stops the run: the rest stays pending.
    const failing = { ...keySigner(aliceSecret), signEvent: async () => { throw new Error('declined'); } };
    results = await sendDirectMessages({ sender: alice, recipients: [erin, finn], content: 'x', signer: failing, relays: ['ws://lookup'], options: { WebSocketImpl: fakeSockets(relays, []), timeoutMs: 200 } });
    assert.deepEqual(results.map((r) => r.status), ['error', 'pending']);
});
