<?php

namespace Tests\Support;

/**
 * The page-side counter of Livewire roundtrips on a virtual clock (performance plan P1c, described in
 * tests/Browser/LivewireTrafficTest.php): `window.__traffic.advance(ms)`, `mark()`, `since(mark)`.
 * Shared by the traffic tests (P1/P3 pages, P7 toggles and searches).
 */
final class LivewireTraffic
{
    public const PROBE = <<<'JS'
    (() => {
        const MIN = 100;
        const realSetTimeout = window.setTimeout.bind(window);
        const realClearTimeout = window.clearTimeout.bind(window);
        const realSetInterval = window.setInterval.bind(window);
        const realClearInterval = window.clearInterval.bind(window);
        const RealDate = Date;
        // The page's calendar starts at the instant the server's clock is frozen on (the test sets window.__trafficBase): the countdowns
        // the dock counts down to (a check-in, a series start) then end after the same virtual seconds in every run, whatever the real time
        // between the fixture and the page's first timer was.
        const base = typeof window.__trafficBase === 'number' ? window.__trafficBase : null;
        const realPerformanceNow = performance.now.bind(performance);
        const queue = new Map();
        let virtualNow = 0;
        let nextId = 1_000_000_000;
        let sequence = 0;

        // The virtual clock: timers of MIN ms or more wait for advance().
        const schedule = (fn, delay, args, every) => {
            const id = nextId++;
            queue.set(id, { id, fn, args, at: virtualNow + delay, every, order: sequence++ });
            return id;
        };
        window.setTimeout = (fn, delay = 0, ...args) => (typeof fn === 'function' && Number(delay) >= MIN ? schedule(fn, Number(delay), args, 0) : realSetTimeout(fn, delay, ...args));
        window.setInterval = (fn, delay = 0, ...args) => (typeof fn === 'function' && Number(delay) >= MIN ? schedule(fn, Number(delay), args, Number(delay)) : realSetInterval(fn, delay, ...args));
        window.clearTimeout = (id) => (queue.has(id) ? queue.delete(id) : realClearTimeout(id));
        window.clearInterval = (id) => (queue.has(id) ? queue.delete(id) : realClearInterval(id));
        window.Date = class extends RealDate {
            constructor(...args) { args.length ? super(...args) : super((base ?? RealDate.now()) + virtualNow); }
            static now() { return (base ?? RealDate.now()) + virtualNow; }
        };
        performance.now = () => realPerformanceNow() + virtualNow;

        const sleep = (ms) => new Promise((resolve) => realSetTimeout(resolve, ms));
        const roundtrips = [];
        const open = new Set();
        const other = {};
        const bytes = (text) => new TextEncoder().encode(typeof text === 'string' ? text : '').length;

        const originalFetch = window.fetch;
        window.fetch = (...args) => {
            const url = new URL(String(args[0]?.url ?? args[0]), location.href);
            if (! url.pathname.includes('livewire')) other[url.pathname] = (other[url.pathname] ?? 0) + 1;
            return originalFetch(...args);
        };

        // Actions called and not yet answered, from the moment of the call: a request is only built after Livewire's
        // real 5 ms buffer, which a loaded machine stretches past the settle's first sleep (measured 2026-10-09: a
        // push's refreshLive missing from its window). Deferred actions wait for a later one and are not counted.
        let actions = 0;

        document.addEventListener('livewire:init', () => {
            Livewire.interceptAction(({ action, onFinish, onCancel, onFailure }) => {
                let done = false;
                const finish = () => { if (! done) { done = true; actions--; } };
                actions++;
                onFinish(finish);
                onCancel(finish);
                onFailure(finish);
                queueMicrotask(() => { if (action.isDeferred()) finish(); });
            });
            Livewire.interceptRequest(({ request, onSend, onResponse, onSuccess, onError, onFailure, onFinish }) => {
                const record = { at: virtualNow, what: '', sent: 0, received: 0, status: null };
                open.add(record);
                onSend(() => {
                    record.sent = bytes(request.options?.body);
                    record.what = [...request.messages].map((message) => message.component.name + ':' + ([...message.actions].map((action) => action.name).join('+') || 'render')).join(',');
                });
                onResponse(({ response }) => { record.status = response.status; });
                onSuccess(({ body }) => { record.received = bytes(body); });
                onError(({ body }) => { record.received = bytes(body); });
                onFailure(() => { record.status = record.status ?? 0; open.delete(record); });
                onFinish(() => open.delete(record));
                roundtrips.push(record);
            });
        });

        // The requests this instant started are answered before the next timer fires.
        const settle = async () => {
            await sleep(12);
            for (let waited = 0; (open.size > 0 || actions > 0) && waited < 5000; waited += 10) await sleep(10);
            await sleep(4);
        };

        window.__traffic = {
            async advance(ms) {
                const target = virtualNow + ms;
                for (;;) {
                    let next = null;
                    for (const timer of queue.values()) {
                        if (timer.at <= target && (next === null || timer.at < next.at || (timer.at === next.at && timer.order < next.order))) next = timer;
                    }
                    if (next === null) break;
                    virtualNow = Math.max(virtualNow, next.at);
                    if (next.every > 0) { next.at += next.every; next.order = sequence++; } else queue.delete(next.id);
                    try { next.fn(...next.args); } catch (error) { realSetTimeout(() => { throw error; }, 0); }
                    // Timers due at the same instant fire before the settle.
                    if (! [...queue.values()].some((timer) => timer.at === virtualNow)) await settle();
                }
                virtualNow = target;
                await settle();
            },
            mark() { return roundtrips.length; },
            since(mark) {
                const seen = roundtrips.slice(mark);
                const labels = {};
                seen.forEach((r) => { labels[r.what] = (labels[r.what] ?? 0) + 1; });
                return {
                    requests: seen.length,
                    sent: seen.reduce((sum, r) => sum + r.sent, 0),
                    received: seen.reduce((sum, r) => sum + r.received, 0),
                    statuses: seen.map((r) => r.status),
                    // When each one started, in virtual seconds since the page's clock began: what moved it shows in the spacing.
                    at: seen.map((r) => Math.round(r.at / 100) / 10),
                    labels,
                    // When each roundtrip left, in virtual ms since load: tells a poll from a one-off.
                    at: seen.map((r) => r.at),
                    other: { ...other },
                };
            },
            resetOther() { Object.keys(other).forEach((key) => delete other[key]); },
            pending: () => open.size + Math.max(0, actions),
            hasLivewire: () => typeof window.Livewire !== 'undefined' && typeof window.Alpine !== 'undefined',
        };
    })();
    JS;
}
