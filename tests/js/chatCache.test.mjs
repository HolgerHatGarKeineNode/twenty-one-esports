/**
 * The local caches of the room chat and the game chat (resources/js/chatCache.js):
 * separate keys, checked loaders, no card ever in plain text, and the
 * shared key of before P23 S2 migrated and removed. Both chats run here as
 * their Alpine objects over a Map-backed localStorage, with key-backed
 * signers and no relay (security audit of p23-s2-lobby-cards, F1 and F2).
 * Run by tests/Feature/Series/CasualLobbyCardsTest.php; runnable alone
 * with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getEventHash, getPublicKey } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';

const store = new Map();
globalThis.localStorage = {
    getItem: (k) => (store.has(k) ? store.get(k) : null),
    setItem: (k, v) => store.set(k, String(v)),
    removeItem: (k) => store.delete(k),
};
globalThis.window = globalThis;
globalThis.addEventListener ??= () => {};
globalThis.removeEventListener ??= () => {};
globalThis.document ??= { addEventListener() {}, querySelector() { return null; } };

const { cacheKey, loadCache, migrateLegacy, sanitizeEntry } = await import('../../resources/js/chatCache.js');
const { cardContent, casualExpiration, lobbyTags, parseCard } = await import('../../resources/js/lobbyCards.js');
const { unwrapMessage, wrapGroupMessage, wrapMessage } = await import('../../resources/js/nostrChat.js');
const { gameChat } = await import('../../resources/js/gameChat.js');
const { roomChat } = await import('../../resources/js/roomChat.js');

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

const hostKey = generateSecretKey();
const guestKey = generateSecretKey();
const H = getPublicKey(hostKey);
const G = getPublicKey(guestKey);
const NAME = 'sats4you';
const PASSWORD = 'k7m2q9';
const NOW = Math.floor(Date.now() / 1000);
const GAME = 77;
const MATCH = 1234;

/** The host's lobby card to the guest, as a relay delivers it (JSON round trip). */
async function cardWrap() {
    const tags = lobbyTags({ game: 'rocket-league', name: NAME, password: PASSWORD });
    const { wraps, targets } = await wrapGroupMessage(keySigner(hostKey), {
        sender: H, recipients: [H, G], content: cardContent(parseCard({ tags, created_at: NOW }, NOW), MATCH), match: MATCH, tags, expiration: casualExpiration(NOW),
    });

    return JSON.parse(JSON.stringify(wraps[targets.indexOf(G)]));
}

/** A chess chat message from the host to the guest. */
async function gameWrap(content) {
    return JSON.parse(JSON.stringify((await wrapMessage(keySigner(hostKey), { sender: H, recipient: G, content, match: GAME })).toRecipient));
}

const game = () => gameChat({ me: G, opponent: { pubkey: H, name: 'h' }, match: GAME, relays: [], labels: {}, muted: [] });
const room = () => roomChat({ me: G, members: [{ pubkey: H, name: 'h' }, { pubkey: G, name: 'g' }], match: MATCH, relays: [], labels: {}, casual: null });
const everything = () => [...store.entries()].map(([k, v]) => k + '=' + v).join('\n');

function textRumor(content, extra = {}) {
    const rumor = { pubkey: H, created_at: NOW, kind: 14, tags: [['p', G], ['match', String(GAME)]], content, ...extra };

    return { ...rumor, id: getEventHash(rumor) };
}

window.nostr = keySigner(guestKey);

test('the game chat receives a card wrap: nothing of it is stored, and the chess chat still works', async () => {
    store.clear();
    const chat = game();
    chat.start();

    await chat.receive(await cardWrap());
    await chat.receive(await gameWrap('gl hf'));

    assert.equal(everything().includes(PASSWORD), false);
    assert.equal(everything().includes(NAME), false);
    assert.deepEqual(chat.messages.map((m) => m.text), ['gl hf']);
    assert.deepEqual(Object.values(JSON.parse(store.get(cacheKey('game', G)))).map((e) => e?.content ?? e), [null, 'gl hf']);
    chat.destroy();
});

test('the room chat keeps its card as a stub under its own key, and the game chat loads beside it', async () => {
    store.clear();
    const chatRoom = room();
    chatRoom.start();
    await chatRoom.receive(await cardWrap());

    const chat = game();
    chat.start();

    assert.equal(everything().includes(PASSWORD), false);
    assert.deepEqual(Object.values(JSON.parse(store.get(cacheKey('room', G)))), [{ stub: true }]);
    assert.equal(store.has(cacheKey('game', G)), false, 'the game chat never saw it');
    assert.deepEqual(chat.messages, []);
    chat.destroy();
    chatRoom.destroy();
});

test('both loaders survive what an older writer or a stranger left: stubs, cards, malformed entries', () => {
    const card = textRumor(`Password: ${PASSWORD}`, { tags: [['p', G], ['match', String(MATCH)], ...lobbyTags({ game: 'rocket-league', name: NAME, password: PASSWORD })] });
    const text = textRumor('hello');
    const junk = {
        stub: { stub: true },
        card,
        text,
        empty: null,
        noTags: { id: 'x', pubkey: H, created_at: NOW, kind: 14, content: 'hi' },
        nullTag: { ...textRumor('boom'), tags: [null] },
        numberTag: { ...textRumor('boom'), tags: [['p', 5]] },
        string: 'nope',
        expired: textRumor('old', { tags: [['p', G], ['match', String(GAME)], ['expiration', String(NOW - 1)]] }),
    };

    for (const chat of ['game', 'room']) {
        store.clear();
        store.set(cacheKey(chat, G), JSON.stringify(junk));
        const alpine = chat === 'game' ? game() : room();

        assert.doesNotThrow(() => alpine.start(), chat);
        assert.doesNotThrow(() => alpine.messages, chat);

        const kept = JSON.parse(store.get(cacheKey(chat, G)));
        assert.deepEqual(Object.keys(kept).sort(), chat === 'room' ? ['empty', 'expired', 'stub', 'text'] : ['card', 'empty', 'expired', 'text'], chat);
        if (chat === 'game') assert.equal(kept.card, null, 'game: a card is null, never kept');
        assert.equal(kept.expired, null, `${chat}: an expired rumor is pruned to null`);
        assert.equal(JSON.stringify(kept).includes(PASSWORD), false, `${chat}: a stored card is dropped, to be opened again`);
        alpine.destroy();
    }

    assert.deepEqual(game().messages, []);
});

test('the shared cache of before is split into both keys through the same checks, and removed', () => {
    store.clear();
    const card = textRumor(`Password: ${PASSWORD}`, { tags: [['p', G], ['match', String(MATCH)], ...lobbyTags({ game: 'rocket-league', name: NAME, password: PASSWORD })] });
    const text = textRumor('hello');
    store.set('esports.chat.cache.' + G, JSON.stringify({ w1: { stub: true }, w2: card, w3: text, w4: null, w5: { tags: 'x' } }));

    const { rumors } = loadCache('game', G);

    assert.equal(store.has('esports.chat.cache.' + G), false);
    assert.deepEqual(JSON.parse(store.get(cacheKey('game', G))), { w2: null, w3: text, w4: null });
    assert.deepEqual(JSON.parse(store.get(cacheKey('room', G))), { w1: { stub: true }, w3: text, w4: null });
    assert.deepEqual(rumors, [text]);
    assert.equal(everything().includes(PASSWORD), false);

    // A key that exists already is never overwritten by the old one.
    store.set('esports.chat.cache.' + G, JSON.stringify({ w9: textRumor('late') }));
    migrateLegacy(G);
    assert.deepEqual(Object.keys(JSON.parse(store.get(cacheKey('game', G)))), ['w2', 'w3', 'w4']);
    assert.equal(store.has('esports.chat.cache.' + G), false);

    // Unreadable old storage is just removed.
    store.set('esports.chat.cache.' + G, '{not json');
    store.delete(cacheKey('room', G));
    migrateLegacy(G);
    assert.equal(store.has('esports.chat.cache.' + G), false);
    assert.equal(store.has(cacheKey('room', G)), false);
});

test('a stub is kept only in the room cache, and only in its exact shape', () => {
    assert.deepEqual(sanitizeEntry('room', { stub: true }), { stub: true });
    assert.equal(sanitizeEntry('game', { stub: true }), undefined);
    assert.equal(sanitizeEntry('room', { stub: true, content: PASSWORD }), undefined);
    assert.equal(sanitizeEntry('room', null), null);
});

test('a rumor with a malformed tag is refused when the wrap is opened, not kept to break the filters', async () => {
    const bad = { pubkey: H, created_at: NOW, kind: 14, tags: [null, ['match', String(GAME)]], content: 'boom' };
    const rumor = { ...bad, id: getEventHash({ ...bad, tags: [] }) };
    const seal = await keySigner(hostKey).signEvent({ kind: 13, created_at: NOW, tags: [], content: await keySigner(hostKey).nip44.encrypt(G, JSON.stringify(rumor)) });
    const key = generateSecretKey();
    const wrap = finalizeEvent({ kind: 1059, created_at: NOW, tags: [['p', G]], content: nip44.encrypt(JSON.stringify(seal), nip44.getConversationKey(key, G)) }, key);

    await assert.rejects(() => unwrapMessage(keySigner(guestKey), wrap, G), (error) => error.code === 'rumor');
});
