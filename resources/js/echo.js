import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/*
 * Reverb settings come from the page (<meta name="reverb">, rendered from
 * config/broadcasting.php), not from build-time VITE_ variables, so the same
 * build works locally, in the browser tests and in production. No meta tag
 * (no app key configured) means no websocket: pages that listen check for
 * window.Echo and fall back to asking the server.
 */
const meta = document.querySelector('meta[name="reverb"]');

if (meta) {
    const reverb = JSON.parse(meta.content);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverb.key,
        wsHost: reverb.host,
        wsPort: reverb.port,
        wssPort: reverb.port,
        forceTLS: reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
