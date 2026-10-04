/**
 * The socket-aware poll and the shell's one dispatcher (performance plan P3):
 * resources/js/livePoll.js and resources/js/playerEvents.js, on a fake clock,
 * a fake document and a fake Pusher connection. Run by
 * tests/Feature/ShellLiveUpdatesTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

/** A fake page: timers on a virtual clock, document.hidden, and window.Echo's Pusher connection (or none). */
function page({ socket = null } = {}) {
    const timers = new Map();
    let now = 0;
    let next = 1;
    const listeners = {};
    const doc = { hidden: false, readyState: 'complete', listeners: {} };
    doc.addEventListener = (type, fn) => ((doc.listeners[type] ??= new Set()).add(fn));
    doc.removeEventListener = (type, fn) => doc.listeners[type]?.delete(fn);

    globalThis.setTimeout = (fn, ms) => {
        const id = next++;
        timers.set(id, { fn, at: now + ms });

        return id;
    };
    globalThis.clearTimeout = (id) => timers.delete(id);
    globalThis.document = doc;
    globalThis.window = {
        addEventListener: (type, fn) => ((listeners[type] ??= new Set()).add(fn)),
        removeEventListener: (type, fn) => listeners[type]?.delete(fn),
        dispatch: (type) => listeners[type]?.forEach((fn) => fn()),
        Echo: socket === null ? undefined : { connector: { pusher: { connection: socket } }, private: socket.privateChannel },
    };

    return {
        doc,
        /** Move the clock by `ms`, firing every timer due on the way, in order. */
        advance(ms) {
            const target = now + ms;
            for (;;) {
                const due = [...timers.entries()].filter(([, t]) => t.at <= target).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) break;
                timers.delete(due[0]);
                now = due[1].at;
                due[1].fn();
            }
            now = target;
        },
        hide(hidden) {
            doc.hidden = hidden;
            doc.listeners.visibilitychange?.forEach((fn) => fn());
        },
    };
}

/** A fake Pusher connection whose state the test changes. */
function connection(state) {
    const handlers = new Set();
    const channel = { events: {}, listen(event, fn) { (this.events[event] ??= []).push(fn); return this; }, stopListening() { return this; } };

    return {
        state,
        channel,
        privateChannel: () => channel,
        bind: (event, fn) => handlers.add(fn),
        unbind: (event, fn) => handlers.delete(fn),
        set(current) {
            this.state = current;
            handlers.forEach((fn) => fn({ current }));
        },
        push(event, payload = {}) {
            channel.events[event]?.forEach((fn) => fn(payload));
        },
    };
}

const { livePoll } = await import('../../resources/js/livePoll.js');

test('without a socket it asks every `seconds`; with one connected only every `withSocket`', () => {
    const p = page();
    let runs = 0;
    const stop = livePoll({ seconds: 30, withSocket: 120, run: () => runs++ });
    p.advance(60_000);
    assert.equal(runs, 2);
    stop();

    const socket = connection('connected');
    const q = page({ socket });
    runs = 0;
    livePoll({ seconds: 30, withSocket: 120, run: () => runs++ });
    q.advance(119_000);
    assert.equal(runs, 0);
    q.advance(1_000);
    assert.equal(runs, 1);
});

test('withSocket 0 never asks while connected; the socket going away brings the fallback back', () => {
    const socket = connection('connected');
    const p = page({ socket });
    let runs = 0;
    livePoll({ seconds: 15, withSocket: 0, run: () => runs++ });
    p.advance(300_000);
    assert.equal(runs, 0);

    socket.set('unavailable');
    p.advance(45_000);
    assert.equal(runs, 3);

    // Back after the gap: one catch-up at once (a push may have been lost), then quiet again.
    socket.set('connected');
    assert.equal(runs, 4);
    p.advance(300_000);
    assert.equal(runs, 4);
});

test('a hidden tab asks nothing, and once when it comes back', () => {
    const p = page();
    let runs = 0;
    livePoll({ seconds: 30, withSocket: 120, run: () => runs++ });
    p.hide(true);
    p.advance(300_000);
    assert.equal(runs, 0);

    p.hide(false);
    assert.equal(runs, 1);
    p.advance(30_000);
    assert.equal(runs, 2);
});

test('stop() ends the poll and its listeners', () => {
    const socket = connection('unavailable');
    const p = page({ socket });
    let runs = 0;
    const stop = livePoll({ seconds: 10, withSocket: 0, run: () => runs++ });
    stop();
    p.advance(60_000);
    socket.set('connected');
    p.hide(true);
    p.hide(false);
    assert.equal(runs, 0);
    assert.equal(p.doc.listeners.visibilitychange.size, 0);
});

test('the shell dispatcher hands every subscriber the same batch in one task, from one channel listener', async () => {
    const socket = connection('connected');
    const p = page({ socket });
    const { subscribePlayerEvents, BATCH_MS } = await import('../../resources/js/playerEvents.js');
    const seen = [];
    const config = { userId: 7, poll: 20, pollWithSocket: 120 };
    const offDock = subscribePlayerEvents(config, (reasons) => seen.push(['dock', [...reasons].sort().join(',')]));
    const offBell = subscribePlayerEvents(config, (reasons) => seen.push(['bell', [...reasons].sort().join(',')]));

    // One listener per event on the channel, however many subscribe.
    assert.equal(socket.channel.events['.series.changed'].length, 1);

    socket.push('.series.changed');
    window.dispatch('esports-notification');
    p.advance(BATCH_MS - 1);
    assert.deepEqual(seen, []);
    p.advance(1);
    assert.deepEqual(seen, [['dock', 'notification,series'], ['bell', 'notification,series']]);

    // The slow poll is shared too: one batch every 120 s with the socket connected.
    seen.length = 0;
    p.advance(120_000 + BATCH_MS);
    assert.deepEqual(seen, [['dock', 'poll'], ['bell', 'poll']]);

    offDock();
    offBell();
    seen.length = 0;
    socket.push('.series.changed');
    p.advance(300_000);
    assert.deepEqual(seen, []);
});
