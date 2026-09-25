/**
 * NIP-17 wrap/unwrap of the game chat (resources/js/nostrChat.js) with
 * throwaway keys, and the PHP side's notification DM and nevent read back
 * by nostr-tools. Run by tests/Nostr/Nip17Test.php, which passes the PHP-made
 * values in NIP17_PHP (JSON); runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip19 from 'nostr-tools/nip19';
import * as nip44 from 'nostr-tools/nip44';
import { hexToBytes } from '@noble/hashes/utils.js';
import { TWO_DAYS, gameMessages, makeGroupRumor, makeRumor, roomMessages, unwrapMessage, wrapGroupMessage, wrapMessage } from '../../resources/js/nostrChat.js';

/** A NIP-07-shaped signer over a secret key, like a browser extension. */
function keySigner(secret) {
    return {
        getPublicKey: async () => getPublicKey(secret),
        signEvent: async (template) => finalizeEvent(template, secret),
        nip44: {
            encrypt: async (pubkey, text) => nip44.encrypt(text, nip44.getConversationKey(secret, pubkey)),
            decrypt: async (pubkey, payload) => nip44.decrypt(payload, nip44.getConversationKey(secret, pubkey)),
        },
    };
}

const alice = generateSecretKey();
const erin = generateSecretKey();
const bob = generateSecretKey();
const [A, E, B] = [alice, erin, bob].map((k) => getPublicKey(k));
const now = Math.floor(Date.now() / 1000);

async function codeOf(promise) {
    try {
        await promise;
    } catch (error) {
        return error.code ?? error.message;
    }

    return null;
}

test('a message wraps for the recipient and for the sender, and each opens only for its owner', async () => {
    const { rumor, toRecipient, toSelf } = await wrapMessage(keySigner(alice), { sender: A, recipient: E, content: 'gl hf', match: 42, now });

    const received = await unwrapMessage(keySigner(erin), toRecipient, E);
    const ownCopy = await unwrapMessage(keySigner(alice), toSelf, A);

    assert.deepEqual(received, rumor);
    assert.deepEqual(ownCopy, rumor);
    assert.equal(received.pubkey, A);
    assert.deepEqual(received.tags, [['p', E], ['match', '42']]);
    assert.equal(received.sig, undefined, 'the rumor stays unsigned');

    for (const wrap of [toRecipient, toSelf]) {
        assert.equal(wrap.kind, 1059);
        assert.notEqual(wrap.pubkey, A, 'the wrap key is random, never the sender');
        assert.ok(wrap.created_at <= now && wrap.created_at >= now - TWO_DAYS, 'wrap time up to two days back');
    }

    assert.equal(await codeOf(unwrapMessage(keySigner(erin), toSelf, E)), 'wrap', 'not addressed to erin');
    assert.equal(await codeOf(unwrapMessage(keySigner(bob), { ...toRecipient, tags: [['p', B]] }, B)), 'wrap', 'a re-addressed wrap fails its signature');
});

test('the sender check catches a seal that claims another author', async () => {
    // bob writes a rumor that says it is from alice, and seals it with his own key.
    const forged = makeRumor({ sender: A, recipient: E, content: 'send me your sats', match: 42, now });
    const seal = await keySigner(bob).signEvent({ kind: 13, created_at: now, tags: [], content: await keySigner(bob).nip44.encrypt(E, JSON.stringify(forged)) });
    const wrapKey = generateSecretKey();
    const wrap = finalizeEvent({ kind: 1059, created_at: now, tags: [['p', E]], content: nip44.encrypt(JSON.stringify(seal), nip44.getConversationKey(wrapKey, E)) }, wrapKey);

    assert.equal(await codeOf(unwrapMessage(keySigner(erin), wrap, E)), 'sender_mismatch');

    // A rumor whose content no longer matches its id is refused too.
    const doctored = { ...makeRumor({ sender: B, recipient: E, content: 'hi', match: 1, now }), content: 'bye' };
    const seal2 = await keySigner(bob).signEvent({ kind: 13, created_at: now, tags: [], content: await keySigner(bob).nip44.encrypt(E, JSON.stringify(doctored)) });
    const wrap2 = finalizeEvent({ kind: 1059, created_at: now, tags: [['p', E]], content: nip44.encrypt(JSON.stringify(seal2), nip44.getConversationKey(wrapKey, E)) }, wrapKey);

    assert.equal(await codeOf(unwrapMessage(keySigner(erin), wrap2, E)), 'rumor');
});

test('a game chat shows its own two players only, once each, and hides muted senders', () => {
    const hello = makeRumor({ sender: A, recipient: E, content: 'hello', match: 7, now: now - 5 });
    const reply = makeRumor({ sender: E, recipient: A, content: 'hi', match: 7, now: now - 3 });
    const otherGame = makeRumor({ sender: A, recipient: E, content: 'wrong board', match: 8, now });
    const stranger = makeRumor({ sender: B, recipient: E, content: 'spam', match: 7, now });

    const shown = (muted) => gameMessages([reply, hello, hello, otherGame, stranger], { me: E, opponent: A, match: 7, muted }).map((r) => r.content);

    assert.deepEqual(shown([]), ['hello', 'hi']);
    assert.deepEqual(shown([A]), ['hi'], 'muting alice hides her messages, not mine');
});

test('a match room message reaches every member once, and the room shows members only, for its match', async () => {
    const carol = generateSecretKey();
    const C = getPublicKey(carol);
    const members = [A, E, C];
    const { rumor, wraps } = await wrapGroupMessage(keySigner(alice), { sender: A, recipients: members, content: 'lobby is up', match: 402, now });

    assert.deepEqual(rumor.tags, [['p', E], ['p', C], ['match', '402']], 'p for every other member, never the sender');
    assert.equal(wraps.length, 3, 'one wrap per member plus the own copy');
    assert.deepEqual(wraps.map((w) => w.tags[0][1]).sort(), [...members].sort());

    for (const [key, pub] of [[erin, E], [carol, C], [alice, A]]) {
        const wrap = wraps.find((w) => w.tags[0][1] === pub);
        assert.deepEqual(await unwrapMessage(keySigner(key), wrap, pub), rumor);
    }

    const intruder = makeGroupRumor({ sender: B, recipients: [E, C], content: 'join my lobby', match: 402, now });
    const pulledIn = makeGroupRumor({ sender: A, recipients: [E, B], content: 'wrong room', match: 402, now });
    const otherMatch = makeGroupRumor({ sender: C, recipients: [A, E], content: 'gg', match: 403, now });
    const reply = makeGroupRumor({ sender: C, recipients: [A, E], content: 'joining', match: 402, now: now + 1 });
    const shown = (muted) => roomMessages([reply, rumor, rumor, intruder, pulledIn, otherMatch], { me: E, members, match: 402, muted }).map((r) => r.content);

    assert.deepEqual(shown([]), ['lobby is up', 'joining']);
    assert.deepEqual(shown([C]), ['lobby is up'], 'muting carol hides her messages only');
});

const php = process.env.NIP17_PHP ? JSON.parse(process.env.NIP17_PHP) : null;

test('a notification DM made in PHP opens in the browser module, from the notification key', { skip: php === null }, async () => {
    const rumor = await unwrapMessage(keySigner(hexToBytes(php.recipientSecret)), php.wrap, php.recipient);

    assert.equal(rumor.pubkey, php.notificationPubkey);
    assert.equal(rumor.content, php.text);
    assert.deepEqual(rumor.tags, [['p', php.recipient], ['match', String(php.match)]]);
});

test('an nevent made in PHP decodes to the same id, author and kind', { skip: php === null }, () => {
    const decoded = nip19.decode(php.nevent);

    assert.equal(decoded.type, 'nevent');
    assert.deepEqual({ id: decoded.data.id, author: decoded.data.author, kind: decoded.data.kind }, php.neventParts);
});
