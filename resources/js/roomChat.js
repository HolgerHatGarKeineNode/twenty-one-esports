/**
 * The chat of a series match room (MatchRoom.dc.html, "Chat · private to both
 * lineups"): NIP-17 group messages between the players of the two lineups,
 * over the configured relays, never through the league server, which cannot
 * read them and stores none (NIP "Chat"). Same signer rules as the game chat
 * (resources/js/gameChat.js): starts on its own only with a NIP-44 capable
 * extension, otherwise on "Open chat". Mute is per sender, for oneself.
 */
import { SimplePool } from 'nostr-tools/pool';
import { canEncrypt, chatSince, roomMessages, unwrapMessage, wrapGroupMessage } from './nostrChat.js';
import { ensureSigner } from './nostrSign.js';

const MUTES_KEY = 'esports.chat.mutes';
const CACHE_PREFIX = 'esports.chat.cache.';
const CACHE_LIMIT = 500;

function readJson(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) ?? 'null') ?? fallback;
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

export function roomChat(config) {
    return {
        rumors: [],
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

            if (!config.me) {
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
                // Back to the series' challenge (`config.since`), not just two days: a series runs for days.
                { kinds: [1059], '#p': [config.me], since: chatSince(config.since) },
                {
                    onevent: (wrap) => this.receive(wrap),
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
            const members = config.members.map((m) => m.pubkey);

            return roomMessages(this.rumors, { me: config.me, members, match: config.match, muted: this.muted }).map((rumor) => ({
                id: rumor.id,
                at: rumor.created_at * 1000,
                pubkey: rumor.pubkey,
                from: rumor.pubkey === config.me ? 'me' : 'them',
                name: rumor.pubkey === config.me ? this.t.you : (config.members.find((m) => m.pubkey === rumor.pubkey)?.name ?? ''),
                text: rumor.content,
            }));
        },

        time(ms) {
            const d = new Date(ms);

            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        },

        async send() {
            const content = this.input.trim();
            if (!content || this.sending || this.status !== 'live') return;

            // Clear at once; the text comes back if sending fails. Sent means
            // one relay took a copy, instead of waiting for every relay.
            this.input = '';
            this.sending = true;
            this.error = '';

            try {
                const { rumor, wraps } = await wrapGroupMessage(window.nostr, {
                    sender: config.me,
                    recipients: config.members.map((m) => m.pubkey),
                    content,
                    match: config.match,
                });
                const onauth = (template) => window.nostr.signEvent(template);

                try {
                    await Promise.any(wraps.flatMap((wrap) => this.pool.publish(config.relays, wrap, { onauth })));
                } catch {
                    this.error = this.t.notSent;
                    this.input ||= content;

                    return;
                }

                this.add(rumor);
            } catch (error) {
                console.warn('[chat] sending failed', error);
                this.error = this.t.failed;
                this.input ||= content;
            } finally {
                this.sending = false;
            }
        },

        isMuted(pubkey) {
            return this.muted.includes(pubkey);
        },

        async toggleMute(pubkey) {
            const mute = !this.isMuted(pubkey);
            this.muted = mute ? [...this.muted, pubkey] : this.muted.filter((p) => p !== pubkey);
            writeJson(MUTES_KEY, this.muted);
            await this.$wire.setMuted(pubkey, mute);
        },
    };
}
