/**
 * Replies from other NIP-17 clients in the match room and the game chat
 * (resources/js/nostrChat.js, roomChat.js, gameChat.js, dmInbox.js).
 *
 * Amethyst writes a kind-14 rumor with `p` tags only (its swipe reply adds a
 * marked `e`), never the league's `match` tag, and publishes each gift wrap
 * to the recipient's DM relays (kind 10050) only, falling back to other
 * relays only without a 10050 (Amethyst EventBroadcaster
 * computeRelayListToBroadcast). So the room has to read on the player's own
 * 10050 relays, publish to each member's 10050 relays, and show an untagged
 * rumor that belongs to the room, and nothing else.
 *
 * Every negative case asserts the whole shown list next to a legitimate
 * reply, so it fails as long as untagged replies are not shown at all.
 * Run by tests/Feature/Series/RoomDmRepliesTest.php; runnable alone with
 * `node --test`.
 */
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { finalizeEvent, generateSecretKey, getEventHash, getPublicKey } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';

const store = new Map();
globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
globalThis.window = { addEventListener() {}, removeEventListener() {} };
globalThis.document = { addEventListener() {}, querySelector: () => null };

const { DM_GRACE, gameMessages, isUntagged, makeGroupRumor, makeRumor, roomMessages } = await import('../../resources/js/nostrChat.js');
const { roomChat } = await import('../../resources/js/roomChat.js');
const { gameChat } = await import('../../resources/js/gameChat.js');

function keySigner(secret) {
    return {
        getPublicKey: async () => getPublicKey(secret),
        signEvent: async (template) => finalizeEvent(template, secret),
        nip44: {
            encrypt: async (pubkey, text) => nip44.encrypt(text, nip44.getConversationKey(secret, pubkey)),
            decrypt: async (pubkey, payload) => nip44.decrypt(payload, nip44.getConversationKey(secret, pubkey)),
        },
    };
}

const keys = Object.fromEntries(['me', 'opp', 'mate', 'opp2', 'stranger'].map((name) => [name, generateSecretKey()]));
const pk = Object.fromEntries(Object.entries(keys).map(([name, secret]) => [name, getPublicKey(secret)]));
const NOW = Math.floor(Date.now() / 1000);
const CREATED = NOW - 3 * 86400;

/** A kind-14 rumor the way Amethyst's ChatMessageEvent.build / .reply writes it: `p` (and `e`), no `match`. */
function amethyst(author, { to, at, replyTo = null, content = 'gg, see you in the lobby' }) {
    const tags = [...(replyTo ? [['e', replyTo, 'reply', pk.me]] : []), ...to.map((p) => ['p', p])];
    const plain = { pubkey: author, created_at: at, kind: 14, tags, content };

    return { ...plain, id: getEventHash(plain) };
}

const texts = (list) => list.map((r) => r.content);

/* ---------- Which untagged rumors the room shows ---------- */

const ROOM = { me: pk.me, members: [pk.me, pk.opp], match: 53, dm: { opponents: [pk.opp], since: CREATED, settled: null } };
const roomHello = makeGroupRumor({ sender: pk.me, recipients: [pk.me, pk.opp], content: 'room hello', match: 53, now: CREATED + 100 });

test('an Amethyst reply without a match tag from the opponent, inside the window, shows in the room and is marked', () => {
    const plain = amethyst(pk.opp, { to: [pk.me], at: CREATED + 200, content: 'plain reply' });
    // Amethyst's swipe reply: the replied message's recipients plus its author, and a marked `e`.
    const swipe = amethyst(pk.opp, { to: [pk.opp, pk.me], at: CREATED + 300, replyTo: roomHello.id, content: 'swipe reply' });

    const shown = roomMessages([swipe, roomHello, plain], ROOM);

    assert.deepEqual(texts(shown), ['room hello', 'plain reply', 'swipe reply']);
    assert.deepEqual(shown.map(isUntagged), [false, true, true]);
});

test('an untagged DM from a non-member does not show; the opponent\'s identical one does', () => {
    const fromOpp = amethyst(pk.opp, { to: [pk.me], at: CREATED + 200, content: 'from opp' });
    const fromStranger = amethyst(pk.stranger, { to: [pk.me], at: CREATED + 200, content: 'from stranger' });
    // A stranger who copies the room's p set still is no member.
    const strangerToRoom = amethyst(pk.stranger, { to: [pk.me, pk.opp], at: CREATED + 210, content: 'stranger to room' });

    assert.deepEqual(texts(roomMessages([roomHello, fromStranger, strangerToRoom, fromOpp], ROOM)), ['room hello', 'from opp']);
});

test('an untagged DM outside the match window does not show: before the match, or after it settled plus the grace', () => {
    const settled = CREATED + 1000;
    const room = { ...ROOM, dm: { ...ROOM.dm, settled } };
    const early = makeGroupRumor({ sender: pk.me, recipients: [pk.me, pk.opp], content: 'old room', match: 53, now: CREATED - 500 });
    const before = amethyst(pk.opp, { to: [pk.me], at: CREATED - 10, content: 'before' });
    const inGrace = amethyst(pk.opp, { to: [pk.me], at: settled + DM_GRACE, content: 'in grace' });
    const after = amethyst(pk.opp, { to: [pk.me], at: settled + DM_GRACE + 1, content: 'after' });

    assert.deepEqual(texts(roomMessages([early, roomHello, before, inGrace, after], room)), ['old room', 'room hello', 'in grace']);
    // An unknown match start shows no untagged rumor at all.
    assert.deepEqual(texts(roomMessages([roomHello, inGrace], { ...room, dm: { ...room.dm, since: null } })), ['room hello']);
});

test('an untagged DM to a different p set does not show: an extra recipient, or a member left out of a group room', () => {
    const extra = amethyst(pk.opp, { to: [pk.me, pk.stranger], at: CREATED + 200, content: 'extra' });
    const exact = amethyst(pk.opp, { to: [pk.me], at: CREATED + 210, content: 'exact' });

    assert.deepEqual(texts(roomMessages([roomHello, extra, exact], ROOM)), ['room hello', 'exact']);

    // 2v2: me + mate against opp + opp2. A 1:1 between me and opp is another conversation.
    const group = { me: pk.me, members: [pk.me, pk.mate, pk.opp, pk.opp2], match: 54, dm: { opponents: [pk.opp, pk.opp2], since: CREATED, settled: null } };
    const groupHello = makeGroupRumor({ sender: pk.mate, recipients: group.members, content: 'group hello', match: 54, now: CREATED + 100 });
    const oneToOne = amethyst(pk.opp, { to: [pk.me], at: CREATED + 200, content: 'just to me' });
    const toAll = amethyst(pk.opp, { to: [pk.me, pk.mate, pk.opp2], at: CREATED + 210, content: 'to all' });

    assert.deepEqual(texts(roomMessages([groupHello, oneToOne, toAll], group)), ['group hello', 'to all']);
});

test('an untagged DM belongs to the room of the tagged message it answers, or else of the newest tagged message before it', () => {
    const otherRoom = makeGroupRumor({ sender: pk.opp, recipients: [pk.me, pk.opp], content: 'match 60 hello', match: 60, now: CREATED + 400 });
    const noAnchorYet = amethyst(pk.opp, { to: [pk.me], at: CREATED + 50, content: 'before any room message' });
    const afterHello = amethyst(pk.opp, { to: [pk.me], at: CREATED + 200, content: 'after hello' });
    const afterOther = amethyst(pk.opp, { to: [pk.me], at: CREATED + 500, content: 'after match 60' });
    // Written after match 60's message, but answering the room's own message.
    const answersHello = amethyst(pk.opp, { to: [pk.opp, pk.me], at: CREATED + 600, replyTo: roomHello.id, content: 'answers hello' });
    const all = [roomHello, otherRoom, noAnchorYet, afterHello, afterOther, answersHello];

    assert.deepEqual(texts(roomMessages(all, ROOM)), ['room hello', 'after hello', 'answers hello']);
    assert.deepEqual(texts(roomMessages(all, { ...ROOM, match: 60 })), ['match 60 hello', 'after match 60']);
});

test('only the opponents\' untagged rumors show, and a muted opponent\'s are hidden like any message', () => {
    const group = { me: pk.me, members: [pk.me, pk.mate, pk.opp, pk.opp2], match: 54, dm: { opponents: [pk.opp, pk.opp2], since: CREATED, settled: null } };
    const groupHello = makeGroupRumor({ sender: pk.me, recipients: group.members, content: 'group hello', match: 54, now: CREATED + 100 });
    const fromMate = amethyst(pk.mate, { to: [pk.me, pk.opp, pk.opp2], at: CREATED + 200, content: 'from mate' });
    const fromMe = amethyst(pk.me, { to: [pk.mate, pk.opp, pk.opp2], at: CREATED + 210, content: 'from me' });
    const fromOpp = amethyst(pk.opp, { to: [pk.me, pk.mate, pk.opp2], at: CREATED + 220, content: 'from opp' });
    const fromOpp2 = amethyst(pk.opp2, { to: [pk.me, pk.mate, pk.opp], at: CREATED + 230, content: 'from opp2' });
    const all = [groupHello, fromMate, fromMe, fromOpp, fromOpp2];

    assert.deepEqual(texts(roomMessages(all, group)), ['group hello', 'from opp', 'from opp2']);
    assert.deepEqual(texts(roomMessages(all, { ...group, muted: [pk.opp] })), ['group hello', 'from opp2']);
});

test('the game chat shows the opponent\'s untagged reply under the same rule', () => {
    const game = { me: pk.me, opponent: pk.opp, match: 7, dm: { since: CREATED, settled: CREATED + 2000 } };
    const hello = makeRumor({ sender: pk.me, recipient: pk.opp, content: 'game hello', match: 7, now: CREATED + 100 });
    const reply = amethyst(pk.opp, { to: [pk.me], at: CREATED + 200, content: 'game reply' });
    const swipe = amethyst(pk.opp, { to: [pk.opp, pk.me], at: CREATED + 300, replyTo: hello.id, content: 'game swipe' });
    const stranger = amethyst(pk.stranger, { to: [pk.me], at: CREATED + 210, content: 'stranger' });
    const late = amethyst(pk.opp, { to: [pk.me], at: CREATED + 2000 + DM_GRACE + 5, content: 'late' });

    const shown = gameMessages([hello, reply, swipe, stranger, late], game);

    assert.deepEqual(texts(shown), ['game hello', 'game reply', 'game swipe']);
    assert.deepEqual(texts(gameMessages([hello, reply], { ...game, muted: [pk.opp] })), ['game hello']);
});

/* ---------- Relays: reading on my 10050, writing to each recipient's 10050 ---------- */

/**
 * Fake relays for the 10050 lookup (resources/js/relayRead.js opens one
 * socket per relay): `lists[url]` are the events the relay holds; every REQ
 * is answered with the matching events and EOSE, on the microtask queue.
 */
function fakeRelays(lists) {
    const asked = [];
    globalThis.WebSocket = class {
        constructor(url) {
            this.url = url;
            queueMicrotask(() => (lists[url] ? this.onopen?.() : this.onerror?.({})));
        }

        send(text) {
            const [type, sub, ...filters] = JSON.parse(text);
            if (type !== 'REQ') return;
            asked.push({ url: this.url, filters });
            const reply = (frame) => queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(frame) }));
            for (const event of lists[this.url]) {
                if (filters.some((f) => f.kinds.includes(event.kind) && f.authors.includes(event.pubkey))) reply(['EVENT', sub, event]);
            }
            reply(['EOSE', sub]);
        }

        close() {}
    };

    return asked;
}

function fakePool() {
    const subs = [];
    const published = [];

    return {
        subs,
        published,
        pool: {
            subscribe: (relays, filter) => {
                subs.push({ relays: [...relays], filter });
                return { close() {} };
            },
            publish: (relays, event) => {
                published.push({ relays: [...relays], event });
                return relays.map(() => Promise.resolve(''));
            },
            destroy() {},
        },
    };
}

const inbox = (who, relays, at = NOW - 60) => finalizeEvent({ kind: 10050, created_at: at, tags: relays.map((r) => ['relay', r]), content: '' }, keys[who]);
const settle = async () => { for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 5)); };
const made = [];
afterEach(() => made.splice(0).forEach((comp) => comp.destroy()));

const CHAT = ['wss://chat.one', 'wss://chat.two'];
const LOOKUP = ['wss://index.one'];

function room(extra = {}) {
    const fake = fakePool();
    window.nostr = keySigner(keys.me);
    const comp = roomChat({
        me: pk.me,
        members: [{ pubkey: pk.me, name: 'me', side: 'challenger' }, { pubkey: pk.opp, name: 'opp', side: 'challenged' }, { pubkey: pk.opp2, name: 'opp2', side: 'challenged' }],
        casual: null,
        match: 53,
        since: CREATED,
        settled: null,
        relays: CHAT,
        lookupRelays: LOOKUP,
        muted: [],
        labels: new Proxy({}, { get: (_, key) => String(key) }),
        ...extra,
    });
    comp.makePool = () => fake.pool;
    comp.init();
    made.push(comp);

    return { comp, ...fake };
}

test('the room subscribes on my own 10050 relays too, with the same #p filter and since', async () => {
    const asked = fakeRelays({
        'wss://index.one': [inbox('me', ['wss://my.inbox', 'wss://chat.one/'])],
        'wss://chat.one': [],
        'wss://chat.two': [],
    });
    const { comp, subs } = room();
    await settle();

    const relays = subs.flatMap((s) => s.relays);
    assert.ok(relays.includes('wss://my.inbox/'), 'my 10050 relay is read: ' + JSON.stringify(subs));
    assert.deepEqual([...new Set(subs.map((s) => JSON.stringify(s.filter)))], [JSON.stringify(subs[0].filter)]);
    assert.deepEqual(subs[0].filter['#p'], [pk.me]);
    assert.deepEqual(relays.filter((r) => r.includes('chat.one')).length, 1, 'a chat relay is not subscribed twice');
    // The 10050s are looked up on the lookup relays and the chat relays.
    assert.deepEqual([...new Set(asked.map((a) => a.url))].sort(), ['wss://chat.one', 'wss://chat.two', 'wss://index.one']);
    assert.equal(comp.status, 'live');

    // What the room draws: the opponents' (other side's) untagged reply, marked, its text untouched.
    const hello = makeGroupRumor({ sender: pk.me, recipients: [pk.me, pk.opp, pk.opp2], content: 'room hello', match: 53, now: CREATED + 100 });
    comp.rumors.push(hello, amethyst(pk.opp, { to: [pk.me, pk.opp2], at: CREATED + 200, content: '<b>gg</b>' }), amethyst(pk.opp, { to: [pk.me], at: CREATED + 210, content: 'only me' }));
    assert.deepEqual(comp.messages.map((m) => [m.text, m.from, m.viaDm, m.card]), [['room hello', 'me', false, null], ['<b>gg</b>', 'them', true, null]]);
});

test('room wraps go to each recipient\'s 10050 relays as well as the chat relays; without a 10050 to the chat relays', async () => {
    fakeRelays({
        'wss://index.one': [inbox('opp', ['wss://opp.inbox']), inbox('me', ['wss://my.inbox'])],
        'wss://chat.one': [],
        'wss://chat.two': [],
    });
    const { comp, published } = room();
    comp.input = 'gl hf';
    await comp.send();

    const to = (who) => published.find((p) => p.event.tags.some((t) => t[0] === 'p' && t[1] === pk[who]));
    assert.deepEqual(to('opp').relays.sort(), ['wss://chat.one/', 'wss://chat.two/', 'wss://opp.inbox/']);
    assert.deepEqual(to('me').relays.sort(), ['wss://chat.one/', 'wss://chat.two/', 'wss://my.inbox/']);
    assert.deepEqual(to('opp2').relays.sort(), ['wss://chat.one/', 'wss://chat.two/']);
    assert.equal(comp.error, '');
});

test('a 10050 is trimmed: only wss relays on public hosts (or configured ones), at most five', async () => {
    const many = ['ws://plain.example', 'wss://127.0.0.1:7777', 'wss://localhost', 'wss://192.168.1.5', 'wss://[::1]', 'wss://a.one', 'wss://b.one', 'wss://c.one', 'wss://d.one', 'wss://e.one', 'wss://f.one'];
    fakeRelays({ 'wss://index.one': [inbox('opp', many)], 'wss://chat.one': [], 'wss://chat.two': [] });
    const { comp, published } = room();
    comp.input = 'hi';
    await comp.send();

    const opp = published.find((p) => p.event.tags.some((t) => t[0] === 'p' && t[1] === pk.opp));
    assert.deepEqual(opp.relays.sort(), ['wss://a.one/', 'wss://b.one/', 'wss://c.one/', 'wss://chat.one/', 'wss://chat.two/', 'wss://d.one/', 'wss://e.one/']);
});

test('the game chat reads on my 10050 relays and sends the opponent\'s wrap to theirs', async () => {
    fakeRelays({
        'wss://index.one': [inbox('opp', ['wss://opp.inbox']), inbox('me', ['wss://my.inbox'])],
        'wss://chat.one': [],
        'wss://chat.two': [],
    });
    const fake = fakePool();
    window.nostr = keySigner(keys.me);
    const comp = gameChat({ me: pk.me, meName: 'me', opponent: { pubkey: pk.opp, name: 'opp' }, match: 7, since: CREATED, settled: null, relays: CHAT, lookupRelays: LOOKUP, muted: [], labels: new Proxy({}, { get: (_, key) => String(key) }) });
    comp.makePool = () => fake.pool;
    comp.$wire = { setMuted: async () => true };
    comp.init();
    made.push(comp);
    await settle();

    assert.ok(fake.subs.flatMap((s) => s.relays).includes('wss://my.inbox/'), JSON.stringify(fake.subs));

    const hello = makeRumor({ sender: pk.me, recipient: pk.opp, content: 'game hello', match: 7, now: CREATED + 100 });
    comp.rumors.push(hello, amethyst(pk.opp, { to: [pk.me], at: CREATED + 200, content: 'gg' }));
    assert.deepEqual(comp.messages.map((m) => [m.text, m.from, m.viaDm]), [['game hello', 'me', false], ['gg', 'them', true]]);

    comp.input = 'gl';
    await comp.send();
    const to = (who) => fake.published.find((p) => p.event.tags.some((t) => t[0] === 'p' && t[1] === pk[who]));
    assert.deepEqual(to('opp').relays.sort(), ['wss://chat.one/', 'wss://chat.two/', 'wss://opp.inbox/']);
    assert.deepEqual(to('me').relays.sort(), ['wss://chat.one/', 'wss://chat.two/', 'wss://my.inbox/']);
});
