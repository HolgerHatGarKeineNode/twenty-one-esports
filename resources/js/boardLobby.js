/**
 * The lobby of a board game (pages/board/⚡lobby), arranged as the chess
 * lobby (P5 of plan mempool-streifen): the "Online now" block
 * (components/lobby/online-now) reads the page-wide `online` presence
 * (window.esportsPresence, resources/js/echo.js) and the lobby's "Looking to
 * play" switch is the page's own state, saved with the wanted value (never a
 * flip: Livewire squashes identical queued calls, see chessLobby).
 *
 * A pairing and an answer to an invite arrive by push on the player's own
 * channel (`board.game-started`, `board.invite`); while searching or waiting
 * the Livewire component also asks on its own (wire:poll).
 *
 * `config`: userId, lookingKey (`<slug>/blitz`), looking (stored state).
 */
export default function boardLobby(config) {
    return {
        online: [],
        connection: 'connecting',
        now: Date.now(),
        ticker: null,
        stops: [],
        looking: Boolean(config.looking),
        savedLooking: Boolean(config.looking),
        savingLooking: false,
        lookingFailed: false,

        init() {
            this.ticker = setInterval(() => (this.now = Date.now()), 1000);

            const pusher = window.Echo?.connector?.pusher;
            if (pusher) {
                this.connection = pusher.connection.state === 'connected' ? 'connected' : 'connecting';
                const onChange = ({ current }) => (this.connection = current);
                pusher.connection.bind('state_change', onChange);
                this.stops.push(() => pusher.connection.unbind('state_change', onChange));
            } else {
                this.connection = 'none';
            }

            if (!config.userId) return;

            this.stops.push(window.esportsPresence?.subscribe((members) => (this.online = members)) ?? (() => {}));

            if (window.Echo) {
                const channel = window.Echo.private('App.Models.User.' + config.userId);
                const started = ({ url }) => {
                    window.esportsAlerts?.leavingTo(url);
                    window.location.assign(url);
                };
                const refresh = () => this.$wire.$refresh();
                channel.listen('.board.game-started', started).listen('.board.invite', refresh);
                this.stops.push(() => channel.stopListening('.board.game-started', started).stopListening('.board.invite', refresh));
            }
        },

        destroy() {
            clearInterval(this.ticker);
            this.stops.forEach((stop) => stop());
        },

        toggleLooking() {
            this.looking = !this.looking;
            this.lookingFailed = false;
            this.saveLooking();
        },

        /* One request at a time, always with the state the switch shows now; a failure puts it back and says so. */
        async saveLooking() {
            if (this.savingLooking || this.looking === this.savedLooking) return;

            this.savingLooking = true;
            try {
                this.savedLooking = Boolean(await this.$wire.setLookingToPlay(this.looking));
            } catch {
                this.looking = this.savedLooking;
                this.lookingFailed = true;
                return;
            } finally {
                this.savingLooking = false;
            }

            this.saveLooking();
        },

        /* The row of the player this one's open invite goes to, until it expires. */
        invited(member) {
            return member.id === this.$wire.invitedUserId && this.now < this.$wire.invitedUntilMs;
        },

        /** Everyone else online, those looking for this board game first, then by name. */
        get others() {
            const mine = (m) => (m.looking === config.lookingKey ? 1 : 0);

            return this.online.filter((m) => m.id !== config.userId).sort((a, b) => mine(b) - mine(a) || a.name.localeCompare(b.name));
        },
    };
}
