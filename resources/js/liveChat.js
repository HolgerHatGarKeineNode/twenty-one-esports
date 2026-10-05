/**
 * Entry for /live (P24): the stream's NIP-53 live chat next to the stage,
 * read and written straight from the browser; the league server never sees a
 * message. Loaded only on that page (layout `scripts`).
 *
 * - liveChat: one subscription per chat relay (kind 1311 and zap receipts
 *   9735 with the stream's `a`, `limit` = the page size), newest at the
 *   bottom. The first answers are held back until every relay sent its EOSE
 *   (or a short wait ran out, so one slow relay does not hold the list), then
 *   the newest page of all of them is drawn once, already scrolled to the
 *   bottom: before this, messages were drawn one by one at the top and the
 *   list jumped down seconds later. Scrolled near the top, the next older
 *   page is read (`until` = the oldest shown) and put above without moving
 *   what the reader sees. Every later arrival goes to its place by
 *   created_at, not to the end. The list follows new messages while the
 *   reader is at the bottom; scrolled up, it counts them in an "N new"
 *   button instead. Names and pictures come from kind 0 (readProfiles() in
 *   relayRead.js): the profile relays, the chat relays and the relay hints
 *   of `p` tags and nprofiles first; whoever is still missing is looked up
 *   the outbox way (NIP-65: their 10002 from the indexers, then their write
 *   relays). A read that did not end in EOSE is tried once more; a profile
 *   nobody has is not asked again for PROFILE_MISS_MS. Found profiles are
 *   cached on this device.
 *   NIP-27 mentions (`nostr:npub…`) show as an @name chip: a league account
 *   links to its player page, anybody else to njump.me. Which mentioned keys
 *   are league accounts the page's `players()` says (pubkeys in, the league
 *   accounts among them out; nothing else about them).
 *   Posting signs a kind 1311 with the viewer's signer (signing.js) and
 *   publishes it; guests only read.
 *   Mutes are the viewer's own, as in the game chat: localStorage plus the
 *   account (Livewire `setMuted`); a run of muted messages folds into one line.
 * - emojiPicker / emojiPopover (resources/js/emojiPicker.js, shared with
 *   the game channels of P21): the emoji picker of einundzwanzig-group
 *   (bridge.ts, emoji-picker.blade.php), ported to nostr-tools. Only pointer
 *   devices get it; on touch the keyboard has emoji.
 */
import { SimplePool } from 'nostr-tools/pool';
import { npubEncode } from 'nostr-tools/nip19';
import { knownCustomEmojis, loadUserCustomEmojis, pushRecentEmoji } from './emoji.js';
import { emojiPicker, emojiPopover } from './emojiPicker.js';
import { proxiedAvatar } from './imageProxy.js';
import { ensureSigner } from './nostrSign.js';
import { newest, publicRelay, readProfiles, readRelays } from './relayRead.js';
import { signerMessage, signTemplate } from './signing.js';
import { NJUMP, botMark, boundProfiles, compareItems, displayRows, formatSats, insertSorted, isHttps, isStreamMessage, length, messageTemplate, newestPage, olderPage, parseZap, profileOf, relayHints, sendBlocker, tokenize } from './streamChat.js';

const MUTES_KEY = 'esports.chat.mutes';
const PROFILES_KEY = 'esports.livechat.profiles';
const PROFILE_TTL_MS = 6 * 60 * 60 * 1000;
/** Pubkeys no relay had a profile for, with when: not asked again for PROFILE_MISS_MS. */
const MISSES_KEY = 'esports.livechat.profileMisses';
const PROFILE_MISS_MS = 30 * 60 * 1000;
/** A profile read that did not end in EOSE is tried once more, this much later. */
const PROFILE_RETRY_MS = 5000;
/** Relay hints (p tags, nprofiles) kept per pubkey, pubkeys with hints kept, and hint relays added to one read. */
const HINTS_PER_KEY = 3;
const HINTED_KEYS = 500;
const HINT_RELAYS = 6;
/** Pubkeys one players() call asks about (the server takes at most 100). */
const PLAYER_BATCH = 100;
const HEX64 = /^[0-9a-f]{64}$/;
/** Messages kept while the reader follows the bottom; scrolled up, nothing is dropped under her. */
const KEEP = 400;
/** Pixels from the bottom that still count as "at the bottom". */
const BOTTOM_SLACK = 8;
/** Pixels from the top at which the next older page is read. */
const OLDER_MARGIN = 160;
/** After the first relay's EOSE, the others get this long before the list is drawn. */
const FIRST_GRACE_MS = 500;
/** No EOSE at all: the list is drawn with what came by then. */
const FIRST_WAIT_MS = 3000;
/** How long a page of older messages waits for a relay. */
const OLDER_WAIT_MS = 4000;
const AVATAR_PLACEHOLDER = '0'.repeat(64);

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
        // private mode or full storage: it only forgets
    }
}

/** Pubkeys without a profile, asked within PROFILE_MISS_MS: pubkey -> when. */
function freshMisses() {
    const now = Date.now();
    const stored = readJson(MISSES_KEY, {});

    return Object.fromEntries(Object.entries(stored && typeof stored === 'object' ? stored : {})
        .filter(([pubkey, at]) => HEX64.test(pubkey) && Number.isFinite(at) && now - at < PROFILE_MISS_MS && at <= now));
}

/** The cached profiles still fresh, each checked again: the stored copy is only as good as whoever wrote it. */
function cachedProfiles() {
    const now = Date.now();
    const stored = readJson(PROFILES_KEY, {});

    return Object.fromEntries(Object.entries(stored && typeof stored === 'object' ? stored : {}).filter(([pubkey, profile]) => /^[0-9a-f]{64}$/.test(pubkey)
        && profile && now - (profile.seen ?? 0) < PROFILE_TTL_MS
        && (profile.picture === null || isHttps(profile.picture))
        && (profile.name === null || (typeof profile.name === 'string' && profile.name.length <= 96))));
}

export function liveChat(config) {
    const t = config.labels;

    return {
        t,
        items: [],
        profiles: {},
        // pubkey -> true for a league account, false for anybody else; only for mentioned keys
        players: {},
        status: (config.relays ?? []).length === 0 ? 'off' : 'connecting',
        input: '',
        sending: false,
        error: '',
        lastSentAt: null,
        atBottom: true,
        unseen: 0,
        older: 'idle',
        muted: [],
        revealed: [],
        menuFor: null,
        me: config.me ?? null,
        maxLength: config.maxLength ?? 280,
        pointerFine: typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover) and (pointer: fine)').matches,

        init() {
            const local = readJson(MUTES_KEY, []);
            // The account's list is the truth for a logged-in viewer; a guest keeps hers on this device.
            this.muted = [...new Set(this.me ? (config.muted ?? []) : (Array.isArray(local) ? local : []))];
            if (this.me) writeJson(MUTES_KEY, this.muted);

            this.profiles = cachedProfiles();
            // In flight; found ones are in `profiles`, missing ones in `misses`.
            this.asked = new Set();
            this.pending = new Set();
            this.misses = freshMisses();
            this.profileTries = new Map();
            this.hints = new Map();
            this.retryKeys = new Set();
            // The component's own $wire, for calls made later from a timer.
            this.wire = this.$wire;
            this.playerQueue = new Set();
            this.playerTries = new Set();
            if (this.me) this.players = { [this.me]: true };

            this.fit = () => this.fitColumn();
            this.fitObserver = new ResizeObserver(this.fit);
            this.fitObserver.observe(document.body);
            window.addEventListener('resize', this.fit);
            this.fit();

            if (this.status === 'off') return;

            // Not reactive: the first answers wait here and are drawn once.
            this.buffer = new Map();
            this.page = config.history ?? 50;
            // A list that changes height (the column fits itself, the dock comes) stays at the bottom when it was there.
            this.listObserver = new ResizeObserver(() => this.pinBottom());
            if (this.$refs.list) this.listObserver.observe(this.$refs.list);

            // One subscription per relay, so each relay's EOSE is seen; ids are deduplicated here.
            const relays = [...new Set(config.relays)];
            const waiting = new Set(relays);
            this.pool = new SimplePool();
            this.subs = relays.map((url) => this.pool.subscribe(
                [url],
                { kinds: [1311, 9735], '#a': [config.address], limit: this.page },
                {
                    onevent: (event) => this.receive(event),
                    oneose: () => {
                        waiting.delete(url);
                        if (waiting.size === 0) {
                            this.caughtUp();
                        } else {
                            this.graceTimer ??= setTimeout(() => this.caughtUp(), FIRST_GRACE_MS);
                        }
                    },
                    maxWait: FIRST_WAIT_MS,
                },
            ));
            // A relay that never answers must not leave the list saying "connecting".
            this.eoseTimer = setTimeout(() => this.caughtUp(), FIRST_WAIT_MS);

            if (this.me && this.pointerFine) {
                // Warm the viewer's own emoji so the picker's tab is there when it opens.
                loadUserCustomEmojis(this.me, config.emojiRelays ?? []).catch(() => {});
            }
        },

        destroy() {
            this.destroyed = true;
            this.fitObserver?.disconnect();
            this.listObserver?.disconnect();
            window.removeEventListener('resize', this.fit);
            clearTimeout(this.eoseTimer);
            clearTimeout(this.graceTimer);
            clearTimeout(this.profileTimer);
            clearTimeout(this.retryTimer);
            clearTimeout(this.playerTimer);
            this.subs?.forEach((sub) => sub.close());
            this.pool?.destroy();
        },

        /**
         * Every relay sent its EOSE, or the wait ran out: the newest page of
         * what came is drawn in one go, and the list stands at its bottom
         * before the browser paints it (afterRender; $nextTick waits for a
         * timer, which lets a frame show the top first).
         */
        caughtUp() {
            clearTimeout(this.eoseTimer);
            clearTimeout(this.graceTimer);
            if (this.status !== 'connecting') return;

            this.items = newestPage([...this.buffer.values()], this.page);
            this.buffer = null;
            this.status = 'live';
            this.afterRender(() => {
                this.toBottom();
                // A short list has no scrollbar to pull: read on until it fills or the chat's start is reached.
                this.readOnIfNearTop();
            });
        },

        /** A relay event as a list item (a message or a zap of this stream), null when it is neither. */
        itemOf(event) {
            if (isStreamMessage(event, config.address)) {
                if (event.content.trim() === '') return null;
                this.notePHints(event);

                return {
                    type: 'message',
                    id: event.id,
                    pubkey: event.pubkey,
                    created_at: event.created_at,
                    tokens: tokenize(event.content, event.tags),
                };
            }

            const zap = parseZap(event, { address: config.address, signers: config.zapSigners ?? [], recipient: config.zapRecipient ?? null, lnurls: config.zapLnurls ?? {}, until: config.zapUntil ?? {} });

            return zap ? { type: 'zap', ...zap, tokens: tokenize(zap.comment, []) } : null;
        },

        receive(event) {
            const item = this.itemOf(event);
            if (!item) return;

            if (this.status === 'connecting') {
                if (!this.buffer.has(item.id)) this.buffer.set(item.id, item);
                this.wantPeople(item);

                return;
            }

            // Older than the oldest shown (a slow relay's first answer): its page brings it. Put in now, it
            // would stand above a gap, and the next page, asked `until` it, would never fill that gap.
            if (this.items.length > 0 && this.older !== 'end' && compareItems(item, this.items[0]) < 0) return;

            const own = item.pubkey === this.me;
            const last = this.items[this.items.length - 1];
            const atEnd = !last || compareItems(item, last) > 0;
            const before = this.items.length;
            let added = false;
            // Late or out of order, it goes to its place; the reader's view stays where it is.
            const pinned = this.keepView(() => {
                // Only a reader at the bottom loses the oldest: above it, nothing is taken from under her.
                added = insertSorted(this.items, item, this.atBottom || own ? KEEP : Infinity);
            }, own);
            if (!added) return;
            // The oldest went: the chat's start is no longer in the list.
            if (this.items.length <= before && this.older === 'end') this.older = 'idle';
            this.wantPeople(item);

            if (!pinned && atEnd && !this.muted.includes(item.pubkey)) {
                this.unseen += 1;
            }
        },

        /**
         * Runs `fn` once Alpine has drawn the change just made, before the
         * browser paints: Alpine flushes its effects in a microtask queued at
         * the first reactive write, so a microtask queued after that write
         * runs after the flush.
         */
        afterRender(fn) {
            queueMicrotask(() => {
                if (!this.destroyed) fn();
            });
        },

        /**
         * Applies `change` to the list and keeps the reader's view: at the
         * bottom (or with `toEnd`) the list stays at the bottom; otherwise the
         * first row she sees stays at the same height, whatever came above
         * it. Returns whether the list was held at the bottom.
         */
        keepView(change, toEnd = false) {
            const list = this.$refs.list;
            const pinned = toEnd || this.atBottom;
            let anchor = null;
            let top = 0;
            if (list && !pinned) {
                const edge = list.getBoundingClientRect().top;
                anchor = [...list.querySelectorAll(':scope > li[data-key]')].find((row) => row.getBoundingClientRect().bottom > edge + 1) ?? null;
                top = anchor?.getBoundingClientRect().top ?? 0;
            }

            change();
            this.afterRender(() => {
                if (pinned) {
                    this.toBottom();
                } else if (anchor?.isConnected) {
                    list.scrollTop += anchor.getBoundingClientRect().top - top;
                }
            });

            return pinned;
        },

        /** Near the top with more to read: the next older page. */
        readOnIfNearTop() {
            const list = this.$refs.list;
            if (list?.checkVisibility() && list.scrollTop < OLDER_MARGIN) this.loadOlder();
        },

        /**
         * The page before the oldest message shown (`until` its created_at,
         * that second included, known ids dropped), put above it. A full page
         * of known events only (one busy second) steps a second back once.
         * The chat's start is reached when the relays that answered have
         * nothing more (olderPage).
         */
        async loadOlder() {
            if (this.older !== 'idle' || this.status !== 'live' || this.items.length === 0) return;
            this.older = 'loading';

            let until = this.items[0].created_at;
            let results = [];
            let fresh = [];
            try {
                for (let attempt = 0; attempt < 2; attempt += 1) {
                    results = await readRelays(config.relays, [{ kinds: [1311, 9735], '#a': [config.address], until, limit: this.page }], { timeoutMs: OLDER_WAIT_MS });
                    const known = new Set(this.items.map((item) => item.id));
                    fresh = results.flatMap((result) => result.events).map((event) => this.itemOf(event)).filter((item) => item && !known.has(item.id));
                    if (fresh.length > 0 || !results.some((result) => result.events.length >= this.page)) break;
                    until -= 1;
                }
            } catch (error) {
                console.warn('[live chat] reading older messages failed', error);
            }
            if (this.destroyed) return;

            const { items, end } = olderPage(results, fresh, this.page);
            this.keepView(() => {
                if (items.length > 0) this.items = newestPage([...items, ...this.items], Infinity);
                this.older = end ? 'end' : 'idle';
            });
            items.forEach((item) => this.wantPeople(item));
            // Still near the top (a short page): read on, but only while pages bring something.
            if (items.length > 0 && !end) this.afterRender(() => this.readOnIfNearTop());
        },

        /** The list's rows; `cont` marks a message that continues its author's previous one (within 2 min): no name line again. */
        get rows() {
            const rows = displayRows(this.items, { muted: this.muted, revealed: this.revealed });
            rows.forEach((row, index) => {
                const previous = rows[index - 1];
                row.cont = row.type === 'message' && previous?.type === 'message' && previous.item.pubkey === row.item.pubkey && row.item.created_at - previous.item.created_at < 120;
            });

            return rows;
        },

        /**
         * From lg the column reaches from its own top to the window's bottom
         * edge, ending above the match dock when one floats there. Its top is
         * where the header leaves it, and the header is 112 px for a player
         * but 181 px for a guest (measured at 1440): no fixed offset fits
         * both. So the column measures itself and sets one CSS variable, read
         * by its `lg:h-[…]` class; the body's ResizeObserver catches a header
         * that changes and a dock that comes or goes (it adds a spacer).
         */
        fitColumn() {
            const column = this.$root;
            if (!column) return;

            // Phones: the height comes from the root's --tabbar-h and --dock-h alone (pages/⚡live), the
            // same values its scroll-padding reserves, so nothing is measured here.
            if (window.innerWidth < 1024) return;

            // The grid it starts in, not the column itself: stuck, the column reports its sticky top (below the header).
            const top = column.parentElement.getBoundingClientRect().top + window.scrollY;
            const dock = document.querySelector('[data-test=match-dock]');
            const dockTop = dock?.checkVisibility() ? dock.getBoundingClientRect().top : null;
            const reserve = dockTop === null ? 16 : Math.max(16, window.innerHeight - dockTop + 12);
            const value = Math.round(top + reserve) + 'px';
            if (column.style.getPropertyValue('--live-chat-top') !== value) {
                column.style.setProperty('--live-chat-top', value);
            }
        },

        /* ---------- Scrolling ---------------------------------------------------------------- */

        onScroll() {
            const list = this.$refs.list;
            if (!list) return;
            this.atBottom = list.scrollHeight - list.scrollTop - list.clientHeight <= BOTTOM_SLACK;
            if (this.atBottom) this.unseen = 0;
            if (this.status === 'live') this.readOnIfNearTop();
        },

        /** Straight to the bottom without animation: the list's own bookkeeping, not a reader's click. */
        toBottom() {
            const list = this.$refs.list;
            if (!list) return;
            list.scrollTop = list.scrollHeight;
            this.atBottom = true;
            this.unseen = 0;
        },

        /** Something in the list changed height (an image loaded, the column was fitted): a reader at the bottom stays there. */
        pinBottom() {
            if (this.atBottom && this.status === 'live') this.toBottom();
        },

        scrollToBottom(smooth = false) {
            const list = this.$refs.list;
            if (!list) return;
            const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            list.scrollTo({ top: list.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' });
            this.atBottom = true;
            this.unseen = 0;
        },

        /** The chat became visible again (the phone's tab): catch up to the bottom. */
        shown() {
            this.$nextTick(() => this.scrollToBottom());
        },

        get unseenLabel() {
            return this.unseen === 1 ? t.newMessage : t.newMessages.replace(':count', this.unseen);
        },

        /* ---------- People ------------------------------------------------------------------- */

        /** The author of an item and everybody it mentions: their profiles, and for mentions whether they play here. */
        wantPeople(item) {
            this.wantProfile(item.pubkey);
            for (const token of item.tokens ?? []) {
                if (token.type !== 'mention') continue;
                this.addHints(token.pubkey, token.relays);
                this.wantProfile(token.pubkey);
                this.wantPlayer(token.pubkey);
            }
        },

        /** The relay hints of a message's `p` tags (NIP-01 tag[2]), kept for the pubkey they name. */
        notePHints(event) {
            for (const tag of event.tags ?? []) {
                if (Array.isArray(tag) && tag[0] === 'p' && typeof tag[2] === 'string' && tag[2] !== '') this.addHints(tag[1], [tag[2]]);
            }
        },

        addHints(pubkey, relays) {
            if (!HEX64.test(pubkey ?? '') || !Array.isArray(relays) || relays.length === 0) return;
            if (!this.hints.has(pubkey) && this.hints.size >= HINTED_KEYS) return;
            this.hints.set(pubkey, relayHints([...(this.hints.get(pubkey) ?? []), ...relays.filter((url) => this.allowRelay(url))], HINTS_PER_KEY));
        },

        /** On an https page only public wss relays (relayRead.js publicRelay()): hints and relay lists are anybody's. */
        allowRelay(url) {
            return window.location.protocol !== 'https:' || publicRelay(url);
        },

        wantProfile(pubkey) {
            if (!HEX64.test(pubkey ?? '') || this.profiles[pubkey] || this.asked.has(pubkey)) return;
            if ((config.profileRelays ?? []).length === 0 && (config.relays ?? []).length === 0) return;
            if (Date.now() - (this.misses[pubkey] ?? -Infinity) < PROFILE_MISS_MS) return;
            this.asked.add(pubkey);
            this.pending.add(pubkey);
            clearTimeout(this.profileTimer);
            this.profileTimer = setTimeout(() => this.loadProfiles(), 250);
        },

        /**
         * One batch of profiles (readProfiles()). Found: shown and cached.
         * Not found although every relay that could have it answered: not
         * asked again for PROFILE_MISS_MS. Not found after a read that did
         * not end in EOSE: once more in PROFILE_RETRY_MS, then the same.
         */
        async loadProfiles() {
            const authors = [...this.pending].slice(0, 50);
            authors.forEach((pubkey) => this.pending.delete(pubkey));
            if (this.pending.size > 0) this.profileTimer = setTimeout(() => this.loadProfiles(), 250);
            if (authors.length === 0) return;

            let events = [];
            let settled = new Set();
            try {
                const hints = relayHints(authors.flatMap((pubkey) => this.hints.get(pubkey) ?? []), HINT_RELAYS);
                ({ events, settled } = await readProfiles(authors, {
                    relays: [...(config.profileRelays ?? []), ...(config.relays ?? []), ...hints],
                    indexers: config.indexerRelays ?? [],
                    allow: (url) => this.allowRelay(url),
                }));
            } catch (error) {
                console.warn('[live chat] reading profiles failed', error);
            }
            if (this.destroyed) return;

            const store = cachedProfiles();
            const misses = freshMisses();
            for (const pubkey of authors) {
                this.asked.delete(pubkey);
                const profile = profileOf(newest(events, pubkey, 0));
                if (profile) {
                    this.profiles[pubkey] = { ...profile, seen: Date.now() };
                    store[pubkey] = this.profiles[pubkey];
                    delete misses[pubkey];
                } else if (settled.has(pubkey) || this.profileTries.has(pubkey)) {
                    misses[pubkey] = Date.now();
                } else {
                    this.profileTries.set(pubkey, 1);
                    this.retryKeys.add(pubkey);
                }
            }
            // Newest first, at most 400 people and 256 KB: localStorage is shared with the rest of the site.
            writeJson(PROFILES_KEY, boundProfiles(store));
            this.misses = Object.fromEntries(Object.entries(misses).sort(([, a], [, b]) => b - a).slice(0, 400));
            writeJson(MISSES_KEY, this.misses);

            if (this.retryKeys.size > 0 && !this.retryTimer) {
                this.retryTimer = setTimeout(() => {
                    this.retryTimer = null;
                    const again = [...this.retryKeys];
                    this.retryKeys.clear();
                    again.forEach((pubkey) => this.wantProfile(pubkey));
                }, PROFILE_RETRY_MS);
            }
        },

        /** Queue a mentioned pubkey for the league's players() lookup. */
        wantPlayer(pubkey) {
            if (!HEX64.test(pubkey ?? '') || Object.hasOwn(this.players, pubkey) || this.playerQueue.has(pubkey) || !this.wire) return;
            this.playerQueue.add(pubkey);
            this.playerTimer ??= setTimeout(() => this.lookupPlayers(), 300);
        },

        /**
         * Ask the league which of the queued pubkeys are league accounts. Only
         * an answer says "no"; a call that got none (failed or throttled) asks
         * once more a few seconds later and otherwise leaves the chip on njump.me.
         */
        async lookupPlayers() {
            this.playerTimer = null;
            const batch = [...this.playerQueue].slice(0, PLAYER_BATCH);
            batch.forEach((pubkey) => this.playerQueue.delete(pubkey));
            if (this.playerQueue.size > 0) this.playerTimer = setTimeout(() => this.lookupPlayers(), 1000);
            if (batch.length === 0) return;

            let answer = null;
            try {
                answer = await this.wire.players(batch);
            } catch (error) {
                console.warn('[live chat] player lookup failed', error);
            }
            if (this.destroyed) return;

            if (!Array.isArray(answer)) {
                for (const pubkey of batch) {
                    if (this.playerTries.has(pubkey)) continue;
                    this.playerTries.add(pubkey);
                    this.playerQueue.add(pubkey);
                }
                if (this.playerQueue.size > 0) this.playerTimer ??= setTimeout(() => this.lookupPlayers(), PROFILE_RETRY_MS);

                return;
            }

            const known = new Set(answer);
            this.players = { ...this.players, ...Object.fromEntries(batch.map((pubkey) => [pubkey, known.has(pubkey)])) };
        },

        /** A mention's link: the player page for a league account, njump.me for anybody else (or while unknown). */
        mentionUrl(token) {
            return this.players[token.pubkey] === true ? (config.playerUrl ?? '/players/NPUB').replace('NPUB', token.npub) : NJUMP + token.npub;
        },

        mentionExternal(token) {
            return this.players[token.pubkey] !== true;
        },

        nameOf(pubkey) {
            if (pubkey === this.me && config.meName) return config.meName;
            const name = this.profiles[pubkey]?.name;
            if (name) return name;
            try {
                return npubEncode(pubkey).slice(0, 12) + '…';
            } catch {
                return t.someone;
            }
        },

        avatarOf(pubkey) {
            // 16 and 24 px in the chat: the proxy's small cut when there is one (imageProxy.js).
            const picture = this.profiles[pubkey]?.picture;
            return picture ? proxiedAvatar(picture) : this.generatedAvatar(pubkey);
        },

        generatedAvatar(pubkey) {
            return (config.avatarUrl ?? '').replace(AVATAR_PLACEHOLDER, pubkey);
        },

        /** The bot badge: the configured bot key only. */
        isBot(pubkey) {
            return botMark(pubkey, { bot: config.bot, profile: this.profiles[pubkey] }) === 'bot';
        },

        /** A profile that calls itself a bot shows its npub next to the name, not a badge: anyone can say so. */
        selfBot(pubkey) {
            return botMark(pubkey, { bot: config.bot, profile: this.profiles[pubkey] }) === 'self';
        },

        shortNpub(pubkey) {
            try {
                const npub = npubEncode(pubkey);

                return npub.slice(0, 10) + '…' + npub.slice(-4);
            } catch {
                return '';
            }
        },

        time(seconds) {
            return new Date(seconds * 1000).toLocaleTimeString(config.locale ?? undefined, { hour: '2-digit', minute: '2-digit' });
        },

        sats(amount) {
            return t.zapped.replace(':amount', formatSats(amount, config.locale ?? 'en'));
        },

        /* ---------- Mutes -------------------------------------------------------------------- */

        isMuted(pubkey) {
            return this.muted.includes(pubkey);
        },

        toggleMenu(id) {
            this.menuFor = this.menuFor === id ? null : id;
        },

        async setMuted(pubkey, mute) {
            if (!pubkey || pubkey === this.me) return;
            this.muted = mute ? [...new Set([...this.muted, pubkey])] : this.muted.filter((known) => known !== pubkey);
            this.menuFor = null;
            writeJson(MUTES_KEY, this.muted);
            if (this.me) {
                try {
                    await this.$wire.setMuted(pubkey, mute);
                } catch (error) {
                    console.warn('[live chat] saving the mute failed', error);
                }
            }
        },

        muteLabel(pubkey) {
            return (this.isMuted(pubkey) ? t.unmute : t.mute).replace(':name', this.nameOf(pubkey));
        },

        mutedLabel(row) {
            return row.ids.length === 1 ? t.mutedOne : t.mutedMany.replace(':count', row.ids.length);
        },

        toggleReveal(row) {
            const first = row.ids[0];
            this.revealed = this.revealed.includes(first) ? this.revealed.filter((id) => id !== first) : [...this.revealed, first];
        },

        isRevealed(row) {
            return this.revealed.includes(row.ids[0]);
        },

        /* ---------- Writing ------------------------------------------------------------------ */

        get remaining() {
            return this.maxLength - length(this.input.trim());
        },

        /**
         * Put an emoji where the caret is (a selection is replaced) and remember
         * it as recently used. The `emoji` tag is not made here: send() derives
         * the tags from the final text.
         */
        insertEmoji(text, emojiTag, label) {
            const field = this.$refs.composer;
            const start = field?.selectionStart ?? this.input.length;
            const end = field?.selectionEnd ?? this.input.length;
            this.input = this.input.slice(0, start) + text + this.input.slice(end);
            pushRecentEmoji(emojiTag ? { custom: true, shortcode: String(emojiTag[1]), url: String(emojiTag[2]) } : { u: text, label: label ?? text });
            // The caret goes behind the emoji now, not on the next tick: a key pressed before that tick
            // landed in front and the tick then moved the caret into the middle of what was typed
            // (P21: LiveChatTest sent "gm st:sareamtoshi:" once the picker moved to its own chunk).
            if (field) {
                const position = start + text.length;
                field.value = this.input;
                field.focus();
                field.setSelectionRange(position, position);
            }
        },

        async send() {
            if (!this.me || this.sending || this.status !== 'live') return;

            const blocker = sendBlocker(this.input, { maxLength: this.maxLength, cooldownMs: config.cooldownMs ?? 2000, lastSentAt: this.lastSentAt });
            if (blocker === 'empty') return;
            if (blocker !== null) {
                this.error = blocker === 'tooLong' ? t.tooLong.replace(':max', this.maxLength) : t.wait;

                return;
            }

            const content = this.input;
            this.sending = true;
            this.error = '';

            try {
                if (!(await ensureSigner())) {
                    this.error = t.noSigner;

                    return;
                }

                const template = messageTemplate(content, { address: config.address, relayHint: config.relayHint, custom: knownCustomEmojis(this.me) });
                let signed;
                try {
                    signed = await signTemplate(template, { pubkey: this.me });
                } catch (error) {
                    this.error = signerMessage(t, error);

                    return;
                }

                // The field empties once it is signed; the text comes back if no relay takes it.
                this.lastSentAt = Date.now();
                this.input = this.input === content ? '' : this.input;
                try {
                    await Promise.any(this.pool.publish(config.relays, signed));
                } catch {
                    this.error = t.notSent;
                    this.input ||= content;

                    return;
                }

                this.receive(signed);
            } catch (error) {
                console.warn('[live chat] sending failed', error);
                this.error = t.failed;
                this.input ||= content;
            } finally {
                this.sending = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('liveChat', liveChat);
    window.Alpine.data('emojiPicker', emojiPicker);
    window.Alpine.data('emojiPopover', emojiPopover);
});
