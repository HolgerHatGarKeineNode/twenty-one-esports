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

document.addEventListener('alpine:init', () => {
    window.Alpine.store('lobbyPin', { card: null, busy: false, error: '' });
    window.Alpine.data('lobbyPin', lobbyPin);
    window.Alpine.data('roomChat', roomChat);
    window.Alpine.data('casualClock', casualClock);
    window.Alpine.data('casualReady', casualReady);
});
