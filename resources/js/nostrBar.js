/**
 * The Nostr bar (P45, resources/views/components/nostr-bar.blade.php): the
 * way from a page to the same thing on Nostr. One panel open at a time:
 *
 *   share   copy the `nostr:` link, the njump.me link or the page link
 *   zap     the LNURL QR code the page already shows elsewhere (no address as text)
 *   follow  preview what changes in the viewer's follow list, sign on click (follow.js)
 *   dm      write a message, sign and send it on click (directMessage.js, loaded on demand)
 *
 * "Open in app" is a plain `nostr:` link (NIP-21) and needs no script.
 * Nothing is signed without a click on the panel's own button, and every
 * failure says what happened; follow refuses whenever the list could not be
 * read (fail closed, follow.js).
 */
import { ensureSigner } from './nostrSign.js';
import { FollowRefused, follow, followPreview, readFollowList } from './follow.js';
import { signerMessage } from './signing.js';

export function nostrBar({ me = null, follow: target = null, dm = null, relays = [], labels = {} } = {}) {
    return {
        panel: null,
        copied: '',
        // follow
        followStep: 'idle',
        followInfo: null,
        followError: '',
        // dm
        dmText: '',
        dmStep: 'idle',
        dmFormat: '',
        dmError: '',
        dmConfirm: '',

        toggle(name) {
            this.panel = this.panel === name ? null : name;
            if (this.panel === 'follow' && ['idle', 'refused', 'error'].includes(this.followStep)) this.readFollow();
            if (this.panel === 'dm') this.$nextTick(() => this.$refs.dmText?.focus());
        },

        close() {
            this.panel = null;
        },

        async copy(text, which) {
            try {
                await navigator.clipboard.writeText(text);
                this.copied = which;
                setTimeout(() => {
                    if (this.copied === which) this.copied = '';
                }, 1500);
            } catch (error) {
                console.warn('[nostr bar] copying failed:', error);
            }
        },

        label(key, replace = {}) {
            let text = labels[key] ?? key;
            for (const [name, value] of Object.entries(replace)) text = text.replace(':' + name, String(value));

            return text;
        },

        async readFollow() {
            if (!me || !target) return;
            this.followStep = 'reading';
            this.followError = '';
            try {
                const read = await readFollowList(me, relays);
                if (!read.read) {
                    this.followStep = 'refused';
                    this.followError = read.reason === 'no_relay_list'
                        ? this.label('followNoRelayList')
                        : this.label('followNotRead', { answered: read.answered, asked: read.asked });

                    return;
                }
                this.followInfo = followPreview(read, target);
                this.followStep = this.followInfo.already ? 'following' : 'preview';
            } catch (error) {
                console.warn('[nostr bar] reading the follow list failed:', error);
                this.followStep = 'error';
                this.followError = this.label('failed');
            }
        },

        /**
         * `allowNewList`: the player chose "Start a new list" (re-audit: a new
         * identity's list is never written silently).
         */
        async confirmFollow(allowNewList = false) {
            if (this.followStep !== 'preview') return;
            this.followStep = 'signing';
            this.followError = '';
            try {
                if (!(await ensureSigner())) {
                    this.followError = labels.signer?.noSigner ?? this.label('failed');
                    this.followStep = 'preview';

                    return;
                }
                const result = await follow({ me, target, relays, expectBefore: this.followInfo?.before ?? null, allowNewList });
                this.followInfo = { ...this.followInfo, published: result.published };
                this.followStep = result.published > 0 ? 'done' : 'unpublished';
            } catch (error) {
                if (error instanceof FollowRefused) {
                    // The list changed or could not be read again: show it anew, never send.
                    this.followStep = 'refused';
                    const key = { changed: 'followChanged', already: 'followAlready', no_relay_list: 'followNoRelayList' }[error.code] ?? 'followNotRead';
                    this.followError = this.label(key, { answered: 0, asked: 0 });
                    // The list changed, or turned out to be a new one since the preview: show the new state.
                    if (error.code === 'changed' || error.code === 'confirm_new_list') this.readFollow();

                    return;
                }
                console.warn('[nostr bar] following failed:', error);
                this.followError = signerMessage(labels.signer ?? {}, error);
                this.followStep = 'preview';
            }
        },

        /**
         * `allowNip04`: the sender agreed to the older format on the confirmation
         * (P45 audit F3: never a silent downgrade).
         */
        async sendDm(allowNip04 = false) {
            if (!me || !dm || this.dmStep === 'sending' || this.dmText.trim() === '') return;
            this.dmStep = 'sending';
            this.dmError = '';
            this.dmConfirm = '';
            try {
                if (!(await ensureSigner())) {
                    this.dmError = labels.signer?.noSigner ?? this.label('failed');
                    this.dmStep = 'idle';

                    return;
                }
                const { DirectMessageRefused, sendDirectMessage } = await import('./directMessage.js');
                try {
                    const result = await sendDirectMessage({ sender: me, recipient: dm, content: this.dmText, signer: window.nostr, relays, allowNip04 });
                    this.dmFormat = result.format;
                    this.dmStep = result.delivered > 0 ? 'sent' : 'unsent';
                    if (result.delivered > 0) this.dmText = '';
                } catch (error) {
                    if (error instanceof DirectMessageRefused && error.code === 'confirm_nip04') {
                        this.dmConfirm = error.reason;
                        this.dmStep = 'confirm';

                        return;
                    }
                    if (error instanceof DirectMessageRefused) {
                        this.dmError = this.label('dm_' + error.code);
                        this.dmStep = 'idle';

                        return;
                    }
                    throw error;
                }
            } catch (error) {
                console.warn('[nostr bar] sending the message failed:', error);
                this.dmError = signerMessage(labels.signer ?? {}, error);
                this.dmStep = 'idle';
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nostrBar', nostrBar);
});
