/**
 * Reading comments, likes and RSVPs (P48, resources/js/commentsRead.js)
 * against fake relays that ignore the tag filters (as a relay may): only
 * comments rooted in the target count, forged ones never, pages go newest
 * first without duplicates, likes and RSVPs count the newest per author, the
 * league's moderation only from its creator. Run by
 * tests/Nostr/CommentsReadTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import {
    clipText, commentFilter, inScope, mayHaveMore, mergeComments, moderation, oldest, readAll, tallyLikes, tallyRsvps,
} from '../../resources/js/commentsRead.js';

const keys = Array.from({ length: 4 }, () => generateSecretKey());
const [anna, bert, carl, league] = keys.map((key) => getPublicKey(key));
const address = `31923:${league}:cup`;
const version = 'f'.repeat(64);
const tournament = { kind: 31923, pubkey: league, eventId: version, address };
const record = { kind: 64, pubkey: league, eventId: 'c'.repeat(64), address: null };

function sign(key, kind, tags, content, createdAt) {
    return finalizeEvent({ kind, tags, content, created_at: createdAt }, key);
}

function comment(key, target, content, createdAt, extra = []) {
    const root = target.address ? ['A', target.address, ''] : ['E', target.eventId, '', target.pubkey];
    const parent = target.address ? ['a', target.address, ''] : ['e', target.eventId, '', target.pubkey];

    return sign(key, 1111, [root, ['K', String(target.kind)], ['P', target.pubkey], parent, ['k', String(target.kind)], ['p', target.pubkey], ...extra], content, createdAt);
}

/** Fake relays that answer every REQ with all their events of the asked kinds, tag filters ignored. */
function fakeSockets(relays) {
    return class FakeSocket {
        constructor(url) {
            this.behaviour = relays[url] ?? { down: true };
            queueMicrotask(() => (this.behaviour.down ? this.onerror?.({}) : this.onopen?.()));
        }

        send(text) {
            const frame = JSON.parse(text);
            const reply = (data) => queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(data) }));
            const kinds = frame.slice(2).flatMap((filter) => filter.kinds ?? []);
            for (const event of this.behaviour.events ?? []) {
                if (kinds.includes(event.kind)) reply(['EVENT', frame[1], event]);
            }
            if (this.behaviour.eose) reply(['EOSE', frame[1]]);
        }

        close() {}
    };
}

test('the filter reads the root scope: #A for a tournament address, #E for an event id; the next page by until', () => {
    assert.deepEqual(commentFilter(tournament, { limit: 20 }), { kinds: [1111], limit: 20, '#A': [address] });
    assert.deepEqual(commentFilter(record, { limit: 20, until: 1_700_000_000 }), { kinds: [1111], limit: 20, '#E': [record.eventId], until: 1_700_000_000 });
});

test('only comments rooted in the target count: another root, a lowercase-only parent, a wrong K, two roots are dropped', () => {
    const good = comment(keys[0], tournament, 'gg', 1_700_000_000);
    const otherRoot = comment(keys[0], { ...tournament, address: `31923:${league}:other` }, 'x', 1_700_000_001);
    const parentOnly = sign(keys[0], 1111, [['a', address], ['k', '31923']], 'x', 1_700_000_002);
    const wrongKind = sign(keys[0], 1111, [['A', address], ['K', '1']], 'x', 1_700_000_003);
    const twoRoots = sign(keys[0], 1111, [['A', address], ['A', `31923:${league}:other`], ['K', '31923']], 'x', 1_700_000_004);

    assert.equal(inScope(good, tournament), true);
    for (const event of [otherRoot, parentOnly, wrongKind, twoRoots]) {
        assert.equal(inScope(event, tournament), false, event.content + JSON.stringify(event.tags));
    }
    assert.equal(inScope(comment(keys[0], record, 'gg', 1), record), true);
    assert.equal(inScope(good, record), false);
});

test('pages merge newest first without duplicates, bounded, and a forged comment never arrives', async () => {
    const first = [3, 2, 1].map((n) => comment(keys[n % 3], tournament, `c${n}`, 1_700_000_000 + n));
    const forged = { ...comment(keys[0], tournament, 'forged', 1_700_000_100), sig: '0'.repeat(128) };
    const WebSocketImpl = fakeSockets({ 'ws://a': { events: [first[0], first[1], forged], eose: true }, 'ws://b': { events: [first[1], first[2]], eose: true } });

    const read = await readAll(['ws://a', 'ws://b'], [commentFilter(tournament, { limit: 2 })], { WebSocketImpl, timeoutMs: 500 });
    const merged = mergeComments([], read.events, tournament, 200);

    assert.equal(read.reached, true);
    assert.deepEqual(merged.list.map((event) => event.content), ['c3', 'c2', 'c1']);
    assert.equal(merged.added, 3);
    assert.equal(mayHaveMore(read.results, 2), true);
    assert.equal(oldest(merged.list), 1_700_000_001);

    // The same page again adds nothing; the cap holds.
    assert.equal(mergeComments(merged.list, read.events, tournament, 200).added, 0);
    assert.equal(mergeComments([], read.events, tournament, 2).list.length, 2);
});

test('no relay answering is "unknown", not "no comments"', async () => {
    const WebSocketImpl = fakeSockets({ 'ws://silent': { events: [], eose: false } });
    const read = await readAll(['ws://down', 'ws://silent'], [commentFilter(tournament)], { WebSocketImpl, timeoutMs: 50 });

    assert.equal(read.reached, false);
    assert.equal(mayHaveMore(read.results, 20), false);
});

test('likes: the newest reaction per author, + or empty is a like, a later - takes it back, a muted author does not count', () => {
    const like = (key, content, at, tags = [['e', version], ['a', address], ['p', league], ['k', '31923']]) => sign(key, 7, tags, content, at);
    const events = [
        like(keys[0], '+', 1),
        like(keys[0], '+', 2),
        like(keys[1], '', 1),
        like(keys[1], '-', 2),
        like(keys[2], '+', 1),
        // Another version of the tournament, found by its address.
        like(keys[3], '+', 1, [['e', 'a'.repeat(64)], ['a', address]]),
        // Not this tournament.
        like(keys[1], '+', 3, [['e', 'b'.repeat(64)], ['a', `31923:${league}:other`]]),
    ];

    assert.deepEqual(tallyLikes(events, tournament, { me: anna }), { count: 3, mine: true });
    assert.deepEqual(tallyLikes(events, tournament, { me: bert }), { count: 3, mine: false });
    assert.deepEqual(tallyLikes(events, tournament, { me: anna, muted: [carl] }), { count: 2, mine: true });

    // An event target: its id must be the LAST e (NIP-25).
    const onRecord = [sign(keys[0], 7, [['e', record.eventId]], '+', 1), sign(keys[1], 7, [['e', record.eventId], ['e', 'd'.repeat(64)]], '+', 1)];
    assert.deepEqual(tallyLikes(onRecord, record, {}), { count: 1, mine: false });
});

test('RSVPs: the newest per author to the address, whatever its d; declined replaces accepted; muted left out', () => {
    const rsvp = (key, status, at, d = address, a = address) => sign(key, 31925, [['d', d], ['a', a], ['status', status]], '', at);
    const events = [
        rsvp(keys[0], 'accepted', 1),
        rsvp(keys[0], 'declined', 2),
        rsvp(keys[1], 'accepted', 1, 'random-uuid'),
        rsvp(keys[2], 'tentative', 1),
        rsvp(keys[3], 'accepted', 1, address, `31923:${league}:other`),
        sign(keys[3], 31925, [['a', address], ['status', 'maybe']], '', 2),
    ];

    assert.deepEqual(tallyRsvps(events, address), { accepted: [bert], declined: [anna], tentative: [carl] });
    assert.deepEqual(tallyRsvps(events, address, { muted: [bert] }), { accepted: [], declined: [anna], tentative: [carl] });
});

test('moderation counts only the creator: 43 hides an id, 44 mutes a pubkey', () => {
    const hide = sign(keys[3], 43, [['e', 'a'.repeat(64)]], '', 1);
    const mute = sign(keys[3], 44, [['p', anna]], '', 1);
    const foreign = sign(keys[1], 44, [['p', carl]], '', 1);

    assert.deepEqual(moderation([hide, mute, foreign], league), { hidden: ['a'.repeat(64)], muted: [anna] });
    assert.deepEqual(moderation([hide, mute], null), { hidden: [], muted: [] });
});

test('a long comment is clipped by characters, not bytes', () => {
    assert.equal(clipText('ä'.repeat(5), 3), 'äää…');
    assert.equal(clipText('🤙🤙', 2), '🤙🤙');
    assert.equal(clipText(null), '');
});
