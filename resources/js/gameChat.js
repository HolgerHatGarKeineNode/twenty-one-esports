/**
 * The chat panel of a game, live or daily (ChessGame.dc.html, "Chat"): NIP-17 messages
 * between the two players over the configured relays, never through the
 * league server, which cannot read them and stores none (NIP "Chat").
 *
 * - Starts on its own only if a signer that can do NIP-44 is already there
 *   (a browser extension). A remote signer (NIP-46, Google) is connected on
 *   "Open chat", so no signer window pops up just for loading a game.
 * - A signer without NIP-44 gets the fallback message instead of a chat.
 * - Decrypted messages are cached on this device per wrap id, so a reload
 *   does not ask the signer to decrypt everything again (NIP-17 allows it).
 * - Mute is for oneself: kept in localStorage and on the account
 *   (Livewire `setMuted`), and muted messages are simply not shown.
 * - Reads back to the game's start (`config.since`, unix seconds), not just
 *   the last two days: a daily game runs for weeks, and a player who comes
 *   back after three days still gets the messages from day one (P5d).
 */
import { SimplePool } from 'nostr-tools/pool';
import { canEncrypt, chatSince, gameMessages, unwrapMessage, wrapMessage } from './nostrChat.js';
import { ensureSigner } from './nostrSign.js';

const MUTES_KEY = 'esports.chat.mutes';
const CACHE_PREFIX = 'esports.chat.cache.';
const CACHE_LIMIT = 500;

function readJson(key, fallback) {
    try {
        const value = JSON.parse(localStorage.getItem(key) ?? 'null');

        return value ?? fallback;
    } catch {
        return fallback;
    }
}

function writeJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // private mode or full storage: the chat still works, it just forgets
    }
}

export function gameChat(config) {
    return {
        rumors: [],
        notices: [],
        input: '',
        status: 'idle',
        sending: false,
        error: '',
        muted: [],
        cache: {},
        pool: null,
        sub: null,
        t: config.labels,

        init() {
            this.muted = [...new Set(config.muted ?? [])];
            writeJson(MUTES_KEY, this.muted);

            this.onNotice = (event) => this.notices.push(event.detail);
            window.addEventListener('chess-chat-notice', this.onNotice);

            if (!config.me || !config.opponent) {
                this.status = 'spectator';
            } else if ((config.relays ?? []).length === 0) {
                this.status = 'no-relays';
            } else if (window.nostr && canEncrypt(window.nostr)) {
                this.start();
            } else {
                this.status = window.nostr ? 'no-nip44' : 'needs-signer';
            }
        },

        destroy() {
            window.removeEventListener('chess-chat-notice', this.onNotice);
            this.sub?.close();
            this.pool?.destroy();
        },

        async connect() {
            this.status = 'starting';
            this.error = '';

            if (!(await ensureSigner())) {
                this.status = 'needs-signer';
                this.error = this.t.noSigner;

                return;
            }

            if (!canEncrypt(window.nostr)) {
                this.status = 'no-nip44';

                return;
            }

            this.start();
        },

        start() {
            this.status = 'live';
            this.cache = readJson(CACHE_PREFIX + config.me, {});

            for (const rumor of Object.values(this.cache)) {
                if (rumor) this.rumors.push(rumor);
            }

            this.pool = new SimplePool();
            this.sub = this.pool.subscribe(
                config.relays,
                { kinds: [1059], '#p': [config.me], since: chatSince(config.since) },
                {
                    onevent: (wrap) => this.receive(wrap),
                    // NIP-42: the league relay serves a gift wrap only to its authenticated recipient.
                    onauth: (template) => window.nostr.signEvent(template),
                },
            );
        },

        async receive(wrap) {
            if (Object.hasOwn(this.cache, wrap.id)) return;

            try {
                this.cache[wrap.id] = await unwrapMessage(window.nostr, wrap, config.me);
                this.add(this.cache[wrap.id]);
            } catch {
                // Not for us, not valid, or a forged sender: never shown.
                this.cache[wrap.id] = null;
            }

            const ids = Object.keys(this.cache);
            if (ids.length > CACHE_LIMIT) ids.slice(0, ids.length - CACHE_LIMIT).forEach((id) => delete this.cache[id]);
            writeJson(CACHE_PREFIX + config.me, this.cache);
        },

        add(rumor) {
            if (!this.rumors.some((r) => r.id === rumor.id)) this.rumors.push(rumor);
        },

        get messages() {
            // Spectators have no chat of their own, only the server lines.
            const own = !config.opponent ? [] : gameMessages(this.rumors, { me: config.me, opponent: config.opponent.pubkey, match: config.match, muted: this.muted }).map((rumor) => ({
                id: rumor.id,
                at: rumor.created_at * 1000,
                from: rumor.pubkey === config.me ? 'me' : 'them',
                name: rumor.pubkey === config.me ? config.meName : config.opponent.name,
                text: rumor.content,
            }));
            const notices = this.notices.map((n, i) => ({ id: 'n' + i, at: n.at, from: 'server', name: this.t.server, text: n.text }));

            return [...own, ...notices].sort((a, b) => a.at - b.at);
        },

        time(ms) {
            const d = new Date(ms);

            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        },

        async send() {
            const content = this.input.trim();
            if (!content || this.sending || this.status !== 'live') return;

            this.sending = true;
            this.error = '';

            try {
                const { rumor, toRecipient, toSelf } = await wrapMessage(window.nostr, {
                    sender: config.me,
                    recipient: config.opponent.pubkey,
                    content,
                    match: config.match,
                });
                const onauth = (template) => window.nostr.signEvent(template);
                const results = await Promise.allSettled([
                    ...this.pool.publish(config.relays, toRecipient, { onauth }),
                    ...this.pool.publish(config.relays, toSelf, { onauth }),
                ]);

                if (!results.some((r) => r.status === 'fulfilled')) {
                    this.error = this.t.notSent;

                    return;
                }

                this.add(rumor);
                this.input = '';
            } catch {
                this.error = this.t.failed;
            } finally {
                this.sending = false;
            }
        },

        get opponentMuted() {
            return this.muted.includes(config.opponent?.pubkey);
        },

        async toggleMute() {
            const pubkey = config.opponent.pubkey;
            const mute = !this.opponentMuted;
            this.muted = mute ? [...this.muted, pubkey] : this.muted.filter((p) => p !== pubkey);
            writeJson(MUTES_KEY, this.muted);
            await this.$wire.setMuted(pubkey, mute);
        },
    };
}
