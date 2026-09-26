/**
 * Rank badges on the player's Nostr profile and share posts (P11).
 *
 * profileBadge: "Show on my Nostr profile" (NIP-58 kind 10008).
 *   1. read the player's NIP-65 relay list (10002) from the read relays, then
 *      the newest 10008 and a deprecated 30008 `profile_badges` from their
 *      write relays and the read relays
 *   2. $wire.prepareProfile(badge, found, reached) -> { template, kept }: the
 *      league builds the new list from the newest one it knows, every entry
 *      kept (App\Support\Badges\ProfileBadges); it refuses when no relay was
 *      reached and it knows no list, so an outage can never wipe a profile
 *   3. the player confirms ("N badges stay"), signs, $wire.submitProfile()
 *   4. the signed list goes to the player's write relays (fallback: the read
 *      relays); the league queues it for its own relays
 *
 * sharePost: the share button of one moment. $wire.prepareShare() -> kind 1
 *   template, sign, $wire.submitShare(), publish to the write relays.
 *
 * Signed events travel as JSON strings (TrimStrings must never touch them).
 */
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';

const WAIT_MS = 4000;

function unique(list) {
    return [...new Set(list.filter((url) => typeof url === 'string' && /^wss?:\/\//.test(url)))];
}

async function withPool(callback) {
    const { SimplePool } = await import('nostr-tools/pool');
    const pool = new SimplePool();
    let reached = false;
    pool.onRelayConnectionSuccess = () => {
        reached = true;
    };

    try {
        return await callback(pool, () => reached);
    } finally {
        try {
            pool.destroy();
        } catch {
            // already closed
        }
    }
}

function query(pool, relays, filter) {
    if (relays.length === 0) {
        return Promise.resolve([]);
    }

    return Promise.race([
        pool.querySync(relays, filter, { maxWait: WAIT_MS }),
        new Promise((resolve) => setTimeout(() => resolve([]), WAIT_MS + 500)),
    ]);
}

function newest(events, pubkey, kind) {
    return events
        .filter((event) => event.pubkey === pubkey && event.kind === kind)
        .sort((a, b) => b.created_at - a.created_at || (a.id < b.id ? -1 : 1))[0];
}

/** The player's write relays from their newest 10002, or []. */
async function writeRelays(pool, relays, pubkey) {
    const list = newest(await query(pool, relays, { kinds: [10002], authors: [pubkey], limit: 1 }), pubkey, 10002);

    return unique((list?.tags ?? [])
        .filter((tag) => tag[0] === 'r' && (tag[2] === undefined || tag[2] === 'write'))
        .map((tag) => tag[1]));
}

/** Publish to every relay; resolves with the number that answered OK. */
async function publish(pool, relays, event) {
    if (relays.length === 0) {
        return 0;
    }

    const results = await Promise.allSettled(pool.publish(relays, event).map((promise) => Promise.race([
        promise,
        new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), WAIT_MS)),
    ])));

    return results.filter((result) => result.status === 'fulfilled').length;
}

function profileBadge({ pubkey, relays = [], messages = {} }) {
    return {
        step: 'idle',
        error: null,
        kept: 0,
        badge: null,
        found: [],
        reached: false,
        template: null,
        published: 0,

        async start(badge) {
            if (this.step === 'reading' || this.step === 'signing') {
                return;
            }

            this.error = null;
            this.badge = badge;
            this.step = 'reading';

            try {
                const read = await withPool(async (pool, reached) => {
                    const own = unique([...relays, ...(await writeRelays(pool, unique(relays), pubkey))]);
                    const events = await query(pool, own, { kinds: [10008], authors: [pubkey] });
                    const legacy = await query(pool, own, { kinds: [30008], authors: [pubkey], '#d': ['profile_badges'] });

                    return { events: [newest(events, pubkey, 10008), newest(legacy, pubkey, 30008)].filter(Boolean), reached: reached() };
                });

                this.found = read.events;
                this.reached = read.reached;
                const prepared = await this.$wire.prepareProfile(badge, JSON.stringify(this.found), this.reached);

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

                const accepted = await this.$wire.submitProfile(this.badge, JSON.stringify(this.found), this.reached, JSON.stringify(signed));

                if (accepted !== true) {
                    this.step = 'idle';

                    return;
                }

                this.published = await withPool(async (pool) => {
                    const targets = await writeRelays(pool, unique(relays), pubkey);

                    return publish(pool, targets.length > 0 ? targets : unique(relays), signed);
                });
                this.step = 'done';
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
        published: 0,

        async share() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;

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

                if ((await this.$wire.submitShare(JSON.stringify(signed))) !== true) {
                    return;
                }

                this.published = await withPool(async (pool) => {
                    const targets = await writeRelays(pool, unique(relays), pubkey);

                    return publish(pool, targets.length > 0 ? targets : unique(relays), signed);
                });
                this.done = true;
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
