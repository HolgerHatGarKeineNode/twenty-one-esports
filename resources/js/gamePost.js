/*
 * "Post this game to my profile" (NIP rev. 9.4): optional, one click to see
 * it, one to sign it; never on its own. Moves are no events, and the league
 * signs the game's record; this is the player's own copy of it on their
 * profile, a kind-64 note with the same PGN that quotes the league's record.
 *
 *   open()  $wire.prepareGamePost() -> the exact note, shown as the preview
 *   post()  signer -> $wire.submitGamePost(): the league checks it against a
 *           fresh template (SignedEventGate), archives it and queues it for
 *           its relays; then the browser sends it to the player's write
 *           relays (NIP-65), as the share posts do (badgeShare.js)
 *
 * Signed events travel as JSON strings (TrimStrings must never touch them).
 * Once posted, `postUrl` opens the note on njump.me (the page gives it for a
 * game posted before; a fresh post builds it from the signed event).
 */
import { encodeBytes } from 'nostr-tools/nip19';
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';
import { publishToRelays, writeRelaysFor } from './relayRead.js';

/**
 * The note's `nevent` in the league's TLV order (id, author, kind; NostrKeys::nevent()), so the
 * link after posting is the one the page shows after a reload. nostr-tools writes the TLVs the other
 * way round: the same note, another string.
 */
export function noteLink({ id, pubkey, kind }) {
    const hex = (h) => h.match(/../g).map((b) => parseInt(b, 16));
    const bytes = [0, 32, ...hex(id), 2, 32, ...hex(pubkey), 3, 4, (kind >>> 24) & 255, (kind >>> 16) & 255, (kind >>> 8) & 255, kind & 255];

    return 'https://njump.me/' + encodeBytes('nevent', new Uint8Array(bytes));
}

export function gamePost({ pubkey, relays = [], posted = false, postUrl = null, preview = false, labels = {} }) {
    return {
        step: posted ? 'done' : 'idle',
        postUrl,
        template: null,
        error: '',
        warning: '',
        onRelays: null,

        init() {
            // Arriving from the game-over card's button: the preview opens, signing still takes a click.
            if (preview && this.step === 'idle') this.open();
        },

        get alt() {
            return (this.template?.tags ?? []).find((tag) => tag[0] === 'alt')?.[1] ?? '';
        },

        message(code) {
            return labels.errors?.[code] ?? labels.errors?.default ?? 'That did not work. Please try again.';
        },

        async open() {
            const wire = this.$wire;
            void wire.$id;
            this.error = '';
            this.warning = '';
            const answer = await wire.prepareGamePost();
            if (!answer?.ok) {
                if (answer?.error === 'already_posted') this.step = 'done';
                else this.error = this.message(answer?.error);

                return;
            }
            this.template = answer.template;
            this.step = 'preview';
        },

        cancel() {
            this.step = 'idle';
            this.template = null;
            this.error = '';
        },

        async post() {
            if (this.step !== 'preview') return;
            // Resolved before the awaits: the button that called this is gone once the step changes.
            const wire = this.$wire;
            void wire.$id;
            this.step = 'posting';
            this.error = '';
            try {
                if (!(await ensureSigner())) {
                    this.error = labels.signer?.noSigner ?? this.message('default');
                    this.step = 'preview';

                    return;
                }
                // The same note as the preview; only created_at is new, so it is inside the league's window.
                const fresh = await wire.prepareGamePost();
                if (!fresh?.ok) {
                    this.error = this.message(fresh?.error);
                    this.step = fresh?.error === 'already_posted' ? 'done' : 'preview';

                    return;
                }
                let signed;
                try {
                    signed = await signTemplate(fresh.template, { pubkey });
                } catch (error) {
                    this.error = signerMessage(labels.signer ?? {}, error);
                    this.step = 'preview';

                    return;
                }
                const answer = await wire.submitGamePost(JSON.stringify(signed));
                if (!answer?.ok) {
                    this.error = this.message(answer?.error);
                    this.step = answer?.error === 'already_posted' ? 'done' : 'preview';

                    return;
                }
                this.postUrl = noteLink(signed);
                this.step = 'done';
                this.onRelays = await publishToRelays(await writeRelaysFor(pubkey, relays), signed);
                if (this.onRelays === 0) this.warning = labels.notOnYourRelays ?? '';
            } catch (error) {
                console.warn('[game post] posting failed:', error);
                this.error = this.message('default');
                if (this.step === 'posting') this.step = 'preview';
            }
        },
    };
}
