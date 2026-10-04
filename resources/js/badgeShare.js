/**
 * Rank badges on the player's Nostr profile and share posts (P11).
 *
 * profileBadge: "Show on my Nostr profile" (NIP-58 kind 10008).
 *   1. read the player's lists (resources/js/relayRead.js): the relay list,
 *      then the newest VALID 10008 and 30008 `profile_badges`; a relay counts
 *      as read only after its EOSE, and at least one write relay must answer
 *   2. $wire.prepareProfile(badge, found, read) -> { template, kept }: the
 *      league re-checks the events and builds the new list, every entry kept
 *      (App\Support\Badges\ProfileBadges); it refuses when no write relay was
 *      read, or when the read came back empty but it knows a list
 *   3. the dialog says how many badges stay and how many relays answered;
 *      the player signs
 *   4. the signed list goes to the player's write relays first; only when at
 *      least one answered OK does it go to the league ($wire.submitProfile())
 *      and the badge count as shown
 *
 * sharePost: the share button of one moment (P11, P46). $wire.prepareShare()
 *   -> kind 1 template, shown as the preview with its card; only "Sign and
 *   post" signs it (a fresh template, which must equal the preview), publishes
 *   it to the write relays, then $wire.submitShare().
 *
 * Signed events travel as JSON strings (TrimStrings must never touch them).
 */
import { ensureSigner } from './nostrSign.js';
import { sameDraft, signerMessage, signTemplate } from './signing.js';
import { publishToRelays, readProfileBadges, writeRelaysFor } from './relayRead.js';

function profileBadge({ pubkey, relays = [], messages = {} }) {
    return {
        step: 'idle',
        error: null,
        warning: null,
        kept: 0,
        answered: 0,
        asked: 0,
        badge: null,
        found: [],
        read: false,
        writeRelays: [],
        template: null,
        published: 0,

        /**
         * `allowNewList`: the player chose "Start a new list" after being told
         * that no badge list and no relay list of theirs was found.
         */
        async start(badge, allowNewList = false) {
            if (this.step === 'reading' || this.step === 'signing') {
                return;
            }

            this.error = null;
            this.warning = null;
            this.badge = badge;
            this.step = 'reading';

            try {
                const result = await readProfileBadges(pubkey, relays, { allowNewList });

                // Re-audit: a new badge list only on the player's word.
                if (! result.read && result.relayList === 'confirm_new_list') {
                    this.step = 'newList';

                    return;
                }

                // P45 audit F2: no relay list, but a badge list somewhere; where the newest lives is unknown.
                if (! result.read && result.relayList === 'none') {
                    this.error = messages.noRelayList ?? messages.failed ?? 'That did not work. Please try again.';
                    this.step = 'idle';

                    return;
                }

                this.found = result.found;
                this.read = result.read;
                this.answered = result.answered;
                this.asked = result.asked;
                this.writeRelays = result.writeRelays;

                const prepared = await this.$wire.prepareProfile(badge, JSON.stringify(this.found), this.read);

                if (! prepared || typeof prepared !== 'object') {
                    this.step = 'idle';

                    return;
                }

                this.template = prepared.template;
                this.kept = prepared.kept;
                this.step = 'confirm';
            } catch (error) {
                console.warn('[badges] reading the profile badges failed:', error);
                this.error = messages.failed ?? 'That did not work. Please try again.';
                this.step = 'idle';
            }
        },

        cancel() {
            this.step = 'idle';
            this.template = null;
        },

        async confirm() {
            if (this.step !== 'confirm') {
                return;
            }

            this.step = 'signing';

            try {
                if (! (await ensureSigner())) {
                    this.error = messages.noSigner;
                    this.step = 'confirm';

                    return;
                }

                let signed;
                try {
                    signed = await signTemplate(this.template, { pubkey });
                } catch (error) {
                    this.error = signerMessage(messages, error);
                    this.step = 'confirm';

                    return;
                }

                // The player's relays first: the league records the list only once one of them holds it,
                // so "On your Nostr profile" never shows for a list no relay of the player has.
                this.published = await publishToRelays(this.writeRelays, signed);

                if (this.published === 0) {
                    this.warning = messages.notPublished ?? 'None of your relays took the new list. Nothing was changed.';
                    this.step = 'idle';

                    return;
                }

                const accepted = await this.$wire.submitProfile(this.badge, JSON.stringify(this.found), this.read, JSON.stringify(signed));
                this.step = accepted === true ? 'done' : 'idle';
            } catch (error) {
                console.warn('[badges] adding the badge failed:', error);
                this.error = messages.failed ?? 'That did not work. Please try again.';
                this.step = 'idle';
            }
        },
    };
}

/**
 * The preview sheet is one element per page (performance plan P4): cloned once from the first share button's
 * <template data-share-sheet>, then moved into the slot of whichever button opened it and bound to that button's
 * state (Alpine.initTree after Alpine.destroyTree), so its card and note stay right under that button.
 */
let sheet = null;
let sheetOwner = null;

function sharePost({ pubkey, relays = [], messages = {}, preview = null }) {
    return {
        // idle -> preview (the exact note and its card) -> posting -> done; nothing is signed before "Sign and post".
        step: 'idle',
        template: null,
        error: null,
        warning: null,
        published: 0,
        preview,

        /** Moves the page's one preview sheet under this button; false when another button is posting with it. */
        mountSheet() {
            if (sheetOwner !== null && sheetOwner !== this && sheetOwner.step === 'posting') {
                return false;
            }

            const slot = this.$root.querySelector('[data-share-slot]');
            const source = document.querySelector('template[data-share-sheet]');

            if (sheet === null && source !== null) {
                sheet = source.content.firstElementChild.cloneNode(true);
            }

            if (slot === null || sheet === null) {
                return false;
            }

            if (sheetOwner !== null && sheetOwner !== this) {
                sheetOwner.cancel();
            }

            if (sheet.parentElement !== slot) {
                if (sheet.isConnected) {
                    window.Alpine.destroyTree(sheet);
                }

                slot.append(sheet);
                window.Alpine.initTree(sheet);
            }

            sheetOwner = this;

            return true;
        },

        get busy() {
            return this.step === 'opening' || this.step === 'posting';
        },

        get done() {
            return this.step === 'done';
        },

        async open() {
            if (this.step !== 'idle') {
                return;
            }

            // Resolved before the awaits: the button that called this is hidden once the step changes.
            const wire = this.$wire;
            void wire.$id;
            this.step = 'opening';
            this.error = null;
            this.warning = null;

            try {
                const template = await wire.prepareShare();

                if (! template || typeof template !== 'object') {
                    this.step = 'idle';

                    return;
                }

                if (! this.mountSheet()) {
                    this.error = messages.failed ?? 'That did not work. Please try again.';
                    this.step = 'idle';

                    return;
                }

                this.template = template;
                this.step = 'preview';
            } catch (error) {
                console.warn('[share] preparing the post failed:', error);
                this.error = messages.failed ?? 'That did not work. Please try again.';
                this.step = 'idle';
            }
        },

        cancel() {
            if (this.step === 'posting') {
                return;
            }

            this.step = 'idle';
            this.template = null;
            this.error = null;
            this.warning = null;
        },

        async post() {
            if (this.step !== 'preview') {
                return;
            }

            const wire = this.$wire;
            void wire.$id;
            this.step = 'posting';
            this.error = null;
            this.warning = null;

            try {
                if (! (await ensureSigner())) {
                    this.error = messages.noSigner;
                    this.step = 'preview';

                    return;
                }

                // A fresh template (created_at inside the league's window); if the note changed since the
                // preview (a new card version, a renamed opponent), it is shown again instead of signed.
                const fresh = await wire.prepareShare();

                if (! fresh || typeof fresh !== 'object') {
                    this.step = 'idle';

                    return;
                }

                if (! sameDraft(fresh, this.template)) {
                    this.template = fresh;
                    this.warning = messages.changed ?? 'The post changed. Check it again, then sign.';
                    this.step = 'preview';

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

                this.published = await publishToRelays(await writeRelaysFor(pubkey, relays), signed);

                if (this.published === 0) {
                    this.warning = messages.notPosted ?? 'None of your relays took the post. Try again later.';
                    this.step = 'preview';

                    return;
                }

                this.step = (await wire.submitShare(JSON.stringify(signed))) === true ? 'done' : 'preview';
            } catch (error) {
                console.warn('[share] posting failed:', error);
                this.error = messages.failed ?? 'That did not work. Please try again.';
                this.step = 'preview';
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('profileBadge', profileBadge);
    window.Alpine.data('sharePost', sharePost);
});
