/**
 * A poll that knows about the websocket (performance plan P3). Pages that get
 * their changes pushed over Reverb ask the server themselves only as a
 * fallback: every `seconds` while there is no live socket (no window.Echo,
 * or the connection is down), every `withSocket` seconds while it is
 * connected (0 = never: the push is the whole story). Nothing is asked while
 * the tab is hidden; a tick skipped that way runs once when the tab comes
 * back, and once when the socket comes back after a gap (a push sent while
 * it was away is lost).
 *
 *   const stop = livePoll({ seconds: 15, withSocket: 120, run: () => this.$wire.$refresh() });
 *   // destroy(): stop();
 *
 * Measured with tests/Browser/LivewireTrafficTest.php (requests per minute
 * with the socket connected and with the tab hidden).
 */

/** The page's Pusher connection (Echo's Reverb connector), or null without a websocket. */
function connection() {
    return window.Echo?.connector?.pusher?.connection ?? null;
}

/** True while the websocket is connected. */
export function socketConnected() {
    return connection()?.state === 'connected';
}

/** `base` milliseconds plus up to `spread` more, so a push to many viewers does not land as one herd on the server. */
export function jittered(base, spread) {
    return base + Math.floor(Math.random() * spread);
}

export function livePoll({ seconds, withSocket = 0, run }) {
    let timer = null;
    let stopped = false;
    let missed = false;
    let wasConnected = false;
    let bound = null;

    const every = () => (socketConnected() ? withSocket : seconds);

    const schedule = () => {
        clearTimeout(timer);
        timer = null;
        const wait = every();
        if (stopped || !(wait > 0)) return;
        timer = setTimeout(tick, wait * 1000);
    };

    const tick = () => {
        if (document.hidden) {
            missed = true;
        } else {
            run();
        }
        schedule();
    };

    const onVisibility = () => {
        if (!document.hidden && missed && !stopped) {
            missed = false;
            run();
            schedule();
        }
    };

    const onState = ({ current }) => {
        if (current === 'connected') {
            // Back after a gap: whatever was pushed meanwhile is lost, so ask once.
            if (wasConnected && !document.hidden) run();
            wasConnected = true;
        }
        schedule();
    };

    const attach = () => {
        const socket = connection();
        if (!socket || bound || stopped) return;
        bound = socket;
        wasConnected = socket.state === 'connected';
        socket.bind('state_change', onState);
        schedule();
    };

    document.addEventListener('visibilitychange', onVisibility);
    attach();
    // echo.js is its own entry: it may run after Alpine started this component.
    if (!bound && document.readyState !== 'complete') {
        window.addEventListener('load', attach, { once: true });
    }
    schedule();

    return () => {
        stopped = true;
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('load', attach);
        bound?.unbind('state_change', onState);
    };
}
