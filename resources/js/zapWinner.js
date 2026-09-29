/**
 * Zap the winner (P47, NIP-57; resources/views/components/⚡zap-winner.blade.php,
 * App\Support\Lightning\WinnerZaps). One panel per winner, one open at a time:
 *
 *   1. pick an amount and an optional comment;
 *   2. "Preview": $wire.prepareZap() returns the kind 9734 zap request, shown
 *      as the player will sign it (to whom, how much, what it refers to);
 *   3. "Sign and get invoice": the template is fetched again (if it changed,
 *      it is shown again instead of signed), signed by the player's signer,
 *      and $wire.zapInvoice() asks the winner's wallet for the invoice, which
 *      the page shows as a QR code with an "Open in wallet" link.
 *
 * Without a signer (or for a guest) the panel shows the winner's LNURL as a
 * QR code: a plain payment without a zap receipt. A Lightning address is
 * never shown as text. Nothing is signed before the click on step 3, and
 * the sats go straight to the winner: the league takes no fee.
 */
import { ensureSigner } from './nostrSign.js';
import { sameDraft, signerMessage, signTemplate } from './signing.js';

export function zapWinner({ pubkey = null, amounts = [21, 210, 2100], messages = {} } = {}) {
    return {
        open: null,
        sats: amounts[1] ?? amounts[0] ?? 21,
        comment: '',
        // idle -> preparing -> preview -> signing -> invoice
        step: 'idle',
        template: null,
        invoice: '',
        qr: '',
        error: '',
        copied: false,

        toggle(id) {
            if (this.step === 'preparing' || this.step === 'signing') return;
            this.open = this.open === id ? null : id;
            this.reset();
        },

        reset() {
            this.step = 'idle';
            this.template = null;
            this.invoice = '';
            this.qr = '';
            this.error = '';
            this.copied = false;
        },

        pick(sats) {
            this.sats = sats;
            if (this.step !== 'idle') this.reset();
        },

        get tagsShown() {
            return (this.template?.tags ?? []).filter((tag) => ['amount', 'p', 'e', 'a'].includes(tag[0]));
        },

        async preview() {
            if (!pubkey || this.open === null || this.step === 'preparing') return;
            const wire = this.$wire;
            void wire.$id;
            this.step = 'preparing';
            this.error = '';
            try {
                const answer = await wire.prepareZap(this.open, Number(this.sats), this.comment);
                if (answer?.error) {
                    this.error = answer.error;
                    this.step = 'idle';

                    return;
                }
                this.template = answer?.template ?? null;
                this.step = this.template ? 'preview' : 'idle';
            } catch (error) {
                console.warn('[zap] preparing the zap request failed:', error);
                this.error = messages.failed ?? 'That did not work. Please try again.';
                this.step = 'idle';
            }
        },

        async sign() {
            if (this.step !== 'preview') return;
            const wire = this.$wire;
            void wire.$id;
            this.step = 'signing';
            this.error = '';
            try {
                if (!(await ensureSigner())) {
                    this.error = messages.noSigner ?? 'No Nostr signer found.';
                    this.step = 'preview';

                    return;
                }
                const fresh = (await wire.prepareZap(this.open, Number(this.sats), this.comment))?.template ?? null;
                if (!fresh || !sameDraft(fresh, this.template)) {
                    this.template = fresh;
                    this.error = messages.changed ?? 'The zap request changed. Check it again, then sign.';
                    this.step = fresh ? 'preview' : 'idle';

                    return;
                }
                let signed;
                try {
                    signed = await signTemplate(fresh, { pubkey });
                } catch (error) {
                    this.error = signerMessage(messages, error);
                    this.step = 'preview';

                    return;
                }
                const answer = await wire.zapInvoice(this.open, Number(this.sats), this.comment, JSON.stringify(signed));
                if (!answer || answer.error) {
                    this.error = answer?.error ?? messages.failed ?? 'That did not work. Please try again.';
                    this.step = 'preview';

                    return;
                }
                this.invoice = answer.invoice;
                this.qr = answer.qr;
                this.step = 'invoice';
            } catch (error) {
                console.warn('[zap] the zap failed:', error);
                this.error = messages.failed ?? 'That did not work. Please try again.';
                this.step = 'preview';
            }
        },

        async copyInvoice() {
            try {
                await navigator.clipboard.writeText(this.invoice);
                this.copied = true;
                setTimeout(() => (this.copied = false), 1500);
            } catch (error) {
                console.warn('[zap] copying the invoice failed:', error);
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('zapWinner', zapWinner);
});
