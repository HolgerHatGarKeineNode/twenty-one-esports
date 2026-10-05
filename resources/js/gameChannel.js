/**
 * Entry for the game overview pages (P21): the global chat of the game, a
 * NIP-28 public channel with NIP-88 polls (rules: resources/js/channelChat.js),
 * read and written straight from the browser on the chat relays; the league
 * server never sees a message. Loaded by /chess and /games/{slug} (layout
 * `scripts`); the markup is components/⚡game-channel.
 *
 * - One subscription for the channel's messages, its polls (a filter and a
 *   limit each) and the creator's hides and mutes; one for the votes of the
 *   shown polls, opened again when that set or the known counting players
 *   change.
 * - Names and avatars come from the league (`$wire.players`): a league
 *   account shows with the name the league knows, anybody else with a short
 *   npub and a generated avatar, marked "not in the league". A poll is shown
 *   and a vote counted only when the league says the pubkey `counts` (a
 *   member, or a result in the league: NIP "Game channels"); how many other
 *   votes arrived is said, not added.
 * - Message text renders through the P24 stream chat's bounded tokenizer
 *   (streamChat.js) and its token partial, never as HTML. A NIP-27 mention
 *   (`nostr:npub…`) is an @name chip: the league lookup above names it and
 *   says where it links, the player page for a league account, njump.me for
 *   anybody else.
 * - Mutes are the viewer's own (localStorage and the account, ChatMute);
 *   the creator's kind 43/44 hide for everyone on this app, and so do the
 *   keys an admin muted or banned site-wide (`config.hidden`, live by push:
 *   resources/js/siteHidden.js).
 * - Guests read only.
 */
import { SimplePool } from 'nostr-tools/pool';
import { npubEncode } from 'nostr-tools/nip19';
import { KIND_HIDE, KIND_MESSAGE, KIND_MUTE, KIND_POLL, KIND_VOTE, addVote, isChannelMessage, isClosed, messageTemplate, moderation, parsePoll, parseVote, pollBlocker, pollTemplate, tally, timeLeft, voteTemplate } from './channelChat.js';
import { knownCustomEmojis, loadUserCustomEmojis, pushRecentEmoji } from './emoji.js';
import { emojiPicker, emojiPopover } from './emojiPicker.js';
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';
import { NJUMP, botMark, displayRows, insertSorted, length, sendBlocker, tokenize } from './streamChat.js';
import { registerAlpine } from './registerAlpine.js';
import { watchSiteHidden } from './siteHidden.js';

const MUTES_KEY = 'esports.chat.mutes';
/** Per channel id: the `created_at` of the newest item the viewer had in front of them (the chat open or the side column). */
const SEEN_KEY = 'esports.chat.seen';
/** A first visit counts what came in this long before as unread: activity, not the whole history. */
const FIRST_VISIT_WINDOW_S = 86400;
/** From here the page holds the chat as an always-open side column (`.chat-rail`, resources/css/app.css). */
const RAIL_QUERY = '(width >= 80rem)';
const KEEP = 200;
const BOTTOM_SLACK = 48;
/** Polls whose votes are read (the newest shown ones). */
const POLLS_WATCHED = 30;
/** Polls read back when the page opens, in their own filter so messages never crowd them out, or they the messages. */
const POLLS_HISTORY = 50;
/** Polls waiting for the league to say whether their author counts (the newest are kept). */
const POLLS_PENDING = 100;
/** Votes read by the open subscription for anybody; the league players' votes have their own filter. */
const VOTES_LIMIT = 500;
/** Counting players named in the votes filter's `authors`. */
const VOTE_AUTHORS = 300;
/**
 * Pubkeys asked about in one lookup. Authors (of polls first, then of
 * messages) and voters each draw from a bucket of their own that refills
 * over time, so a flood of keys slows the lookups down but never uses them
 * up for the page's life, and voters never hold up an author. Queues are
 * bounded; what does not fit waits for the next event that names it.
 */
const LOOKUP_BATCH = 100;
const BUCKET_SIZE = 300;
const BUCKET_REFILL_PER_S = 30;
const QUEUE_MAX = 2000;
const LOOKUP_TRIES = 3;
/** How long a change nobody counts (a vote from outside the league) may wait before the cards show it. */
const UNCOUNTED_DELAY_MS = 1000;

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

/**
 * One REQ per relay carrying every filter. SimplePool.subscribe() takes ONE
 * filter (nostr-tools 2.x): handed a list, it sent `["REQ", id, [f1, f2]]`,
 * which nos.lol, Primal and nostr.mom refuse ("provided filter is not an
 * object", measured 2026-10-03), so the chat read no history and a sent
 * message was gone after a reload. subscribeMap() groups the filters per relay.
 */
export function subscribeFilters(pool, relays, filters, params) {
    return pool.subscribeMap([...new Set(relays)].flatMap((url) => filters.map((filter) => ({ url, filter }))), params);
}

const nextFrame = (callback) => (typeof requestAnimationFrame === 'function' ? requestAnimationFrame(callback) : setTimeout(callback, 16));
const cancelFrame = (handle) => (typeof cancelAnimationFrame === 'function' ? cancelAnimationFrame(handle) : clearTimeout(handle));

export function gameChannel(config) {
    const t = config.labels;
    const nowSeconds = () => Math.floor(Date.now() / 1000);
    /*
     * Outside Alpine's reactivity: the vote book can hold thousands of entries,
     * and a vote must cost next to nothing until it changes what a reader sees
     * (security review of P21: 300 votes nobody counts rebuilt every row and
     * poll card 300 times, 25.8 s of main thread). The cards read `tallies`,
     * which flush() writes, at most once a frame for a counted change and once
     * a second for anything else.
     */
    const state = {
        polls: new Map(),
        pendingPolls: new Map(),
        rejectedPolls: new Set(),
        book: new Map(),
        moderationEvents: [],
        mod: { hidden: new Set(), muted: new Set() },
        // Keys an admin muted or banned site-wide (siteHidden.js): left out like the creator's mutes.
        siteHidden: new Set(),
        asked: new Set(),
        queues: { poll: new Set(), author: new Set(), voter: new Set() },
        buckets: { author: { tokens: BUCKET_SIZE, at: Date.now() }, voter: { tokens: BUCKET_SIZE, at: Date.now() } },
        tries: new Map(),
        dirty: new Set(),
        frame: null,
        slow: null,
        voteAuthors: '',
        incoming: [],
        itemsFrame: null,
        rowCache: new Map(),
    };
    /** Left out for everyone: hidden by the creator, or its author muted by the creator or site-wide. */
    const leftOut = (item) => state.mod.hidden.has(item.id) || state.mod.muted.has(item.pubkey) || state.siteHidden.has(item.pubkey);

    return {
        t,
        items: [],
        status: (config.relays ?? []).length === 0 ? 'off' : 'connecting',
        input: '',
        sending: false,
        error: '',
        lastSentAt: null,
        atBottom: true,
        unseen: 0,
        muted: [],
        revealed: [],
        menuFor: null,
        me: config.me ?? null,
        maxLength: config.maxLength ?? 280,
        pointerFine: typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover) and (pointer: fine)').matches,
        // per league account: { name, avatar, counts }; `false` for a pubkey the league does not know
        people: {},
        // per shown poll: the tally the card draws (flush())
        tallies: {},
        // bumped when the creator's moderation changes
        modVersion: 0,
        // tick for "closes in", twice a minute
        clock: nowSeconds(),

        // Below xl the chat is one bar until the viewer opens it; from xl it is the open side column (`rail`).
        open: false,
        rail: false,
        seenAt: 0,

        // The poll form
        composing: false,
        question: '',
        answers: ['', ''],
        duration: config.poll?.durations?.[1] ?? config.poll?.durations?.[0] ?? 86400,
        voting: null,

        init() {
            // The component's own $wire, bound to its root, for calls made later from any element.
            state.wire = this.$wire;
            // The league already said who the viewer is: no lookup, and their own poll shows at once when it counts.
            if (this.me) {
                this.people = { [this.me]: { name: config.meName ?? '', avatar: config.meAvatar ?? '', counts: config.meCounts === true } };
                state.asked.add(this.me);
            }
            const local = readJson(MUTES_KEY, []);
            this.muted = [...new Set(this.me ? (config.muted ?? []) : (Array.isArray(local) ? local : []))];
            if (this.me) writeJson(MUTES_KEY, this.muted);

            this.clockTimer = setInterval(() => { this.clock = nowSeconds(); }, 30_000);
            this.stopSiteHidden = watchSiteHidden(config.hidden ?? [], this.me, (hidden) => {
                state.siteHidden = hidden;
                this.modVersion += 1;
            });

            const seen = readJson(SEEN_KEY, {});
            this.seenAt = Number.isInteger(seen?.[config.channel]) ? seen[config.channel] : nowSeconds() - FIRST_VISIT_WINDOW_S;
            if (typeof window.matchMedia === 'function') {
                this.railQuery = window.matchMedia(RAIL_QUERY);
                this.rail = this.railQuery.matches;
                this.onRail = (event) => {
                    this.rail = event.matches;
                    if (this.rail) this.$nextTick(() => this.scrollToBottom());
                    this.markSeen();
                };
                this.railQuery.addEventListener?.('change', this.onRail);
            }

            if (this.status === 'off') return;

            this.pool = new SimplePool();
            this.sub = subscribeFilters(
                this.pool,
                config.relays,
                [
                    { kinds: [KIND_MESSAGE], '#e': [config.channel], limit: config.history ?? 120 },
                    { kinds: [KIND_POLL], '#e': [config.channel], limit: POLLS_HISTORY },
                    { kinds: [KIND_HIDE, KIND_MUTE], authors: [config.creator], limit: 500 },
                ],
                { onevent: (event) => this.receive(event), oneose: () => this.caughtUp(), maxWait: 6000 },
            );
            this.eoseTimer = setTimeout(() => this.caughtUp(), 6500);

            if (this.me && this.pointerFine) {
                loadUserCustomEmojis(this.me, config.emojiRelays ?? []).catch(() => {});
            }
        },

        destroy() {
            clearTimeout(this.eoseTimer);
            clearTimeout(this.voteTimer);
            clearTimeout(this.lookupTimer);
            this.lookupTimer = null;
            clearTimeout(state.slow);
            cancelFrame(state.frame);
            cancelFrame(state.itemsFrame);
            clearInterval(this.clockTimer);
            this.stopSiteHidden?.();
            this.railQuery?.removeEventListener?.('change', this.onRail);
            this.sub?.close();
            this.votes?.close();
            this.pool?.destroy();
        },

        caughtUp() {
            clearTimeout(this.eoseTimer);
            if (this.status !== 'connecting') return;
            this.status = 'live';
            this.$nextTick(() => this.scrollToBottom());
        },

        receive(event) {
            if (event?.kind === KIND_HIDE || event?.kind === KIND_MUTE) {
                if (event.pubkey !== config.creator || state.moderationEvents.length >= 1000) return;
                state.moderationEvents.push(event);
                state.mod = moderation(state.moderationEvents, config.creator);
                this.modVersion += 1;

                return;
            }

            if (event?.kind === KIND_VOTE) {
                this.takeVote(parseVote(event));

                return;
            }

            if (event?.kind === KIND_POLL) {
                this.takePoll(parsePoll(event, config.channel));

                return;
            }

            if (!isChannelMessage(event, config.channel)) return;
            this.show({ type: 'message', id: event.id, pubkey: event.pubkey, created_at: event.created_at, tokens: tokenize(event.content, event.tags) });
        },

        /**
         * Put an item into the list at the next frame, with everything else
         * that arrived until then: one list change per frame, not one per
         * event (measured with the auditor's harness: 300 messages one by one
         * rebuilt the list 300 times, 34 s of main thread).
         */
        show(item) {
            state.incoming.push(item);
            if (state.itemsFrame === null) state.itemsFrame = nextFrame(() => this.flushItems());
        },

        flushItems() {
            state.itemsFrame = null;
            const incoming = state.incoming.splice(0);
            const follow = this.atBottom;
            const list = [...this.items];
            const added = incoming.filter((item) => insertSorted(list, item, Infinity));
            if (added.length === 0) return;
            // KEEP messages at most, the oldest go; polls are never pushed out by messages (their number is bounded upstream).
            let messages = list.filter((item) => item.type === 'message').length;
            this.items = messages <= KEEP ? list : list.filter((item) => item.type !== 'message' || messages-- <= KEEP);

            for (const item of added) {
                this.wantPerson(item.pubkey, 'author');
                // A mentioned key is asked about like an author: its chip needs the name and the link.
                for (const token of item.tokens ?? []) {
                    if (token.type === 'mention') this.wantPerson(token.pubkey, 'author');
                }
            }
            this.markSeen();
            if (this.status !== 'live') return;
            if (follow || added.some((item) => item.pubkey === this.me)) {
                this.$nextTick(() => this.scrollToBottom());
            } else {
                this.unseen += added.filter((item) => !this.muted.includes(item.pubkey) && !leftOut(item)).length;
            }
        },

        /**
         * A poll is shown once the league says its author counts. Until then it
         * waits outside the list and the watched polls, so polls of fresh keys
         * can neither crowd the list nor take the vote slots of a real one. At
         * most POLLS_PENDING wait; one that finds the waiting room full is
         * dropped rather than one already waiting (its author is being asked).
         */
        takePoll(poll) {
            if (!poll || state.polls.has(poll.id) || state.pendingPolls.has(poll.id) || state.rejectedPolls.has(poll.id)) return;

            const author = this.people[poll.pubkey];
            if (author?.counts === true) {
                this.confirmPoll(poll);
            } else if (author === undefined) {
                if (state.pendingPolls.size >= POLLS_PENDING) return;
                state.pendingPolls.set(poll.id, poll);
                this.wantPerson(poll.pubkey, 'poll');
            } else if (state.rejectedPolls.size < 1000) {
                state.rejectedPolls.add(poll.id);
            }
        },

        confirmPoll(poll) {
            state.pendingPolls.delete(poll.id);
            state.polls.set(poll.id, poll);
            this.markDirty(poll.id, true);
            this.show({ type: 'poll', id: poll.id, pubkey: poll.pubkey, created_at: poll.created_at, poll });
            this.watchVotes();
        },

        /**
         * A vote goes into the book. It asks for a redraw only when it changes
         * what a card shows: a counted vote (or the viewer's own) at the next
         * frame, a vote nobody counts within a second; a vote the book ignores,
         * or one past MAX_VOTERS, never. An unknown voter is looked up first.
         */
        takeVote(vote) {
            if (!vote) return;
            const outcome = addVote(state.book, vote, state.polls);
            if (outcome !== 'added' && outcome !== 'replaced') return;

            const voter = this.people[vote.pubkey];
            if (vote.pubkey === this.me || voter?.counts === true) {
                this.markDirty(vote.poll, true);
            } else if (voter === undefined) {
                this.wantPerson(vote.pubkey, 'voter');
            } else {
                this.markDirty(vote.poll, false);
            }
        },

        markDirty(pollId, soon) {
            state.dirty.add(pollId);
            if (soon && state.frame === null) {
                state.frame = nextFrame(() => this.flush());
            } else if (!soon && state.frame === null && state.slow === null) {
                state.slow = setTimeout(() => this.flush(), UNCOUNTED_DELAY_MS);
            }
        },

        markAllDirty(soon) {
            for (const id of state.polls.keys()) this.markDirty(id, soon);
        },

        /** Recompute the tallies of the changed polls and hand the cards only what differs. */
        flush() {
            cancelFrame(state.frame);
            clearTimeout(state.slow);
            state.frame = null;
            state.slow = null;
            const dirty = [...state.dirty];
            state.dirty.clear();

            for (const id of dirty) {
                const poll = state.polls.get(id);
                if (!poll) continue;
                const outcome = tally(poll, state.book, { counts: (pubkey) => this.people[pubkey]?.counts === true, me: this.me });
                const held = this.tallies[id];
                if (!held || held.total !== outcome.total || held.uncounted !== outcome.uncounted || held.mine !== outcome.mine || poll.options.some((option) => held.counts[option.id] !== outcome.counts[option.id])) {
                    this.tallies[id] = outcome;
                }
            }
        },

        /** Read the votes of the newest shown polls: anybody's up to VOTES_LIMIT, and the known counting players' all. */
        watchVotes() {
            clearTimeout(this.voteTimer);
            this.voteTimer = setTimeout(() => {
                const ids = this.items.filter((item) => item.type === 'poll').slice(-POLLS_WATCHED).map((item) => item.id);
                if (ids.length === 0 || !this.pool) return;
                const authors = Object.keys(this.people).filter((pubkey) => this.people[pubkey]?.counts === true).slice(0, VOTE_AUTHORS);
                const key = ids.join() + '|' + authors.join();
                if (key === state.voteAuthors) return;
                state.voteAuthors = key;
                const filters = [{ kinds: [KIND_VOTE], '#e': ids, limit: VOTES_LIMIT }];
                if (authors.length > 0) filters.push({ kinds: [KIND_VOTE], '#e': ids, authors });
                this.votes?.close();
                this.votes = subscribeFilters(this.pool, config.relays, filters, { onevent: (event) => this.receive(event) });
            }, 250);
        },

        /* ---------- People ------------------------------------------------------------------- */

        /**
         * Queue a pubkey for the league lookup. Authors of what is shown come
         * first and have their own budget; voters are asked about after them,
         * within theirs, so a flood of voter keys cannot keep a message's or a
         * poll's author unknown.
         */
        wantPerson(pubkey, role) {
            if (state.asked.has(pubkey)) {
                // Asked as a voter or message author, and now it wrote a poll: move it to the front.
                if (role === 'poll' && (state.queues.author.delete(pubkey) || state.queues.voter.delete(pubkey))) state.queues.poll.add(pubkey);

                return;
            }
            const queue = state.queues[role];
            if (queue.size >= QUEUE_MAX) return;
            queue.add(pubkey);
            state.asked.add(pubkey);
            if (!this.lookupTimer) this.lookupTimer = setTimeout(() => this.lookup(), 200);
        },

        /** Take up to `want` tokens from a bucket, refilled since it was last used. */
        take(name, want) {
            const bucket = state.buckets[name];
            const now = Date.now();
            bucket.tokens = Math.min(BUCKET_SIZE, bucket.tokens + ((now - bucket.at) / 1000) * BUCKET_REFILL_PER_S);
            bucket.at = now;
            const granted = Math.min(want, Math.floor(bucket.tokens));
            bucket.tokens -= granted;

            return granted;
        },

        /**
         * Ask the league which of the queued pubkeys are league accounts and
         * whose votes count. Only an answer says "no": a lookup that got none
         * (a failed request) leaves them unknown and asks again, up to
         * LOOKUP_TRIES times.
         *
         * Through the `$wire` taken in init(), never `this.$wire` here: a
         * lookup can be started by a click on a poll answer, and `this` then
         * resolves `$wire` from that button, which the next render replaces.
         * Measured in the browser test: the call on the detached button
         * resolved undefined and never reached the server, and the voter was
         * marked "not in the league".
         */
        async lookup() {
            this.lookupTimer = null;
            const authors = [...state.queues.poll, ...state.queues.author];
            const authorBatch = authors.slice(0, this.take('author', Math.min(LOOKUP_BATCH, authors.length)));
            const voterBatch = [...state.queues.voter].slice(0, this.take('voter', Math.min(LOOKUP_BATCH - authorBatch.length, state.queues.voter.size)));
            const batch = [...authorBatch, ...voterBatch];
            const roles = new Map();
            for (const pubkey of batch) {
                roles.set(pubkey, state.queues.poll.has(pubkey) ? 'poll' : (state.queues.author.has(pubkey) ? 'author' : 'voter'));
                state.queues.poll.delete(pubkey);
                state.queues.author.delete(pubkey);
                state.queues.voter.delete(pubkey);
            }
            const waiting = state.queues.poll.size + state.queues.author.size + state.queues.voter.size;
            // More to ask: next batch soon, or in a second when the buckets are empty.
            if (waiting > 0) this.lookupTimer = setTimeout(() => this.lookup(), batch.length > 0 ? 200 : 1000);
            if (batch.length === 0) return;

            let answer;
            try {
                answer = await state.wire.players(batch);
            } catch (error) {
                console.warn('[game chat] player lookup failed', error);
            }

            if (answer === null || typeof answer !== 'object') {
                for (const pubkey of batch) {
                    if ((state.tries.get(pubkey) ?? 0) >= LOOKUP_TRIES) continue;
                    state.tries.set(pubkey, (state.tries.get(pubkey) ?? 0) + 1);
                    state.queues[roles.get(pubkey)].add(pubkey);
                }
                clearTimeout(this.lookupTimer);
                this.lookupTimer = setTimeout(() => this.lookup(), 1000);

                return;
            }

            const next = { ...this.people };
            let counting = false;
            for (const pubkey of batch) {
                const found = answer[pubkey];
                next[pubkey] = found && typeof found === 'object' ? { name: String(found.name ?? ''), avatar: String(found.avatar ?? ''), counts: found.counts === true } : false;
                counting ||= next[pubkey] !== false && next[pubkey].counts;
            }
            this.people = next;

            for (const poll of [...state.pendingPolls.values()]) {
                const author = this.people[poll.pubkey];
                if (author === undefined) continue;
                state.pendingPolls.delete(poll.id);
                if (author !== false && author.counts) {
                    this.confirmPoll(poll);
                } else if (state.rejectedPolls.size < 1000) {
                    state.rejectedPolls.add(poll.id);
                }
            }

            // Voters that turned out to count change a tally now; the rest may wait.
            this.markAllDirty(counting);
            if (counting) this.watchVotes();
        },

        /** A league account (shown by its league name). */
        isPlayer(pubkey) {
            return !!this.people[pubkey];
        },

        nameOf(pubkey) {
            if (pubkey === this.me && config.meName) return config.meName;
            if (this.people[pubkey]) return this.people[pubkey].name;
            try {
                return npubEncode(pubkey).slice(0, 12) + '…';
            } catch {
                return t.someone;
            }
        },

        avatarOf(pubkey) {
            return this.people[pubkey]?.avatar || this.generatedAvatar(pubkey);
        },

        generatedAvatar(pubkey) {
            return (config.avatarUrl ?? '').replace(config.avatarPlaceholder ?? '0'.repeat(64), pubkey);
        },

        /** A mention's link: the player page for a league account, njump.me for anybody else (or while unknown). */
        mentionUrl(token) {
            return this.people[token.pubkey] ? (config.playerUrl ?? '/players/NPUB').replace('NPUB', token.npub) : NJUMP + token.npub;
        },

        mentionExternal(token) {
            return !this.people[token.pubkey];
        },

        markOf(pubkey) {
            if (pubkey === config.creator) return 'league';
            if (botMark(pubkey, { bot: config.bot }) === 'bot') return 'bot';

            return this.people[pubkey] === false ? 'outside' : null;
        },

        /* ---------- The list ----------------------------------------------------------------- */

        /**
         * The rows: the creator's hidden messages and muted people left out
         * (polls are in the list only once their author counts); runs of the
         * viewer's own mutes folded. Votes never touch this.
         */
        get rows() {
            this.modVersion;
            const shown = this.items.filter((item) => !leftOut(item));
            const rows = displayRows(shown, { muted: this.muted, revealed: this.revealed });
            const cache = new Map();
            const stable = rows.map((row, index) => {
                const previous = rows[index - 1];
                row.cont = row.type === 'message' && previous?.type === 'message' && previous.item.pubkey === row.item.pubkey && row.item.created_at - previous.item.created_at < 120;
                // The same row object as last time when nothing about it changed: the list then renders only what is new.
                const held = state.rowCache.get(row.key);
                const same = held && held.type === row.type && held.item === row.item && held.cont === row.cont && held.revealed === row.revealed && (row.type !== 'muted' || held.ids.join() === row.ids.join());
                cache.set(row.key, same ? held : row);

                return same ? held : row;
            });
            state.rowCache = cache;

            return stable;
        },

        /** Open polls, newest first: the side column from lg. */
        get openPolls() {
            this.modVersion;
            return this.items.filter((item) => item.type === 'poll' && !isClosed(item.poll, this.clock) && !leftOut(item)).reverse().slice(0, 3).map((item) => item.poll);
        },

        get hasItems() {
            return this.rows.length > 0;
        },

        /* ---------- The bar below xl: open, latest, unread ----------------------------------- */

        toggle() {
            this.open = !this.open;
            if (!this.open) return;
            this.markSeen();
            this.$nextTick(() => this.scrollToBottom());
        },

        /** Whether the viewer has the messages in front of them: the chat open, or the side column. */
        get showing() {
            return this.open || this.rail;
        },

        /** Everything shown up to now counts as read while the chat is in front of the viewer. */
        markSeen() {
            if (!this.showing) return;
            const newest = this.items.reduce((max, item) => Math.max(max, item.created_at), 0);
            if (newest <= this.seenAt) return;
            this.seenAt = newest;
            const seen = readJson(SEEN_KEY, {});
            writeJson(SEEN_KEY, { ...(seen && typeof seen === 'object' && !Array.isArray(seen) ? seen : {}), [config.channel]: newest });
        },

        /** The newest message or poll the list shows (no muted fold, nothing the creator hid). */
        get latest() {
            const rows = this.rows;
            for (let index = rows.length - 1; index >= 0; index--) {
                if (rows[index].type === 'message' || rows[index].type === 'poll') return rows[index].item;
            }

            return null;
        },

        /** Messages and polls of others since the viewer last had the chat in front of them, muted ones left out. */
        get unread() {
            this.modVersion;
            return this.items.filter((item) => item.created_at > this.seenAt && item.pubkey !== this.me && !this.muted.includes(item.pubkey) && !leftOut(item)).length;
        },

        get unreadBadge() {
            return this.unread > 99 ? '99+' : String(this.unread);
        },

        get unreadLabel() {
            return this.unread === 1 ? t.newMessage : t.newMessages.replace(':count', this.unread);
        },

        get toggleLabel() {
            const action = this.open ? t.closeChat : t.openChat;

            return this.unread > 0 && !this.open ? action + ', ' + this.unreadLabel : action;
        },

        /** One line of the newest item as plain text: a message's tokens, or "asks" and the question of a poll. */
        previewText(item) {
            if (item.type === 'poll') return t.asks + ' ' + (item.poll?.question ?? '');

            return (item.tokens ?? []).map((token) => {
                if (token.type === 'emoji') return ':' + token.value + ':';
                if (token.type === 'mention') return '@' + this.nameOf(token.pubkey);

                return token.type === 'ref' ? t.quoted : token.value;
            }).join('').replace(/\s+/g, ' ').trim();
        },

        /** The bar's line while there is no message to show. */
        get previewIdle() {
            if (this.status === 'off') return t.chatOff;

            return this.status === 'live' ? t.chatEmpty : t.connecting;
        },

        /** A poll's result as the card draws it, from `tallies`: per answer the count, the share and whether it is mine or leading. */
        result(poll) {
            const outcome = this.tallies[poll.id] ?? { counts: {}, total: 0, uncounted: 0, mine: null };
            const top = Math.max(0, ...poll.options.map((option) => outcome.counts[option.id] ?? 0));
            const closed = isClosed(poll, this.clock);

            return {
                closed,
                total: outcome.total,
                uncounted: outcome.uncounted,
                mine: outcome.mine,
                totalLabel: outcome.total === 1 ? t.vote : t.votes.replace(':count', outcome.total),
                when: closed ? t.closed : t.closesIn.replace(':time', timeLeft(poll.endsAt, { now: this.clock, locale: config.locale ?? 'en' })),
                options: poll.options.map((option) => {
                    const count = outcome.counts[option.id] ?? 0;

                    return {
                        ...option,
                        count,
                        share: outcome.total === 0 ? 0 : Math.round((count / outcome.total) * 100),
                        mine: outcome.mine === option.id,
                        leading: closed && top > 0 && count === top,
                    };
                }),
            };
        },

        /* ---------- Scrolling ---------------------------------------------------------------- */

        onScroll() {
            const list = this.$refs.list;
            if (!list) return;
            this.atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < BOTTOM_SLACK;
            if (this.atBottom) this.unseen = 0;
        },

        scrollToBottom(smooth = false) {
            const list = this.$refs.list;
            if (!list) return;
            const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            list.scrollTo({ top: list.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' });
            this.atBottom = true;
            this.unseen = 0;
        },

        get unseenLabel() {
            return this.unseen === 1 ? t.newMessage : t.newMessages.replace(':count', this.unseen);
        },

        time(seconds) {
            return new Date(seconds * 1000).toLocaleTimeString(config.locale ?? undefined, { hour: '2-digit', minute: '2-digit' });
        },

        shortNpub(pubkey) {
            try {
                const npub = npubEncode(pubkey);

                return npub.slice(0, 10) + '…' + npub.slice(-4);
            } catch {
                return '';
            }
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
                    await state.wire.setMuted(pubkey, mute);
                } catch (error) {
                    console.warn('[game chat] saving the mute failed', error);
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

        /** Sign with the viewer's signer and publish; resolves to the signed event, or null after setting `error`. */
        async publish(template, notSent = t.notSent) {
            if (!(await ensureSigner())) {
                this.error = t.noSigner;

                return null;
            }

            let signed;
            try {
                signed = await signTemplate(template, { pubkey: this.me });
            } catch (error) {
                this.error = signerMessage(t, error);

                return null;
            }

            try {
                await Promise.any(this.pool.publish(config.relays, signed));
            } catch {
                this.error = notSent;

                return null;
            }

            return signed;
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
                const template = messageTemplate(content, { channel: config.channel, relayHint: config.relayHint, custom: knownCustomEmojis(this.me) });
                this.lastSentAt = Date.now();
                this.input = this.input === content ? '' : this.input;
                const signed = await this.publish(template);
                if (signed === null) {
                    this.input ||= content;

                    return;
                }
                this.receive(signed);
            } catch (error) {
                console.warn('[game chat] sending failed', error);
                this.error = t.failed;
                this.input ||= content;
            } finally {
                this.sending = false;
            }
        },

        /* ---------- Polls -------------------------------------------------------------------- */

        /** Polls are for accounts whose votes count: the page offers the form to them only. */
        get mayPoll() {
            return !!this.me && config.meCounts === true;
        },

        openPollForm() {
            if (!this.mayPoll) return;
            this.composing = true;
            this.error = '';
            this.$nextTick(() => this.$refs.question?.focus());
        },

        addAnswer() {
            if (this.answers.length < (config.poll?.maxOptions ?? 4)) this.answers = [...this.answers, ''];
        },

        removeAnswer(index) {
            if (this.answers.length > 2) this.answers = this.answers.filter((_, i) => i !== index);
        },

        get pollProblem() {
            return pollBlocker({ question: this.question, options: this.answers }, config.poll ?? {});
        },

        async sendPoll() {
            if (!this.me || this.sending || this.status !== 'live') return;
            if (this.pollProblem !== null) {
                this.error = t.pollInvalid;

                return;
            }

            this.sending = true;
            this.error = '';

            try {
                const template = pollTemplate({
                    question: this.question,
                    options: this.answers,
                    duration: Number(this.duration),
                    channel: config.channel,
                    relayHint: config.relayHint,
                    relays: config.relays,
                    limits: config.poll ?? {},
                });
                const signed = await this.publish(template);
                if (signed === null) return;
                this.composing = false;
                this.question = '';
                this.answers = ['', ''];
                this.receive(signed);
            } catch (error) {
                console.warn('[game chat] the poll failed', error);
                this.error = t.failed;
            } finally {
                this.sending = false;
            }
        },

        async vote(poll, option) {
            if (!this.me || this.voting !== null || isClosed(poll) || this.result(poll).mine === option) return;
            this.voting = poll.id;
            this.error = '';

            try {
                const signed = await this.publish(voteTemplate(poll, option, { relayHint: config.relayHint }), t.voteNotSent);
                if (signed !== null) this.receive(signed);
            } catch (error) {
                console.warn('[game chat] the vote failed', error);
                this.error = t.failed;
            } finally {
                this.voting = null;
            }
        },
    };
}

registerAlpine(() => {
    window.Alpine.data('gameChannel', gameChannel);
    window.Alpine.data('emojiPicker', emojiPicker);
    window.Alpine.data('emojiPopover', emojiPopover);
});
