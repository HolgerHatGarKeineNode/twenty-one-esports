/**
 * The Hyperbitcoinization lobby (components/⚡hyper-lobby, plan "Hyperbitcoinization", P3): renders again
 * whenever a table changes (`hyper.lobby` on the public channel), and opens a match in a new tab once a
 * table of this player starts: by push on the player's own channel (`hyper.table-started`) or right after
 * the player's own click that filled the table (the component's `hyper-open` event). Each match opens
 * once per tab, however many of these arrive. A browser may block a tab opened without a click; the
 * component then shows the match's "Open match" button, which always works.
 *
 * Without a websocket the lobby asks the server every `fallback` seconds.
 *
 * `config`: userId (null for a guest), fallback (seconds).
 */
export default function hyperLobby(config) {
    return {
        stops: [],
        timer: null,
        poll: null,

        init() {
            const refresh = () => {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.$wire.$refresh(), 250);
            };
            const open = ({ url }) => {
                this.open(url);
                refresh();
            };

            if (window.Echo) {
                const lobby = window.Echo.channel('hyper.lobby');
                lobby.listen('.hyper.lobby', refresh);
                this.stops.push(() => lobby.stopListening('.hyper.lobby', refresh));

                if (config.userId) {
                    const own = window.Echo.private('App.Models.User.' + config.userId);
                    own.listen('.hyper.table-started', open);
                    this.stops.push(() => own.stopListening('.hyper.table-started', open));
                }
            }

            this.poll = setInterval(() => {
                if (window.Echo?.connector?.pusher?.connection?.state !== 'connected') this.$wire.$refresh();
            }, (config.fallback ?? 10) * 1000);
        },

        /** Opens the match once per tab. */
        open(url) {
            if (!url) return;
            const seen = JSON.parse(sessionStorage.getItem('hyper-opened') || '[]');
            if (seen.includes(url)) return;
            sessionStorage.setItem('hyper-opened', JSON.stringify([...seen, url].slice(-20)));
            window.open(url, '_blank');
        },

        destroy() {
            this.stops.forEach((stop) => stop());
            clearTimeout(this.timer);
            clearInterval(this.poll);
        },
    };
}
