/**
 * The live stream's status on every shell page (P20b): one poller per
 * window feeding `Alpine.store('live')` = { live, viewers }, which the
 * header badge, the More sheet, the floating player and /live read.
 *
 * Seeded from the JSON the server rendered with the page (<x-live-player>,
 * [data-live-feed]), so the first paint needs no request. Then GET `url`
 * (/stream/status) every `interval` seconds while the page is visible: paused
 * while it is hidden and asked at once when it comes back; after a failed
 * request the pause doubles, up to 5 minutes. A wire:navigate keeps the
 * window and with it the one poller. `viewers` is null when the stream
 * shares no count, and always off air: nothing shows then, never a 0.
 */

const MAX_BACKOFF_MS = 300_000;

export function readSeed() {
    const el = document.querySelector('[data-live-feed]');
    if (!el) return null;

    try {
        const seed = JSON.parse(el.textContent);

        return seed && typeof seed.url === 'string' ? seed : null;
    } catch {
        return null;
    }
}

function normalise(data) {
    const live = data?.live === true;
    const viewers = live && Number.isInteger(data?.viewers) && data.viewers >= 0 ? data.viewers : null;

    return { live, viewers };
}

export function liveStore(seed) {
    return {
        ...normalise(seed ?? {}),
        /** Counts the changes of a shown count, so a number can tick once when it changes. */
        ticks: 0,

        set(data) {
            const next = normalise(data);
            if (next.viewers !== null && this.viewers !== null && next.viewers !== this.viewers) this.ticks += 1;
            this.live = next.live;
            this.viewers = next.viewers;
        },

        /**
         * Replays the count's tick on `el` after a change (x-effect). A CSS
         * animation, so reduced motion switches it off with everything else.
         */
        tick(el) {
            if (this.ticks === 0) return;
            el.classList.remove('live-tick');
            void el.offsetWidth;
            el.classList.add('live-tick');
        },
    };
}

export function startLiveFeed(seed, store) {
    if (!seed || window.__liveFeed) return;

    const interval = Math.max(1, Number(seed.interval) || 15) * 1000;
    let failures = 0;
    let timer = null;
    let busy = false;

    const schedule = (ms) => {
        clearTimeout(timer);
        timer = setTimeout(tick, ms);
    };

    async function tick() {
        // Hidden: nothing is asked; visibilitychange asks again when the page is back.
        if (document.visibilityState !== 'visible' || busy) return;
        busy = true;

        try {
            const response = await fetch(seed.url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'omit' });
            if (!response.ok) throw new Error('status ' + response.status);
            store.set(await response.json());
            failures = 0;
        } catch {
            failures += 1;
        } finally {
            busy = false;
        }

        schedule(failures === 0 ? interval : Math.min(MAX_BACKOFF_MS, interval * 2 ** failures));
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            schedule(0);
        } else {
            clearTimeout(timer);
        }
    });

    window.__liveFeed = { tick: () => schedule(0), failures: () => failures };
    schedule(interval);
}
