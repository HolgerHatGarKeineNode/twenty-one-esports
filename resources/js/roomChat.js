/**
 * The chat of a series match room (MatchRoom.dc.html, "Chat · private to both
 * lineups"): NIP-17 group messages between the players of the two lineups,
 * over the configured relays, never through the league server, which cannot
 * read them and stores none (NIP "Chat"). Same signer rules as the game chat
 * (resources/js/gameChat.js): starts on its own only with a NIP-44 capable
 * extension, otherwise on "Open chat". Mute is per sender, for oneself.
 *
 * A casual 1v1 room (`config.casual`, P23 S2) adds the NIP's "Lobby and
 * account cards" (resources/js/lobbyCards.js):
 *
 *   - every message, text or card, carries the room's NIP-40 `expiration`
 *     on rumor, seal and wrap; an expired message is hidden and dropped
 *     from the cache;
 *   - the host shares the lobby as a card, and only after a relay took the
 *     wrap to the OPPONENT (not merely the copy to self) tells the league,
 *     which stores the flag `lobby_shared_at` and nothing else;
 *   - the guest's client tells the league once it opened a valid card from
 *     the host (`lobby_seen_at`);
 *   - cards never enter the local cache: a stub marks the wrap, and the card
 *     is opened again from the relays after a reload.
 */
import { SimplePool } from 'nostr-tools/pool';
import { HOST_CARD, accountTags, cacheEntry, cardContent, casualExpiration, isExpired, lobbyTags, openCardIds, parseCard, randomPassword } from './lobbyCards.js';
import { canEncrypt, chatSince, roomMessages, unwrapMessage, wrapGroupMessage } from './nostrChat.js';
import { ensureSigner } from './nostrSign.js';

const MUTES_KEY = 'esports.chat.mutes';
const CACHE_PREFIX = 'esports.chat.cache.';
const CACHE_LIMIT = 500;

/** The guest asks again this often when its "seen" beat the host's "shared" to the league. */
const SEEN_RETRIES = [1000, 2000, 4000, 8000, 16000];

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

const nowSeconds = () => Math.floor(Date.now() / 1000);

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

        // Casual 1v1 (null in every other room): the league's state, refreshed by the page on each render.
        casual: config.casual ?? null,
        composer: '',
        lobbyName: '',
        lobbyPassword: '',
        accountId: '',
        cardError: '',
        revealed: [],
        seenBusy: false,

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
            const now = nowSeconds();
            let pruned = false;

            for (const [id, entry] of Object.entries(this.cache)) {
                if (entry && !entry.stub && isExpired(entry, now)) {
                    this.cache[id] = null;
                    pruned = true;
                } else if (entry && !entry.stub) {
                    this.rumors.push(entry);
                }
            }

            if (pruned) writeJson(CACHE_PREFIX + config.me, this.cache);

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
            // A stub is a card seen before: open it again, it was never stored.
            if (Object.hasOwn(this.cache, wrap.id) && !this.cache[wrap.id]?.stub) return;

            let rumor = null;

            if (!isExpired(wrap)) {
                try {
                    rumor = await unwrapMessage(window.nostr, wrap, config.me);
                } catch {
                    rumor = null;
                }
            }

            this.cache[wrap.id] = cacheEntry(rumor);
            if (rumor !== null && this.cache[wrap.id] !== null) this.add(rumor);

            const ids = Object.keys(this.cache);
            if (ids.length > CACHE_LIMIT) ids.slice(0, ids.length - CACHE_LIMIT).forEach((id) => delete this.cache[id]);
            writeJson(CACHE_PREFIX + config.me, this.cache);
        },

        add(rumor) {
            if (!this.rumors.some((r) => r.id === rumor.id)) this.rumors.push(rumor);
            this.checkSeen();
        },

        /** The page's casual state after a render (`casual-room` from the Livewire component). */
        casualUpdate(state) {
            if (!this.casual || !state) return;
            this.casual = { ...this.casual, ...state };
            this.checkSeen();
        },

        memberName(pubkey) {
            return pubkey === config.me ? this.t.you : (config.members.find((m) => m.pubkey === pubkey)?.name ?? '');
        },

        get messages() {
            const now = nowSeconds();
            const members = config.members.map((m) => m.pubkey);
            const rumors = roomMessages(
                this.rumors.filter((r) => !isExpired(r, now)),
                { me: config.me, members, match: config.match, muted: [] },
            );
            // Cards exist only in a casual room; elsewhere every message is text, as other clients show it.
            const cards = new Map();

            if (this.casual) {
                for (const rumor of rumors) {
                    const card = parseCard(rumor, now);
                    if (card && !card.invalid) cards.set(rumor.id, card);
                }
            }

            const open = openCardIds([...cards].map(([id, card]) => ({ rumor: rumors.find((r) => r.id === id), card })));

            return rumors
                // A muted player's messages stay hidden; their cards collapse while the match is open (NIP "Mute and abuse").
                .filter((rumor) => !this.isMuted(rumor.pubkey) || (cards.has(rumor.id) && this.casual?.open))
                .map((rumor) => {
                    const card = cards.get(rumor.id) ?? null;

                    return {
                        id: rumor.id,
                        at: rumor.created_at * 1000,
                        pubkey: rumor.pubkey,
                        from: rumor.pubkey === config.me ? 'me' : 'them',
                        name: this.memberName(rumor.pubkey),
                        text: rumor.content,
                        card: card === null ? null : this.cardView(card, open.has(rumor.id)),
                        mutedCard: card !== null && this.isMuted(rumor.pubkey) && !this.revealed.includes(rumor.id),
                    };
                });
        },

        /** What the template draws of a card: title, fields as text, and its state. */
        cardView(card, isOpen) {
            const fields = card.closed ? [] : card.kind === 'lobby'
                ? [{ label: this.t.cardName, value: card.name }, { label: this.t.cardPassword, value: card.password }]
                : [{ label: this.t.cardEaId, value: card.id }];

            return {
                kind: card.kind,
                marker: card.kind === 'lobby' ? card.game : card.service,
                closed: card.closed,
                title: card.kind === 'lobby' ? this.t.lobbyTitle : this.t.accountTitle,
                state: !isOpen ? 'replaced' : card.closed ? 'closed' : 'open',
                note: !isOpen ? this.t.cardReplaced : card.closed ? (card.kind === 'lobby' ? this.t.lobbyClosed : this.t.accountWithdrawn) : '',
                fields,
            };
        },

        reveal(id) {
            if (!this.revealed.includes(id)) this.revealed = [...this.revealed, id];
            this.checkSeen();
        },

        time(ms) {
            const d = new Date(ms);

            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        },

        /** NIP-40 time of every message in this room, null outside a casual 1v1. */
        expiration() {
            return this.casual?.expiresFrom ? casualExpiration(this.casual.expiresFrom) : null;
        },

        /**
         * Seal, wrap and publish one rumor to every member and to self.
         * `needOpponent`: success only once a relay took a wrap to someone
         * else, the copy to self alone does not count. Resolves to the rumor,
         * or null after setting `error`.
         */
        async deliver({ content, tags = [], needOpponent = false }) {
            const { rumor, wraps, targets } = await wrapGroupMessage(window.nostr, {
                sender: config.me,
                recipients: config.members.map((m) => m.pubkey),
                content,
                match: config.match,
                tags,
                expiration: this.expiration(),
            });
            const onauth = (template) => window.nostr.signEvent(template);
            const published = wraps.map((wrap, i) => ({ target: targets[i], acks: this.pool.publish(config.relays, wrap, { onauth }) }));
            const counted = needOpponent ? published.filter((p) => p.target !== config.me) : published;

            try {
                await Promise.any(counted.flatMap((p) => p.acks));
            } catch {
                this.error = needOpponent ? this.t.cardNotSent : this.t.notSent;

                return null;
            }

            this.add(rumor);

            return rumor;
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
                if ((await this.deliver({ content })) === null) this.input ||= content;
            } catch (error) {
                console.warn('[chat] sending failed', error);
                this.error = this.t.failed;
                this.input ||= content;
            } finally {
                this.sending = false;
            }
        },

        /* ---------- Cards (casual 1v1) ---------- */

        /** Which cards this player may send here: `lobby` (Rocket League host), `account` (EA FC, both). */
        get cardKinds() {
            if (!this.casual || !this.casual.started || !this.casual.open) return [];
            const host = HOST_CARD[this.casual.game];
            if (host?.marker === 'lobby') return this.casual.isHost ? ['lobby'] : [];

            return host?.marker === 'account' ? ['account'] : [];
        },

        /** A card goes to exactly one opponent (NIP: refused in a 1v1 room naming more members). */
        oneOpponent() {
            return config.members.filter((m) => m.pubkey !== config.me).length === 1;
        },

        myOpenCard(kind) {
            return this.messages.find((m) => m.from === 'me' && m.card?.kind === kind && m.card.state === 'open') ?? null;
        },

        openComposer(kind) {
            const mine = this.myOpenCard(kind);
            this.cardError = '';
            this.composer = kind;

            if (kind === 'lobby') {
                this.lobbyName = mine?.card.fields[0].value ?? this.casual.lobbyName ?? '';
                // A fresh password for every new lobby: an old one may sit in stored wraps (NIP threat table).
                this.lobbyPassword = randomPassword();
            } else {
                this.accountId = mine?.card.fields[0].value ?? this.casual.eaId ?? '';
            }
        },

        newPassword() {
            this.lobbyPassword = randomPassword();
        },

        async sendCard(kind, withdraw = false) {
            if (this.sending || this.status !== 'live' || !this.cardKinds.includes(kind)) return;
            this.cardError = '';
            this.error = '';

            if (!this.oneOpponent()) {
                this.cardError = this.t.cardOneOpponent;

                return;
            }

            let tags;
            try {
                tags = kind === 'lobby'
                    ? lobbyTags({ game: this.casual.game, name: withdraw ? '' : this.lobbyName.trim(), password: withdraw ? '' : this.lobbyPassword.trim() })
                    : accountTags({ service: HOST_CARD[this.casual.game].value, id: withdraw ? '' : this.accountId.trim() });
                if (!withdraw && tags.length === 1) throw new Error('card_value');
            } catch {
                this.cardError = this.t.cardInvalid;

                return;
            }

            const card = parseCard({ tags, created_at: nowSeconds() });
            this.sending = true;

            try {
                const rumor = await this.deliver({ content: cardContent(card, config.match), tags, needOpponent: true });

                if (rumor !== null) {
                    this.composer = '';
                    if (this.casual.isHost && !withdraw && tags[0][1] === HOST_CARD[this.casual.game].value) await this.reportShared();
                }
            } catch (error) {
                console.warn('[chat] card failed', error);
                this.error = this.t.failed;
            } finally {
                this.sending = false;
            }
        },

        /** The host's card reached the opponent's relay: tell the league (the flag only). */
        async reportShared() {
            if (this.casual.shared) return;
            const answer = await this.$wire.casualLobbyShared();

            if (answer?.ok) {
                this.casual = { ...this.casual, shared: true };
            } else {
                this.error = answer?.message || this.t.failed;
            }
        },

        /** Guest: a valid, open card from the host is on screen, so tell the league once. */
        checkSeen() {
            const c = this.casual;
            if (!c || c.isHost || !c.started || !c.open || c.seen || this.seenBusy || !c.hostPubkey) return;
            const want = HOST_CARD[c.game];
            const seen = this.messages.some((m) => m.pubkey === c.hostPubkey && m.card && m.card.state === 'open' && !m.mutedCard && m.card.marker === want?.value);

            if (seen) this.reportSeen();
        },

        async reportSeen(attempt = 0) {
            this.seenBusy = true;

            try {
                const answer = await this.$wire.casualLobbySeen();

                if (answer?.ok) {
                    this.casual = { ...this.casual, seen: true };
                } else if (answer?.reason === 'no_lobby_yet' && attempt < SEEN_RETRIES.length) {
                    // The card can arrive before the host's flag does: ask again shortly.
                    setTimeout(() => this.reportSeen(attempt + 1), SEEN_RETRIES[attempt]);

                    return;
                }
            } catch (error) {
                console.warn('[chat] seen flag failed', error);
            }

            this.seenBusy = false;
        },

        copy(value) {
            navigator.clipboard?.writeText(value);
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
