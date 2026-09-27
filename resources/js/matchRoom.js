/**
 * Entry for the series match room (pages/matches/room): the group chat of the
 * two lineups, and for a casual 1v1 (P23) the steps' countdown and the Ready
 * button. Loaded only on that page (layout `scripts`).
 */
import { canEncrypt } from './nostrChat.js';
import { ensureSigner } from './nostrSign.js';
import { roomChat } from './roomChat.js';

/** mm:ss left until a casual deadline (unix seconds), counted down in the browser. */
export function casualClock(at) {
    return {
        left: '',
        timer: null,

        init() {
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick() {
            const seconds = Math.max(0, at - Math.floor(Date.now() / 1000));
            const hours = Math.floor(seconds / 3600);
            const rest = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
            this.left = hours > 0 ? `${hours}:${rest}` : rest;
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
                if (!(await ensureSigner())) {
                    this.error = messages.noSigner;

                    return;
                }

                if (!canEncrypt(window.nostr)) {
                    this.error = messages.noNip44;

                    return;
                }

                await this.$wire.casualReady();
            } catch (error) {
                console.warn('[room] ready failed', error);
                this.error = messages.failed;
            } finally {
                this.busy = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('roomChat', roomChat);
    window.Alpine.data('casualClock', casualClock);
    window.Alpine.data('casualReady', casualReady);
});
