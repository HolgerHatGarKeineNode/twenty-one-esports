/**
 * Following a player (resources/js/follow.js) against fake relays and a
 * key-backed signer: a list that cannot be read is never replaced, every
 * existing follow survives, the newest list counts, and the signed event is
 * checked before it goes out. Run by tests/Nostr/FollowTest.php; runnable
 * alone with `node --test tests/js/follow.test.mjs`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import { FollowRefused, checkSigned, follow, followPreview, followTemplate, readFollowList } from '../../resources/js/follow.js';

const secret = generateSecretKey();
const me = getPublicKey(secret);
const target = getPublicKey(generateSecretKey());
const signer = { signEvent: async (draft) => finalizeEvent(draft, secret) };
const others = Array.from({ length: 380 }, () => getPublicKey(generateSecretKey()));

function sign(kind, tags, createdAt, content = '') {
    return finalizeEvent({ kind, tags, content, created_at: createdAt }, secret);
}

/**
 * Fake relays: `relays[url]` = { events, eose } | { down: true }. Every EVENT
 * frame any socket receives is recorded in `sent`, and answered OK true.
 */
function fakeSockets(relays, sent = []) {
    return class FakeSocket {
        constructor(url) {
            this.url = url;
            this.behaviour = relays[url] ?? { down: true };
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

const relayList = sign(10002, [['r', 'ws://write-a'], ['r', 'ws://write-b', 'write'], ['r', 'ws://read-only', 'read']], 1_700_000_000);
const bigList = sign(3, [...others.map((p) => ['p', p]), ['t', 'kept-as-is'], ['p', others[0], 'wss://hint', 'petname']], 1_700_000_100, '{"wss://legacy":{"read":true,"write":true}}');

test('a follow list that one write relay could not deliver is refused, and nothing is signed or sent', async () => {
    const sent = [];
    let signed = 0;
    const WebSocketImpl = fakeSockets({
        'ws://config': { events: [relayList], eose: true },
        'ws://write-a': { events: [bigList], eose: true },
        'ws://write-b': { down: true },
    }, sent);
    const options = { WebSocketImpl, timeoutMs: 200 };

    const read = await readFollowList(me, ['ws://config'], options);
    assert.equal(read.read, false);
    assert.equal(read.answered, 1);
    assert.equal(read.asked, 2);
    assert.throws(() => followTemplate(read, target, { me }), (error) => error instanceof FollowRefused && error.code === 'not_read');

    await assert.rejects(
        follow({ me, target, relays: ['ws://config'], signer: { signEvent: async (d) => { signed++; return finalizeEvent(d, secret); } }, options }),
        (error) => error.code === 'not_read',
    );
    assert.equal(signed, 0);
    assert.deepEqual(sent, []);
});

test('no configured relay answering the relay-list lookup is a failed read too', async () => {
    const read = await readFollowList(me, ['ws://down-1', 'ws://down-2'], { WebSocketImpl: fakeSockets({}), timeoutMs: 200 });
    assert.equal(read.read, false);
    assert.throws(() => followTemplate(read, target, { me }), (error) => error.code === 'not_read');
});

test('every existing follow, tag and the content survive; one p is added and the list is newer', async () => {
    const sent = [];
    const WebSocketImpl = fakeSockets({
        'ws://config': { events: [relayList], eose: true },
        'ws://write-a': { events: [bigList], eose: true },
        'ws://write-b': { events: [bigList], eose: true },
    }, sent);

    const result = await follow({ me, target, relays: ['ws://config'], signer, now: 1_700_000_000, expectBefore: 380, options: { WebSocketImpl, timeoutMs: 200 } });

    assert.deepEqual(result.event.tags, [...bigList.tags, ['p', target]]);
    assert.equal(result.event.content, bigList.content);
    assert.ok(result.event.created_at > bigList.created_at);
    assert.equal(result.before, 380);
    assert.equal(result.after, 381);
    // To both write relays and the configured relay, and nowhere else.
    assert.deepEqual(sent.map((s) => s.url).sort(), ['ws://config', 'ws://write-a', 'ws://write-b']);
    assert.equal(result.published, 3);
});

test('the newest valid list counts, not the longest, and a forged newer copy is ignored', async () => {
    const older = sign(3, others.map((p) => ['p', p]), 1_700_000_000);
    const newer = sign(3, [['p', others[1]]], 1_700_000_500);
    const forged = { ...sign(3, [], 1_700_009_999), sig: '0'.repeat(128) };
    const WebSocketImpl = fakeSockets({
        'ws://config': { events: [older, forged, newer], eose: true },
    });

    const read = await readFollowList(me, ['ws://config'], { WebSocketImpl, timeoutMs: 200 });
    assert.equal(read.read, true);
    assert.equal(read.list.id, newer.id);
    assert.deepEqual(followTemplate(read, target, { me, now: 1 }).tags, [['p', others[1]], ['p', target]]);
});

test('the preview counts what changes, and says when no list exists at all', () => {
    assert.deepEqual(followPreview({ read: true, list: bigList }, target), { before: 380, after: 381, already: false, fresh: false });
    assert.deepEqual(followPreview({ read: true, list: null }, target), { before: 0, after: 1, already: false, fresh: true });
    assert.deepEqual(followPreview({ read: true, list: bigList }, others[5]), { before: 380, after: 380, already: true, fresh: false });
});

test('already followed, oneself and a malformed key are refused', () => {
    assert.throws(() => followTemplate({ read: true, list: bigList }, others[3], { me }), (error) => error.code === 'already');
    assert.throws(() => followTemplate({ read: true, list: bigList }, me, { me }), (error) => error.code === 'self');
    assert.throws(() => followTemplate({ read: true, list: bigList }, 'npub1xyz', { me }), (error) => error.code === 'bad_pubkey');
});

test('a list changed between preview and click is not overwritten', async () => {
    const WebSocketImpl = fakeSockets({ 'ws://config': { events: [bigList], eose: true } });
    await assert.rejects(
        follow({ me, target, relays: ['ws://config'], signer, expectBefore: 12, options: { WebSocketImpl, timeoutMs: 200 } }),
        (error) => error.code === 'changed',
    );
});

test('a signed event that dropped a follow is caught before it is sent', () => {
    const template = followTemplate({ read: true, list: bigList }, target, { me, now: 1 });
    const shorter = finalizeEvent({ ...template, tags: template.tags.slice(1) }, secret);
    assert.throws(() => checkSigned(shorter, template, me), (error) => error.code === 'mismatch');
    assert.equal(checkSigned(finalizeEvent({ ...template }, secret), template, me).kind, 3);
});
