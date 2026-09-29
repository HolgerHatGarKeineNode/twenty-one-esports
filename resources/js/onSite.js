/**
 * "On the site" for notifications (App\Support\Notifications\OnSite): while
 * a logged-in page is visible it POSTs the `presence-user` meta's
 * `data-ping` URL once a minute, at once when it becomes visible again.
 * The server counts the player as on the site for three minutes after the
 * last ping and keeps push and DM back meanwhile; a hidden or closed tab
 * stops pinging, so they go out again. One timer per window: a
 * wire:navigate keeps it.
 */

const PING_MS = 60_000;
// The first one waits for the page's own start-up requests (Livewire, the websocket's auth).
const FIRST_PING_MS = 2_000;

export function startOnSitePing() {
    const meta = document.querySelector('meta[name="presence-user"]');
    const url = meta?.dataset.ping;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!url || !token || window.esportsOnSite) return;

    let last = 0;
    const ping = () => {
        if (document.visibilityState !== 'visible' || Date.now() - last < PING_MS / 2) return;
        last = Date.now();
        fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' }).catch(() => {});
    };

    window.esportsOnSite = { ping };
    setTimeout(ping, FIRST_PING_MS);
    setInterval(ping, PING_MS);
    document.addEventListener('visibilitychange', ping);
}
