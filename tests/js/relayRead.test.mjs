/**
 * The P11 relay reader (resources/js/relayRead.js) against fake relays:
 * a relay counts as read only after EOSE, forged copies of the right id do
 * not hide the real event, the newest valid event wins. Run by
 * tests/Feature/Badges/RelayReadTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import { newest, outboxPlan, publicRelay, publishToRelay, readProfileBadges, readProfiles, readRelay } from '../../resources/js/relayRead.js';

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

/**
 * Relays for the chats' profile reads (readProfiles): each `relays[url]`
 * holds events and says whether it ends with EOSE; a REQ gets the events its
 * filter's kinds and authors match, and every REQ is recorded in `asked`.
 */
function profileSockets(relays, asked = []) {
    return class ProfileSocket {
        constructor(url) {
            this.url = url;
            this.relay = relays[url];
            queueMicrotask(() => (this.relay ? this.onopen?.() : this.onerror?.({})));
        }

        send(text) {
            const [, id, ...filters] = JSON.parse(text);
            asked.push({ url: this.url, filters });
            const reply = (data) => queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(data) }));
            for (const event of this.relay.events ?? []) {
                if (filters.some((filter) => filter.kinds.includes(event.kind) && (!filter.authors || filter.authors.includes(event.pubkey)))) reply(['EVENT', id, event]);
            }
            if (this.relay.eose !== false) reply(['EOSE', id]);
        }

        close() {}
    };
}

function profileOf(secretKey, name, createdAt = 1_700_000_000) {
    return finalizeEvent({ kind: 0, tags: [], content: JSON.stringify({ name }), created_at: createdAt }, secretKey);
}

test('profiles: the usual relays first, then the outbox way (indexer 10002, write relays) for whoever is missing', async () => {
    const [known, outside, nowhere] = [generateSecretKey(), generateSecretKey(), generateSecretKey()];
    const outsideKey = getPublicKey(outside);
    const nowhereKey = getPublicKey(nowhere);
    const list = finalizeEvent({ kind: 10002, tags: [['r', 'wss://usual'], ['r', 'wss://home'], ['r', 'wss://inbox-only', 'read']], content: '', created_at: 1_700_000_000 }, outside);
    // A forged copy of the right kind 0 on the outbox relay, served first: the real one still wins.
    const forged = { ...profileOf(outside, 'Mallory', 1_700_000_100), sig: '0'.repeat(128) };
    const asked = [];
    const WebSocketImpl = profileSockets({
        'wss://usual': { events: [profileOf(known, 'Known')] },
        'wss://index': { events: [list] },
        'wss://home': { events: [forged, profileOf(outside, 'Outside')] },
    }, asked);

    const { events, settled } = await readProfiles([getPublicKey(known), outsideKey, nowhereKey], { relays: ['wss://usual'], indexers: ['wss://index'] }, { WebSocketImpl, timeoutMs: 200 });

    assert.deepEqual(events.map((event) => JSON.parse(event.content).name).sort(), ['Known', 'Outside']);
    assert.deepEqual([...settled].sort(), [getPublicKey(known), outsideKey, nowhereKey].sort());
    // The indexer is asked only about the missing ones; the write relay only about its own author, and the
    // read-only relay and the already asked usual relay not at all.
    assert.deepEqual(asked.find((req) => req.url === 'wss://index').filters[0].authors.sort(), [outsideKey, nowhereKey].sort());
    assert.deepEqual(asked.filter((req) => req.url === 'wss://home').map((req) => req.filters[0].authors), [[outsideKey]]);
    assert.equal(asked.filter((req) => req.url === 'wss://inbox-only').length, 0);
    assert.equal(asked.filter((req) => req.url === 'wss://usual').length, 1);
});

test('profiles: a read without EOSE leaves the missing ones unsettled, so they are asked again', async () => {
    const someone = generateSecretKey();
    const key = getPublicKey(someone);
    const list = finalizeEvent({ kind: 10002, tags: [['r', 'wss://home']], content: '', created_at: 1_700_000_000 }, someone);
    const options = { timeoutMs: 100 };

    const silentUsual = await readProfiles([key], { relays: ['wss://usual'] }, { ...options, WebSocketImpl: profileSockets({ 'wss://usual': { events: [], eose: false } }) });
    assert.equal(silentUsual.settled.size, 0);

    const silentIndexer = await readProfiles([key], { relays: ['wss://usual'], indexers: ['wss://index'] }, { ...options, WebSocketImpl: profileSockets({ 'wss://usual': { events: [] }, 'wss://index': { events: [list], eose: false } }) });
    assert.equal(silentIndexer.settled.size, 0);

    const silentHome = await readProfiles([key], { relays: ['wss://usual'], indexers: ['wss://index'] }, { ...options, WebSocketImpl: profileSockets({ 'wss://usual': { events: [] }, 'wss://index': { events: [list] }, 'wss://home': { events: [], eose: false } }) });
    assert.equal(silentHome.settled.size, 0);

    const allAnswered = await readProfiles([key], { relays: ['wss://usual'], indexers: ['wss://index'] }, { ...options, WebSocketImpl: profileSockets({ 'wss://usual': { events: [] }, 'wss://index': { events: [list] }, 'wss://home': { events: [] } }) });
    assert.deepEqual([...allAnswered.settled], [key]);
});

test('the outbox plan is bounded whatever the relay lists say, and a live page goes to public wss relays only', () => {
    const keys = Array.from({ length: 6 }, () => generateSecretKey());
    const lists = keys.map((key, i) => finalizeEvent({ kind: 10002, tags: Array.from({ length: 10 }, (_, j) => ['r', `wss://r${i}-${j}.example`]), content: '', created_at: 1_700_000_000 }, key));
    const plan = outboxPlan(lists, keys.map((key) => getPublicKey(key)), { perAuthor: 3, maxRelays: 8 });
    assert.equal(plan.size, 8);
    assert.ok([...plan.values()].every((who) => who.length === 1));

    const hostile = finalizeEvent({ kind: 10002, tags: ['ws://plain.example', 'wss://localhost', 'wss://127.0.0.1:7777', 'wss://192.168.1.1', 'wss://10.0.0.8', 'wss://172.20.0.1', 'wss://[::1]', 'wss://printer.local', 'wss://user:pw@relay.example', 'wss://nos.lol', 'wss://relay.example/path'].map((url) => ['r', url]), content: '', created_at: 1_700_000_000 }, keys[0]);
    assert.deepEqual([...outboxPlan([hostile], [getPublicKey(keys[0])], { perAuthor: 20, allow: publicRelay }).keys()], ['wss://nos.lol', 'wss://relay.example/path']);
});
