/**
 * "On the site" for notifications (App\Support\Notifications\OnSite): while
 * a logged-in page is visible it POSTs the `presence-user` meta's
 * `data-ping` URL every 30 s (the first 2 s after load, and at once when the
 * page becomes visible again). A hidden or closed page just stops; the
 * server counts the player as away 75 s after the last ping. While the
 * player is on the site the server sends them no push and no DM; the bell
 * and the toast reach them.
 *
 * No "left" request on hide or close: measured on 2026-09-30, any request
 * sent at that moment (a beacon, or a plain fetch) made the next page of
 * the in-process test server fail its image loads (SettingsTabsTest 5/5 →
 * 1/5 and 0/5 passing; without it 5/5). The short window does the same job
 * without a request at the moment the page goes.
 *
 * The server always answers 204 (OnSitePingController): `X-On-Site:
 * signed-out` (a tab left open after logging out) stops the pings for good,
 * `X-On-Site: slow` or a 429 doubles the pause, up to 10 minutes. A
 * redirect, 401 or 419 stops them too. One timer per window: a
 * wire:navigate keeps it.
 */

const PING_MS = 30_000;
// The first one waits for the page's own start-up requests (Livewire, the websocket's auth).
const FIRST_PING_MS = 2_000;
const MAX_PAUSE_MS = 600_000;

export function startOnSitePing() {
    const meta = document.querySelector('meta[name="presence-user"]');
    const url = meta?.dataset.ping;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!url || !token || window.esportsOnSite) return;

    let last = 0;
    let pause = PING_MS;
    let timer = null;
    let stopped = false;

    const schedule = (ms) => {
        clearTimeout(timer);
        if (!stopped) timer = setTimeout(ping, ms);
    };

    const stop = () => {
        stopped = true;
        clearTimeout(timer);
    };

    async function ping() {
        if (stopped) return;
        if (document.visibilityState !== 'visible' || Date.now() - last < PING_MS / 2) {
            schedule(pause);
            return;
        }
        last = Date.now();

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                credentials: 'same-origin',
                redirect: 'manual',
            });
            const state = response.headers.get('X-On-Site');

            if (state === 'signed-out' || response.type === 'opaqueredirect' || [401, 419].includes(response.status)) {
                stop();
                return;
            }

            pause = state === 'slow' || response.status === 429 ? Math.min(pause * 2, MAX_PAUSE_MS) : PING_MS;
        } catch {
            // Offline for a moment: try again at the next turn.
        }

        schedule(pause);
    }

    window.esportsOnSite = { ping, stopped: () => stopped };
    schedule(FIRST_PING_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            last = 0;
            ping();
        }
    });
}
