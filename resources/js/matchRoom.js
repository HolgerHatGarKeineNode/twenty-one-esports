/**
 * Entry for the series match room (pages/matches/room): the group chat of the
 * two lineups, and for a casual 1v1 (P23) the steps' countdown and the Ready
 * button. Loaded only on that page (layout `scripts`).
 */
import { casualClock } from './casualClock.js';
import { signerGate } from './casualPlay.js';
import { roomChat } from './roomChat.js';

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

document.addEventListener('alpine:init', () => {
    window.Alpine.data('roomChat', roomChat);
    window.Alpine.data('casualClock', casualClock);
    window.Alpine.data('casualReady', casualReady);
});
