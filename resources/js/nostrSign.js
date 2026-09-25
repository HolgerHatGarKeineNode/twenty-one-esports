/**
 * Signing for clan actions (P4): <div x-data="nostrAction({ pubkey, messages })">.
 *
 * Flow (server: App\Support\Clans\ClanService):
 *   1. $wire.<prepare>(...)       -> unsigned templates { kind, tags, content, created_at }
 *   2. window.nostr.signEvent(t)  -> one signature per template (NIP-07, or the
 *                                    NIP-46 / Google signer from the login module)
 *   3. $wire.<submit>(json)       -> the server rebuilds the templates, checks each
 *                                    signed event against them and applies the action
 *
 * The signed events travel as one JSON string: Laravel's TrimStrings and
 * ConvertEmptyStringsToNull must never touch a signed payload.
 */
import { connectMill, dropFailedBunker, hasNostrExtension } from './millAuth.js';

const DEFAULT_MESSAGES = {
    noSigner: 'No Nostr signer found. Install a Nostr browser extension or use a remote signer.',
    rejected: 'The confirmation was not given. Please try again.',
    wrongKey: 'This signer holds a different key than the one you logged in with.',
    failed: 'That did not work. Please try again.',
};

export async function ensureSigner() {
    if (hasNostrExtension() || typeof window.nostr?.signEvent === 'function') {
        return true;
    }

    try {
        await connectMill({ methods: ['nip46', 'pomegranate'], pomegranate: true });
    } catch {
        dropFailedBunker();

        return false;
    }

    return typeof window.nostr?.signEvent === 'function';
}

function nostrAction({ pubkey = null, messages = {} } = {}) {
    return {
        busy: false,
        error: null,
        messages: { ...DEFAULT_MESSAGES, ...messages },

        /**
         * @param {string} prepare  Livewire method returning the templates
         * @param {string} submit   Livewire method taking the signed events as JSON
         * @param {Array} args      arguments for both methods
         */
        async run(prepare, submit, ...args) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;

            try {
                const templates = await this.$wire[prepare](...args);

                if (! Array.isArray(templates)) {
                    // The server refused before signing and has set its own message.
                    return;
                }

                if (templates.length > 0 && ! (await ensureSigner())) {
                    this.error = this.messages.noSigner;

                    return;
                }

                const signed = [];
                for (const template of templates) {
                    let event;
                    try {
                        event = await window.nostr.signEvent({
                            kind: template.kind,
                            created_at: Math.max(template.created_at, Math.floor(Date.now() / 1000)),
                            tags: template.tags,
                            content: template.content,
                        });
                    } catch {
                        this.error = this.messages.rejected;

                        return;
                    }

                    // Extensions may hand back proxies; a JSON round trip gives a plain object.
                    event = JSON.parse(JSON.stringify(event));

                    if (pubkey && event.pubkey !== pubkey) {
                        this.error = this.messages.wrongKey;

                        return;
                    }

                    signed.push(event);
                }

                await this.$wire[submit](...args, JSON.stringify(signed));
            } catch {
                this.error = this.messages.failed;
            } finally {
                this.busy = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nostrAction', nostrAction);
});
