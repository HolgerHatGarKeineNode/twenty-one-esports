/**
 * The game channel's component (resources/js/gameChannel.js, P21) in Node,
 * with a fake relay pool and a fake league lookup: what the security review
 * of P21 found (F1 vote flood, F2 who counts, F3 slot starvation) stays
 * fixed. A vote asks for a redraw only when it changes a counted tally;
 * a poll enters the list and the watched set only once the league says its
 * author counts; authors are looked up before voters, each within a budget.
 * Run by tests/Feature/GameChat/GameChannelsTest.php; runnable alone with
 * `node --test`.
 */
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';

globalThis.localStorage = { getItem: () => null, setItem: () => {} };
globalThis.window = { matchMedia: () => ({ matches: false }) };
globalThis.document = { addEventListener() {} };

const { gameChannel } = await import('../../resources/js/gameChannel.js');

const made = [];
afterEach(() => made.splice(0).forEach((comp) => comp.destroy()));

const hex = () => [...crypto.getRandomValues(new Uint8Array(32))].map((b) => b.toString(16).padStart(2, '0')).join('');
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const NOW = Math.floor(Date.now() / 1000);

/** A component wired to fakes; `counting` and `accounts` are the league's answers. */
function channel({ counting = new Set(), accounts = new Set(), me = null, meCounts = false } = {}) {
    const CHANNEL = hex();
    const subs = [];
    const lookups = [];
    const comp = gameChannel({ channel: CHANNEL, creator: hex(), relays: [], labels: new Proxy({}, { get: (_, key) => String(key) }), me, meName: 'me', meCounts });
    comp.$wire = {
        players: async (keys) => {
            lookups.push([...keys]);
            return Object.fromEntries(keys.filter((key) => counting.has(key) || accounts.has(key)).map((key) => [key, { name: 'P', avatar: '/a', counts: counting.has(key) }]));
        },
        setMuted: async () => true,
    };
    comp.$nextTick = (callback) => callback();
    comp.$refs = {};
    comp.init();
    made.push(comp);
    comp.pool = { subscribe: (relays, filters) => { subs.push(filters); return { close() {} }; }, destroy() {} };
    comp.status = 'live';

    const poll = (pubkey, createdAt = NOW - 1000) => ({ id: hex(), pubkey, kind: 1068, created_at: createdAt, content: 'Q?', tags: [['e', CHANNEL, '', 'root'], ['option', 'a1', 'A'], ['option', 'b2', 'B'], ['polltype', 'singlechoice'], ['endsAt', String(NOW + 86400)]] });
    const vote = (pollId, pubkey, option = 'a1', createdAt = NOW - 10) => ({ id: hex(), pubkey, kind: 1018, created_at: createdAt, content: '', tags: [['e', pollId], ['response', option]] });
    const message = (pubkey, text = 'hi') => ({ id: hex(), pubkey, kind: 42, created_at: NOW - 5, content: text, tags: [['e', CHANNEL, '', 'root']] });
    const settle = async () => { for (let i = 0; i < 20; i++) await wait(60); };

    return { comp, subs, lookups, poll, vote, message, settle };
}

test('a poll waits outside the list until the league says its author counts; a non-counting author\'s never enters', async () => {
    const [player, account] = [hex(), hex()];
    const { comp, subs, poll, settle } = channel({ counting: new Set([player]), accounts: new Set([account]) });
    const real = poll(player);
    const fromAccount = poll(account);
    const fromStranger = poll(hex());

    comp.receive(real);
    comp.receive(fromAccount);
    comp.receive(fromStranger);
    assert.equal(comp.items.length, 0, 'nothing shown before the league answered');

    await settle();
    assert.deepEqual(comp.items.map((item) => item.id), [real.id]);
    assert.deepEqual(subs.at(-1)[0]['#e'], [real.id], 'only the confirmed poll is watched');
    assert.deepEqual(subs.at(-1)[1].authors, [player], 'the counting players\' votes have their own filter');
    assert.equal(subs.at(-1)[0].limit, 500, 'anybody\'s votes are read with a limit');
});

test('230 junk polls neither empty the chat nor take the vote slots of the real poll', async () => {
    const player = hex();
    const { comp, subs, poll, message, settle } = channel({ counting: new Set([player]) });
    const real = poll(player, NOW - 2000);
    comp.receive(real);
    for (let i = 0; i < 20; i++) comp.receive(message(player, 'real ' + i));
    for (let i = 0; i < 230; i++) comp.receive(poll(hex(), NOW - 100 + i));
    await settle();

    assert.equal(comp.items.filter((item) => item.type === 'poll').length, 1);
    assert.equal(comp.items.filter((item) => item.type === 'message').length, 20);
    assert.deepEqual(subs.at(-1)[0]['#e'], [real.id]);
});

test('a vote nobody counts costs no redraw: votes of unknown keys are looked up, then shown within a second at most', async () => {
    const player = hex();
    const { comp, poll, vote, settle } = channel({ counting: new Set([player]) });
    const real = poll(player);
    comp.receive(real);
    await settle();
    const before = comp.tallies[real.id];

    for (let i = 0; i < 300; i++) comp.receive(vote(real.id, hex(), i % 2 ? 'a1' : 'b2'));
    await wait(50);
    assert.equal(comp.tallies[real.id], before, 'no tally change while the voters are unknown');

    await settle();
    await wait(1100);
    assert.deepEqual({ total: comp.tallies[real.id].total, uncounted: comp.tallies[real.id].uncounted }, { total: 0, uncounted: 300 });

    // A counting player's vote is on the next flush, and replaces nothing but the tally object.
    const tallies = comp.tallies;
    comp.receive(vote(real.id, player, 'b2'));
    await wait(50);
    assert.equal(comp.tallies, tallies);
    assert.deepEqual(comp.tallies[real.id].counts, { a1: 0, b2: 1 });
});

test('votes the book ignores never ask for a redraw', async () => {
    const player = hex();
    const { comp, poll, vote, settle } = channel({ counting: new Set([player]) });
    const real = poll(player);
    comp.receive(real);
    await settle();
    const held = comp.tallies[real.id];

    comp.receive(vote(real.id, player, 'zz9'));
    comp.receive(vote(real.id, player, 'a1', NOW - 5000));
    comp.receive(vote(hex(), player, 'a1'));
    await wait(1100);
    assert.equal(comp.tallies[real.id], held);
});

test('authors are looked up before voters, and a flood of voter keys only slows the voters down', async () => {
    const player = hex();
    const { comp, lookups, poll, vote, message, settle } = channel({ counting: new Set([player]) });
    const real = poll(player);
    comp.receive(real);
    await settle();
    const started = Date.now();

    for (let i = 0; i < 2000; i++) comp.receive(vote(real.id, hex()));
    await wait(700);
    // After 2000 voter keys the voters' bucket is spent: a new author still goes first.
    const author = hex();
    comp.receive(message(author, 'late author'));
    await wait(40);
    comp.flushItems();
    await wait(600);
    assert.ok(lookups.slice(1).some((batch) => batch.includes(author)), 'the late author was asked');
    const voterBatches = lookups.slice(1).filter((batch) => !batch.includes(author));
    const askedVoters = voterBatches.flat().length;
    const seconds = (Date.now() - started) / 1000;
    assert.ok(askedVoters <= 300 + 30 * seconds + 100, `voters asked at the bucket's pace: ${askedVoters} in ${seconds.toFixed(1)} s`);
    assert.ok(askedVoters >= 300, 'the first bucket full was asked');
});

test('the viewer who counts sees their own poll at once; one who does not gets no poll form', async () => {
    const me = hex();
    const counting = channel({ me, meCounts: true });
    counting.comp.receive(counting.poll(me));
    await wait(40);
    assert.equal(counting.comp.items.length, 1);
    assert.equal(counting.comp.mayPoll, true);
    assert.equal(counting.lookups.length, 0, 'the viewer is never looked up');

    const other = channel({ me: hex(), meCounts: false });
    assert.equal(other.comp.mayPoll, false);
    other.comp.openPollForm();
    assert.equal(other.comp.composing, false);
});
