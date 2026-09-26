/**
 * The P11 relay reader (resources/js/relayRead.js) against fake relays:
 * a relay counts as read only after EOSE, forged copies of the right id do
 * not hide the real event, the newest valid event wins. Run by
 * tests/Feature/Badges/RelayReadTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import { newest, publishToRelay, readProfileBadges, readRelay } from '../../resources/js/relayRead.js';

const secret = generateSecretKey();
const pubkey = getPublicKey(secret);

function sign(kind, tags, createdAt) {
    return finalizeEvent({ kind, tags, content: '', created_at: createdAt }, secret);
}

/**
 * A fake WebSocket factory. `relays[url]` is a behaviour:
 *   { events: [...], eose: true }  answers every REQ with the events, then EOSE
 *   { events: [...], eose: false } answers with events and then stays silent
 *   { down: true }                 fails to connect
 *   { ok: true|false }             answers an EVENT with OK
 */
function fakeSockets(relays) {
    return class FakeSocket {
        constructor(url) {
            this.url = url;
            this.behaviour = relays[url] ?? { down: true };
            setTimeout(() => (this.behaviour.down ? this.onerror?.({}) : this.onopen?.()), 1);
        }

        send(text) {
            const frame = JSON.parse(text);
            const reply = (data) => setTimeout(() => this.onmessage?.({ data: JSON.stringify(data) }), 1);

            if (frame[0] === 'REQ') {
                const kinds = frame.slice(2).flatMap((filter) => filter.kinds ?? []);
                for (const event of this.behaviour.events ?? []) {
                    if (kinds.includes(event.kind)) {
                        reply(['EVENT', frame[1], event]);
                    }
                }
                if (this.behaviour.eose) {
                    setTimeout(() => this.onmessage?.({ data: JSON.stringify(['EOSE', frame[1]]) }), 5);
                }
            } else if (frame[0] === 'EVENT') {
                reply(['OK', frame[1].id, this.behaviour.ok === true, '']);
            }
        }

        close() {}
    };
}

test('a forged copy of the right id, served first, does not hide the real event', async () => {
    const real = sign(10008, [['a', '30009:' + 'a'.repeat(64) + ':bravery'], ['e', '1'.repeat(64)]], 1_700_000_000);
    const forged = { ...real, sig: '0'.repeat(128) };
    const WebSocketImpl = fakeSockets({ 'ws://junk': { events: [forged, real], eose: true } });

    for (let run = 0; run < 20; run++) {
        const result = await readRelay('ws://junk', [{ kinds: [10008] }], { WebSocketImpl, timeoutMs: 200 });
        assert.equal(result.eose, true);
        assert.equal(result.events.length, 1);
        assert.equal(result.events[0].sig, real.sig);
    }
});

test('the newest valid list wins, not the first to arrive, and a forged newer one is ignored', async () => {
    const older = sign(10008, [['a', '30009:' + 'a'.repeat(64) + ':x'], ['e', '1'.repeat(64)]], 1_700_000_000);
    const newer = sign(10008, [['a', '30009:' + 'b'.repeat(64) + ':y'], ['e', '2'.repeat(64)]], 1_700_000_100);
    const forgedNewest = { ...sign(10008, [], 1_700_000_200), sig: '1'.repeat(128) };
    const WebSocketImpl = fakeSockets({ 'ws://a': { events: [older, forgedNewest], eose: true }, 'ws://b': { events: [newer], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://a', 'ws://b'], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(result.read, true);
    assert.deepEqual(result.found.map((event) => event.id), [newer.id]);
    assert.equal(newest([older, newer], pubkey, 10008).id, newer.id);
});

test('a relay that is down, or answers without EOSE, is not read', async () => {
    const list = sign(10008, [['a', '30009:' + 'a'.repeat(64) + ':x'], ['e', '1'.repeat(64)]], 1_700_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://silent': { events: [list], eose: false } });

    const down = await readRelay('ws://down', [{ kinds: [10008] }], { WebSocketImpl, timeoutMs: 200 });
    const silent = await readRelay('ws://silent', [{ kinds: [10008] }], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(down.eose, false);
    assert.equal(silent.eose, false);
    assert.equal(silent.events.length, 1, 'events that arrived are kept, but the relay does not count as read');
});

test('the write relays of the relay list must answer; a read relay alone is not enough', async () => {
    const relayList = sign(10002, [['r', 'ws://write-down'], ['r', 'ws://read-only', 'read']], 1_700_000_000);
    const list = sign(10008, [['a', '30009:' + 'a'.repeat(64) + ':x'], ['e', '1'.repeat(64)]], 1_700_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://configured': { events: [relayList, list], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://configured'], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.deepEqual(result.writeRelays, ['ws://write-down']);
    assert.equal(result.answered, 0);
    assert.equal(result.asked, 1);
});

test('every write relay must answer: one of two down is not a read, even if the other one answered empty', async () => {
    const relayList = sign(10002, [['r', 'ws://write-up'], ['r', 'ws://write-down']], 1_700_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://configured': { events: [relayList], eose: true }, 'ws://write-up': { events: [], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://configured'], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.equal(result.answered, 1);
    assert.equal(result.asked, 2);
});

test('nothing is read when no configured relay answers the relay list', async () => {
    const result = await readProfileBadges(pubkey, ['ws://down'], { WebSocketImpl: fakeSockets({}), timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.deepEqual(result.found, []);
});

test('publishing counts only OK true', async () => {
    const event = sign(10008, [], 1_700_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://yes': { ok: true }, 'ws://no': { ok: false } });

    assert.equal(await publishToRelay('ws://yes', event, { WebSocketImpl, timeoutMs: 200 }), true);
    assert.equal(await publishToRelay('ws://no', event, { WebSocketImpl, timeoutMs: 200 }), false);
    assert.equal(await publishToRelay('ws://down', event, { WebSocketImpl, timeoutMs: 200 }), false);
});
