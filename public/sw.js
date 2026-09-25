/*
 * Service worker for browser push (Web Push, RFC 8030): shows the league's
 * notifications (your move, deadline reminder, challenge, game over) and
 * opens the game when one is clicked. The payload is the JSON of
 * App\Support\Notifications\Notice::toPushPayload(); it arrives decrypted.
 */
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'TWENTY ONE esports', body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'TWENTY ONE esports', {
            body: data.body || '',
            tag: data.tag || undefined,
            icon: '/apple-touch-icon.png',
            badge: '/favicon.svg',
            data: { url: data.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((w) => w.url === url);

            return open ? open.focus() : self.clients.openWindow(url);
        }),
    );
});
