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
 * sharePost: the share button of one moment. $wire.prepareShare() -> kind 1
 *   template, sign, publish to the write relays, then $wire.submitShare().
 *
 * Signed events travel as JSON strings (TrimStrings must never touch them).
 */
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';
import { newest, publishToRelays, readProfileBadges, readRelays, relayUrls, writeRelaysOf } from './relayRead.js';

/** The player's write relays, or the configured relays when they have no relay list. */
async function writeRelaysFor(pubkey, relays) {
    const lists = await readRelays(relays, [{ kinds: [10002], authors: [pubkey] }]);
    const own = writeRelaysOf(newest(lists.flatMap((result) => result.events), pubkey, 10002));

    return own.length > 0 ? own : relayUrls(relays);
}

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

        async start(badge) {
            if (this.step === 'reading' || this.step === 'signing') {
                return;
            }

            this.error = null;
            this.warning = null;
            this.badge = badge;
            this.step = 'reading';

            try {
                const result = await readProfileBadges(pubkey, relays);
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

function sharePost({ pubkey, relays = [], messages = {} }) {
    return {
        busy: false,
        done: false,
        error: null,
        warning: null,
        published: 0,

        async share() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;
            this.warning = null;

            try {
                const template = await this.$wire.prepareShare();

                if (! template || typeof template !== 'object') {
                    return;
                }

                if (! (await ensureSigner())) {
                    this.error = messages.noSigner;

                    return;
                }

                let signed;
                try {
                    signed = await signTemplate(template, { pubkey });
                } catch (error) {
                    this.error = signerMessage(messages, error);

                    return;
                }

                this.published = await publishToRelays(await writeRelaysFor(pubkey, relays), signed);

                if (this.published === 0) {
                    this.warning = messages.notPosted ?? 'None of your relays took the post. Try again later.';

                    return;
                }

                this.done = (await this.$wire.submitShare(JSON.stringify(signed))) === true;
            } catch (error) {
                console.warn('[share] posting failed:', error);
                this.error = messages.failed ?? 'That did not work. Please try again.';
            } finally {
                this.busy = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('profileBadge', profileBadge);
    window.Alpine.data('sharePost', sharePost);
});
