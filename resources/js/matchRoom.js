/**
 * Entry for the series match room (pages/matches/room): the group chat of the
 * two lineups, and for a casual 1v1 (P23) the steps' countdown and the Ready
 * button. Loaded only on that page (layout `scripts`).
 */
import { casualClock } from './casualClock.js';
import { signerGate } from './casualPlay.js';
import { livePoll } from './livePoll.js';
import { roomChat } from './roomChat.js';
import { registerAlpine } from './registerAlpine.js';

/** A change pushed for this series, or a clock edge, asks the server after this wait (several pushes make one sync). */
const SYNC_DEBOUNCE_MS = 250;
/** A sync that would land while the player types or has the Submit dialog open waits this long and tries again. */
const SYNC_RETRY_MS = 8000;

/**
 * The room's root (performance plan P3): asks the server for the live sheet
 * (`$wire.sync()`, which answers without a render when nothing changed)
 * when something may have changed, instead of every 8 seconds:
 *
 * - a push: SeriesMatchChanged (`.series.changed`) on the player's private
 *   channel names this series (every write of SeriesService sends it: score,
 *   rosters, lobby, report, answer, decision);
 * - a clock edge: the server renders the next moment the room changes by
 *   time alone (kick-off, the no-show window, a casual deadline) into
 *   `data-next-edge` (server ms); the sync lands just after it;
 * - the fallback (livePoll.js): every `poll` seconds without a live
 *   websocket, every `pollWithSocket` seconds with one (a push lost on the
 *   way); nothing while the tab is hidden, one sync when it comes back.
 *
 * While the player types in a field or has the Submit dialog open, a sync
 * waits (it would morph the form under their hands), as the 8-second tick
 * did. Before P3 that tick ran in hidden tabs too and was never cleared.
 */
export function roomSync(config) {
    return {
        submit: false,
        reportInView: false,
        debounce: null,
        edgeTimer: null,
        edgeAt: 0,
        stopPoll: null,
        channel: null,
        onPush: null,
        skew: 0,

        init() {
            // Read once from data-*: the config in x-data must not change between renders (a changed x-data restarts this).
            this.skew = Number(this.$root.dataset.serverNow || Date.now()) - Date.now();
            this.stopPoll = livePoll({ seconds: config.poll, withSocket: config.pollWithSocket, run: () => this.requestSync(0) });
            this.watchEdge();

            if (window.Echo && config.userId) {
                this.onPush = (payload) => {
                    if (Number(payload?.number) === Number(config.number)) this.requestSync(SYNC_DEBOUNCE_MS);
                };
                this.channel = window.Echo.private('App.Models.User.' + config.userId);
                this.channel.listen('.series.changed', this.onPush);
            }
        },

        destroy() {
            clearTimeout(this.debounce);
            clearTimeout(this.edgeTimer);
            this.stopPoll?.();
            // Stop listening, never leave: the dock and the bell share the player's channel.
            this.channel?.stopListening('.series.changed', this.onPush);
        },

        /** Arm one timer for the next clock edge the last render named; a render that names another re-arms it. */
        watchEdge() {
            const at = Number(this.$root.dataset.nextEdge || 0);
            if (at === this.edgeAt) return;
            this.edgeAt = at;
            clearTimeout(this.edgeTimer);
            this.edgeTimer = null;
            if (at > 0) {
                // A second after the edge, so the server's clock has passed it too.
                const wait = Math.max(0, at - (Date.now() + this.skew)) + 1000;
                this.edgeTimer = setTimeout(() => {
                    this.edgeTimer = null;
                    this.requestSync(0);
                }, wait);
            }
        },

        requestSync(wait) {
            clearTimeout(this.debounce);
            this.debounce = setTimeout(() => this.sync(), wait);
        },

        async sync() {
            this.debounce = null;
            if (document.activeElement?.matches('input, textarea, select') || this.submit) {
                this.requestSync(SYNC_RETRY_MS);

                return;
            }
            await this.$wire.sync();
            await this.$nextTick();
            this.watchEdge();
        },
    };
}

/**
 * The Ready button of a casual 1v1: a player without a signer that can
 * encrypt with NIP-44 cannot receive the lobby card, so the button asks for
 * one first and says why it refuses (NIP "No NIP-44 signer, no card"). There
 * is no fallback.
 */
export function casualReady(messages) {
    return {
        busy: false,
        error: '',

        async ready() {
            if (this.busy) return;
            this.busy = true;
            this.error = '';

            try {
                this.error = await signerGate(messages);
                if (this.error === '') {
                    await this.$wire.casualReady();
                }
            } catch (error) {
                console.warn('[room] ready failed', error);
                this.error = messages.failed;
            } finally {
                this.busy = false;
            }
        },
    };
}

/**
 * The pinned lobby card in a casual 1v1's steps (partials/casual-steps):
 * draws what the room chat publishes to Alpine.store('lobbyPin') and hands
 * its buttons back to the chat as a `lobby-pin` window event. It holds no
 * card itself and talks to nobody: name and password stay in this tab.
 */
export function lobbyPin(labels) {
    return {
        copied: '',

        get pin() {
            return window.Alpine.store('lobbyPin');
        },

        get card() {
            return this.pin.card;
        },

        get sharedLine() {
            const card = this.card;
            if (!card) return '';
            const line = (card.mine ? labels.sharedByMe : labels.sharedBy).replace(':time', () => card.time);

            return line.replace(':name', () => card.by);
        },

        act(action) {
            window.dispatchEvent(new CustomEvent('lobby-pin', { detail: action }));
        },

        copy(value, field) {
            navigator.clipboard?.writeText(value);
            this.copied = field;
            setTimeout(() => {
                if (this.copied === field) this.copied = '';
            }, 1500);
        },
    };
}

registerAlpine(() => {
    window.Alpine.store('lobbyPin', { card: null, busy: false, error: '' });
    window.Alpine.data('lobbyPin', lobbyPin);
    window.Alpine.data('roomChat', roomChat);
    window.Alpine.data('roomSync', roomSync);
    window.Alpine.data('casualClock', casualClock);
    window.Alpine.data('casualReady', casualReady);
});
