/**
 * The Alpine side of the clan application DMs (clanDm.js, loaded on demand):
 * <div x-data="clanDms({ me, relays, labels })">, and inside it
 * `await deliver(messages)` with the texts the league handed back
 * (`[{ pubkey, content }]`).
 *
 * `dmNote` says how many got it, `dmError` why none did. A recipient who
 * can only be reached by NIP-04 (no DM inbox relays) waits in `waiting`
 * until the sender says yes (`sendWaiting()`, the "Send as NIP-04" button):
 * never a silent downgrade. A signer without NIP-44 is never downgraded
 * (labels.no_nip44). The application or the decline is already stored by
 * then: a DM that does not go out never undoes it, the bell has told the
 * clan anyway.
 */
import { ensureSigner } from './nostrSign.js';
import { signerMessage } from './signing.js';

export function clanDms({ me = null, relays = [], labels = {} } = {}) {
    return {
        dmBusy: false,
        dmNote: '',
        dmError: '',
        waiting: [],
        sentCount: 0,
        total: 0,

        async deliver(messages, allowNip04 = []) {
            if (!Array.isArray(messages) || messages.length === 0 || this.dmBusy) return;
            this.dmBusy = true;
            this.dmError = '';
            if (allowNip04.length === 0) {
                this.dmNote = '';
                this.sentCount = 0;
                this.total = messages.length;
                this.waiting = [];
            }
            try {
                if (!(await ensureSigner())) {
                    this.dmError = labels.noSigner ?? '';

                    return;
                }
                const { sendClanDms } = await import('./clanDm.js');
                const results = await sendClanDms({ sender: me, messages, signer: window.nostr, relays, allowNip04 });
                const confirm = new Set(results.filter((result) => result.status === 'confirm').map((result) => result.recipient));
                const failed = results.find((result) => result.status === 'error');
                const refused = results.find((result) => result.status === 'refused');

                this.sentCount += results.filter((result) => result.status === 'sent').length;
                this.waiting = [...this.waiting.filter((message) => !allowNip04.includes(message.pubkey)), ...messages.filter((message) => confirm.has(message.pubkey))];

                if (this.sentCount > 0) {
                    this.dmNote = (labels.sent ?? ':sent of :total').replace(':sent', String(this.sentCount)).replace(':total', String(this.total));
                }
                if (failed) {
                    this.dmError = signerMessage(labels.signer ?? {}, failed.error);
                } else if (refused?.code === 'no_nip44') {
                    this.dmError = labels.no_nip44 ?? '';
                } else if (this.sentCount === 0 && this.waiting.length === 0) {
                    this.dmError = labels.none ?? '';
                }
            } catch (error) {
                console.warn('[clan dm] sending failed:', error);
                this.dmError = signerMessage(labels.signer ?? {}, error);
            } finally {
                this.dmBusy = false;
            }
        },

        /** The sender's yes to NIP-04 for the recipients without DM inbox relays. */
        sendWaiting() {
            const waiting = [...this.waiting];

            return this.deliver(waiting, waiting.map((message) => message.pubkey));
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('clanDms', clanDms);
});
