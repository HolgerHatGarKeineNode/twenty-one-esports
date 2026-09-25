/**
 * Alpine component for the login buttons: <div x-data="nostrLogin({ messages })">.
 *
 * Flow (server: App\Http\Controllers\Auth\NostrLoginController):
 *   1. POST /auth/nostr/challenge  -> { challenge, url, method, kind, profile_relays }
 *   2. window.nostr.signEvent(kind 27235, tags u/method/challenge)   (NIP-98 style)
 *   3. read the signer's kind-0 profile from a few relays (best effort, read only)
 *   4. POST /auth/nostr/login { event, profile } -> { redirect }
 *
 * Plain fetch instead of a Livewire listener: nothing else is in flight when
 * the session id rotates on login, so no 419 races (see the portal history).
 */
import { connectMill, dropFailedBunker, hasNostrExtension } from './millAuth.js';

const PROFILE_WAIT_MS = 1500;

const DEFAULT_MESSAGES = {
    failed: 'Login failed. Please try again.',
    noSigner: 'No Nostr signer found. Install a Nostr browser extension or use a remote signer.',
    signRejected: 'The signature was not given. Please try again.',
    googleFailed: 'Google login did not work. Please try again or use Nostr.',
    connectAborted: 'Connecting the signer was cancelled or timed out.',
};

function xsrfToken() {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.content;

    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

async function postJson(url, body = {}) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));
    if (! response.ok) {
        const error = new Error(data?.message || `HTTP ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return data;
}

/**
 * Newest kind-0 event of the pubkey from the given relays, or null.
 * Read-only; failures and slow relays are ignored.
 */
async function fetchProfile(pubkey, relays) {
    if (! Array.isArray(relays) || relays.length === 0) {
        return null;
    }

    let pool;
    try {
        const { SimplePool } = await import('nostr-tools/pool');
        pool = new SimplePool();
        // Hard upper bound: a dead relay may otherwise add its connection timeout.
        const events = await Promise.race([
            pool.querySync(relays, { kinds: [0], authors: [pubkey], limit: 1 }, { maxWait: PROFILE_WAIT_MS }),
            new Promise((resolve) => setTimeout(() => resolve([]), PROFILE_WAIT_MS + 500)),
        ]);

        return events.sort((a, b) => b.created_at - a.created_at)[0] ?? null;
    } catch {
        return null;
    } finally {
        try {
            pool?.close(relays);
        } catch {
            // already closed
        }
    }
}

export default ({ messages = {}, challengeUrl = '/auth/nostr/challenge' } = {}) => ({
    busy: false,
    error: null,
    messages: { ...DEFAULT_MESSAGES, ...messages },

    get hasExtension() {
        return hasNostrExtension();
    },

    async loginWithGoogle() {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.error = null;
        try {
            await connectMill({ methods: ['pomegranate'], pomegranate: true });
        } catch (error) {
            dropFailedBunker();
            this.busy = false;
            if (error?.message !== 'cancelled') {
                this.error = this.messages.googleFailed;
            }

            return;
        }

        await this.signIn();
    },

    async loginWithNostr() {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.error = null;

        if (! hasNostrExtension()) {
            try {
                await connectMill({ methods: ['nip46'] });
            } catch (error) {
                dropFailedBunker();
                this.busy = false;
                if (error?.message !== 'cancelled') {
                    this.error = this.messages.connectAborted;
                }

                return;
            }
        }

        await this.signIn();
    },

    async signIn() {
        try {
            if (typeof window.nostr?.signEvent !== 'function') {
                this.fail(this.messages.noSigner);

                return;
            }

            const challenge = await postJson(challengeUrl);

            let signed;
            try {
                signed = await window.nostr.signEvent({
                    kind: challenge.kind,
                    created_at: Math.floor(Date.now() / 1000),
                    tags: [
                        ['u', challenge.url],
                        ['method', challenge.method],
                        ['challenge', challenge.challenge],
                    ],
                    content: '',
                });
            } catch {
                this.fail(this.messages.signRejected);

                return;
            }

            // Some extensions return proxies (cloneInto) that fetch cannot
            // serialise reliably; a JSON round trip gives a plain object.
            const event = JSON.parse(JSON.stringify(signed));
            const profile = await fetchProfile(event.pubkey, challenge.profile_relays);

            const result = await postJson(challenge.url, { event, profile });

            // Full page load: fresh document, fresh CSRF token after the session rotation.
            window.location.assign(result.redirect);
        } catch {
            this.fail(this.messages.failed);
        }
    },

    fail(message) {
        dropFailedBunker();
        this.error = message;
        this.busy = false;
    },
});
