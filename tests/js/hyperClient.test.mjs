/**
 * The Hyperbitcoinization page's pure parts (plan "Hyperbitcoinization", P2): which plies the page still has
 * to show (resources/js/hyper/sync.js), the table between snapshots (mirror.js) and the reactions under a
 * chat message (reactions.js). Run by tests/Feature/Hyper/HyperClientScriptTest.php; runnable alone with
 * `node --test tests/js/hyperClient.test.mjs`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PlySync } from '../../resources/js/hyper/sync.js';
import { applyEvent, fromSnapshot, units } from '../../resources/js/hyper/mirror.js';
import { REACTIONS, addReaction, countsFor, reactionTarget, reactionTemplate } from '../../resources/js/hyper/reactions.js';

test('an action answer and its broadcast are shown once, whichever arrives first', () => {
    const answerFirst = new PlySync(7);
    assert.equal(answerFirst.offer({ from: 7, to: 8, events: [{ type: 'placed' }] }).type, 'take');
    assert.equal(answerFirst.offer({ from: 7, to: 8, events: [{ type: 'placed' }] }).type, 'drop');

    const broadcastFirst = new PlySync(7);
    assert.equal(broadcastFirst.offer({ from: 7, to: 8, events: [] }).type, 'take');
    assert.equal(broadcastFirst.offer({ from: 7, to: 8, events: [] }).type, 'drop');
    assert.equal(broadcastFirst.ply, 8);
});

test('a missed broadcast leaves a gap that only the catch-up fills, in order, without holes or repeats', () => {
    const sync = new PlySync(3);
    // A whole bot turn (plies 6..9) arrives while 4 and 5 were missed: nothing is taken, the page asks after 3.
    assert.deepEqual(sync.offer({ from: 5, to: 9, events: [{ type: 'turn_ended' }] }), { type: 'gap', after: 3 });
    // A truncated broadcast carries no events: also a gap.
    assert.equal(sync.offer({ from: 3, to: 4, events: null }).type, 'gap');

    const { batches, more } = sync.catchUp({ ply: 9, actions: [4, 5, 6, 8].map((ply) => ({ ply, seat: 1, source: 'bot', events: [{ type: 'moved', ply }] })) });
    // 7 is missing in this answer: the queue stops before the hole and says there is more.
    assert.deepEqual(batches.map((b) => [b.from, b.to]), [[3, 4], [4, 5], [5, 6]]);
    assert.equal(more, true);

    const second = sync.catchUp({ ply: 9, actions: [5, 6, 7, 8, 9].map((ply) => ({ ply, seat: 1, source: 'bot', events: [] })) });
    assert.deepEqual(second.batches.map((b) => b.to), [7, 8, 9]);
    assert.equal(second.more, false);
    // The broadcast for 6..9 that comes late is seen already.
    assert.equal(sync.offer({ from: 5, to: 9, events: [] }).type, 'drop');
});

function table() {
    const territories = {};
    for (const id of ['ny', 'mexiko', 'texas', 'irland']) territories[id] = { owner: null, pleb: 0, maxi: 0, asic: 0, shield: null };
    Object.assign(territories.ny, { owner: 0, pleb: 5, asic: 1, maxi: 1 });
    Object.assign(territories.mexiko, { owner: 1, pleb: 2, maxi: 1 });
    Object.assign(territories.texas, { owner: 0, pleb: 1 });

    return fromSnapshot({
        id: 'm', ply: 4, status: 'active', seat: 0, me: 0,
        seats: [{ seat: 0, faction: 'bitcoiner', bot: false, user_id: 1, name: 'Anna' }, { seat: 1, faction: 'fed', bot: true, user_id: null, name: null }],
        state: { round: 2, phase: 'attack', territories, seats: [{ faction: 'bitcoiner', fiat: 4, sats: 6, loot: 6, hand: ['attack51'], hand_count: 1, free_plebs: 0 }, { faction: 'fed', fiat: 1, sats: 2, loot: 2, hand: null, hand_count: 2, free_plebs: 0 }] },
    });
}

test('the mirror writes the server’s numbers: losses plebs first, a conquest moves the units, placements cost what they say', () => {
    const G = table();
    applyEvent(G, { type: 'dice_rolled', seat: 0, from: 'ny', to: 'mexiko', attacker_losses: 1, defender_losses: 2 });
    assert.deepEqual([G.terr.ny.pleb, G.terr.mexiko.pleb, G.terr.mexiko.maxi], [4, 0, 1]);
    applyEvent(G, { type: 'dice_rolled', seat: 0, from: 'ny', to: 'mexiko', attacker_losses: 0, defender_losses: 1 });
    assert.equal(units(G, 'mexiko'), 0);
    applyEvent(G, { type: 'territory_conquered', seat: 0, territory: 'mexiko', from: 'ny', previous_owner: 1, units: 3 });
    assert.equal(G.terr.mexiko.owner, 0);
    assert.equal(units(G, 'mexiko'), 3);
    // Plebs move first, then ASICs: 4 plebs + 1 ASIC + 1 maxi at home, 3 plebs go.
    assert.deepEqual([G.terr.ny.pleb, G.terr.ny.asic, G.terr.ny.maxi], [1, 1, 1]);

    applyEvent(G, { type: 'placed', seat: 0, territory: 'texas', unit: 'pleb', count: 2, source: 'fiat', cost: 2.5, bonus: 1 });
    assert.equal(G.terr.texas.pleb, 4);
    assert.equal(G.seats[0].fiat, 1.5);
    applyEvent(G, { type: 'placed', seat: 0, territory: 'texas', unit: 'asic', count: 1, source: 'sats', cost: 3, bonus: 0 });
    assert.equal(G.seats[0].sats, 3);
});

test('the mirror plays cards as their events say: a 51% attack from a lone unit, a hand that shrinks, an elimination', () => {
    const G = table();
    applyEvent(G, { type: 'card_played', seat: 0, card: 'attack51', target: 'mexiko', effect: { from: 'texas' } });
    assert.deepEqual([G.terr.mexiko.owner, units(G, 'mexiko')], [0, 1]);
    assert.deepEqual(G.seats[0].hand, []);
    applyEvent(G, { type: 'player_eliminated', seat: 1, by: 0, cards: 2 });
    assert.equal(G.seats[1].out, true);
    assert.equal(G.seats[0].handCount, 2);
    applyEvent(G, { type: 'game_won', seat: 0, by_limit: false, round: 2, standings: [0], loot: [7.5, 2] });
    assert.deepEqual([G.over, G.winner, G.seats[0].loot], [true, 0, 7.5]);
});

test('reactions count once per pubkey and emoji, only in this channel, only from the set, never hidden keys', () => {
    const channel = 'c'.repeat(64);
    const message = { id: 'a'.repeat(64), pubkey: 'b'.repeat(64) };
    const template = reactionTemplate(message, '🔥', { channel, relayHint: 'wss://r' });
    assert.deepEqual(template.tags, [['e', channel, 'wss://r', 'root'], ['e', message.id, 'wss://r'], ['p', message.pubkey], ['k', '42']]);
    assert.equal(template.kind, 7);

    const event = (pubkey, content, tags = template.tags) => ({ kind: 7, pubkey, content, tags });
    const book = new Map();
    const anna = '1'.repeat(64);
    const bert = '2'.repeat(64);
    assert.equal(addReaction(book, event(anna, '🔥'), { channel }), message.id);
    assert.equal(addReaction(book, event(anna, '🔥'), { channel }), null, 'the same pubkey and emoji counts once');
    assert.equal(addReaction(book, event(bert, '🔥'), { channel }), message.id);
    assert.equal(addReaction(book, event(bert, '😂'), { channel }), message.id);
    assert.equal(addReaction(book, event(bert, '👍'), { channel }), null, 'outside the set');
    assert.equal(reactionTarget(event(anna, '🔥', [['e', 'd'.repeat(64)], ['e', message.id]]), channel), null, 'another channel');
    assert.equal(reactionTarget(event(anna, '🔥', [['e', message.id]]), channel), null, 'no channel root');

    assert.deepEqual(countsFor(book, message.id, { me: anna }), [{ emoji: '🔥', count: 2, mine: true }, { emoji: '😂', count: 1, mine: false }]);
    assert.deepEqual(countsFor(book, message.id, { me: anna, hidden: new Set([bert]) }), [{ emoji: '🔥', count: 1, mine: true }]);
    assert.equal(REACTIONS.length, 6);
});
