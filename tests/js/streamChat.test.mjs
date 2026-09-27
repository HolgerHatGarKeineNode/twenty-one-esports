/**
 * resources/js/streamChat.js and the pure half of resources/js/emoji.js: the
 * rules of the /live stream chat (P24). Run by tests/Nostr/StreamChatJsTest.php;
 * runnable alone with `node --test tests/js/streamChat.test.mjs`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import {
    bolt11Msats, displayRows, emojiTagsForContent, insertSorted, isStreamMessage, messageTemplate, parseZap, profileOf, sendBlocker, tokenize,
} from '../../resources/js/streamChat.js';
import { emojisFromTags, groupEmojis, searchEmojis, setAddresses } from '../../resources/js/emoji.js';

const streamKey = getPublicKey(generateSecretKey());
const ADDRESS = `30311:${streamKey}:twentyone-247`;
const OTHER = `30311:${streamKey}:another`;

test('only kind 1311 with the stream address is a message of this stream', () => {
    const base = { kind: 1311, content: 'gm', tags: [['a', ADDRESS, 'wss://r.example', 'root']] };
    assert.equal(isStreamMessage(base, ADDRESS), true);
    assert.equal(isStreamMessage({ ...base, tags: [['a', OTHER]] }, ADDRESS), false);
    assert.equal(isStreamMessage({ ...base, kind: 1 }, ADDRESS), false);
    assert.equal(isStreamMessage({ ...base, tags: [] }, ADDRESS), false);
});

test('a message splits into text, links without their closing punctuation, and emoji its own tags give an https image', () => {
    const tags = [['emoji', 'soapbox', 'https://img.example/soapbox.png'], ['emoji', 'bad', 'http://img.example/bad.png'], ['emoji', 'x-y', 'https://img.example/x.png']];
    assert.deepEqual(tokenize('gm :soapbox: see https://example.com/a?b=1. :bad: :unknown:', tags), [
        { type: 'text', value: 'gm ' },
        { type: 'emoji', value: 'soapbox', url: 'https://img.example/soapbox.png' },
        { type: 'text', value: ' see ' },
        { type: 'link', value: 'example.com/a?b=1', url: 'https://example.com/a?b=1' },
        { type: 'text', value: '. :bad: :unknown:' },
    ]);
    // Markup stays text: the page renders tokens with x-text.
    assert.deepEqual(tokenize('<img src=x onerror=alert(1)>', []), [{ type: 'text', value: '<img src=x onerror=alert(1)>' }]);
    assert.deepEqual(tokenize('javascript:alert(1)', []), [{ type: 'text', value: 'javascript:alert(1)' }]);
});

test('emoji tags come from the final text: known shortcodes only, once each, in order, https only', () => {
    const custom = [{ shortcode: 'zap', url: 'https://img.example/zap.png' }, { shortcode: 'hodl', url: 'https://img.example/hodl.gif' }, { shortcode: 'evil', url: 'javascript:alert(1)' }];
    assert.deepEqual(emojiTagsForContent(':hodl: and :zap: and :hodl: :nope: :evil:', custom), [
        ['emoji', 'hodl', 'https://img.example/hodl.gif'],
        ['emoji', 'zap', 'https://img.example/zap.png'],
    ]);
    assert.deepEqual(emojiTagsForContent('no colon here', custom), []);
    assert.deepEqual(emojiTagsForContent(':zap:', []), []);
});

test('the template is a 1311 with the stream address as root first, then the emoji tags, and no t tag', () => {
    const template = messageTemplate('  gm :zap:  #bitcoin ', { address: ADDRESS, relayHint: 'wss://r.example', custom: [{ shortcode: 'zap', url: 'https://img.example/zap.png' }], now: 1_700_000_000_000 });
    assert.deepEqual(template, {
        kind: 1311,
        created_at: 1_700_000_000,
        tags: [['a', ADDRESS, 'wss://r.example', 'root'], ['emoji', 'zap', 'https://img.example/zap.png']],
        content: 'gm :zap:  #bitcoin',
    });
});

test('sending needs text, at most 280 characters counted as a reader counts them, and 2 s since the last post', () => {
    assert.equal(sendBlocker('   '), 'empty');
    assert.equal(sendBlocker('a'.repeat(280)), null);
    assert.equal(sendBlocker('a'.repeat(281)), 'tooLong');
    // 280 emoji are 280 characters, not 560 UTF-16 units.
    assert.equal(sendBlocker('😀'.repeat(280)), null);
    assert.equal(sendBlocker('😀'.repeat(281)), 'tooLong');
    assert.equal(sendBlocker('gm', { lastSentAt: 10_000, now: 11_999 }), 'wait');
    assert.equal(sendBlocker('gm', { lastSentAt: 10_000, now: 12_000 }), null);
});

test('BOLT-11 amounts in millisatoshis for every multiplier and network', () => {
    assert.equal(bolt11Msats('lnbc210n1pjabc'), 21_000n);
    assert.equal(bolt11Msats('lnbc2u1pjabc'), 200_000n);
    assert.equal(bolt11Msats('lnbc1m1pjabc'), 100_000_000n);
    assert.equal(bolt11Msats('lnbc1pjabc'), null);
    assert.equal(bolt11Msats('lnbcrt5u1pjabc'), 500_000n);
    assert.equal(bolt11Msats('lnbc15p1pjabc'), null);
    assert.equal(bolt11Msats('lnbc10p1pjabc'), 1n);
    assert.equal(bolt11Msats('nonsense'), null);
});

function zapPair({ address = ADDRESS, amount = '21000', invoice = 'lnbc210n1pjabc', signerKey = generateSecretKey(), comment = 'great stream', requestKey = generateSecretKey(), requestTags = null } = {}) {
    const request = finalizeEvent({
        kind: 9734,
        created_at: 1_700_000_000,
        tags: requestTags ?? [['a', address], ['p', streamKey], ['relays', 'wss://r.example'], ...(amount === null ? [] : [['amount', amount]])],
        content: comment,
    }, requestKey);
    const receipt = finalizeEvent({
        kind: 9735,
        created_at: 1_700_000_010,
        tags: [['p', streamKey], ['a', address], ['bolt11', invoice], ['description', JSON.stringify(request)]],
        content: '',
    }, signerKey);

    return { request, receipt, signer: getPublicKey(signerKey) };
}

test('a zap counts when a known LNURL server signed it, its request is valid and for this stream, and the amounts agree', () => {
    const { request, receipt, signer } = zapPair();
    assert.deepEqual(parseZap(receipt, { address: ADDRESS, signers: [signer] }), {
        id: receipt.id, pubkey: request.pubkey, created_at: receipt.created_at, sats: 21, comment: 'great stream',
    });

    // Anyone can publish a 9735: an unknown signer is dropped.
    assert.equal(parseZap(receipt, { address: ADDRESS, signers: [] }), null);
    // Another stream's zap.
    const other = zapPair({ address: OTHER });
    assert.equal(parseZap(other.receipt, { address: ADDRESS, signers: [other.signer] }), null);
    // The invoice says 21 sats, the request asked for 2100.
    const cheat = zapPair({ amount: '2100000' });
    assert.equal(parseZap(cheat.receipt, { address: ADDRESS, signers: [cheat.signer] }), null);
    // Without an amount in the request, the invoice decides.
    const noAmount = zapPair({ amount: null, invoice: 'lnbc5u1pjabc' });
    assert.equal(parseZap(noAmount.receipt, { address: ADDRESS, signers: [noAmount.signer] }).sats, 500);
});

test('a zap whose request was tampered with or has no valid signature is dropped', () => {
    const { request, signer } = zapPair();
    const signerKey = generateSecretKey();
    const forged = { ...request, content: 'I paid a million' };
    const receipt = finalizeEvent({
        kind: 9735, created_at: 1_700_000_010, content: '',
        tags: [['a', ADDRESS], ['bolt11', 'lnbc210n1pjabc'], ['description', JSON.stringify(forged)]],
    }, signerKey);
    assert.equal(parseZap(receipt, { address: ADDRESS, signers: [getPublicKey(signerKey)] }), null);
    assert.ok(signer);

    const junk = finalizeEvent({ kind: 9735, created_at: 1, content: '', tags: [['a', ADDRESS], ['bolt11', 'lnbc210n1pjabc'], ['description', 'not json']] }, signerKey);
    assert.equal(parseZap(junk, { address: ADDRESS, signers: [getPublicKey(signerKey)] }), null);
});

test('the list stays in time order, each id once, capped at the oldest end', () => {
    const list = [];
    assert.equal(insertSorted(list, { id: 'b', created_at: 20 }), true);
    assert.equal(insertSorted(list, { id: 'a', created_at: 10 }), true);
    assert.equal(insertSorted(list, { id: 'c', created_at: 20 }), true);
    assert.equal(insertSorted(list, { id: 'b', created_at: 20 }), false);
    assert.deepEqual(list.map((item) => item.id), ['a', 'b', 'c']);
    insertSorted(list, { id: 'd', created_at: 30 }, 3);
    assert.deepEqual(list.map((item) => item.id), ['b', 'c', 'd']);
});

test('a run of muted messages folds into one row, and opens on request', () => {
    const items = [
        { type: 'message', id: '1', pubkey: 'a' },
        { type: 'message', id: '2', pubkey: 'm' },
        { type: 'zap', id: '3', pubkey: 'n' },
        { type: 'message', id: '4', pubkey: 'b' },
        { type: 'message', id: '5', pubkey: 'm' },
    ];
    const rows = displayRows(items, { muted: ['m', 'n'] });
    assert.deepEqual(rows.map((row) => [row.type, row.ids ?? row.key]), [['message', '1'], ['muted', ['2', '3']], ['message', '4'], ['muted', ['5']]]);

    const open = displayRows(items, { muted: ['m', 'n'], revealed: ['2'] });
    assert.deepEqual(open.map((row) => row.type + (row.revealed ? '+' : '')), ['message', 'muted', 'message+', 'zap+', 'message', 'muted']);
});

test('a profile gives a name, an https picture and the bot flag, nothing else', () => {
    assert.deepEqual(profileOf({ content: JSON.stringify({ display_name: ' Anna ', name: 'anna', picture: 'http://x/a.png', bot: true }), created_at: 5 }), { name: 'Anna', picture: null, bot: true, at: 5 });
    assert.equal(profileOf({ content: 'not json' }), null);
    assert.equal(profileOf({ content: JSON.stringify({ name: 'x'.repeat(80) }) }).name.length, 48);
});

test('emoji: emojibase groups without skin tones, NIP-30 tags and set addresses, search over both', () => {
    const groups = groupEmojis(
        [
            { group: 0, order: 2, label: 'grinning face', unicode: '😀', tags: ['smile'] },
            { group: 0, order: 1, label: 'rocket face', unicode: '🙂', tags: [] },
            { group: 2, order: 1, label: 'light skin tone', unicode: '🏻' },
            { group: 8, order: 1, label: 'no unicode' },
        ],
        { groups: [{ key: 'smileys-emotion', message: 'smileys', order: 0 }, { key: 'component', message: 'components', order: 2 }, { key: 'flags', message: 'flags', order: 8 }] },
    );
    assert.deepEqual(groups.map((group) => [group.key, group.icon, group.emojis.map((e) => e.u)]), [['smileys-emotion', '🙂', ['🙂', '😀']]]);

    assert.deepEqual(emojisFromTags([['emoji', 'ok', 'https://a/ok.png'], ['emoji', 'no', 'http://a/no.png'], ['emoji', 'bad name', 'https://a/b.png'], ['p', 'x']]), [{ shortcode: 'ok', url: 'https://a/ok.png' }]);
    assert.deepEqual(setAddresses({ tags: [['a', `30030:${streamKey}:party:time`], ['a', '30030:nothex:x'], ['a', `30023:${streamKey}:x`]] }), [{ author: streamKey, d: 'party:time' }]);

    const hits = searchEmojis('ro', groups, [{ shortcode: 'rocket', url: 'https://a/r.png' }]);
    assert.deepEqual(hits.map((hit) => hit.custom ? ':' + hit.shortcode + ':' : hit.u), [':rocket:', '🙂']);
    assert.deepEqual(searchEmojis('smile', groups, []).map((hit) => hit.u), ['😀']);
    assert.deepEqual(searchEmojis('  ', groups, []), []);
});
