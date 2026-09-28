/**
 * Casual 1v1 (P23 S3) on every page of a logged-in player: the module of the
 * game pages (components/⚡casual-play) and the ready prompt
 * (components/⚡casual-ready). Realtime comes from the player's private
 * channel (SeriesInviteChanged, SeriesMatchChanged) and the `online`
 * presence (who looks for a game); without a websocket both components
 * poll on their own (wire:poll), so nothing here is needed for them to work.
 */
import { ensureSigner } from './nostrSign.js';
import { canEncrypt } from './signerCapabilities.js';

/**
 * The gate in front of every casual step that commits the player (search,
 * invite, accept, ready): the lobby travels only through the NIP-17 match
 * chat, so a player without a signer that encrypts with NIP-44 could never
 * get it. Returns '' when the signer is fine, else the message to show.
 */
export async function signerGate(messages) {
    try {
        if (!(await ensureSigner())) {
            return messages.noSigner;
        }
    } catch (error) {
        console.warn('[casual] no signer', error);

        return messages.noSigner;
    }

    return canEncrypt(window.nostr) ? '' : messages.noNip44;
}

/** mm:ss of a number of milliseconds (never negative). */
export function clock(ms) {
    const seconds = Math.max(0, Math.ceil(ms / 1000));

    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
}

/**
 * Listen on the player's private channel once Echo is there (echo.js is its
 * own entry and may come after this one). Returns a stop function.
 */
function onPlayerChannel(userId, events, handler) {
    const attach = () => {
        if (!window.Echo || !userId) return () => {};
        const channel = window.Echo.private('App.Models.User.' + userId);
        events.forEach((event) => channel.listen(event, handler));

        return () => events.forEach((event) => channel.stopListening(event, handler));
    };

    if (window.Echo || document.readyState === 'complete') {
        return attach();
    }

    let stop = () => {};
    window.addEventListener('load', () => (stop = attach()), { once: true });

    return () => stop();
}

/**
 * The module on a game page. `config`: userId, looking (whether "Looking to
 * play" is on for this module's game), serverNow (ms), messages.
 */
export function casualPlay(config) {
    return {
        now: Date.now(),
        skew: Number(config.serverNow || Date.now()) - Date.now(),
        ticker: null,
        poller: null,
        stops: [],
        busy: false,
        gateError: '',
        looking: Boolean(config.looking),
        savedLooking: Boolean(config.looking),
        savingLooking: false,
        lookingFailed: false,
        presenceKey: null,

        init() {
            this.ticker = setInterval(() => (this.now = Date.now()), 1000);
            const refresh = () => this.$wire.$refresh();
            // Who is looking changes without a push when there is no websocket: ask while the module is on screen.
            if (config.userId) {
                this.poller = setInterval(() => {
                    const box = this.$root.getBoundingClientRect();
                    if (!document.hidden && box.bottom > 0 && box.top < window.innerHeight) refresh();
                }, config.poll * 1000);
            }
            this.stops.push(onPlayerChannel(config.userId, ['.series.invite', '.series.changed'], refresh));

            // Someone switched "Looking to play" for a casual game on or off: the list is the server's, ask again.
            this.stops.push(window.esportsPresence?.subscribe((members) => {
                const key = members.filter((m) => String(m.looking ?? '').endsWith('/1v1')).map((m) => m.id + ':' + m.looking).sort().join(',');
                if (this.presenceKey !== null && key !== this.presenceKey) refresh();
                this.presenceKey = key;
            }) ?? (() => {}));
        },

        destroy() {
            clearInterval(this.ticker);
            clearInterval(this.poller);
            this.stops.forEach((stop) => stop());
        },

        /** mm:ss until a server time (ms). */
        left(endsAtMs) {
            return clock(endsAtMs - (this.now + this.skew));
        },

        /** mm:ss since a server time (ms). */
        since(startMs) {
            return clock(this.now + this.skew - startMs);
        },

        /** Share of `totalMs` left until `endsAtMs`, 0..1. */
        share(endsAtMs, totalMs) {
            return Math.max(0, Math.min(1, (endsAtMs - (this.now + this.skew)) / totalMs));
        },

        /** A committing action: the signer gate first, then the server. */
        async act(method, ...args) {
            if (this.busy) return;
            this.busy = true;
            this.gateError = '';

            try {
                this.gateError = await signerGate(config.messages);
                if (this.gateError === '') {
                    await this.$wire[method](...args);
                }
            } catch (error) {
                console.warn('[casual] ' + method + ' failed', error);
                this.gateError = config.messages.failed;
            } finally {
                this.busy = false;
            }
        },

        /* "Looking to play" for this game: the switch shows the wanted state at once and the server catches up (as the chess lobby's). */
        async toggleLooking() {
            if (!this.looking) {
                this.gateError = await signerGate(config.messages);
                if (this.gateError !== '') return;
            }
            this.looking = !this.looking;
            this.lookingFailed = false;
            this.saveLooking();
        },

        async saveLooking() {
            if (this.savingLooking || this.looking === this.savedLooking) return;

            this.savingLooking = true;
            try {
                this.savedLooking = Boolean(await this.$wire.setLooking(this.looking));
            } catch {
                this.looking = this.savedLooking;
                this.lookingFailed = true;

                return;
            } finally {
                this.savingLooking = false;
            }

            this.saveLooking();
        },
    };
}

/**
 * The ready prompt (components/⚡casual-ready). `config`: userId, readyBy
 * and total (ms, server clock), serverNow (ms), messages. When its clock
 * runs out it asks the server once, which voids the check and says so.
 */
export function casualReadyPrompt(config) {
    return {
        now: Date.now(),
        skew: Number(config.serverNow || Date.now()) - Date.now(),
        ticker: null,
        asked: false,
        busy: false,
        error: '',

        init() {
            this.ticker = setInterval(() => {
                this.now = Date.now();
                if (!this.asked && config.readyBy && this.now + this.skew >= config.readyBy + 500) {
                    this.asked = true;
                    this.$wire.check();
                }
            }, 250);
            this.$nextTick(() => this.$refs.ready?.focus({ preventScroll: true }));
        },

        destroy() {
            clearInterval(this.ticker);
        },

        get left() {
            return clock(config.readyBy - (this.now + this.skew));
        },

        get share() {
            return Math.max(0, Math.min(1, (config.readyBy - (this.now + this.skew)) / config.total));
        },

        get urgent() {
            return config.readyBy - (this.now + this.skew) < 15_000;
        },

        async ready() {
            if (this.busy) return;
            this.busy = true;
            this.error = '';

            try {
                this.error = await signerGate(config.messages);
                if (this.error === '') {
                    await this.$wire.ready();
                }
            } catch (error) {
                console.warn('[casual] ready failed', error);
                this.error = config.messages.failed;
            } finally {
                this.busy = false;
            }
        },
    };
}

/**
 * The root of the ready prompt, on every page: a pairing, an invite answered
 * or a match changed makes it ask the server at once instead of at its
 * next poll.
 */
export function casualWatch(userId, seconds) {
    return {
        stop: () => {},
        poller: null,

        init() {
            this.stop = onPlayerChannel(userId, ['.series.invite', '.series.changed'], () => this.$wire.check());
            // The server says whether anything waits (data-polling); a render may change it at any time.
            this.poller = setInterval(() => {
                if (this.$root.dataset.polling === '1' && !document.hidden) this.$wire.check();
            }, seconds * 1000);
        },

        destroy() {
            this.stop();
            clearInterval(this.poller);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('casualWatch', casualWatch);
    window.Alpine.data('casualPlay', casualPlay);
    window.Alpine.data('casualReadyPrompt', casualReadyPrompt);
});
