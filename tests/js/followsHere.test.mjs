/**
 * "Your follows here" (resources/js/followsHere.js) against fake relays: the
 * newest valid kind 3 wins, a partial read is shown as partial, the player
 * and duplicates drop out, the split keeps the list order, names come from
 * signed kind 0 only. Run by tests/Nostr/FollowsHereTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import { MAX_FOLLOWS, profileName, readFollows, readNames, splitFollows } from '../../resources/js/followsHere.js';

const meSecret = generateSecretKey();
const me = getPublicKey(meSecret);
const keys = Array.from({ length: 6 }, () => getPublicKey(generateSecretKey()));

function signed(secret, kind, tags, created_at = 1_700_000_000, content = '') {
    return finalizeEvent({ kind, tags, content, created_at }, secret);
}

/** Fake relays: answer a REQ with the events of the asked kinds and authors, then EOSE (unless silent). */
function fakeSockets(relays, opened = []) {
    return class FakeSocket {
        constructor(url) {
            this.url = url;
            opened.push(url);
            this.behaviour = relays[url] ?? { events: [], eose: true };
            queueMicrotask(() => (this.behaviour.down ? this.onerror?.({}) : this.onopen?.()));
        }

        send(text) {
            const frame = JSON.parse(text);
            const reply = (data) => queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(data) }));
            if (frame[0] !== 'REQ') return;
            const filters = frame.slice(2);
            for (const event of this.behaviour.events ?? []) {
                if (filters.some((filter) => (filter.kinds ?? []).includes(event.kind) && (filter.authors ?? []).includes(event.pubkey))) reply(['EVENT', frame[1], event]);
            }
            if (this.behaviour.eose) reply(['EOSE', frame[1]]);
        }

        close() {}
    };
}

const options = (relays, opened) => ({ WebSocketImpl: fakeSockets(relays, opened), timeoutMs: 200 });

test('the newest valid kind 3 of the write relays and the configured relays wins; self and duplicates drop out', async () => {
    const relayList = signed(meSecret, 10002, [['r', 'ws://mine', 'write']]);
    const older = signed(meSecret, 3, keys.slice(0, 5).map((key) => ['p', key]), 1_700_000_000);
    const newer = signed(meSecret, 3, [['p', keys[2]], ['p', me], ['p', keys[0]], ['p', keys[2]], ['p', 'not-a-key'], ['e', keys[3]]], 1_700_000_100);
    const forged = { ...signed(meSecret, 3, [['p', keys[5]]], 1_800_000_000), sig: 'ff'.repeat(64) };

    const read = await readFollows(me, ['ws://lookup'], options({
        'ws://lookup': { events: [relayList, older, forged], eose: true },
        'ws://mine': { events: [newer], eose: true },
    }));

    assert.deepEqual(read.pubkeys, [keys[2], keys[0]]);
    assert.equal(read.found, true);
    assert.equal(read.complete, true);
    assert.equal(read.asked, 2);
});

test('a silent relay makes the read partial, not empty; no list anywhere is "not found"', async () => {
    const list = signed(meSecret, 3, [['p', keys[1]]]);
    const partial = await readFollows(me, ['ws://a', 'ws://b'], options({ 'ws://a': { events: [list], eose: true }, 'ws://b': { events: [], eose: false } }));

    assert.deepEqual(partial.pubkeys, [keys[1]]);
    assert.equal(partial.complete, false);
    assert.equal(partial.answered, 1);

    const none = await readFollows(me, ['ws://a'], options({ 'ws://a': { events: [], eose: true } }));
    assert.deepEqual(none.pubkeys, []);
    assert.equal(none.found, false);
    assert.equal(none.complete, true);
});

test('at most MAX_FOLLOWS pubkeys go to the league', async () => {
    const many = Array.from({ length: MAX_FOLLOWS + 3 }, (_, index) => ['p', index.toString(16).padStart(64, '0')]);
    const read = await readFollows(me, ['ws://a'], options({ 'ws://a': { events: [signed(meSecret, 3, many)], eose: true } }));

    assert.equal(read.pubkeys.length, MAX_FOLLOWS);
    assert.equal(read.truncated, true);
});

test('the split keeps the follow order and ignores anything the league did not send as a pubkey', () => {
    const { here, notHere } = splitFollows([keys[3], keys[0], keys[4], keys[1]], [keys[1], keys[3], 'junk', null]);

    assert.deepEqual(here, [keys[3], keys[1]]);
    assert.deepEqual(notHere, [keys[0], keys[4]]);
});

test('names come from the newest signed kind 0, as text, and accounts without a name are left out', async () => {
    const secret = generateSecretKey();
    const pubkey = getPublicKey(secret);
    const nameless = generateSecretKey();
    const events = [
        signed(secret, 0, [], 1_700_000_000, JSON.stringify({ name: 'old' })),
        signed(secret, 0, [], 1_700_000_100, JSON.stringify({ display_name: '  <b>Satoshi</b>  ', name: 'sn' })),
        signed(nameless, 0, [], 1_700_000_000, '{"about":"no name"}'),
    ];
    const names = await readNames([pubkey, getPublicKey(nameless), 'junk'], ['ws://a'], options({ 'ws://a': { events, eose: true } }));

    assert.equal(names.get(pubkey), '<b>Satoshi</b>');
    assert.equal(names.has(getPublicKey(nameless)), false);
    assert.equal(profileName({ content: 'not json' }), null);
});

test('no pubkeys, no relay asked', async () => {
    const opened = [];
    const names = await readNames([], ['ws://a'], options({}, opened));

    assert.equal(names.size, 0);
    assert.deepEqual(opened, []);
});
