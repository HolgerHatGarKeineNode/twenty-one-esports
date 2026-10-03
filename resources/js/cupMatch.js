/**
 * The cup match signal on the client (resources/views/components/⚡cup-match.blade.php:
 * the header badge and the page banner; App\Support\Tournaments\CupMatchNow).
 * What it shows comes from the server; this module only asks for a new render
 * when something may have changed: a notification (alerts.js raises
 * `esports-notification` for every one, the league's "your cup game is on"
 * included), a game that started or a series that changed on the player's
 * private channel, and a slow poll in case an event was missed.
 */

const REFRESH_DEBOUNCE_MS = 250;

export default function cupMatch(config) {
    return {
        connected: false,
        poller: null,
        debounce: null,
        onNotification: null,

        init() {
            this.onNotification = () => this.requestRefresh();
            window.addEventListener('esports-notification', this.onNotification);
            this.connect();
            if (!window.Echo && document.readyState !== 'complete') {
                window.addEventListener('load', () => this.connect(), { once: true });
            }
            const seconds = window.Echo ? config.pollWithSocket : config.poll;
            if (seconds > 0) {
                this.poller = setInterval(() => {
                    if (!document.hidden) this.requestRefresh();
                }, seconds * 1000);
            }
        },

        destroy() {
            window.removeEventListener('esports-notification', this.onNotification);
            clearInterval(this.poller);
            clearTimeout(this.debounce);
        },

        connect() {
            if (!window.Echo || this.connected || !config.userId) return;
            this.connected = true;
            const refresh = () => this.requestRefresh();
            window.Echo.private('App.Models.User.' + config.userId)
                .listen('.chess.game-started', refresh)
                .listen('.board.game-started', refresh)
                .listen('.series.changed', refresh);
        },

        requestRefresh() {
            clearTimeout(this.debounce);
            this.debounce = setTimeout(() => this.$wire.$refresh(), REFRESH_DEBOUNCE_MS);
        },
    };
}
