import { playSound, sounds } from './sounds.js';

/**
 * Notifications on every logged-in page (P5c). The server sends each new
 * notification on the player's private channel (App\Events\UserNotified);
 * this module turns it into what the player notices:
 *
 * - a toast in the Overlays style with the action ("Play now"); for an
 *   opponent found or an accepted invite a short countdown then opens the
 *   game on its own, and "Stay here" cancels it
 * - its sound (resources/js/sounds.js)
 * - with the tab hidden: a flashing tab title ("● Opponent found — TWENTY ONE")
 *   and, if the player allowed it, a desktop notification
 * - a window event `esports-notification`, which reloads the bell
 *
 * The game page an alert points to handles its own sounds and overlays, so
 * there the alert only updates the bell and the title.
 *
 * Settings and labels come from <meta name="alert-settings">. Loaded from
 * resources/js/echo.js, so it runs wherever the websocket does.
 */

const SITE = 'TWENTY ONE';

// Listen for the first gesture now, so a later alert can be heard.
sounds();
const FLASH_MS = 1000;

function readConfig() {
    try {
        const meta = document.querySelector('meta[name="alert-settings"]');

        return meta ? JSON.parse(meta.content) : null;
    } catch {
        return null;
    }
}

function reducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

function samePage(url) {
    try {
        return new URL(url, window.location.origin).pathname === window.location.pathname;
    } catch {
        return false;
    }
}

/* ---------- tab title --------------------------------------------------------------------------------------- */

const title = {
    original: null,
    timer: null,

    flash(text) {
        if (!document.hidden) return;
        this.stop();
        this.original = document.title;
        const flashed = '● ' + text + ' — ' + SITE;
        // Reduced motion: the title changes once instead of blinking.
        if (reducedMotion()) {
            document.title = flashed;

            return;
        }
        let on = false;
        const swap = () => {
            on = !on;
            document.title = on ? flashed : this.original;
        };
        swap();
        this.timer = setInterval(swap, FLASH_MS);
    },

    stop() {
        clearInterval(this.timer);
        this.timer = null;
        if (this.original !== null) document.title = this.original;
        this.original = null;
    },
};

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) title.stop();
});

/* ---------- desktop notification ---------------------------------------------------------------------------- */

function desktopNotification(alert) {
    if (!document.hidden || !('Notification' in window) || Notification.permission !== 'granted') return;
    const options = { body: alert.body, tag: alert.match ? 'game-' + alert.match : alert.id, icon: '/apple-touch-icon.png', data: { url: alert.url } };
    try {
        const notification = new Notification(alert.title, options);
        notification.onclick = () => {
            window.focus();
            window.location.assign(alert.url);
            notification.close();
        };
    } catch {
        // Android Chrome has no Notification constructor; the service worker can show it.
        navigator.serviceWorker?.getRegistration('/').then((registration) => registration?.showNotification(alert.title, options)).catch(() => {});
    }
}

/* ---------- the permission prompt ------------------------------------------------------------------------- */

const ASKED_KEY = 'esports.notify-asked';

/**
 * Should the page offer desktop notifications now? Only once per browser,
 * and only while the browser has not been asked yet.
 */
export function shouldAskForNotifications() {
    try {
        return 'Notification' in window && Notification.permission === 'default' && localStorage.getItem(ASKED_KEY) === null;
    } catch {
        return false;
    }
}

export async function answerNotificationPrompt(allow) {
    try {
        localStorage.setItem(ASKED_KEY, allow ? 'allowed' : 'declined');
    } catch {
        // Private mode: the prompt may come again, nothing breaks.
    }
    if (allow && 'Notification' in window) {
        return Notification.requestPermission();
    }

    return 'Notification' in window ? Notification.permission : 'denied';
}

/* ---------- one alert --------------------------------------------------------------------------------------- */

const state = {
    config: readConfig(),
    // A page that is already on its way to this URL (the lobby after a pairing).
    leavingTo: null,
    seen: new Set(),
};

export function leavingTo(url) {
    state.leavingTo = url;
}

export function handleAlert(alert) {
    if (!alert?.id || state.seen.has(alert.id)) return;
    state.seen.add(alert.id);
    const labels = state.config?.labels ?? {};

    window.dispatchEvent(new CustomEvent('esports-notification', { detail: alert }));
    title.flash(alert.title);
    desktopNotification(alert);

    const here = samePage(alert.url);
    const onItsGame = here && /^\/games\/\d+/.test(window.location.pathname);
    const alreadyGoing = state.leavingTo !== null && state.leavingTo === alert.url;

    if (!onItsGame && !alreadyGoing) playSound(alert.sound);
    if (here || alreadyGoing) return;

    window.dispatchEvent(new CustomEvent('toast', {
        detail: {
            tone: alert.tone,
            title: alert.title,
            text: alert.body,
            time: labels.justNow ?? '',
            action: alert.action ? { label: alert.action, href: alert.url } : null,
            countdown: alert.redirect ? (state.config?.countdown ?? 5) : null,
            labels: { opening: labels.opening ?? 'Opening in :s s', stay: labels.stay ?? 'Stay here' },
            alertId: alert.id,
        },
    }));
}

/* ---------- wiring ------------------------------------------------------------------------------------------ */

window.esportsAlerts = { handle: handleAlert, leavingTo, shouldAsk: shouldAskForNotifications, answer: answerNotificationPrompt, title };

/**
 * Called by resources/js/echo.js once Echo exists (a static import runs
 * before the importing module's body, so this cannot subscribe on load).
 */
export function startAlerts() {
    if (window.Echo && state.config?.userId) {
        window.Echo.private('App.Models.User.' + state.config.userId).listen('.user.notified', handleAlert);
    }
}
