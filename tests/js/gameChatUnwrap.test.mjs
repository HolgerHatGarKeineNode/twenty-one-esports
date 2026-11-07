/**
 * The game chat (resources/js/gameChat.js) and a signer that fails for a moment (DerCaddy, 2026-11-07: "Your chats"
 * showed StackingJones' message, the game's chat was empty). The match room and the desk retried such a wrap since
 * 2026-10-12 (chatUnwrapRetry.test.mjs); the game chat cached it as null and never opened it again, while the
 * tournament desk on the same page opened it and "Your chats" found it in the desk's cache.
 *
 * Now the game chat retries like the room, reads what the room and the desk opened on this device, and drops its
 * null entries of before once. Run by tests/Feature/Board/GameChatUnwrapTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';

const store = new Map();
globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
globalThis.window = { addEventListener() {}, removeEventListener() {}, dispatchEvent() {} };
globalThis.document = { addEventListener() {}, querySelector: () => null, visibilityState: 'visible' };

// The DM relay lookup (dmInbox.js) opens its own sockets: a relay that answers every REQ with EOSE at once.
globalThis.WebSocket = class {
    constructor(url) {
        this.url = url;
        this.readyState = 1;
        queueMicrotask(() => this.onopen?.());
    }

    send(frame) {
        const [type, sub] = JSON.parse(frame);
        if (type === 'REQ') queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(['EOSE', sub]) }));
    }

    close() {
        this.readyState = 3;
        queueMicrotask(() => this.onclose?.());
    }
};

const { cacheKey, purgeKey } = await import('../../resources/js/chatCache.js');
const { wrapMessage } = await import('../../resources/js/nostrChat.js');
const { UNWRAP_RETRIES, gameChat } = await import('../../resources/js/gameChat.js');

// Short retries for the test; the production delays are seconds.
UNWRAP_RETRIES.splice(0, UNWRAP_RETRIES.length, 20, 20, 20);

function keySigner(secret, { failDecrypts = 0 } = {}) {
    let failures = 0;
    const signer = {
        decryptCalls: 0,
        getPublicKey: async () => getPublicKey(secret),
        signEvent: async (template) => finalizeEvent(template, secret),
        nip44: {
            encrypt: async (pubkey, text) => nip44.encrypt(text, nip44.getConversationKey(secret, pubkey)),
            decrypt: async (pubkey, payload) => {
                signer.decryptCalls++;
                if (failures < failDecrypts) {
                    failures++;
                    throw new Error('signer timeout');
                }

                return nip44.decrypt(payload, nip44.getConversationKey(secret, pubkey));
            },
        },
    };

    return signer;
}

const keys = { me: generateSecretKey(), opp: generateSecretKey() };
const pk = { me: getPublicKey(keys.me), opp: getPublicKey(keys.opp) };
const NOW = Math.floor(Date.now() / 1000);
const MATCH = 'board:77';
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const made = [];
afterEach(() => made.splice(0).forEach((comp) => comp.destroy()));

/** The game chat of board game 77 between me and opp, started with `signer`; `keep` leaves the storage as it is. */
function game(signer, { keep = false } = {}) {
    if (!keep) store.clear();
    window.nostr = signer;
    const comp = gameChat({
        me: pk.me,
        meName: 'me',
        opponent: { pubkey: pk.opp, name: 'StackingJones' },
        match: MATCH,
        since: NOW - 86400,
        settled: null,
        relays: ['wss://chat.one'],
        lookupRelays: [],
        muted: [],
        labels: new Proxy({}, { get: (_, key) => String(key) }),
    });
    comp.makePool = () => ({ subscribe: () => ({ close() {} }), publish: (relays) => relays.map(() => Promise.resolve('')), destroy() {} });
    comp.$wire = { setMuted: async () => {} };
    made.push(comp);

    return comp;
}

/** A message from opp to me in this game, as its wrap addressed to me, and the rumor inside. */
async function fromOpp(content) {
    const { rumor, toRecipient } = await wrapMessage(keySigner(keys.opp), { sender: pk.opp, recipient: pk.me, content, match: MATCH });

    return { rumor, wrap: toRecipient };
}

test('a wrap the signer could not open is not cached and shows up once the signer answers again', async () => {
    const signer = keySigner(keys.me, { failDecrypts: 1 });
    const comp = game(signer);
    comp.start();
    const { wrap } = await fromOpp('gg, das war knapp');

    await comp.receive(wrap);

    assert.equal(Object.hasOwn(comp.cache, wrap.id), false, 'a failed unwrap is not cached: ' + JSON.stringify(comp.cache));
    assert.deepEqual(comp.messages, []);

    await wait(60);

    assert.deepEqual(comp.messages.map((m) => m.text), ['gg, das war knapp']);
    assert.ok(JSON.parse(store.get(cacheKey('game', pk.me)))[wrap.id], 'the opened message is cached');
});

test('a signer that keeps failing gives up after the retries, and the wrap is still not cached as null', async () => {
    const signer = keySigner(keys.me, { failDecrypts: 100 });
    const comp = game(signer);
    comp.start();
    const { wrap } = await fromOpp('never opened here');

    await comp.receive(wrap);
    await wait(120);

    assert.equal(Object.hasOwn(comp.cache, wrap.id), false);
    assert.equal(signer.decryptCalls, 1 + UNWRAP_RETRIES.length);
});

test('destroy() cancels the retries', async () => {
    const signer = keySigner(keys.me, { failDecrypts: 100 });
    const comp = game(signer);
    comp.start();
    const { wrap } = await fromOpp('closed before the retry');

    await comp.receive(wrap);
    comp.destroy();
    await wait(80);

    assert.equal(signer.decryptCalls, 1);
});

test('a message the desk or the match room opened on this device shows in the game chat at once', async () => {
    store.clear();
    const { rumor, wrap } = await fromOpp('gg. das war wahrscheinlich');
    // The game chat failed on it before (null), the desk opened it: what "Your chats" listed.
    store.set(cacheKey('game', pk.me), JSON.stringify({ [wrap.id]: null }));
    store.set(purgeKey('game', pk.me), '1');
    store.set(cacheKey('desk', pk.me), JSON.stringify({ [wrap.id]: rumor }));

    const signer = keySigner(keys.me);
    const comp = game(signer, { keep: true });
    comp.start();

    assert.deepEqual(comp.messages.map((m) => m.text), ['gg. das war wahrscheinlich']);
    assert.equal(comp.unread, 1);
    assert.equal(signer.decryptCalls, 0, 'nothing asked of the signer');
    assert.equal(JSON.parse(store.get(cacheKey('desk', pk.me)))[wrap.id].content, 'gg. das war wahrscheinlich', 'the desk cache is left alone');
});

test('the null entries of a game cache from before are dropped once, so the relays bring the wraps again', async () => {
    store.clear();
    const { wrap } = await fromOpp('cached as null by the old game chat');
    store.set(cacheKey('game', pk.me), JSON.stringify({ [wrap.id]: null }));
    // Marked by the first purge (2026-10-12), not by the second.
    store.set('esports.chat.nullpurge.game.' + pk.me, '1');

    const signer = keySigner(keys.me);
    const comp = game(signer, { keep: true });
    comp.start();

    assert.equal(Object.hasOwn(comp.cache, wrap.id), false, 'the null is dropped');
    await comp.receive(wrap);
    assert.deepEqual(comp.messages.map((m) => m.text), ['cached as null by the old game chat']);

    // Once: a null written by this code (a wrap that is no message for me) stays null afterwards.
    const cached = JSON.parse(store.get(cacheKey('game', pk.me)));
    store.set(cacheKey('game', pk.me), JSON.stringify({ ...cached, other: null }));
    const again = game(signer, { keep: true });
    again.start();
    assert.equal(again.cache.other, null, 'the cache is not purged a second time');
    assert.ok(again.cache[wrap.id], 'the reopened message stays');
});
