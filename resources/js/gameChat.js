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
 *   The cache is the game chat's own (resources/js/chatCache.js): text
 *   messages only; a lobby or account card of a match room, which this
 *   subscription receives as well, is stored as null and never shown here.
 * - Mute is for oneself: kept in localStorage and on the account
 *   (Livewire `setMuted`), and muted messages are simply not shown.
 * - Reads back to the game's start (`config.since`, unix seconds), not just
 *   the last two days: a daily game runs for weeks, and a player who comes
 *   back after three days still gets the messages from day one (P5d).
 * - Replies from other NIP-17 clients (resources/js/dmInbox.js): reads on
 *   the player's own DM relays (10050) too, sends each wrap to its
 *   recipient's DM relays too, and shows the opponent's reply without the
 *   `match` tag when it belongs to this game (dmReplies() in nostrChat.js),
 *   marked "via Nostr DM".
 */
import { SimplePool } from 'nostr-tools/pool';
import { entryFor, loadCache, saveCache } from './chatCache.js';
import { isExpired } from './lobbyCards.js';
import { extraInboxRelays, lookupInboxes, relaysFor } from './dmInbox.js';
import { canEncrypt, chatSince, gameMessages, isUntagged, unwrapMessage, wrapMessage } from './nostrChat.js';
import { awaitSigner, ensureSigner } from './nostrSign.js';

const MUTES_KEY = 'esports.chat.mutes';

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
        inboxSub: null,
        // Both players' DM relays (10050), looked up once per start: Promise<Map<pubkey, relays>>.
        inboxes: null,
        closed: false,
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
            } else if (window.nostr) {
                this.status = 'no-nip44';
            } else {
                this.startWhenSignerArrives();
            }
        },

        /** An extension's window.nostr arrives after the page; a paired remote signer comes back without a dialog. */
        async startWhenSignerArrives() {
            this.status = 'starting';
            const found = await awaitSigner();
            if (this.closed || this.status !== 'starting') return;
            if (!found) {
                this.status = 'needs-signer';
            } else if (canEncrypt(window.nostr)) {
                this.start();
            } else {
                this.status = 'no-nip44';
            }
        },

        destroy() {
            window.removeEventListener('chess-chat-notice', this.onNotice);
            this.closed = true;
            this.sub?.close();
            this.inboxSub?.close();
            this.pool?.destroy();
        },

        /** The relay pool (a seam for tests/js/dmReplies.test.mjs). */
        makePool() {
            return new SimplePool();
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
            const { cache, rumors } = loadCache('game', config.me);
            this.cache = cache;
            this.rumors.push(...rumors);

            this.pool = this.makePool();
            const filter = { kinds: [1059], '#p': [config.me], since: chatSince(config.since) };
            const handlers = {
                onevent: (wrap) => this.receive(wrap),
                // NIP-42: the league relay serves a gift wrap only to its authenticated recipient.
                onauth: (template) => window.nostr.signEvent(template),
            };
            this.sub = this.pool.subscribe(config.relays, filter, handlers);

            // NIP-17: other clients send to my DM relays (10050), so read there too, same filter.
            const lookup = [...(config.lookupRelays ?? []), ...config.relays];
            this.inboxes = lookupInboxes([config.me, config.opponent.pubkey], lookup, { trusted: lookup }).catch(() => new Map());
            this.inboxes.then((inboxes) => {
                const extra = extraInboxRelays(config.me, config.relays, inboxes);
                if (!this.closed && extra.length > 0) this.inboxSub = this.pool.subscribe(extra, filter, handlers);
            });
        },

        async receive(wrap) {
            if (Object.hasOwn(this.cache, wrap.id)) return;

            let rumor = null;

            if (!isExpired(wrap)) {
                try {
                    rumor = await unwrapMessage(window.nostr, wrap, config.me);
                } catch {
                    // Not for us, not valid, or a forged sender: never shown.
                    rumor = null;
                }
            }

            // A card of a match room is no game message: null, never its tags or content.
            this.cache[wrap.id] = entryFor('game', rumor);
            if (this.cache[wrap.id] !== null) this.add(this.cache[wrap.id]);
            saveCache('game', config.me, this.cache);
        },

        add(rumor) {
            if (!this.rumors.some((r) => r.id === rumor.id)) this.rumors.push(rumor);
        },

        get messages() {
            const now = Math.floor(Date.now() / 1000);
            // Spectators have no chat of their own, only the server lines.
            const dm = { since: config.since ?? null, settled: config.settled ?? null };
            const own = !config.opponent ? [] : gameMessages(this.rumors.filter((r) => !isExpired(r, now)), { me: config.me, opponent: config.opponent.pubkey, match: config.match, muted: this.muted, dm }).map((rumor) => ({
                id: rumor.id,
                at: rumor.created_at * 1000,
                from: rumor.pubkey === config.me ? 'me' : 'them',
                name: rumor.pubkey === config.me ? config.meName : config.opponent.name,
                text: rumor.content,
                viaDm: isUntagged(rumor),
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

            // Clear at once; the text comes back if sending fails. Sent means
            // one relay took the opponent's copy: waiting for every relay
            // (a slow or dead one times out) left the text in the field.
            this.input = '';
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
                // NIP-17: each wrap also to its recipient's DM relays (10050); the chat relays always.
                const inboxes = (await this.inboxes) ?? new Map();
                const delivered = this.pool.publish(relaysFor(config.opponent.pubkey, config.relays, inboxes), toRecipient, { onauth });
                Promise.allSettled(this.pool.publish(relaysFor(config.me, config.relays, inboxes), toSelf, { onauth }));

                try {
                    await Promise.any(delivered);
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
