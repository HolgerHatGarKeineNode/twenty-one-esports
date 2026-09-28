/**
 * The rules of a game's global chat (resources/js/channelChat.js, P21, NIP
 * "Game channels"): NIP-28 messages in the channel, NIP-88 polls scoped to
 * it, one vote per pubkey (the newest within the poll's limits, the lowest id
 * on a tie), whose votes count, closed polls, the creator's moderation, and
 * the bounds on anybody's input. Run by tests/Feature/GameChat/GameChannelsTest.php;
 * runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey, verifyEvent } from 'nostr-tools/pure';
import {
    MAX_TAGS, MAX_VOTERS, addVote, inChannel, isChannelMessage, isClosed, messageTemplate, moderation, optionId, parsePoll, parseVote, pollBlocker, pollTemplate, tally, timeLeft, voteTemplate,
} from '../../resources/js/channelChat.js';

const CHANNEL = 'c'.repeat(64);
const OTHER = 'd'.repeat(64);
const NOW = 1_790_600_000;
const alice = generateSecretKey();
const bob = generateSecretKey();
const [A, B] = [alice, bob].map((key) => getPublicKey(key));
const hex = (n) => n.toString(16).padStart(64, '0');

const signed = (key, template) => finalizeEvent({ created_at: NOW, content: '', tags: [], ...template }, key);

function poll(overrides = {}) {
    return signed(alice, {
        kind: 1068,
        content: 'Which format next Friday?',
        tags: [['e', CHANNEL, 'wss://r', 'root'], ['option', 'aa1', 'Blitz'], ['option', 'bb2', 'Rapid'], ['relay', 'wss://r'], ['polltype', 'singlechoice'], ['endsAt', String(NOW + 3600)]],
        ...overrides,
    });
}

/** A parsed vote without a signature (the book never checks one: the pool does). */
const vote = (pubkey, option, createdAt, id, pollId) => ({ id, pubkey, created_at: createdAt, poll: pollId, option });

test('a message is a kind 42 with the channel as root; ours carries no t tag and the emoji tags its text needs', () => {
    const template = messageTemplate('  gm :sats: ', { channel: CHANNEL, relayHint: 'wss://r', custom: [{ shortcode: 'sats', url: 'https://e.example/s.png' }], now: NOW * 1000 });
    assert.deepEqual(template, { kind: 42, created_at: NOW, tags: [['e', CHANNEL, 'wss://r', 'root'], ['emoji', 'sats', 'https://e.example/s.png']], content: 'gm :sats:' });

    const event = signed(alice, template);
    assert.equal(isChannelMessage(event, CHANNEL), true);
    assert.equal(isChannelMessage(event, OTHER), false);
    // A reply's second `e` is not the root; a positional `e` without marker still counts (older NIP-28 clients).
    assert.equal(inChannel({ tags: [['e', OTHER, '', 'root'], ['e', CHANNEL, '', 'reply']] }, CHANNEL), false);
    assert.equal(inChannel({ tags: [['e', CHANNEL]] }, CHANNEL), true);
    assert.equal(isChannelMessage({ ...event, kind: 1 }, CHANNEL), false);
    assert.equal(isChannelMessage({ ...event, content: '   ' }, CHANNEL), false);
    // The channel tag behind MAX_TAGS other tags is not read.
    assert.equal(inChannel({ tags: [...Array.from({ length: MAX_TAGS }, () => ['x', '1']), ['e', CHANNEL, '', 'root']] }, CHANNEL), false);
});

test('a poll is built from question, answers and duration, and reads back as it was sent', () => {
    let n = 0;
    const template = pollTemplate({ question: ' Best time? ', options: ['20:00', ' 21:00 ', ''], duration: 86400, channel: CHANNEL, relayHint: 'wss://r', relays: ['wss://r', 'wss://s'], now: NOW * 1000, newId: () => ['id1', 'id1', 'id2'][n++] });

    assert.deepEqual(template, {
        kind: 1068,
        created_at: NOW,
        tags: [['e', CHANNEL, 'wss://r', 'root'], ['option', 'id1', '20:00'], ['option', 'id2', '21:00'], ['relay', 'wss://r'], ['relay', 'wss://s'], ['polltype', 'singlechoice'], ['endsAt', String(NOW + 86400)]],
        content: 'Best time?',
    });

    const event = signed(alice, template);
    assert.equal(verifyEvent(event), true);
    assert.deepEqual(parsePoll(event, CHANNEL), { id: event.id, pubkey: A, created_at: NOW, question: 'Best time?', options: [{ id: 'id1', label: '20:00' }, { id: 'id2', label: '21:00' }], endsAt: NOW + 86400 });
    assert.equal(parsePoll(event, OTHER), null);
    assert.match(optionId(), /^[a-z0-9]{9}$/);
});

test('what may not be sent as a poll', () => {
    const limits = { questionMax: 140, optionMax: 60, maxOptions: 4 };
    assert.equal(pollBlocker({ question: 'Q?', options: ['a', 'b'] }, limits), null);
    assert.equal(pollBlocker({ question: '  ', options: ['a', 'b'] }, limits), 'question');
    assert.equal(pollBlocker({ question: 'x'.repeat(141), options: ['a', 'b'] }, limits), 'question');
    assert.equal(pollBlocker({ question: 'two\nlines', options: ['a', 'b'] }, limits), 'question');
    assert.equal(pollBlocker({ question: 'Q?', options: ['a', ''] }, limits), 'options');
    assert.equal(pollBlocker({ question: 'Q?', options: ['a', 'b', 'c', 'd', 'e'] }, limits), 'options');
    assert.equal(pollBlocker({ question: 'Q?', options: ['Yes', 'yes'] }, limits), 'options');
    assert.equal(pollBlocker({ question: 'Q?', options: ['a', 'x'.repeat(61)] }, limits), 'options');
    assert.equal(pollBlocker({ question: 'Q?', options: ['a', 'b' + String.fromCharCode(0x2028)] }, limits), 'options');
    assert.throws(() => pollTemplate({ question: 'Q?', options: ['a'], duration: 60, channel: CHANNEL }), /poll_options/);
    assert.throws(() => pollTemplate({ question: 'Q?', options: ['a', 'b'], duration: 0, channel: CHANNEL }), /poll_duration/);
});

test('a received poll is shown only when it is ours to show, and bounded', () => {
    assert.notEqual(parsePoll(poll(), CHANNEL), null);
    assert.equal(parsePoll(poll({ tags: poll().tags.map((t) => (t[0] === 'polltype' ? ['polltype', 'multiplechoice'] : t)) }), CHANNEL), null, 'multiple choice is not ours');
    assert.equal(parsePoll(poll({ tags: poll().tags.filter((t) => t[0] !== 'endsAt') }), CHANNEL), null, 'no end');
    assert.equal(parsePoll(poll({ tags: poll().tags.map((t) => (t[0] === 'endsAt' ? ['endsAt', String(NOW)] : t)) }), CHANNEL), null, 'ends when it starts');
    assert.equal(parsePoll(poll({ tags: poll().tags.map((t) => (t[0] === 'endsAt' ? ['endsAt', '1e12'] : t)) }), CHANNEL), null, 'not an integer');
    assert.equal(parsePoll(poll({ tags: poll().tags.filter((t) => t[1] !== 'bb2') }), CHANNEL), null, 'one answer');
    assert.equal(parsePoll(poll({ content: '' }), CHANNEL), null, 'no question');

    // A duplicate or malformed option is skipped, not trusted.
    const odd = parsePoll(poll({ tags: [...poll().tags, ['option', 'aa1', 'again'], ['option', 'bad id', 'x'], ['option', 'cc3', '']] }), CHANNEL);
    assert.deepEqual(odd.options.map((o) => o.id), ['aa1', 'bb2']);

    // The auditor's size: 63 KB of question, 1000 option tags.
    const huge = parsePoll(poll({ content: 'Q'.repeat(63_000), tags: [['e', CHANNEL, '', 'root'], ['endsAt', String(NOW + 60)], ...Array.from({ length: 1000 }, (_, i) => ['option', 'o' + i, 'x'.repeat(5000)])] }), CHANNEL);
    assert.equal(huge, null, 'more than MAX_OPTIONS answers within the first MAX_TAGS tags: not shown');
    const long = parsePoll(poll({ content: 'Q'.repeat(63_000), tags: [['e', CHANNEL, '', 'root'], ['endsAt', String(NOW + 60)], ['option', 'a', 'x'.repeat(5000)], ['option', 'b', 'y']] }), CHANNEL);
    assert.equal([...long.question].length, 281);
    assert.equal([...long.options[0].label].length, 120);
});

test('one vote per pubkey: the newest within the poll, the lowest id on a tie; a bad vote leaves the good one', () => {
    const p = parsePoll(poll(), CHANNEL);
    const polls = new Map([[p.id, p]]);
    const book = new Map();

    assert.equal(addVote(book, vote(A, 'aa1', NOW + 10, hex(9), p.id), polls), 'added');
    assert.equal(addVote(book, vote(A, 'bb2', NOW + 20, hex(8), p.id), polls), 'replaced');
    assert.equal(addVote(book, vote(A, 'aa1', NOW + 15, hex(1), p.id), polls), 'ignored', 'older');
    assert.equal(addVote(book, vote(A, 'aa1', NOW + 20, hex(9), p.id), polls), 'ignored', 'same second, higher id');
    assert.equal(addVote(book, vote(A, 'aa1', NOW + 20, hex(2), p.id), polls), 'replaced', 'same second, lower id');
    assert.equal(addVote(book, vote(A, 'zz9', NOW + 30, hex(3), p.id), polls), 'ignored', 'no such answer');
    assert.equal(addVote(book, vote(A, 'bb2', NOW - 1, hex(4), p.id), polls), 'ignored', 'before the poll');
    assert.equal(addVote(book, vote(A, 'bb2', NOW + 3601, hex(5), p.id), polls), 'ignored', 'after its end');
    assert.equal(addVote(book, vote(A, 'bb2', NOW + 3600, hex(6), OTHER), polls), 'ignored', 'another poll');
    assert.equal(book.get(p.id).get(A).option, 'aa1');

    // The last second counts; the order of arrival does not matter.
    const later = new Map();
    addVote(later, vote(B, 'bb2', NOW + 3600, hex(7), p.id), polls);
    addVote(later, vote(B, 'aa1', NOW + 100, hex(1), p.id), polls);
    assert.equal(later.get(p.id).get(B).option, 'bb2');
});

test('a vote event reads its first e and its first response only', () => {
    const p = poll();
    const event = signed(bob, { kind: 1018, tags: [['e', p.id], ['e', OTHER], ['response', 'bb2'], ['response', 'aa1']] });
    assert.deepEqual(parseVote(event), { id: event.id, pubkey: B, created_at: NOW, poll: p.id, option: 'bb2' });
    assert.equal(parseVote({ ...event, tags: [['e', p.id]] }), null);
    assert.equal(parseVote({ ...event, kind: 7 }), null);
    assert.deepEqual(voteTemplate({ id: p.id }, 'aa1', { relayHint: 'wss://r', now: NOW * 1000 }), { kind: 1018, created_at: NOW, tags: [['e', p.id, 'wss://r'], ['response', 'aa1']], content: '' });
});

test('the tally counts whom the page counts, says how many others voted, and knows my vote', () => {
    const p = parsePoll(poll(), CHANNEL);
    const polls = new Map([[p.id, p]]);
    const book = new Map();
    const [x, y, z] = ['1', '2', '3'].map((d) => d.repeat(64));
    addVote(book, vote(A, 'aa1', NOW + 1, hex(1), p.id), polls);
    addVote(book, vote(x, 'aa1', NOW + 1, hex(2), p.id), polls);
    addVote(book, vote(y, 'bb2', NOW + 1, hex(3), p.id), polls);
    addVote(book, vote(z, 'bb2', NOW + 1, hex(4), p.id), polls);

    assert.deepEqual(tally(p, book, { counts: (pubkey) => pubkey !== z, me: A }), { counts: { aa1: 2, bb2: 1 }, total: 3, uncounted: 1, mine: 'aa1' });
    assert.deepEqual(tally(p, new Map(), {}), { counts: { aa1: 0, bb2: 0 }, total: 0, uncounted: 0, mine: null });
});

test('a closed poll takes no votes after its end, and says it is closed', () => {
    const p = parsePoll(poll(), CHANNEL);
    assert.equal(isClosed(p, NOW + 3599), false);
    assert.equal(isClosed(p, NOW + 3600), true);
    assert.equal(timeLeft(p.endsAt, { now: NOW, locale: 'en' }), 'in 60 min.');
    assert.equal(timeLeft(NOW + 3 * 86400, { now: NOW, locale: 'en' }), 'in 3 days');
});

test('a vote book is bounded per poll; a known voter may still change', () => {
    const p = parsePoll(poll(), CHANNEL);
    const polls = new Map([[p.id, p]]);
    const book = new Map();
    for (let i = 0; i < 3; i++) addVote(book, vote(hex(100 + i), 'aa1', NOW + 1, hex(i + 1), p.id), polls, 3);

    assert.equal(addVote(book, vote(hex(999), 'aa1', NOW + 1, hex(50), p.id), polls, 3), 'full');
    assert.equal(addVote(book, vote(hex(100), 'bb2', NOW + 2, hex(51), p.id), polls, 3), 'replaced');
    assert.equal(MAX_VOTERS, 5000);
});

test('only the creator moderates: hides by e, mutes by p', () => {
    const creator = A;
    const events = [
        signed(alice, { kind: 43, tags: [['e', hex(1)]] }),
        signed(alice, { kind: 44, tags: [['p', B]] }),
        signed(bob, { kind: 44, tags: [['p', A]] }),
        signed(alice, { kind: 44, tags: [['p', 'not-a-key']] }),
    ];
    const { hidden, muted } = moderation(events, creator);
    assert.deepEqual([...hidden], [hex(1)]);
    assert.deepEqual([...muted], [B]);
});
