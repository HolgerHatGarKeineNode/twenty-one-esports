/**
 * Invite DMs (resources/js/inviteDm.js) against fake relays and key-backed
 * signers: the draft for the preview sends nothing; the click sends NIP-17
 * where it can; a recipient that could only get NIP-04 gets nothing until the
 * player agreed for exactly that recipient; a failing signer stops the run.
 * Run by tests/Nostr/FollowsHereTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip04 from 'nostr-tools/nip04';
import * as nip44 from 'nostr-tools/nip44';
import { InviteRefused, MAX_INVITES, inviteDraft, sendInvites } from '../../resources/js/inviteDm.js';
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
const finnSecret = generateSecretKey();
const finn = getPublicKey(finnSecret);
const link = 'https://esports.example/i/AbCdEfGhIjKlMnOpQrStUv';
const text = 'I play on TWENTY ONE Esports. Join me: ' + link;

function signed(secret, kind, tags) {
    return finalizeEvent({ kind, tags, content: '', created_at: 1_700_000_000 }, secret);
}

/** Fake relays; every EVENT is recorded in `sent`, every socket in `opened`. */
function fakeSockets(relays, sent, opened = []) {
    return class FakeSocket {
        constructor(url) {
            this.url = url;
            opened.push(url);
            this.behaviour = relays[url] ?? { events: [], eose: true };
            queueMicrotask(() => this.onopen?.());
        }

        send(raw) {
            const frame = JSON.parse(raw);
            const reply = (data) => queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(data) }));
            if (frame[0] === 'REQ') {
                const filters = frame.slice(2);
                for (const event of this.behaviour.events ?? []) {
                    if (filters.some((filter) => (filter.kinds ?? []).includes(event.kind) && (filter.authors ?? []).includes(event.pubkey))) reply(['EVENT', frame[1], event]);
                }
                reply(['EOSE', frame[1]]);
            } else if (frame[0] === 'EVENT') {
                sent.push({ url: this.url, event: frame[1] });
                reply(['OK', frame[1].id, true, '']);
            }
        }

        close() {}
    };
}

// Erin has a DM relay list (NIP-17); Finn has none, only a NIP-65 read relay (NIP-04 after a yes).
const lookup = {
    'ws://lookup': {
        events: [
            signed(erinSecret, 10050, [['relay', 'wss://erin-dm.example']]),
            signed(finnSecret, 10002, [['r', 'ws://finn-inbox', 'read']]),
            signed(aliceSecret, 10050, [['relay', 'wss://alice-dm.example']]),
        ],
    },
};

test('the preview draft sends nothing and needs recipients, at most MAX_INVITES, and the link in the text', () => {
    const draft = inviteDraft({ sender: alice, recipients: [erin, finn, erin], text: '  ' + text + '  ', link });

    assert.deepEqual(draft.recipients, [erin, finn]);
    assert.equal(draft.content, text);

    const refused = (args, code) => assert.throws(() => inviteDraft({ sender: alice, text, link, ...args }), (error) => error instanceof InviteRefused && error.code === code);
    refused({ recipients: [] }, 'no_recipients');
    refused({ recipients: Array.from({ length: MAX_INVITES + 1 }, () => getPublicKey(generateSecretKey())) }, 'too_many');
    refused({ recipients: [alice] }, 'bad_recipient');
    refused({ recipients: ['npub1nope'] }, 'bad_recipient');
    refused({ recipients: [erin], text: 'Join me!' }, 'no_link');
    refused({ recipients: [erin], text: link + 'x'.repeat(2000) }, 'too_long');
});

test('no socket opens and nothing is published before sendInvites (the click)', async () => {
    const sent = [];
    const opened = [];
    fakeSockets(lookup, sent, opened);
    inviteDraft({ sender: alice, recipients: [erin], text, link });

    assert.deepEqual(opened, []);
    assert.deepEqual(sent, []);
});

test('the click: NIP-17 to a recipient with DM relays, NIP-04 held back for one without until the player agrees for them', async () => {
    const sent = [];
    const options = { WebSocketImpl: fakeSockets(lookup, sent), timeoutMs: 200 };
    const signer = keySigner(aliceSecret);

    const first = await sendInvites({ sender: alice, recipients: [erin, finn], text, link, signer, relays: ['ws://lookup'], options });

    assert.deepEqual(first.map((result) => [result.recipient, result.status]), [[erin, 'sent'], [finn, 'confirm']]);
    assert.equal(first[1].reason, 'no_dm_relays');
    assert.equal(sent.filter((entry) => entry.event.kind === 4).length, 0, 'no NIP-04 DM before the yes');

    // Erin reads the invite with her own key, from her DM relay.
    const wrap = sent.find((entry) => entry.url === 'wss://erin-dm.example/' && entry.event.kind === 1059);
    const rumor = await unwrapMessage(keySigner(erinSecret), wrap.event, erin);
    assert.equal(rumor.content, text);
    assert.equal(rumor.pubkey, alice);

    // A yes for Finn only: exactly one kind 4, to his read relay, and nothing new for Erin.
    const before = sent.length;
    const second = await sendInvites({ sender: alice, recipients: [finn], text, link, signer, relays: ['ws://lookup'], allowNip04: [finn], options });
    const kind4 = sent.slice(before).filter((entry) => entry.event.kind === 4);

    assert.deepEqual(second.map((result) => [result.status, result.format]), [['sent', 'nip04']]);
    assert.ok(kind4.some((entry) => entry.url === 'ws://finn-inbox'));
    assert.ok(kind4.every((entry) => entry.event.tags.some((tag) => tag[0] === 'p' && tag[1] === finn)));
    assert.equal(sent.slice(before).some((entry) => entry.event.tags.some((tag) => tag[1] === erin)), false);

    // A yes for someone else does not unlock Finn.
    sent.length = 0;
    const other = await sendInvites({ sender: alice, recipients: [finn], text, link, signer, relays: ['ws://lookup'], allowNip04: [erin], options });
    assert.equal(other[0].status, 'confirm');
    assert.equal(sent.length, 0);
});

test('a signer that declines stops the run; the rest waits instead of prompting again', async () => {
    const sent = [];
    let prompts = 0;
    const declining = { ...keySigner(aliceSecret), signEvent: async () => { prompts += 1; throw new Error('User rejected'); } };

    const results = await sendInvites({
        sender: alice, recipients: [erin, finn, getPublicKey(generateSecretKey())], text, link, signer: declining, relays: ['ws://lookup'],
        options: { WebSocketImpl: fakeSockets(lookup, sent), timeoutMs: 200 },
    });

    assert.deepEqual(results.map((result) => result.status), ['error', 'pending', 'pending']);
    assert.equal(prompts, 1);
    assert.equal(sent.length, 0);
});
