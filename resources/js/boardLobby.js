/**
 * The lobby of a board game (pages/board/⚡lobby), arranged as the chess
 * lobby (P5 of plan mempool-streifen): the "Online now" block
 * (components/lobby/online-now) reads the page-wide `online` presence
 * (window.esportsPresence, resources/js/echo.js) and the lobby's "Looking to
 * play" switch is the page's own state, saved with the wanted value (never a
 * flip: Livewire squashes identical queued calls, see chessLobby).
 *
 * A pairing and an answer to an invite arrive by push on the player's own
 * channel (`board.game-started`, `board.invite`). While searching or waiting
 * the page asks the server itself only when the root's data-check-at (server
 * ms) says so: a wider range may fit now, the invite expires, or the slow
 * net under a lost push (performance plan P7, F7: the chess lobby's
 * pattern); every `fallback` seconds while the websocket is down; and once
 * when the channel (re)subscribes, for a push sent while it was away.
 *
 * `config`: userId, lookingKey (`<slug>/blitz`), looking (stored state),
 * fallback (seconds between asks without a websocket). Proof of Pong's lobby
 * (components/⚡pong-lobby, plan "Proof of Pong", P2) uses it too, with its
 * own `events` ({ started, invite }) and `newTab`: its match opens in a tab of
 * its own where the browser lets a push open one, else in this one.
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
        // Server clock minus this browser's, so data-check-at (server ms) is read right.
        skew: 0,
        // The data-check-at last read from the root, the moment to ask next, the moment last asked for.
        shownCheckAt: 0,
        dueAt: 0,
        askedFor: 0,
        retryAfter: 0,
        askedAt: 0,
        asking: false,

        init() {
            this.skew = Number(this.$root.dataset.serverNow || Date.now()) - Date.now();
            this.ticker = setInterval(() => {
                this.now = Date.now();
                this.checkIfDue();
            }, 1000);

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
                const events = { started: '.board.game-started', invite: '.board.invite', ...(config.events ?? {}) };
                const started = ({ url }) => {
                    window.esportsAlerts?.leavingTo(url);
                    if (config.newTab && window.open(url, '_blank')) {
                        this.$wire.$refresh();

                        return;
                    }
                    window.location.assign(url);
                };
                const refresh = () => this.$wire.$refresh();
                channel.listen(events.started, started).listen(events.invite, refresh);
                this.stops.push(() => channel.stopListening(events.started, started).stopListening(events.invite, refresh));
                // A push sent before this subscription (or while the socket was away) is lost: a waiting lobby asks once.
                channel.subscribed?.(() => {
                    if (this.checkAt()) this.ask();
                });
            }
        },

        /** The moment to ask next (server ms), 0 while the lobby waits for nothing; a new render's data-check-at wins. */
        checkAt() {
            const shown = Number(this.$root.dataset.checkAt || 0);
            if (shown !== this.shownCheckAt) {
                this.shownCheckAt = shown;
                this.dueAt = shown;
            }

            return this.dueAt;
        },

        checkIfDue() {
            const at = this.checkAt();
            if (!at || this.asking) return;

            if (this.connection !== 'connected') {
                if (this.now - this.askedAt >= (config.fallback ?? 4) * 1000) this.ask();

                return;
            }
            // Once per moment: a failed request does not turn into one per second, it is retried after 10 s.
            if (at !== this.askedFor && this.now + this.skew >= at && this.now >= this.retryAfter) {
                this.askedFor = at;
                this.ask();
            }
        },

        /* poll() answers with the next moment: a poll that changed nothing skips its render and so the new data-check-at. */
        async ask() {
            this.asking = true;
            this.askedAt = this.now;
            try {
                const next = await this.$wire.poll();
                this.dueAt = typeof next === 'number' ? next : 0;
            } catch {
                // Without the socket the next fallback tick asks again; with it the same moment is asked for again in 10 s.
                this.askedFor = null;
                this.retryAfter = this.now + 10_000;
            } finally {
                this.asking = false;
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
