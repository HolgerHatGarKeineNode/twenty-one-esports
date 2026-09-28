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
 *
 * Delivery runs on the microtask queue (`queueMicrotask`), not on real
 * timers. `readRelay()` races this delivery against its own `setTimeout`
 * deadline (`timeoutMs`); under host CPU load, `setTimeout` callbacks queued
 * relative to "now" (open, then each reply, then EOSE) get pushed further
 * out every time the process is starved between them, while the outer
 * deadline was fixed at call time — so a busy host can make the deadline
 * fire before a relay that legitimately answered ever gets to. Microtasks
 * have no such relative delay: once fakeSockets() is scheduled at all, the
 * whole open→send→reply→EOSE chain drains in one burst, before the event
 * loop is allowed to move on to any pending timer, however overdue. That
 * keeps `readRelay()`'s timeoutMs a bound on genuinely slow/silent relays
 * (which it is meant to test), not a race with the fixture's own plumbing.
 * See tests/Feature/Badges/RelayReadTest.php for the regression this fixes.
 */
function fakeSockets(relays) {
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
                    if (kinds.includes(event.kind)) {
                        reply(['EVENT', frame[1], event]);
                    }
                }
                if (this.behaviour.eose) {
                    // Queued after the event replies above: microtasks drain
                    // FIFO, so EOSE still arrives after the events it follows.
                    reply(['EOSE', frame[1]]);
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
    // P45 audit F2: the write relays come from the player's relay list, never from the configured relays.
    const relayList = sign(10002, [['r', 'ws://a'], ['r', 'ws://b']], 1_700_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://a': { events: [relayList, older, forgedNewest], eose: true }, 'ws://b': { events: [newer], eose: true } });

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

/*
 * P45 security audit F2 applied to the badge lists: without the player's own
 * write relays nothing counts as read, so the league never builds a 10008 from
 * a configured relay's stale copy.
 */
test('audit F2 (A): the relay holding the relay list does not answer; not read', async () => {
    const relayList = sign(10002, [['r', 'ws://own-write']], 1_700_000_000);
    const stale = sign(10008, [['a', '30009:' + 'a'.repeat(64) + ':x'], ['e', '1'.repeat(64)]], 1_600_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://config-b': { events: [stale], eose: true }, 'ws://own-write': { events: [relayList], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.equal(result.relayList, 'not_read');
    assert.deepEqual(result.found, []);
});

test('audit F2 (B): no configured relay holds a relay list; not read, although every relay answered', async () => {
    const stale = sign(10008, [['a', '30009:' + 'a'.repeat(64) + ':x'], ['e', '1'.repeat(64)]], 1_600_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://config-a': { events: [stale], eose: true }, 'ws://config-b': { events: [], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.equal(result.relayList, 'none');
    assert.deepEqual(result.found, []);
});

/* The softer F2 rule for the badge list: a new identity may start one; anything less stays refused. */
test('new identity: every configured relay answered, no relay list, no badge list; read, the configured relays stand in', async () => {
    const WebSocketImpl = fakeSockets({ 'ws://config-a': { events: [], eose: true }, 'ws://config-b': { events: [], eose: true } });

    // Re-audit: without the player's "Start a new list" nothing counts as read.
    const unconfirmed = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl, timeoutMs: 200 });
    assert.equal(unconfirmed.read, false);
    assert.equal(unconfirmed.relayList, 'confirm_new_list');
    assert.deepEqual(unconfirmed.writeRelays, []);

    const result = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl, timeoutMs: 200, allowNewList: true });

    assert.equal(result.read, true);
    assert.equal(result.relayList, 'new');
    assert.deepEqual(result.found, []);
    assert.deepEqual(result.writeRelays, ['ws://config-a', 'ws://config-b']);
});

test('new identity, but one configured relay did not answer: not read', async () => {
    const WebSocketImpl = fakeSockets({ 'ws://config-a': { events: [], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.equal(result.relayList, 'not_read');
});

test('no relay list, but a legacy 30008 badge list on a configured relay: not read', async () => {
    const legacy = sign(30008, [['d', 'profile_badges'], ['a', '30009:' + 'a'.repeat(64) + ':x'], ['e', '1'.repeat(64)]], 1_600_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://config-a': { events: [], eose: true }, 'ws://config-b': { events: [legacy], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl, timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.equal(result.relayList, 'none');
    assert.deepEqual(result.found, []);
});

test('new identity, but a relay goes silent on the badge read after answering the relay list: not read', async () => {
    const connections = {};
    class FlakySocket {
        constructor(url) {
            connections[url] = (connections[url] ?? 0) + 1;
            this.down = url === 'ws://config-b' && connections[url] > 1;
            queueMicrotask(() => (this.down ? this.onerror?.({}) : this.onopen?.()));
        }

        send(text) {
            const frame = JSON.parse(text);
            queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(['EOSE', frame[1]]) }));
        }

        close() {}
    }

    const result = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl: FlakySocket, timeoutMs: 200 });

    assert.equal(result.read, false);
    assert.equal(result.relayList, 'not_read');
    assert.equal(connections['ws://config-b'], 2);
});

test('"Start a new list" never overrides a badge list that exists: still not read', async () => {
    const stale = sign(10008, [['a', '30009:' + 'a'.repeat(64) + ':x'], ['e', '1'.repeat(64)]], 1_600_000_000);
    const WebSocketImpl = fakeSockets({ 'ws://config-a': { events: [stale], eose: true }, 'ws://config-b': { events: [], eose: true } });

    const result = await readProfileBadges(pubkey, ['ws://config-a', 'ws://config-b'], { WebSocketImpl, timeoutMs: 200, allowNewList: true });

    assert.equal(result.read, false);
    assert.equal(result.relayList, 'none');
});
