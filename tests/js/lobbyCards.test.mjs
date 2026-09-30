/**
 * Lobby and account cards of a casual 1v1 room (resources/js/lobbyCards.js,
 * NIP "Lobby and account cards"): build, parse, validation, the newest card
 * wins, the NIP-40 expiration, what the local cache keeps, and a round trip
 * through the NIP-17 seal and wrap (resources/js/nostrChat.js) with
 * throwaway keys. Run by tests/Feature/Series/CasualLobbyCardsTest.php;
 * runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';
import {
    ACCOUNT_CARDS, HOST_CARD, MAX_FUTURE, accountTags, cacheEntry, cardContent, casualExpiration, expirationOf, isExpired, lobbyTags, openCardIds, parseCard, randomPassword, validValue,
} from '../../resources/js/lobbyCards.js';
import { unwrapMessage, wrapGroupMessage } from '../../resources/js/nostrChat.js';

const NOW = 1_790_000_000;

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

const rumor = (tags, extra = {}) => ({ id: 'a'.repeat(64), pubkey: 'b'.repeat(64), created_at: NOW, kind: 14, tags: [['p', 'c'.repeat(64)], ['match', '1234'], ...tags], content: 'x', ...extra });

test('cards are built from the fields, and a withdrawal carries the marker alone', () => {
    assert.deepEqual(lobbyTags({ game: 'rocket-league', name: 'sats4you', password: 'k7m2q9' }), [['lobby', 'rocket-league'], ['lobby-name', 'sats4you'], ['lobby-password', 'k7m2q9']]);
    assert.deepEqual(lobbyTags({ game: 'rocket-league' }), [['lobby', 'rocket-league']]);
    assert.deepEqual(accountTags({ service: 'ea', id: 'Satoshi_21' }), [['account', 'ea'], ['account-id', 'Satoshi_21']]);
    assert.deepEqual(accountTags({ service: 'ea' }), [['account', 'ea']]);

    // Nothing the parser would refuse is ever built.
    assert.throws(() => lobbyTags({ game: 'rocket-league', name: 'only a name' }), /card_value/);
    assert.throws(() => lobbyTags({ game: 'rocket-league', name: 'x'.repeat(65), password: 'p' }), /card_value/);
    assert.throws(() => lobbyTags({ game: 'ea-sports-fc-26', name: 'n', password: 'p' }), /card_game/);
    assert.throws(() => accountTags({ service: 'battlenet', id: 'x' }), /card_service/);
    assert.throws(() => accountTags({ service: 'ea', id: 'two\nlines' }), /card_value/);
});

test('the fallback content is the NIP\'s plain English, from the tags', () => {
    const lobby = parseCard(rumor(lobbyTags({ game: 'rocket-league', name: 'sats4you', password: 'k7m2q9' })), NOW);
    const account = parseCard(rumor(accountTags({ service: 'ea', id: 'Satoshi_21' })), NOW);

    assert.equal(cardContent(lobby, 1234), 'Rocket League private match\nName: sats4you\nPassword: k7m2q9\n(lobby card for match 1234)');
    assert.equal(cardContent(account, 1234), 'EA ID: Satoshi_21\n(add me as a friend for match 1234)');
    assert.equal(cardContent(parseCard(rumor([['lobby', 'rocket-league']]), NOW), 1234), 'Lobby closed (match 1234)');
    assert.equal(cardContent(parseCard(rumor([['account', 'ea']]), NOW), 1234), 'EA ID withdrawn (match 1234)');
});

test('an Age of Empires II lobby card carries the league\'s lobby rules as one line of its text, a closed one does not', () => {
    const rules = 'League rules: map Arabia, any civilisation, spectators delayed by 2 minutes.';
    const lobby = parseCard(rumor(lobbyTags({ game: 'age-of-empires-2', name: 'e21-7', password: 'k7m2q9' })), NOW);
    const closed = parseCard(rumor([['lobby', 'age-of-empires-2']]), NOW);

    assert.equal(cardContent(lobby, 7, rules), `Age of Empires II private match\nName: e21-7\nPassword: k7m2q9\n${rules}\n(lobby card for match 7)`);
    assert.equal(cardContent(lobby, 7), 'Age of Empires II private match\nName: e21-7\nPassword: k7m2q9\n(lobby card for match 7)');
    assert.equal(cardContent(closed, 7, rules), 'Lobby closed (match 7)');
});

test('a card is read from its tags only; anything off the rules is a plain message', () => {
    assert.equal(parseCard(rumor([])), null, 'no marker: a text message');
    assert.deepEqual(parseCard(rumor([['lobby', 'rocket-league'], ['lobby-name', 'n'], ['lobby-password', 'p']], { content: 'Name: evil' }), NOW),
        { kind: 'lobby', game: 'rocket-league', key: 'lobby:rocket-league', closed: false, name: 'n', password: 'p' });
    assert.deepEqual(parseCard(rumor([['account', 'ea']]), NOW), { kind: 'account', service: 'ea', key: 'account:ea', closed: true });

    const invalid = {
        'two markers': [['lobby', 'rocket-league'], ['account', 'ea'], ['account-id', 'x']],
        'a marker twice': [['account', 'ea'], ['account', 'ea'], ['account-id', 'x']],
        'a data tag twice': [['account', 'ea'], ['account-id', 'x'], ['account-id', 'y']],
        'a data tag of the other card': [['account', 'ea'], ['account-id', 'x'], ['lobby-password', 'p']],
        'a name without password': [['lobby', 'rocket-league'], ['lobby-name', 'n']],
        'an unknown game': [['lobby', 'ea-sports-fc-26'], ['lobby-name', 'n'], ['lobby-password', 'p']],
        'an unknown service': [['account', 'battlenet'], ['account-id', 'x']],
        'an empty value': [['account', 'ea'], ['account-id', '']],
        'a control character': [['account', 'ea'], ['account-id', 'a\u0007b']],
        'a line separator': [['account', 'ea'], ['account-id', 'a\u2028b']],
        '65 characters': [['account', 'ea'], ['account-id', 'x'.repeat(65)]],
    };

    for (const [name, tags] of Object.entries(invalid)) {
        assert.deepEqual(parseCard(rumor(tags), NOW), { invalid: true }, name);
    }
});

test('values count code points: 64 emoji pass, 65 do not', () => {
    assert.equal(validValue('⚽'.repeat(64)), true);
    assert.equal(validValue('⚽'.repeat(65)), false);
    assert.equal(validValue('x'.repeat(64)), true);
    assert.equal(validValue(42), false);
});

test('a card dated more than 10 minutes ahead of the reader is a plain message', () => {
    const tags = accountTags({ service: 'ea', id: 'x' });

    assert.equal(parseCard(rumor(tags, { created_at: NOW + MAX_FUTURE }), NOW).invalid, undefined);
    assert.deepEqual(parseCard(rumor(tags, { created_at: NOW + MAX_FUTURE + 1 }), NOW), { invalid: true });
});

test('the newest card wins per author and marker, the lowest id on a tie', () => {
    const entry = (pubkey, id, createdAt, tags) => {
        const r = rumor(tags, { pubkey, id, created_at: createdAt });

        return { rumor: r, card: parseCard(r, NOW + 3600) };
    };
    const lobby = lobbyTags({ game: 'rocket-league', name: 'n', password: 'p' });
    const ea = accountTags({ service: 'ea', id: 'x' });
    const host = 'h'.repeat(64);
    const guest = 'g'.repeat(64);

    const entries = [
        entry(host, '05', NOW, lobby),
        entry(host, '04', NOW + 10, lobby),
        entry(host, '03', NOW + 10, lobby), // same second: the lower id wins
        entry(host, '02', NOW, ea), // another marker: its own slot
        entry(guest, '01', NOW, ea), // another author: its own slot
    ];

    assert.deepEqual([...openCardIds(entries)].sort(), ['01', '02', '03']);

    // A withdrawal supersedes like any card.
    const closed = entry(host, '06', NOW + 20, [['lobby', 'rocket-league']]);
    assert.deepEqual([...openCardIds([...entries, closed])].sort(), ['01', '02', '06']);
});

test('the expiration is the next 00:00 UTC at or after max(A + D, now) + 7 days', () => {
    const at = (iso) => Date.parse(iso) / 1000;

    assert.equal(casualExpiration(at('2026-09-28T01:45:00Z'), at('2026-09-28T00:00:00Z')), at('2026-10-06T00:00:00Z'));
    // Already on a day boundary: that day.
    assert.equal(casualExpiration(at('2026-09-28T00:00:00Z'), at('2026-09-27T12:00:00Z')), at('2026-10-05T00:00:00Z'));
    // A message after the regular end (a dispute) still lives at least 7 days.
    assert.equal(casualExpiration(at('2026-09-28T01:45:00Z'), at('2026-09-30T23:59:59Z')), at('2026-10-08T00:00:00Z'));
    // One second past midnight rounds to the next day.
    assert.equal(casualExpiration(at('2026-09-28T00:00:01Z'), 0), at('2026-10-06T00:00:00Z'));
});

test('an expired message is expired; a missing or malformed expiration never is', () => {
    assert.equal(isExpired({ tags: [['expiration', String(NOW)]] }, NOW), true);
    assert.equal(isExpired({ tags: [['expiration', String(NOW + 1)]] }, NOW), false);
    assert.equal(expirationOf({ tags: [] }), null);
    assert.equal(expirationOf({ tags: [['expiration', '-5']] }), null);
    assert.equal(expirationOf({ tags: [['expiration', '12abc']] }), null);
    assert.equal(expirationOf({ tags: [['expiration', '1e3']] }), null);
});

test('the local cache keeps text, a stub for every card, and nothing that expired', () => {
    const text = rumor([]);
    const card = rumor(lobbyTags({ game: 'rocket-league', name: 'sats4you', password: 'k7m2q9' }), { content: 'Password: k7m2q9' });
    const broken = rumor([['lobby', 'rocket-league'], ['lobby-name', 'only']], { content: 'Name: only' });
    const future = rumor(accountTags({ service: 'ea', id: 'Satoshi_21' }), { created_at: NOW + 99_999 });

    assert.equal(cacheEntry(text, NOW), text);
    assert.deepEqual(cacheEntry(card, NOW), { stub: true });
    assert.deepEqual(cacheEntry(broken, NOW), { stub: true }, 'an invalid card is a plain message, but not cached either');
    assert.deepEqual(cacheEntry(future, NOW), { stub: true });
    assert.equal(cacheEntry(rumor([['expiration', String(NOW)]]), NOW), null);
    assert.equal(cacheEntry(null, NOW), null);
    assert.equal(JSON.stringify(cacheEntry(card, NOW)).includes('k7m2q9'), false);
});

test('a card goes through seal and wrap with the same expiration on all three layers', async () => {
    const [host, guest] = [generateSecretKey(), generateSecretKey()];
    const [H, G] = [host, guest].map((k) => getPublicKey(k));
    const tags = lobbyTags({ game: 'rocket-league', name: 'sats4you', password: 'k7m2q9' });
    const expiration = casualExpiration(NOW, NOW);
    const content = cardContent(parseCard({ tags, created_at: NOW }, NOW), 1234);

    const { rumor: sent, wraps, targets } = await wrapGroupMessage(keySigner(host), { sender: H, recipients: [H, G], content, match: 1234, tags, expiration, now: NOW });

    assert.deepEqual(targets, [G, H], 'the opponent first, the copy to self last');
    assert.deepEqual(sent.tags, [['p', G], ['match', '1234'], ...tags, ['expiration', String(expiration)]]);

    for (const [i, wrap] of wraps.entries()) {
        assert.deepEqual(wrap.tags, [['p', targets[i]], ['expiration', String(expiration)]]);
        const reader = targets[i] === G ? guest : host;
        const seal = JSON.parse(nip44.decrypt(wrap.content, nip44.getConversationKey(reader, wrap.pubkey)));
        assert.deepEqual(seal.tags, [['expiration', String(expiration)]]);

        const opened = await unwrapMessage(keySigner(reader), wrap, targets[i]);
        assert.equal(opened.id, sent.id);
        assert.deepEqual(parseCard(opened, NOW), { kind: 'lobby', game: 'rocket-league', key: 'lobby:rocket-league', closed: false, name: 'sats4you', password: 'k7m2q9' });
    }

    // Without an expiration nothing changes for other rooms.
    const plain = await wrapGroupMessage(keySigner(host), { sender: H, recipients: [H, G], content: 'hi', match: 1234, now: NOW });
    assert.deepEqual(plain.rumor.tags, [['p', G], ['match', '1234']]);
    assert.deepEqual(plain.wraps[0].tags, [['p', G]]);
});

test('the suggested password is random, six characters, without look-alikes', () => {
    const passwords = new Set(Array.from({ length: 50 }, () => randomPassword()));

    assert.equal(passwords.size, 50);
    for (const password of passwords) assert.match(password, /^[a-hjkmnp-z2-9]{6}$/);

    // Bytes of 248 and above are dropped, so no character is favoured.
    let calls = 0;
    const bytes = (n) => (calls++ === 0 ? new Uint8Array(n).fill(255) : Uint8Array.from({ length: n }, (_, i) => i));
    assert.equal(randomPassword(6, bytes), 'abcdef');
});

test('Age of Empires II: the host shares a lobby card, either player may send a Steam or Xbox account card (rev. 9.16)', () => {
    const lobby = lobbyTags({ game: 'age-of-empires-2', name: 'e21-1234', password: 'k7m2q9' });
    const steam = accountTags({ service: 'steam', id: 'Saladin_21' });

    assert.deepEqual(HOST_CARD['age-of-empires-2'], { marker: 'lobby', value: 'age-of-empires-2' });
    assert.deepEqual(ACCOUNT_CARDS['age-of-empires-2'], ['steam', 'xbox']);
    assert.equal(ACCOUNT_CARDS['rocket-league'], undefined);
    assert.deepEqual(parseCard(rumor(lobby), NOW), { kind: 'lobby', game: 'age-of-empires-2', key: 'lobby:age-of-empires-2', closed: false, name: 'e21-1234', password: 'k7m2q9' });
    assert.deepEqual(parseCard(rumor(steam), NOW), { kind: 'account', service: 'steam', key: 'account:steam', closed: false, id: 'Saladin_21' });
    assert.equal(cardContent(parseCard(rumor(steam), NOW), 1234), 'Steam: Saladin_21\n(add me as a friend for match 1234)');
    assert.deepEqual(accountTags({ service: 'xbox' }), [['account', 'xbox']]);
});
